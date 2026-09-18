// executor-api.js — Helper API passed to each executor's run.js.
//
// Each executor folder contains a `run.js` (CommonJS) that exports an async
// function `run(api)`. The launcher loads it inside a worker thread (see
// executor-runner.js) and calls it with the `api` object built here.
//
// The API replaces the old Windows batch scripts: it can run Laravel artisan
// commands, configure models, import bots, and launch the actual executor
// processes (python / kuwa-executor) as tracked background children.
//
// Environment (PATH, KUWA_ROOT, cache dirs, bundled python/php/node folders)
// is already configured by the launcher before the worker starts, so run.js
// does NOT need to call variables.bat.

const path = require('path');
const fs = require('fs');
const net = require('net');
const http = require('http');
const { spawn, spawnSync, execSync } = require('child_process');

const TASKKILL = path.join(process.env.SystemRoot || 'C:\\Windows', 'System32', 'taskkill.exe');

function forceKillProcessTree(pid) {
  if (!pid) return;
  if (process.platform === 'win32') {
    try {
      spawnSync(TASKKILL, ['/PID', String(pid), '/T', '/F'], { windowsHide: true, stdio: 'ignore' });
    } catch {}
    return;
  }

  try { spawnSync('pkill', ['-KILL', '-P', String(pid)], { stdio: 'ignore' }); } catch {}
  try { process.kill(pid, 'SIGKILL'); } catch {}
}

function requestShutdown(port, deadline) {
  return new Promise((resolve) => {
    const attempt = () => {
      if (Date.now() >= deadline) {
        resolve(false);
        return;
      }

      const request = http.get({ host: '127.0.0.1', port, path: '/shutdown', timeout: 250 }, (response) => {
        response.resume();
        response.once('end', () => resolve(true));
      });
      const retry = () => {
        if (Date.now() >= deadline) resolve(false);
        else setTimeout(attempt, 100);
      };
      request.once('error', retry);
      request.once('timeout', () => request.destroy());
    };
    attempt();
  });
}

// Allocate sequential ports from a shared, cross-worker-thread counter so
// that executors launched concurrently (each in its own worker thread, which
// used to get its OWN independently-randomized counter) never hand out the
// same port to two different executors. `createPortState()` is called ONCE
// by the main thread (start.js / start-executor.js) and the resulting
// SharedArrayBuffer is passed to every worker via workerData.portState; each
// worker reconstructs an Int32Array view over the SAME memory and increments
// it atomically.
//
// Kept narrow and away from the high ephemeral range (roughly 49152-65535)
// that Windows/Hyper-V/WSL carve up into "excluded port range" reservations
// (see `netsh interface ipv4 show excludedportrange protocol=tcp`) — ports
// inside those reservations fail to bind with an "access denied"-style error
// (WSAEACCES / Python errno 13) even though nothing is actually listening on
// them. A lower, narrower range is less likely to overlap those reservations,
// but isn't guaranteed to avoid them entirely, so pickPort() below still
// verifies each candidate with a real bind test before handing it out.
const PORT_RANGE_BASE = 20000;
const PORT_RANGE_SIZE = 5000; // 20000-24999

function createPortState() {
  const buffer = new SharedArrayBuffer(4);
  const view = new Int32Array(buffer);
  // Randomize the starting offset so repeated launcher runs don't always
  // begin at the same port (helps avoid a lingering process from a previous
  // run that hasn't released its port yet).
  Atomics.store(view, 0, Math.floor(Math.random() * PORT_RANGE_SIZE));
  return { buffer, base: PORT_RANGE_BASE, range: PORT_RANGE_SIZE };
}

// Fallback per-process counter, used only if no shared portState was
// provided (should not normally happen — kept for robustness).
let _nextPort = PORT_RANGE_BASE + Math.floor(Math.random() * PORT_RANGE_SIZE);

