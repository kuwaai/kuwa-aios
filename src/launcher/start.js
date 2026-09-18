// start.js - Kuwa Windows startup script
// Combines the functionality of start.bat + start.py into a single Node.js script.

const fs = require('fs');
const path = require('path');
const { spawn } = require('child_process');
const ctx = require('./lib/context');
const { SCRIPT_DIR: baseDir, ROOT_DIR: rootDir } = ctx;
const {
  sleep, waitAndRemove,
  globFiles, httpGet, runCapture,
  runAndLog, runAndLogAsync, spawnBackground, ask,
} = require('./lib/helpers');
const {
  cleanupTempBats, recreateNginxHtmlLink, extractPackages,
} = require('./lib/setup');
const {
  runExecutorWorker, getBotAccessCode, importBot, listExecutorFolders, readRunConfig,
} = require('./lib/executors');
const { createPortState } = require('./lib/executor-api');
const { createDependencyManager } = require('./lib/dependencies');
const { stopAll, killTrackedProcs } = require('./stop');

// ─── Constants ───────────────────────────────────────────────────────────────

const MAX_WORKERS = 10;

// ─── Concurrency Pool ────────────────────────────────────────────────────────

async function pooledMap(items, fn, concurrency) {
  const results = [];
  let i = 0;
  async function worker() {
    while (i < items.length) {
      const idx = i++;
      results[idx] = await fn(items[idx], idx);
    }
  }
  await Promise.all(Array.from({ length: Math.min(concurrency, items.length) }, () => worker()));
  return results;
}

// ─── Paths ───────────────────────────────────────────────────────────────────

let kuwaRoot = process.env.KUWA_ROOT || path.join(baseDir, 'root');

process.env.PYTHONUTF8 = '1';
process.env.PYTHONIOENCODING = 'utf8';

// ─── Background Processes ────────────────────────────────────────────────────

const processes = [];
// Worker threads running each executor's run.js.
const executorWorkers = [];
// Map of executor folder name -> worker thread, for stdin routing.
const workersByFolder = new Map();
// Child-process metadata retained so the launcher can recover its registry if
// an earlier IPC registration event was missed.
const executorProcesses = new Map();
const requestedExecutorStops = new Set();

// ─── Launcher IPC Bridge ─────────────────────────────────────────────────────

// When launched by launcher.js, start.js has an IPC channel on fd 3. We use it
// to surface each executor's console output as a separate tab and to route
// stdin typed in the UI back to the right executor.
function notifyLauncher(msg) {
  if (process.send) {
    try { process.send(msg); } catch {}
  }
}

function registerExecutorWorker(folder, worker) {
  workersByFolder.set(folder, worker);
  worker.on('exit', () => {
    if (workersByFolder.get(folder) === worker) workersByFolder.delete(folder);
    const i = executorWorkers.indexOf(worker);
    if (i !== -1) executorWorkers.splice(i, 1);
  });
}

function executorProcessDetails(folder) {
  const processes = [...(executorProcesses.get(folder) || new Map()).values()];
  const pids = processes.map((item) => item.pid).filter(Boolean);
  const ports = processes.map((item) => item.port).filter(Boolean);
  return {
    pid: pids[0] || null,
    pids,
    port: ports[0] || null,
    ports,
  };
}

if (process.on) {
  process.on('message', (msg) => {
    if (!msg || typeof msg !== 'object') return;
    if (msg.type === 'exec-stdin') {
      const worker = workersByFolder.get(msg.folder);
      if (worker) {
        try { worker.postMessage({ type: 'stdin', text: msg.text }); } catch {}
      }
    } else if (msg.type === 'exec-start') {
      const folder = String(msg.folder || '').trim();
      if (folder) {
        requestedExecutorStops.delete(folder);
        const alreadyManaged = workersByFolder.has(folder);
        const result = launchExecutor(folder);
        notifyLauncher({ type: 'exec-started', folder, started: !!result || alreadyManaged, ...executorProcessDetails(folder) });
      }
    } else if (msg.type === 'exec-stop') {
      const folder = String(msg.folder || '').trim();
      const worker = workersByFolder.get(folder);
      if (folder) requestedExecutorStops.add(folder);
      if (worker) {
        try { worker.postMessage({ type: 'stop' }); } catch {}
      }
    } else if (msg.type === 'reload') {
      reloadServices();
    }
  });
}

let reloading = false;
async function reloadServices() {
  if (reloading) return;
  reloading = true;
  stopping = true;

  const workers = executorWorkers.slice();
  for (const worker of workers) {
    try { worker.postMessage({ type: 'stop', timeoutMs: 5000 }); } catch {}
  }

  await Promise.race([
    Promise.all(workers.map((worker) => new Promise((resolve) => worker.once('exit', resolve)))),
    sleep(7000),
  ]);
  for (const worker of executorWorkers) {
    try { await worker.terminate(); } catch {}
  }
  killTrackedProcs(processes);
  process.exit(0);
}

function bg(cmd, cwd, env) {
  return spawnBackground(cmd, { cwd, env, tracker: processes });
}

// ─── On-demand Executor Launching ────────────────────────────────────────────

// Context captured during startServers so individual executors can be started
// later via launcher IPC (exec-start) without restarting start.js.
let execContext = null;

// Launch a single executor folder as a worker thread. Returns the runResult
// (excludeCode/dependencies/ready) or null if it could not be launched (already
// running, missing folder, or context not ready).
function launchExecutor(folder) {
  if (!execContext) return null;
  if (workersByFolder.has(folder)) return null; // already running
  const { cfg, executorsDir, executorDirs, depManager, portState, apiBaseUrl, apiToken } = execContext;
  const fp = path.join(executorsDir, folder);
  if (!fs.existsSync(fp)) {
    console.log(`Executor folder not found: ${folder}`);
    return null;
  }
  const { dependencies } = readRunConfig(fp);
  const gate = depManager.ensureAll(dependencies, folder);
  // Register before dependency provisioning so every attempted executor has a
  // log file, including failures before its worker is created.
  notifyLauncher({ type: 'exec-register', folder });
  return runExecutorWorker(fp, {
    cfg,
    dirs: executorDirs,
    gate,
    portState,
    apiBaseUrl,
    apiToken,
    onLog: (text) => {
      // Mirror executor output to the launcher's per-folder console/log.
      notifyLauncher({ type: 'exec-log', folder, text });
    },
    onProcess: ({ pid, port }) => {
      if (pid) {
        if (!executorProcesses.has(folder)) executorProcesses.set(folder, new Map());
        executorProcesses.get(folder).set(pid, { pid, port });
      }
      notifyLauncher({ type: 'exec-process', folder, pid, port });
    },
    onProcessExit: ({ pid, port }) => {
      if (pid) executorProcesses.get(folder)?.delete(pid);
      notifyLauncher({ type: 'exec-process-exit', folder, pid, port });
    },
    onExit: () => notifyLauncher({ type: 'exec-exit', folder }),
      onWorker: (w) => {
        executorWorkers.push(w);
        registerExecutorWorker(folder, w);
        if (requestedExecutorStops.has(folder)) {
          try { w.postMessage({ type: 'stop' }); } catch {}
        }
      },
  });
}

// ─── Environment Setup ──────────────────────────────────────────────────────

function initEnvironment() {
  // Configure proxy from Windows registry
  const { configureProxy } = require('./lib/getproxy');
  configureProxy();

  const { initVariables } = require('./lib/config');
  const cfg = initVariables(false);

  const folderVars = [
    'nginx_folder', 'redis_folder', 'php_folder', 'node_folder',
    'python_folder', 'gitbash_folder', 'RunHiddenConsole_folder', 'ffmpeg_folder',
  ];
  for (const key of folderVars) {
    if (cfg[key] && !process.env[key]) process.env[key] = cfg[key];
  }
  kuwaRoot = process.env.KUWA_ROOT || path.join(baseDir, 'root');
  return cfg;
}

// ─── Hard Exit ──────────────────────────────────────────────────────────────

let stopping = false;
function hardExit(restart) {
  if (stopping) return;
  stopping = true;
  // Terminate executor worker threads first so they stop spawning processes.
  for (const w of executorWorkers) {
    try { w.terminate(); } catch {}
  }
  stopAll({ restart, trackedProcs: processes });
}

// ─── Start Servers ──────────────────────────────────────────────────────────