// Messages a spawned executor prints when it fails to bind its listening
// port (already in use, excluded by the OS, or — as seen on Windows —
// "access denied" for a port that's effectively unavailable). Matched
// case-insensitively against the child's combined stdout/stderr. Kept as a
// second line of defense in case a port passes the pre-flight bind test here
// but is grabbed or excluded between the check and the child's own bind.
const BIND_FAIL_RE = /address already in use|only one usage of each socket address|errno 98|errno 13|winerror 10048|eaddrinuse|attempting to bind|access is denied|拒絕存取|存取權限不足/i;
const MAX_PORT_BIND_ATTEMPTS = 15;
const PORT_BIND_GRACE_MS = 3000;

// Probe whether `port` can actually be bound on `bindAddr` right now — this
// catches BOTH ordinary "already in use" conflicts AND Windows' OS-level
// "excluded port range" reservations (Hyper-V/WSL/Docker), which reject the
// bind with an access-denied style error even though the port looks free.
// Resolves true/false; never rejects.
function canBindPort(port, bindAddr) {
  return new Promise((resolve) => {
    const tester = net.createServer();
    tester.once('error', () => resolve(false));
    tester.listen({ port, host: bindAddr, exclusive: true }, () => {
      tester.close(() => resolve(true));
    });
  });
}

// `php artisan ...` calls (e.g. bot:import) can occasionally hang (observed:
// a stuck child that never emits 'close' on Windows), which would
// permanently stall that executor's own setup (its background processes
// would never launch). Cap how long we wait before force-killing it and
// moving on.
const ARTISAN_TIMEOUT_MS = 30000;

// modelConfig() always uses the POST /api/models/configure HTTP route (see
// below) — the `model:config` artisan command has been removed. The web
// server may not be fully ready the instant nginx is started, so allow a
// couple of quick retries before giving up.
const MODEL_CONFIG_API_MAX_ATTEMPTS = 5;
const MODEL_CONFIG_API_RETRY_DELAY_MS = 2000;