async function startServers(cfg) {
  // Redis
  const redisPath = path.join(baseDir, 'packages', process.env.redis_folder || 'redis');
  const rdbPath = path.join(redisPath, 'dump.rdb');
  if (fs.existsSync(rdbPath)) fs.unlinkSync(rdbPath);
  bg('redis-server.exe redis.conf', redisPath);

  // Laravel
  const webPath = path.resolve(rootDir, 'src', 'multi-chat');

  // Apply any pending database migrations on EVERY startup — not just on a
  // fresh package.zip extraction (see setup.js). New releases ship new
  // migrations, and a plain `start` on an already-installed copy would
  // otherwise never run them. The `turu_bot` system account (seeded by
  // 2026_07_06_000000_seed_turu_system_account.php) is exactly such a case:
  // without it `php artisan turu:system-token` returns nothing, every
  // executor's modelConfig() short-circuits with "no system API token
  // available", so NO model/LLM records are created and every bot that
  // references them fails to import ("Base executor ... not found"). `migrate`
  // is idempotent (already-applied migrations are skipped) and `--force`
  // skips the interactive production confirmation prompt.
  runAndLog('php artisan migrate --force', webPath);

  runAndLog('php artisan web:config --settings="updateweb_path=%PATH%"', webPath);
  // Stop any workers left over from a previous run (e.g. orphaned after a
  // crash, or still tracked in the DB from before a rebuild) before starting
  // fresh ones — otherwise old and new workers pile up.
  runAndLog('php artisan workers:stop', webPath);
  bg('php artisan workers:start 10', webPath);

  // Kernel
  const kernelPath = path.resolve(rootDir, 'src', 'kernel');
  const picklePath = path.join(kernelPath, 'records.pickle');
  if (fs.existsSync(picklePath)) fs.unlinkSync(picklePath);
  bg('kuwa-kernel', kernelPath, { SAFETY_GUARD_DISABLE: '1' });

  console.log('Waiting for kernel to be available...');
  while (true) {
    try { await httpGet('http://127.0.0.1:9000', 1000); console.log('Kernel is up.'); break; }
    catch { await sleep(1000); }
  }

  // HTTP server — the web UI does not depend on executor/model registration
  // at all, so start it here, BEFORE launching any executors. This fully
  // decouples nginx from executor setup: even if an executor's setup phase
  // hangs forever (stuck dependency download, etc.), nginx/the web UI is
  // already up and unaffected — no timeout guesswork needed to protect it.
  startNginx();
  // "nginx spawned" is NOT the same as "the web server can serve requests":
  // the php-cgi children need a moment to come up, and the very first PHP
  // request still has to boot/warm the Laravel framework. Every executor
  // registers its model via POST /api/models/configure THROUGH this web
  // server, so if we let executors start before it is actually serving, those
  // calls fail (they only get MODEL_CONFIG_API_MAX_ATTEMPTS quick retries)
  // and the model never lands in the `llms` table. In particular the shared
  // `.tool/kuwa/pipe` base executor then goes unregistered, which makes every
  // bot that references it (ContractExtractor, Mermaid, ...) fail to import
  // with `Base executor ".tool/kuwa/pipe" not found`. Block here until the web
  // server answers with a real HTTP response before continuing.
  console.log('Waiting for the web server to be ready...');
  {
    const WEB_READY_TIMEOUT_MS = 120000;
    const webReadyDeadline = Date.now() + WEB_READY_TIMEOUT_MS;
    while (true) {
      try {
        // Any real HTTP status (even a 302 redirect to /login) means nginx +
        // php-cgi are serving. A 5xx means nginx is up but php-cgi/Laravel is
        // still warming (or crashed) — keep waiting for a healthy response.
        const status = await httpGet('http://127.0.0.1/', 2000);
        if (status && status < 500) {
          console.log(`Web server is up (HTTP ${status}).`);
          break;
        }
      } catch { /* connection refused / timeout while it comes up */ }
      if (Date.now() > webReadyDeadline) {
        console.log(`Web server did not become ready within ${WEB_READY_TIMEOUT_MS / 1000}s; continuing anyway (model configuration may fail).`);
        break;
      }
      await sleep(1000);
    }
  }

  // Fetch a fresh API token for the hidden `turu_bot` system account (see
  // `php artisan turu:system-token`) ONCE here, and hand it to every executor
  // worker thread. Executors then configure their models via
  // `POST /api/models/configure` \u2014 the `model:config` artisan command has
  // been removed entirely, so this is the only way models get configured.
  // The API route goes through the already-running web server instead of
  // spawning a new `php artisan` process per executor. Every call to
  // `turu:system-token` ROTATES the token (revokes any previous one), so
  // this must only be called ONCE per launcher run.
  console.log('Fetching a system API token for model configuration...');
  const apiToken = runCapture('php artisan turu:system-token', { cwd: webPath });
  if (apiToken) {
    console.log('System API token acquired; executors will configure models via the API.');
  } else {
    console.log('Could not obtain a system API token (has `php artisan migrate` been run on this install?). Model configuration will fail until this is fixed.');
  }
  const apiBaseUrl = process.env.KUWA_API_BASE_URL || 'http://127.0.0.1';

  // Executors — each runs its run.js in a dedicated worker thread.
  const executorsDir = path.join(baseDir, 'executors');
  const folderPaths = listExecutorFolders(executorsDir);
  console.log(`Executor folders selected: ${folderPaths.length}`);

  const executorDirs = {
    baseDir,
    rootDir,
    multiChatDir: path.resolve(rootDir, 'src', 'multi-chat'),
    srcExecutorDir: path.resolve(rootDir, 'src', 'executor'),
    packagesDir: path.join(baseDir, 'packages'),
  };

  // Provision shared executor dependencies (e.g. ffmpeg) lazily and exactly
  // once. Each executor is held back (gated) until its declared dependencies
  // are ready; executors sharing a dependency all wait on the same download.
  const depManager = createDependencyManager({ cfg, dirs: executorDirs });

  // Capture context so executors can be started on-demand later (launcher IPC).
  // `portState` is a SharedArrayBuffer-backed counter shared by EVERY
  // executor's worker thread (see executor-api.js's createPortState/pickPort)
  // so concurrently-launched executors never hand out the same
  // EXECUTOR_PORT — previously each worker thread had its own independently
  // randomized counter, so simultaneous launches could collide.
  const portState = createPortState();
  execContext = { cfg, executorsDir, executorDirs, depManager, portState, apiBaseUrl, apiToken };

  // Launch every executor's worker thread concurrently (unbounded — one slot
  // per folder) instead of throttling through the MAX_WORKERS pool used for
  // bot import below. When an API token was obtained above, each executor's
  // setup phase configures its model via the API (no DB-contention concerns
  // — see the comment above `apiToken`). The (now rarer) artisan-CLI
  // fallback path and `bot:import` rely on `database.php`'s sqlite
  // `busy_timeout`/`journal_mode=wal` to ride out any write contention
  // instead of a cross-process lock. Removing the concurrency cap lets all
  // executors' non-DB setup work (dependency provisioning, process spawn)
  // proceed in parallel, which is what actually speeds up boot.
  //
  // Guard each individual `initialized` wait with its own timeout: if a
  // single executor's setup phase hangs (e.g. a stuck `model:config` child
  // process), waiting on it forever would keep its pool slot occupied,
  // which in turn stalls this whole pooledMap()/Promise.all() — blocking
  // everything after it indefinitely (not just that one executor). Timing
  // out here lets the pool move on so other executors still come up even if
  // one never finishes.
  const LAUNCH_INITIALIZED_TIMEOUT_MS = 60000;
  const runResults = (await pooledMap(
    folderPaths,
    async (fp) => {
      const folder = path.basename(fp);
      const r = launchExecutor(folder);
      if (r) {
        let timedOut = false;
        await Promise.race([
          r.initialized,
          sleep(LAUNCH_INITIALIZED_TIMEOUT_MS).then(() => { timedOut = true; }),
        ]);
        if (timedOut) {
          console.log(`--- Executor "${folder}" setup timed out after ${LAUNCH_INITIALIZED_TIMEOUT_MS / 1000}s; continuing without blocking other services. ---`);
        }
      }
      return r;
    },
    folderPaths.length || 1,
  )).filter(Boolean);
  const excludeArgs = runResults.map(r => r.excludeCode).filter(Boolean);

  const excludedAccessCodes = new Set(
    excludeArgs.filter(a => a.startsWith('--exclude=') && a.length > 10).map(a => a.substring(10))
  );
  if (excludedAccessCodes.size > 0) {
    console.log(`Bots to be initialized separately: ${[...excludedAccessCodes].join(', ')}`);
  }

  // Wait for every executor to finish its setup phase (model:config + launch),
  // but cap the wait so a slow/hung executor (e.g. npm/git installs) cannot
  // block model pruning and bot import indefinitely.
  console.log('--- Waiting for executors to finish setup... ---');
  const EXECUTOR_SETUP_TIMEOUT_MS = 120000;
  await Promise.race([
    Promise.all(runResults.map(r => r.initialized)),
    sleep(EXECUTOR_SETUP_TIMEOUT_MS).then(() =>
      console.log(`--- Executor setup wait timed out after ${EXECUTOR_SETUP_TIMEOUT_MS / 1000}s; continuing. ---`)),
  ]);
  console.log('--- Executor setup complete ---');

  if (excludeArgs.length > 0) {
    runAndLog(`php artisan model:prune --force ${excludeArgs.join(' ')}`, webPath);
  }

  runAndLog('php artisan model:reset-health', webPath);
  await sleep(4000);

  // Import bots
  console.log('--- Preparing to import bots... ---');
  const botsDir = path.join(kuwaRoot, 'bootstrap', 'bot');
  if (fs.existsSync(botsDir) && fs.statSync(botsDir).isDirectory()) {
    const botFiles = globFiles(botsDir).filter(p => excludedAccessCodes.has(getBotAccessCode(p)));
    if (botFiles.length > 0) {
      console.log(`Importing ${botFiles.length} bot(s) with up to ${MAX_WORKERS} workers...`);
      await pooledMap(botFiles, f => importBot(f, webPath), MAX_WORKERS);
    } else {
      console.log('No bot files to import.');
    }
  } else {
    console.log(`Bot directory not found, skipping import: ${botsDir}`);
  }

  console.log('--- System initialized. Press Ctrl+C or type a command. ---');
  runAndLog('start http://127.0.0.1');
}

// ─── HTTP Server Launcher ───────────────────────────────────────────────────

function startNginx() {
  const phpPath = path.join(baseDir, 'packages', process.env.php_folder || 'php');
  for (let port = 9101; port <= 9110; port++) {
    bg(`php-cgi.exe -b 127.0.0.1:${port}`, phpPath, { PHP_FCGI_CHILDREN: '2', PHP_FCGI_MAX_REQUESTS: '0' });
  }
  bg('php-cgi.exe -b 127.0.0.1:9123', phpPath);

  recreateNginxHtmlLink(baseDir, rootDir);

  bg('nginx.exe', path.join(baseDir, 'packages', process.env.nginx_folder || 'nginx'));
  console.log('Nginx started!');
}

// ─── Command Loop ───────────────────────────────────────────────────────────

async function commandLoop() {
  const underLauncher = process.env.KUWA_LAUNCHER === '1';
  while (true) {
    // Under the launcher, the launcher owns the terminal and prints its own
    // prompt, so stay silent here; commands only arrive via routed stdin.
    const answer = await ask(underLauncher ? '' : 'Enter a command (stop, seed, hf login, reload): ');
    const cmd = (answer || '').trim().toLowerCase();

    if (cmd === 'stop') {
      // Let the launcher own shutdown so it isn't killed along with the services.
      if (underLauncher) notifyLauncher({ type: 'lifecycle', action: 'stop' });
      else hardExit(false);
    }
    else if (cmd === 'seed') { runAndLog('AdminSeeder.bat', path.resolve(rootDir, 'src', 'multi-chat', 'executables', 'bat')); }
    else if (cmd === 'hf login') { runAndLog('hf.exe login'); }
    else if (cmd === 'reload') {
      if (underLauncher) notifyLauncher({ type: 'lifecycle', action: 'reload' });
      else hardExit(true);
    }
    else if (cmd) { console.log('Unknown command.'); }
  }
}

// ─── Main ───────────────────────────────────────────────────────────────────

async function main() {
  const cfg = initEnvironment();
  cleanupTempBats(path.join(baseDir, 'executors'));
  extractPackages(baseDir, rootDir, kuwaRoot);
  recreateNginxHtmlLink(baseDir, rootDir);
  await startServers(cfg);
  commandLoop();
}

main().catch((err) => {
  console.error(`Fatal error: ${err.message}`);
  console.error(err.stack);
  process.exit(1);
});