function createApi(workerData, parentPort) {
  const { folderPath, accessCode, name, cfg, dirs, apiBaseUrl, apiToken } = workerData;

  // Reconstruct the Int32Array view over the shared port-counter buffer (if
  // one was provided) so pickPort() below draws from the SAME counter as
  // every other executor's worker thread.
  const portState = workerData.portState
    ? { ...workerData.portState, view: new Int32Array(workerData.portState.buffer) }
    : null;

  function pickPort() {
    if (portState) {
      const n = Atomics.add(portState.view, 0, 1);
      return portState.base + (n % portState.range);
    }
    const p = _nextPort++;
    if (_nextPort > PORT_RANGE_BASE + PORT_RANGE_SIZE) _nextPort = PORT_RANGE_BASE;
    return p;
  }

  // Draw candidate ports from pickPort() and verify each one with an actual
  // bind test (canBindPort) before handing it out, so callers never even
  // attempt to launch an executor on a port that Windows will refuse (e.g. an
  // excluded reservation) or that's already occupied. Tries up to `attempts`
  // distinct candidates; returns null if none of them are bindable.
  async function findAvailablePort(bindAddr, attempts = MAX_PORT_BIND_ATTEMPTS) {
    for (let i = 0; i < attempts; i++) {
      const candidate = pickPort();
      if (await canBindPort(candidate, bindAddr)) return candidate;
    }
    return null;
  }

  // Long-running background children. The worker stays alive while any are
  // running so their output keeps streaming to the launcher console.
  const children = [];
  let stopRequested = false;
  let resolveIdle = null;

  const post = (type, payload = {}) => {
    try { parentPort.postMessage({ type, ...payload }); } catch {}
  };
  const log = (msg) => post('log', { text: String(msg).endsWith('\n') ? String(msg) : String(msg) + '\n' });

  const formatCommand = (cmd, args) => [cmd, ...args].map((part) => {
    const value = String(part);
    return /[\s"']/u.test(value) ? JSON.stringify(value) : value;
  }).join(' ');

  function forward(child) {
    if (child.stdout) child.stdout.on('data', (d) => post('log', { text: d.toString('utf-8') }));
    if (child.stderr) child.stderr.on('data', (d) => post('log', { text: d.toString('utf-8') }));
  }

  // Run a command to completion; resolves with the exit code. Pass
  // `opts.timeoutMs` to force-kill the child (and resolve instead of hanging
  // forever) if it doesn't close in time.
  function run(cmd, args = [], opts = {}) {
    return new Promise((resolve) => {
      log(`[${name}] cwd=${opts.cwd || folderPath} $ ${formatCommand(cmd, args)}`);
      const child = spawn(cmd, args, {
        cwd: opts.cwd || folderPath,
        env: {
          ...process.env,
          KUWA_EXECUTOR_LOG_API_URL: process.env.KUWA_LAUNCHER_LOG_API_BASE
            ? `${process.env.KUWA_LAUNCHER_LOG_API_BASE}/${encodeURIComponent(name)}?tail=0`
            : '',
          KUWA_EXECUTOR_LOG_NAME: name,
          ...(opts.env || {}),
        },
        stdio: ['ignore', 'pipe', 'pipe'],
        shell: !!opts.shell,
        windowsHide: true,
      });
      forward(child);
      let settled = false;
      let timer = null;
      const finish = (code) => {
        if (settled) return;
        settled = true;
        if (timer) clearTimeout(timer);
        resolve(code);
      };
      if (opts.timeoutMs) {
        timer = setTimeout(() => {
          log(`[${name}] cwd=${opts.cwd || folderPath} $ ${formatCommand(cmd, args)} — timed out after ${opts.timeoutMs / 1000}s; killing and continuing.`);
          forceKillProcessTree(child.pid);
          finish(1);
        }, opts.timeoutMs);
      }
      child.on('close', (code) => finish(code || 0));
      child.on('error', (e) => { log(`[${name}] cwd=${opts.cwd || folderPath} $ ${formatCommand(cmd, args)} error: ${e.message}`); finish(1); });
    });
  }

  // Run a full command line through cmd.exe (needed for .cmd/.bat tools like
  // npm, pnpm, bun, git pipelines).
  function sh(commandLine, opts = {}) {
    return run(process.env.ComSpec || 'cmd.exe', ['/d', '/s', '/c', commandLine], opts);
  }

  // Launch a long-running background process; tracked so the worker stays alive.
  function spawnBg(cmd, args = [], opts = {}) {
    log(`[${name}] cwd=${opts.cwd || folderPath} (bg) ${formatCommand(cmd, args)}`);
    const child = spawn(cmd, args, {
      cwd: opts.cwd || folderPath,
      env: { ...process.env, ...(opts.env || {}) },
      stdio: ['pipe', 'pipe', 'pipe'],
      shell: !!opts.shell,
      windowsHide: true,
    });
    forward(child);
    children.push(child);
    post('process', { pid: child.pid || null, port: child._executorPort || null });
    child.on('exit', () => {
      const i = children.indexOf(child);
      if (i !== -1) children.splice(i, 1);
      post('process-exit', { pid: child.pid || null });
      if (children.length === 0 && resolveIdle) resolveIdle();
    });
    child.on('error', (e) => { log(`[${name}] cwd=${opts.cwd || folderPath} (bg) ${formatCommand(cmd, args)} failed: ${e.message}`); });
    if (stopRequested && child.pid) {
      forceKillProcessTree(child.pid);
    }
    return child;
  }

  // Write text to the stdin of every running background child (commands typed
  // in the launcher UI are routed here).
  function writeStdin(text) {
    let wrote = false;
    for (const child of children) {
      if (child.stdin && child.stdin.writable) {
        try { child.stdin.write(text); wrote = true; } catch {}
      }
    }
    return wrote;
  }

  function which(cmd) {
    const r = spawnSync('where', [cmd], { shell: true, stdio: 'pipe', env: process.env, windowsHide: true });
    return r.status === 0;
  }

  // ── Path helpers ──
  const inFolder = (...p) => path.join(folderPath, ...p);
  const srcExecutor = (...p) => path.join(dirs.srcExecutorDir, ...p);

  // ── Laravel / Kuwa helpers ──
  // `database.php` configures sqlite `busy_timeout`/`journal_mode=wal`, which
  // lets concurrent writers wait out short lock contention on their own —
  // and model configuration (the main source of concurrent artisan calls)
  // now goes through the API instead of the CLI — so no cross-process lock
  // is needed here anymore.
  function artisan(args, opts = {}) {
    return run('php', ['artisan', ...args], { cwd: dirs.multiChatDir, timeoutMs: ARTISAN_TIMEOUT_MS, ...opts });
  }

  // Configure a model (and optionally create its default bot) via the
  // POST /api/models/configure API route (App\Services\ModelConfigurator) —
  // the `model:config` artisan command has been removed, so this is the only
  // way to configure a model now. Every executor's setup phase calls this.
  // The API route hits the same database through the already-running
  // Laravel/nginx web server instead of spawning a new `php artisan` process
  // per executor.
  async function modelConfigViaApi(code, displayName, options = {}) {
    const form = new FormData();
    form.append('access_code', code);
    form.append('name', displayName);
    if (options.image) {
      const img = path.isAbsolute(options.image) ? options.image : inFolder(options.image);
      if (fs.existsSync(img)) {
        form.append('image', new Blob([fs.readFileSync(img)]), path.basename(img));
      } else {
        log(`[${name}] Warning: model image not found, skipping: ${img}`);
      }
    }
    if (options.order !== undefined && options.order !== null) form.append('order', String(options.order));
    if (options.doNotCreateBot) form.append('do_not_create_bot', '1');
    if (options.force) form.append('force', '1');

    const url = `${apiBaseUrl}/api/models/configure`;
    log(`[${name}] $ POST ${url} (access_code=${code})`);
    for (let attempt = 1; attempt <= MODEL_CONFIG_API_MAX_ATTEMPTS; attempt++) {
      const lastAttempt = attempt >= MODEL_CONFIG_API_MAX_ATTEMPTS;
      try {
        const res = await fetch(url, {
          method: 'POST',
          headers: { Authorization: `Bearer ${apiToken}` },
          body: form,
          signal: AbortSignal.timeout(ARTISAN_TIMEOUT_MS),
        });
        const body = await res.json().catch(() => ({}));
        if (res.ok && body.status === 'success') {
          log(`[${name}] model configure OK (model_id=${body.model_id}, bot_id=${body.bot_id ?? 'none'})`);
          return 0;
        }
        // A 5xx (nginx/php-fpm/php-cgi still warming up right after nginx
        // started) is worth retrying; a definitive 4xx application error is not
        // — EXCEPT the SQLite "database is locked" transient error, which the
        // API surfaces as a 422 (it's a caught RuntimeException, and
        // Illuminate\Database\QueryException extends RuntimeException). Many
        // executors call this endpoint concurrently at launcher startup, and
        // ModelConfigurator::configure() already retries the whole DB
        // transaction internally, but under heavy contention all of ITS
        // attempts can still be exhausted — so retry here too as a second
        // layer of defense instead of permanently failing model registration.
        const isTransientDbLock = res.status === 422 && /database is locked/i.test(JSON.stringify(body.message ?? ''));
        if ((res.status >= 500 || isTransientDbLock) && !lastAttempt) {
          await new Promise((r) => setTimeout(r, MODEL_CONFIG_API_RETRY_DELAY_MS));
          continue;
        }
        log(`[${name}] model configure failed (HTTP ${res.status}): ${JSON.stringify(body.message ?? body)}`);
        return 1;
      } catch (e) {
        if (lastAttempt) {
          log(`[${name}] model configure request error: ${e.message}`);
          return 1;
        }
        // The web server may still be coming up right after nginx started; retry briefly.
        await new Promise((r) => setTimeout(r, MODEL_CONFIG_API_RETRY_DELAY_MS));
      }
    }
    return 1;
  }

  // The `model:config` artisan command has been removed entirely — every
  // caller now goes through the API. Without a system API token (e.g. this
  // install hasn't run `php artisan migrate` / the system-account seed migration
  // yet), there is no way to configure the model at all, so fail loudly
  // instead of trying a CLI command that no longer exists.
  async function modelConfig(code, displayName, options = {}) {
    if (!apiToken) {
      log(`[${name}] Cannot configure model "${displayName}" (${code}): no system API token available. Run \`php artisan migrate\` on this install first.`);
      return 1;
    }
    return modelConfigViaApi(code, displayName, options);
  }

  function importBot(botPath) {
    const abs = path.isAbsolute(botPath) ? botPath : inFolder(botPath);
    return artisan(['bot:import', abs]);
  }

  // ── Executor launchers (background) ──
  // Allocate a known port for the executor so we can later call its /shutdown
  // endpoint gracefully. The executor honors the EXECUTOR_PORT environment
  // variable; we record the port on the child for stopChildren().
  //
  // Spawns `cmd` with EXECUTOR_PORT set to a port that has already passed a
  // real bind test (findAvailablePort — catches both ordinary conflicts and
  // Windows OS-level "excluded port range" reservations). If the process's
  // own output STILL indicates the port couldn't be bound (a race against
  // another process grabbing it between our test and the child's own bind),
  // it is killed and retried with a NEW port, up to MAX_PORT_BIND_ATTEMPTS
  // times. Resolves once a process looks healthy (no failure signal within
  // PORT_BIND_GRACE_MS), exits for an unrelated reason, or attempts are
  // exhausted.
  function spawnWithPortRetry(cmd, args, opts) {
    return new Promise((resolveSpawn) => {
      const bind_addr = process.env.EXECUTOR_BIND_ADDR || '127.0.0.1';
      const tryAttempt = async (attempt) => {
        if (stopRequested) {
          resolveSpawn(null);
          return;
        }
        const port = await findAvailablePort(bind_addr, MAX_PORT_BIND_ATTEMPTS - attempt + 1);
        if (port === null) {
          log(`[${name}] Giving up on ${formatCommand(cmd, args)}: no bindable port found in range ${PORT_RANGE_BASE}-${PORT_RANGE_BASE + PORT_RANGE_SIZE - 1} after ${attempt} attempt(s).`);
          resolveSpawn(null);
          return;
        }
        const env = { ...(opts.env || {}), EXECUTOR_PORT: String(port), EXECUTOR_BIND_ADDR: bind_addr };
        const suffix = attempt > 1 ? ` (attempt ${attempt}/${MAX_PORT_BIND_ATTEMPTS})` : '';
        log(`[${name}] cwd=${opts.cwd || folderPath} (bg) ${formatCommand(cmd, args)} [port ${port}]${suffix}`);
        const child = spawn(cmd, args, {
          cwd: opts.cwd || folderPath,
          env: {
            ...process.env,
            KUWA_EXECUTOR_LOG_API_URL: process.env.KUWA_LAUNCHER_LOG_API_BASE
              ? `${process.env.KUWA_LAUNCHER_LOG_API_BASE}/${encodeURIComponent(name)}?tail=0`
              : '',
            KUWA_EXECUTOR_LOG_NAME: name,
            ...env,
          },
          stdio: ['pipe', 'pipe', 'pipe'],
          shell: !!opts.shell,
          windowsHide: true,
        });
        child._executorPort = port;

        let buf = '';
        let settled = false;
        let timer = null;
        const onData = (d) => {
          const text = d.toString('utf-8');
          buf += text;
          post('log', { text });
          if (!settled && BIND_FAIL_RE.test(buf)) finish('bind_failed');
        };
        child.stdout.on('data', onData);
        child.stderr.on('data', onData);

        const finish = (verdict) => {
          if (settled) return;
          settled = true;
          if (timer) clearTimeout(timer);

          if (verdict === 'ok') {
            // Looks healthy — track it for the lifetime of the worker, same
            // bookkeeping as spawnBg() below.
            children.push(child);
            post('process', { pid: child.pid || null, port });
            child.on('exit', () => {
              const i = children.indexOf(child);
              if (i !== -1) children.splice(i, 1);
              post('process-exit', { pid: child.pid || null, port });
              if (children.length === 0 && resolveIdle) resolveIdle();
            });
            child.on('error', (e) => { log(`[${name}] cwd=${opts.cwd || folderPath} (bg) ${formatCommand(cmd, args)} failed: ${e.message}`); });
            resolveSpawn(child);
            return;
          }

          if (verdict === 'exited') {
            log(`[${name}] ${formatCommand(cmd, args)} exited immediately (port ${port} did not look like the cause); not retrying.`);
            resolveSpawn(child);
            return;
          }

          // verdict === 'bind_failed'
          forceKillProcessTree(child.pid);
          if (attempt >= MAX_PORT_BIND_ATTEMPTS) {
            log(`[${name}] Giving up on ${formatCommand(cmd, args)} after ${MAX_PORT_BIND_ATTEMPTS} port bind attempts.`);
            resolveSpawn(null);
            return;
          }
          log(`[${name}] Port ${port} looks unavailable for ${formatCommand(cmd, args)}; retrying with a different port.`);
          tryAttempt(attempt + 1);
        };

        child.once('exit', () => finish(BIND_FAIL_RE.test(buf) ? 'bind_failed' : 'exited'));
        child.once('error', (e) => { buf += e.message; finish('bind_failed'); });
        timer = setTimeout(() => finish('ok'), PORT_BIND_GRACE_MS);
        if (stopRequested) {
          forceKillProcessTree(child.pid);
          resolveSpawn(null);
        }
      };

      tryAttempt(1);
    });
  }

  function startExecutor(args, opts = {}) {
    const count = opts.count || 1;
    return Promise.all(
      Array.from({ length: count }, () => spawnWithPortRetry('kuwa-executor', args, opts))
    );
  }

  function startPython(script, args = [], opts = {}) {
    const count = opts.count || 1;
    return Promise.all(
      Array.from({ length: count }, () => spawnWithPortRetry('python', [script, ...args], opts))
    );
  }

  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  // Resolves when all background children have exited (or immediately if none).
  function waitForChildren() {
    if (children.length === 0) return Promise.resolve();
    return new Promise((res) => { resolveIdle = res; });
  }

  // Stop every background child. First tries a graceful shutdown via the
  // executor's /shutdown endpoint (waiting up to 10s), then force-kills the
  // process tree if it's still alive.
  function stopChildren(timeoutMs = 10000) {
    stopRequested = true;
    const list = children.slice();
    if (list.length === 0) return Promise.resolve();
    log(`[${name}] Stopping ${list.length} process(es)...`);

    const stopOne = async (child) => {
      const pid = child.pid;
      let exited = false;

      child.once('exit', () => {
        exited = true;
        log(`[${name}] PID ${pid} exited.`);
      });

      const cleanup = () => {
        try { child.stdin?.destroy(); } catch {}
        try { child.stdout?.destroy(); } catch {}
        try { child.stderr?.destroy(); } catch {}
        const i = children.indexOf(child);
        if (i !== -1) children.splice(i, 1);
        if (children.length === 0 && resolveIdle) resolveIdle();
      };

      if (!pid) {
        try { child.kill(); } catch {}
        cleanup();
        return;
      }

      // 1) Graceful shutdown via the /shutdown endpoint on the port we assigned
      //    at launch time. Then poll (non-blocking) up to 10s for the process
      //    to exit on its own.
      const port = child._executorPort;
      if (port) {
        log(`[${name}] Requesting graceful shutdown at http://localhost:${port}/shutdown...`);
        const deadline = Date.now() + timeoutMs;
        await requestShutdown(port, deadline);
        log(`[${name}] Waiting up to 10s for PID ${pid} to shut down gracefully...`);
        while (Date.now() < deadline && !exited) {
          await sleep(500);
        }
        if (exited) { cleanup(); return; }
        log(`[${name}] PID ${pid} did not exit gracefully within 10s.`);
      }

      // 2) Force-kill the entire process tree if it's still running.
      log(`[${name}] Force-killing PID ${pid} and children...`);
      forceKillProcessTree(pid);
      log(`[${name}] PID ${pid} killed.`);
      cleanup();

      // Give the OS a moment to deliver the exit event.
      for (let waited = 0; waited < 3000 && !exited; waited += 200) {
        await sleep(200);
      }
    };

    return Promise.all(list.map(stopOne));
  }

  return {
    name, accessCode, cfg, dirs, folderPath,
    log, run, sh, which, spawnBg, writeStdin,
    artisan, modelConfig, importBot,
    startExecutor, startPython,
    inFolder, srcExecutor, sleep,
    waitForChildren, stopChildren,
  };
}

module.exports = { createApi, createPortState };
