// launcher.js - Turu Launcher API Server
// Local-only HTTP API server with build/start/update/stop controls.
// On launch: stops all existing processes, then runs start.js.
// On exit: triggers stop.js to kill all processes.

const http = require('http');
const fs = require('fs');
const path = require('path');
const os = require('os');
const readline = require('readline');
const { spawn, spawnSync, execFileSync } = require('child_process');
const { SCRIPT_DIR: BASE_DIR, ROOT_DIR } = require('./lib/context');
const { getRepoProcesses } = require('./stop');
const { readRunConfig } = require('./lib/executors');
const {
  isExecutorRunning: readExecutorRunning,
  isExecutorStopped: readExecutorStopped,
  waitForExecutorState,
} = require('./lib/executor-control');

const CONSOLE_TITLE = 'Turu Launcher';

function isExecutorRunning(name) {
  return readExecutorRunning(registry?.procs?.get('exec: ' + name));
}

function isExecutorStopped(name) {
  const entry = registry?.procs?.get('exec: ' + name);
  return readExecutorStopped(entry);
}

function isExecutorRegistered(name) {
  return registry?.procs?.has('exec: ' + name) === true;
}
let handingOffToElevatedLauncher = false;

process.title = CONSOLE_TITLE;

// ─── Admin Privilege Elevation (Windows) ─────────────────────────────────────
// Ask for admin rights once at startup via the native UAC dialog. If the user
// declines (or elevation isn't possible), continue running unelevated so a
// single denial never blocks booting the app.
(function ensureElevated() {
  if (os.platform() !== 'win32') return;
  if (process.env.KUWA_ELEVATION_ATTEMPTED === '1') return; // don't loop

  const isAdmin = () => {
    try {
      execFileSync('net', ['session'], { stdio: 'ignore', windowsHide: true });
      return true;
    } catch {
      return false;
    }
  };

  if (isAdmin()) return;

  console.log('Requesting administrator privileges (UAC)...');
  // Re-launch via launcher.bat (in a fresh cmd window) rather than spawning
  // node.exe directly. Elevating node.exe directly via Start-Process/-Verb
  // RunAs creates a console window that doesn't properly attach stdin, so
  // typing in it does nothing. Going through launcher.bat gives the elevated
  // process a normal, interactive cmd console.
  //
  // UAC elevation starts the new process with the user's default logon
  // environment rather than inheriting the current process's PATH (e.g. it
  // can drop user-scoped PATH entries added via nvm, choco, etc.). We
  // generate a tiny wrapper .bat (written directly to disk, so there's no
  // shell-quoting to worry about) that restores the current user's PATH
  // before calling launcher.bat, then elevate cmd.exe to run that wrapper.
  let batPath = path.join(BASE_DIR, 'launcher.bat');
  const cliArgs = process.argv.slice(2);
  const debugUacIdx = cliArgs.indexOf('--debug-uac');
  if (debugUacIdx !== -1) {
    batPath = path.join(ROOT_DIR, 'src', 'debug_uac.bat');
    cliArgs.splice(debugUacIdx, 1);
  }
  const currentPath = process.env.PATH || process.env.Path || '';
  const wrapperPath = path.join(os.tmpdir(), `kuwa_launcher_elevate_${process.pid}.bat`);

  // Ensure the console uses a TrueType font (Consolas) when it starts, which
  // fixes the Windows legacy console bug where CJK and IME input break after
  // the code page is changed to UTF-8 (chcp 65001).
  try {
    const regKey = 'HKCU\\Console\\' + CONSOLE_TITLE;
    execFileSync('reg', ['add', regKey, '/v', 'FaceName', '/t', 'REG_SZ', '/d', 'Consolas', '/f'], { stdio: 'ignore', windowsHide: true });
    execFileSync('reg', ['add', regKey, '/v', 'FontFamily', '/t', 'REG_DWORD', '/d', '0x36', '/f'], { stdio: 'ignore', windowsHide: true });
    execFileSync('reg', ['add', regKey, '/v', 'CodePage', '/t', 'REG_DWORD', '/d', '0xfde9', '/f'], { stdio: 'ignore', windowsHide: true });
    execFileSync('reg', ['add', regKey, '/v', 'InterceptCopyPaste', '/t', 'REG_DWORD', '/d', '1', '/f'], { stdio: 'ignore', windowsHide: true });
  } catch {}

  const quoteArg = (s) => `"${String(s).replace(/"/g, '\\"')}"`;
  const argString = cliArgs.map(quoteArg).join(' ');
  // A single `set "PATH=..."` line in cmd.exe is capped at ~8191 characters.
  // Only splice it in when it safely fits; otherwise leave PATH untouched
  // rather than risk truncating/corrupting the wrapper script.
  const setPathLine = (`set "PATH=${currentPath}"`.length < 8000)
    ? `set "PATH=${currentPath}"\r\n`
    : '';

  const wrapperContent =
    `@echo off\r\n` +
    `title ${CONSOLE_TITLE}\r\n` +
    `chcp 65001 >nul\r\n` +
    setPathLine +
    `cd /d ${quoteArg(BASE_DIR)}\r\n` +
    `call ${quoteArg(batPath)}${argString ? ' ' + argString : ''}\r\n` +
    `del /q "%~f0"\r\n`;

  // Write without BOM because cmd.exe batch files do not support it.
  fs.writeFileSync(wrapperPath, wrapperContent, 'utf-8');

  const quotePs = (s) => `'${String(s).replace(/'/g, "''")}'`;
  
  // We launch an elevated cmd.exe, which then uses the `start` command to create a new
  // visible console window with the initial title matching CONSOLE_TITLE running the bat wrapper.
  // This initial title perfectly matches the registry key we just created, ensuring
  // conhost.exe natively applies the Consolas font.
  // The `-WindowStyle Hidden` prevents the parent cmd.exe from flashing on screen.
  const startArgs = `/c start "${CONSOLE_TITLE}" cmd.exe /c "${wrapperPath}"`;
  
  const psCommand =
    `Start-Process -FilePath 'cmd.exe' ` +
    `-WindowStyle Hidden ` +
    `-ArgumentList ${quotePs(startArgs)} ` +
    `-WorkingDirectory ${quotePs(BASE_DIR)} -Verb RunAs`;

  const result = spawnSync('powershell.exe', ['-NoProfile', '-Command', psCommand], {
    stdio: 'inherit',
    windowsHide: false,
  });

  if (!result.error && result.status === 0) {
    // Elevated instance was launched successfully — let it take over.
    handingOffToElevatedLauncher = true;
    process.exit(0);
  }
  // UAC was denied or elevation failed — continue unelevated.
  console.log('Continuing without administrator privileges.');
})();

// This file lives at <repo>/src/launcher. SCRIPT_DIR holds the launcher
// scripts (build/start/stop).
const SCRIPT_DIR = path.resolve(__dirname);
const LOGS_DIR = path.join(BASE_DIR, 'logs');
const EXECUTORS_DIR = path.join(BASE_DIR, 'executors');
const EXEC_LOGS_DIR = path.join(EXECUTORS_DIR, 'logs');
const BUILD_LOG = path.join(LOGS_DIR, 'build.log');
const START_LOG = path.join(LOGS_DIR, 'start.log');
const BUILD_INITIAL_LOG = path.join(LOGS_DIR, 'build__initial_.log');
const STDIN_FILE = path.join(BASE_DIR, 'root', 'dev', 'update_stdin');
const STDOUT_FILE = path.join(BASE_DIR, 'root', 'dev', 'update_stdout');

const PORT = 9417;
process.env.KUWA_LAUNCHER_LOG_API_BASE = `http://127.0.0.1:${PORT}/api/logs/executor`;
const CONFIG_PATH = path.join(BASE_DIR, 'config.yaml');
const DEFAULT_CONFIG_PATH = path.join(BASE_DIR, '_config.yaml');

fs.mkdirSync(LOGS_DIR, { recursive: true });
fs.mkdirSync(path.join(BASE_DIR, 'root', 'dev'), { recursive: true });

// ─── Config Management ───────────────────────────────────────────────────────

// Load the list of enabled executor names from config.yaml
function loadEnabledExecutors() {
  try {
    const configPath = fs.existsSync(CONFIG_PATH) ? CONFIG_PATH : DEFAULT_CONFIG_PATH;
    if (!fs.existsSync(configPath)) return [];
    const content = fs.readFileSync(configPath, 'utf-8').replace(/^\uFEFF/, '');
    const enabled = [];
    for (const line of content.split(/\r?\n/)) {
      const trimmed = line.trim();
      if (!trimmed || trimmed.startsWith('#')) continue;
      const match = line.match(/^\s*-\s*(.+)$/);
      if (match) {
        const name = match[1].trim().replace(/^["']|["']$/g, '');
        if (name) enabled.push(name);
      }
    }
    return enabled;
  } catch {
    return [];
  }
}

// Save the list of enabled executors to config.yaml
function saveEnabledExecutors(executors) {
  try {
    const lines = executors.map(name => `- ${name}`);
    const content = lines.join('\n') + '\n';
    fs.writeFileSync(CONFIG_PATH, content, 'utf-8');
    return true;
  } catch (e) {
    console.error('Error saving config:', e);
    return false;
  }
}

// List all available executor folders
function listAllExecutors() {
  try {
    if (!fs.existsSync(EXECUTORS_DIR)) return [];
    return fs.readdirSync(EXECUTORS_DIR)
      .filter(f => fs.statSync(path.join(EXECUTORS_DIR, f)).isDirectory())
      .sort();
  } catch {
    return [];
  }
}

// Wipe per-executor logs on each launcher startup so each session starts clean.
function resetExecutorLogs() {
  try {
    if (fs.existsSync(EXEC_LOGS_DIR)) {
      for (const f of fs.readdirSync(EXEC_LOGS_DIR)) {
        if (f.endsWith('.log')) {
          try { fs.unlinkSync(path.join(EXEC_LOGS_DIR, f)); } catch {}
        }
      }
    }
  } catch {}
  fs.mkdirSync(EXEC_LOGS_DIR, { recursive: true });
}
resetExecutorLogs();

// Wipe the stale "build (initial)" log left over from a previous run. It's
// only recreated when an initial build actually runs on this startup, so
// without this it can otherwise linger indefinitely with old content.
try { fs.unlinkSync(BUILD_INITIAL_LOG); } catch {}

// ─── In-Memory Process Registry (Debug UI) ───────────────────────────────────

const DEBUG_PORT = 7679;
const DEBUG_API_ENABLED = !/^(0|false|no|off)$/i.test(process.env.KUWA_LAUNCHER_DEBUG_API || '');

class ProcessRegistry {
  constructor() {
    this.procs = new Map();
    this._listSubs = new Set();
  }

  _logPath(name) {
    return path.join(LOGS_DIR, name.replace(/[^a-zA-Z0-9_.-]/g, '_') + '.log');
  }

  register(name, proc, opts = {}) {
    this.unregister(name);
    const logFile = opts.logFile || this._logPath(name);
    try { fs.writeFileSync(logFile, '', 'utf-8'); } catch {}
    const entry = {
      proc,
      logFile,
      subs: new Set(),
      alive: true,
      hasStdin: !!(opts.hasStdin && proc && proc.stdin),
      group: opts.group || null,
    };
    this.procs.set(name, entry);

    const stampLines = createStamper();
    const onData = (data) => {
      const buf = Buffer.isBuffer(data) ? data : Buffer.from(data);
      const stamped = stampLines(buf.toString('utf-8'));
      if (stamped) {
        try { fs.appendFileSync(logFile, stamped); } catch {}
        for (const fn of entry.subs) { try { fn({ text: stamped }); } catch {} }
      }
    };

    if (proc.stdout) proc.stdout.on('data', onData);
    if (proc.stderr) proc.stderr.on('data', onData);
    proc.on('exit', (code) => {
      if (this.procs.get(name) === entry) {
        entry.alive = false;
        onData(Buffer.from(`\n--- Process exited (code ${code}) ---\n`));
        this._notifyList();
      }
    });
    this._notifyList();
  }

  unregister(name) {
    const e = this.procs.get(name);
    if (e) {
      if (e._watcher) { try { e._watcher.close(); } catch {} }
      if (e._interval) clearInterval(e._interval);
      this.procs.delete(name);
      this._notifyList();
    }
  }

  getLog(name, maxBytes) {
    const e = this.procs.get(name);
    if (!e || !e.logFile) return '';
    try {
      const stat = fs.statSync(e.logFile);
      if (stat.size === 0) return '';
      if (!maxBytes || stat.size <= maxBytes) {
        return fs.readFileSync(e.logFile, 'utf-8');
      }
      const fd = fs.openSync(e.logFile, 'r');
      const buf = Buffer.alloc(maxBytes);
      fs.readSync(fd, buf, 0, maxBytes, stat.size - maxBytes);
      fs.closeSync(fd);
      return buf.toString('utf-8');
    } catch { return ''; }
  }

  subscribe(name, fn) {
    const e = this.procs.get(name);
    if (!e) return () => {};
    e.subs.add(fn);
    return () => e.subs.delete(fn);
  }

  writeStdin(name, text) {
    const e = this.procs.get(name);
    if (!e) return false;
    if (typeof e.onStdin === 'function') {
      try { return !!e.onStdin(text); } catch { return false; }
    }
    if (!e.alive || !e.proc || !e.proc.stdin) return false;
    try { e.proc.stdin.write(text); return true; } catch { return false; }
  }

  // Register an "external" process whose output and stdin are bridged from
  // another process (e.g. an executor running inside start.js). The launcher
  // owns the log file and broadcasts appended text to subscribers; stdin is
  // routed back through the provided onStdin callback.
  registerExternal(name, opts = {}) {
    this.unregister(name);
    const logFile = opts.logFile || this._logPath(name);
    try { fs.writeFileSync(logFile, '', 'utf-8'); } catch {}
    const entry = {
      proc: null,
      logFile,
      subs: new Set(),
      alive: opts.alive !== false,
      hasStdin: !!opts.hasStdin,
      onStdin: opts.onStdin || null,
      pid: opts.pid || null,
      pids: new Set((Array.isArray(opts.pids) ? opts.pids : (opts.pid ? [opts.pid] : [])).filter(Boolean)),
      port: opts.port || null,
      ports: Array.isArray(opts.ports) ? opts.ports : (opts.port ? [opts.port] : []),
      group: opts.group || null,
      overwrite: false,
      collapsed: !!opts.collapsed,
      _stamper: createStamper(),
    };
    this.procs.set(name, entry);
    this._notifyList();
  }

  // Append text to an external entry's log file and broadcast to subscribers.
  appendExternal(name, text) {
    const e = this.procs.get(name);
    if (!e) return;
    const stamped = e._stamper ? e._stamper(text) : text;
    if (!stamped) return;
    try { fs.appendFileSync(e.logFile, stamped); } catch {}
    for (const fn of e.subs) { try { fn({ text: stamped }); } catch {} }
  }

  setAlive(name, alive) {
    const e = this.procs.get(name);
    if (e && e.alive !== alive) {
      e.alive = alive;
      this._notifyList();
    }
  }

  list() {
    return [...this.procs.entries()].map(([name, e]) => {
      let logBytes = 0;
      try { logBytes = fs.statSync(e.logFile).size; } catch {}
      return {
        name, alive: e.alive, hasStdin: e.hasStdin,
        logBytes, pid: e.pid || (e.proc ? e.proc.pid : null),
        pids: e.pids instanceof Set ? [...e.pids] : (e.pid ? [e.pid] : []),
        port: e.port || null,
        ports: Array.isArray(e.ports) ? e.ports : (e.port ? [e.port] : []),
        group: e.group,
        overwrite: !!e.overwrite,
        collapsed: !!e.collapsed,
      };
    });
  }

  registerFile(name, filePath, opts = {}) {
    this.unregister(name);
    const overwrite = !!opts.overwrite;
    const entry = {
      proc: null,
      logFile: filePath,
      subs: new Set(),
      alive: true,
      hasStdin: false,
      group: opts.group || null,
      overwrite: overwrite,
      collapsed: !!opts.collapsed,
      _watcher: null,
      _interval: null,
      _offset: 0,
      _lastBroadcast: null, // tracks last broadcasted content to avoid re-sending unchanged data
    };
    this.procs.set(name, entry);

    // Set initial offset to current file size
    try { entry._offset = fs.statSync(filePath).size; } catch {}

    const readNew = () => {
      try {
        const stat = fs.statSync(filePath);
        if (overwrite) {
          if (stat.size === 0 && entry._offset === 0) return;
          const content = fs.readFileSync(filePath, 'utf-8').trimEnd();
          entry._offset = stat.size;
          // Only broadcast when content actually changes — prevents stale log data
          // (e.g. previous "Build complete") from being re-sent to new subscribers.
          if (content === entry._lastBroadcast) return;
          entry._lastBroadcast = content;
          if (!content) {
            // File was cleared (resetStdoutFile called) — signal a genuine new build start.
            for (const fn of entry.subs) { try { fn({ reset: true, text: '' }); } catch {} }
            return;
          }
          for (const fn of entry.subs) { try { fn({ reset: true, text: content }); } catch {} }
          return;
        }
        if (stat.size < entry._offset) {
          // File truncated (new run) — notify reset
          entry._offset = 0;
          for (const fn of entry.subs) { try { fn({ reset: true, text: '' }); } catch {} }
        }
        if (stat.size > entry._offset) {
          const fd = fs.openSync(filePath, 'r');
          const buf = Buffer.alloc(stat.size - entry._offset);
          fs.readSync(fd, buf, 0, buf.length, entry._offset);
          fs.closeSync(fd);
          entry._offset = stat.size;
          const text = buf.toString('utf-8');
          for (const fn of entry.subs) { try { fn({ text }); } catch {} }
        }
      } catch {}
    };

    // Watch for changes
    try {
      if (!fs.existsSync(filePath)) fs.writeFileSync(filePath, '', 'utf-8');
      entry._watcher = fs.watch(filePath, { persistent: false }, readNew);
    } catch {}
    entry._interval = setInterval(readNew, 1000);

    this._notifyList();
  }

  onListChange(fn) { this._listSubs.add(fn); return () => this._listSubs.delete(fn); }
  _notifyList() { for (const fn of this._listSubs) { try { fn(); } catch {} } }
}

const registry = new ProcessRegistry();

// ─── Timestamped Logging ─────────────────────────────────────────────────────

function ts() {
  const d = new Date();
  const y = d.getFullYear();
  const mo = String(d.getMonth() + 1).padStart(2, '0');
  const da = String(d.getDate()).padStart(2, '0');
  const h = String(d.getHours()).padStart(2, '0');
  const mi = String(d.getMinutes()).padStart(2, '0');
  const s = String(d.getSeconds()).padStart(2, '0');
  return `[${y}/${mo}/${da} ${h}:${mi}:${s}]`;
}

// Prepend timestamp to each non-empty line in a chunk of text.
// Returns a stamper function with its own line-remainder buffer.
function createStamper() {
  let remainder = '';
  return function stampLines(text) {
    const input = remainder + text;
    const lines = input.split('\n');
    // Last element is either '' (text ended with \n) or an incomplete line
    remainder = lines.pop();
    let result = '';
    for (const line of lines) {
      if (line.length === 0) {
        result += '\n';
      } else {
        result += ts() + ' ' + line + '\n';
      }
    }
    return result;
  };
}

const _origLog = console.log;
const _origErr = console.error;
console.log = (...args) => _origLog(ts(), ...args);
console.error = (...args) => _origErr(ts(), ...args);

// ─── Process Management ──────────────────────────────────────────────────────

let buildProc = null;   // The persistent build.js process
let startProc = null;   // A running start.js
let buildRunning = false;
let startRunning = false;
let reloadPending = false;

// Write "0" to stdin file so the persistent build process idles
function writeBuildStdin(value) {
  fs.writeFileSync(STDIN_FILE, String(value), 'utf-8');
}

// Launch the persistent build.js --stdin/--stdout process. `stdinValue` is
// written *before* spawning, so build.js picks it up as its initial trigger
// (see its `initialContent` check) instead of waiting for a later file change.
function spawnPersistentBuild(stdinValue = '0') {
  if (buildProc) return;
  writeBuildStdin(stdinValue);
  buildProc = spawn(process.execPath, [path.join(SCRIPT_DIR, 'build.js'), '--stdin', STDIN_FILE, '--stdout', STDOUT_FILE, '--log', BUILD_LOG], {
    cwd: BASE_DIR,
    env: { ...process.env, KUWA_LAUNCHER: '1' },
    stdio: 'ignore',
    windowsHide: true,
  });
  // Show the mirrored output file in debug UI (overwrite mode for TUI)
  registry.registerFile('build (persistent)', STDOUT_FILE, { group: 'build', overwrite: true });
  // Show detailed build log in debug UI (append mode, collapsed by default)
  registry.registerFile('build.log', BUILD_LOG, { group: 'build', collapsed: true });
  buildProc.on('exit', () => { buildProc = null; });
}

// Kill the running persistent build.js child and spawn a brand-new one before
// triggering a build. The child only has the build.js/config.js/etc. code it
// loaded at spawn time in memory — just writing a new value to the stdin file
// makes the *existing* process re-run its already-loaded (possibly stale)
// code, so any source edits since the launcher started would never be picked
// up without this restart.
function restartPersistentBuild(triggerValue) {
  const respawn = () => spawnPersistentBuild(triggerValue);
  if (buildProc) {
    const old = buildProc;
    buildProc = null;
    old.once('exit', respawn);
    try { old.kill(); } catch { respawn(); }
  } else {
    respawn();
  }
}

function runStart() {
  if (startRunning) return false;
  startRunning = true;
  startProc = spawn(process.execPath, [path.join(SCRIPT_DIR, 'start.js')], {
    cwd: BASE_DIR,
    env: { ...process.env, KUWA_LAUNCHER: '1' },
    stdio: ['pipe', 'pipe', 'pipe', 'ipc'],
    windowsHide: true,
  });
  registry.register('start (services)', startProc, { hasStdin: true, logFile: START_LOG });
  // Forward timestamped output to launcher console
  registry.subscribe('start (services)', (ev) => {
    if (ev && ev.text) process.stdout.write(ev.text);
  });
  // Track which executor folders start.js has reported, so we can mark them
  // dead when start.js exits.
  const execFolders = new Set();
  const registerExecutor = (folder, details = {}) => {
    execFolders.add(folder);
    const existing = registry.procs.get('exec: ' + folder);
    if (existing && existing.proc === null) {
      existing.alive = details.alive !== false;
      if (!(existing.pids instanceof Set)) existing.pids = new Set(existing.pid ? [existing.pid] : []);
      for (const pid of details.pids || (details.pid ? [details.pid] : [])) {
        if (pid) existing.pids.add(pid);
      }
      existing.pid = existing.pid || details.pid || null;
      existing.ports = Array.from(new Set([...(existing.ports || []), ...(details.ports || []), ...(details.port ? [details.port] : [])]));
      existing.port = existing.port || details.port || existing.ports[0] || null;
      existing.hasStdin = true;
      registry._notifyList();
      return;
    }
    registry.registerExternal('exec: ' + folder, {
      logFile: path.join(EXEC_LOGS_DIR, folder.replace(/[^a-zA-Z0-9_.-]/g, '_') + '.log'),
      hasStdin: true,
      pid: details.pid || null,
      pids: Array.isArray(details.pids) ? details.pids : (details.pid ? [details.pid] : []),
      port: details.port || null,
      ports: Array.isArray(details.ports) ? details.ports : (details.port ? [details.port] : []),
      onStdin: (text) => {
        try { startProc.send({ type: 'exec-stdin', folder, text }); return true; }
        catch { return false; }
      },
    });
  };
  // Bridge per-executor messages from start.js into the registry as separate
  // tabs, each with its own log file and stdin routing.
  startProc.on('message', (msg) => {
    if (!msg || typeof msg !== 'object') return;
    if (msg.type === 'exec-register') {
      const folder = String(msg.folder || '').trim();
      if (!folder) return;
      registerExecutor(folder, msg);
    } else if (msg.type === 'exec-started') {
      const folder = String(msg.folder || '').trim();
      if (folder && msg.started && !isExecutorRegistered(folder)) {
        registerExecutor(folder, msg);
      } else if (folder && msg.started) {
        const entry = registry.procs.get('exec: ' + folder);
        if (entry && msg.pid) {
          if (!(entry.pids instanceof Set)) entry.pids = new Set(entry.pid ? [entry.pid] : []);
          entry.pids.add(msg.pid);
          entry.pid = msg.pid;
          entry.port = msg.port || entry.port;
          entry.ports = Array.from(new Set([...(entry.ports || []), ...(msg.ports || [])]));
          registry._notifyList();
        }
      }
    } else if (msg.type === 'exec-process') {
      const folder = String(msg.folder || '').trim();
      if (folder) {
        let entry = registry.procs.get('exec: ' + folder);
        if (!entry) {
          registerExecutor(folder, msg);
          entry = registry.procs.get('exec: ' + folder);
        }
        if (entry) {
          if (!(entry.pids instanceof Set)) entry.pids = new Set(entry.pid ? [entry.pid] : []);
          if (msg.pid) entry.pids.add(msg.pid);
          entry.pid = entry.pid || msg.pid || null;
          if (msg.port) {
            entry.port = entry.port || msg.port;
            entry.ports = Array.from(new Set([...(entry.ports || []), msg.port]));
          }
          entry.alive = true;
          registry._notifyList();
        }
      }
    } else if (msg.type === 'exec-process-exit') {
      const folder = String(msg.folder || '').trim();
      if (folder) {
        const entry = registry.procs.get('exec: ' + folder);
        if (!entry) return;
        if (entry.pids instanceof Set && msg.pid) entry.pids.delete(msg.pid);
        if (Array.isArray(entry.ports) && msg.port) entry.ports = entry.ports.filter((port) => port !== msg.port);
        const nextPid = entry.pids instanceof Set ? [...entry.pids][0] : null;
        entry.pid = nextPid || null;
        entry.port = entry.ports?.[0] || null;
        entry.alive = !!nextPid;
        registry._notifyList();
      }
    } else if (msg.type === 'exec-log') {
      const folder = String(msg.folder || '').trim();
      if (folder && msg.text) {
        if (!registry.procs.has('exec: ' + folder)) registerExecutor(folder, msg);
        registry.appendExternal('exec: ' + folder, msg.text);
      }
    } else if (msg.type === 'exec-exit') {
      const folder = String(msg.folder || '').trim();
      if (folder) {
        const entry = registry.procs.get('exec: ' + folder);
        if (entry) {
          entry.alive = false;
          entry.pid = null;
          entry.pids = new Set();
          entry.port = null;
          entry.ports = [];
          registry._notifyList();
        }
      }
    } else if (msg.type === 'lifecycle') {
      if (msg.action === 'stop') {
        console.log('Stop requested by services. Shutting down launcher...');
        shutdown();
        process.exit(0);
      } else if (msg.action === 'reload') {
        console.log('Reload requested by services. Restarting...');
        reloadServices();
      }
    }
  });
  startProc.on('exit', () => {
    for (const folder of execFolders) registry.setAlive('exec: ' + folder, false);
    startRunning = false;
    startProc = null;
    if (reloadPending) {
      reloadPending = false;
      runStart();
    }
  });
  return true;
}

function runStop() {
  // Kill the start.js child directly first, before stop.js runs,
  // so it cannot re-spawn anything while we're shutting down.
  if (startProc) {
    try { startProc.kill(); } catch {}
    startProc = null;
  }
  startRunning = false;
  const env = { ...process.env, KUWA_EXCLUDE_PIDS: String(process.pid) };
  try {
    execFileSync(process.execPath, [path.join(SCRIPT_DIR, 'stop.js')], { cwd: BASE_DIR, env, stdio: 'ignore', timeout: 30000 });
  } catch {}
}

function reloadServices() {
  if (!startProc || reloadPending) return false;
  reloadPending = true;
  try {
    startProc.send({ type: 'reload' });
    return true;
  } catch {
    reloadPending = false;
    return false;
  }
}

// ─── Log Tailing via SSE ─────────────────────────────────────────────────────

function streamLog(filePath, res) {
  res.writeHead(200, {
    'Content-Type': 'text/event-stream',
    'Cache-Control': 'no-cache',
    'Connection': 'keep-alive',
    'X-Accel-Buffering': 'no',
  });

  let offset = 0;
  let fileSize = 0;

  // Send initial content
  try {
    const stat = fs.statSync(filePath);
    fileSize = stat.size;
    if (fileSize > 0) {
      // Send last 64KB max on connect
      const start = Math.max(0, fileSize - 65536);
      const fd = fs.openSync(filePath, 'r');
      const buf = Buffer.alloc(fileSize - start);
      fs.readSync(fd, buf, 0, buf.length, start);
      fs.closeSync(fd);
      const text = buf.toString('utf-8');
      res.write(`data: ${JSON.stringify({ reset: start === 0, text })}\n\n`);
      offset = fileSize;
    }
  } catch {}

  // Watch for changes
  let watcher;
  const sendNew = () => {
    try {
      const stat = fs.statSync(filePath);
      if (stat.size < offset) {
        // File was truncated (new run) — reset
        offset = 0;
        res.write(`data: ${JSON.stringify({ reset: true, text: '' })}\n\n`);
      }
      if (stat.size > offset) {
        const fd = fs.openSync(filePath, 'r');
        const buf = Buffer.alloc(stat.size - offset);
        fs.readSync(fd, buf, 0, buf.length, offset);
        fs.closeSync(fd);
        offset = stat.size;
        res.write(`data: ${JSON.stringify({ text: buf.toString('utf-8') })}\n\n`);
      }
    } catch {}
  };

  try {
    // Ensure file exists for watcher
    if (!fs.existsSync(filePath)) {
      fs.writeFileSync(filePath, '', 'utf-8');
    }
    watcher = fs.watch(filePath, { persistent: false }, sendNew);
  } catch {}

  // Also poll every 2s as a fallback (fs.watch can miss events)
  const interval = setInterval(sendNew, 2000);

  res.on('close', () => {
    if (watcher) watcher.close();
    clearInterval(interval);
  });
}

// ─── HTTP Server ─────────────────────────────────────────────────────────────

const server = http.createServer((req, res) => {
  const url = new URL(req.url, `http://${req.headers.host}`);

  // CORS: allow browser requests from the Laravel app
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');

  if (req.method === 'OPTIONS') {
    res.writeHead(204);
    res.end();
    return;
  }

  if (req.method === 'GET' && url.pathname === '/api/logs/build') {
    // Use registry subscription so overwrite-mode (TGI) works correctly
    const name = buildProc ? 'build (persistent)' : 'build';
    const entry = registry.procs.get(name);
    if (!entry) {
      res.writeHead(200, {
        'Content-Type': 'text/event-stream',
        'Cache-Control': 'no-cache',
        'Connection': 'keep-alive',
        'X-Accel-Buffering': 'no',
      });
      res.write(`data: ${JSON.stringify({ text: 'No build process running.\n' })}\n\n`);
      res.end();
      return;
    }
    res.writeHead(200, {
      'Content-Type': 'text/event-stream',
      'Cache-Control': 'no-cache',
      'Connection': 'keep-alive',
      'X-Accel-Buffering': 'no',
    });

    // When ?waitReset=1, suppress stale build output until the next reset event
    // (i.e. build.js detects the stdin trigger and truncates its log). This avoids
    // showing the previous build's content to the UI while waiting for the new run.
    const waitReset = url.searchParams.get('waitReset') === '1';
    let resetSeen = !waitReset;

    if (!waitReset) {
      const existing = registry.getLog(name, 262144);
      if (existing) {
        res.write(`data: ${JSON.stringify({ reset: true, text: existing })}\n\n`);
      }
    } else {
      // Keep the connection visibly alive while waiting for the new build to start
      res.write(`data: ${JSON.stringify({ text: 'Waiting for build to start...\n' })}\n\n`);
    }

    const unsub = registry.subscribe(name, (ev) => {
      try {
        if (!resetSeen) {
          if (!ev || !ev.reset) return; // drop stale lines from previous run
          resetSeen = true;
        }
        res.write(`data: ${JSON.stringify(ev)}\n\n`);
      } catch {}
    });

    // The update request triggers the build before the browser can open this
    // stream, so a fast build may emit its reset event before we subscribe.
    // Fall back quickly in that race instead of making the UI appear frozen.
    let fallbackTimer = null;
    if (waitReset) {
      fallbackTimer = setTimeout(() => {
        if (resetSeen) return;
        resetSeen = true;
        const existing = registry.getLog(name, 262144);
        if (existing) {
          try { res.write(`data: ${JSON.stringify({ reset: true, text: existing })}\n\n`); } catch {}
        }
      }, 3000);
    }

    res.on('close', () => {
      if (fallbackTimer) clearTimeout(fallbackTimer);
      unsub();
    });
    return;
  }

  if (req.method === 'GET' && url.pathname.startsWith('/api/logs/executor/')) {
    const folder = decodeURIComponent(url.pathname.slice('/api/logs/executor/'.length));
    const name = `exec: ${folder}`;
    res.writeHead(200, {
      'Content-Type': 'text/event-stream',
      'Cache-Control': 'no-cache',
      'Connection': 'keep-alive',
      'X-Accel-Buffering': 'no',
    });
    const existing = url.searchParams.get('tail') === '0' ? '' : registry.getLog(name, 262144);
    if (existing) res.write(`data: ${JSON.stringify({ reset: true, text: existing })}\n\n`);
    const unsub = registry.subscribe(name, (event) => {
      try { res.write(`data: ${JSON.stringify(event)}\n\n`); } catch {}
    });
    res.on('close', unsub);
    return;
  }

  if (req.method === 'GET' && url.pathname === '/api/logs/start') {
    streamLog(START_LOG, res);
    return;
  }

  res.writeHead(404, { 'Content-Type': 'text/plain' });
  res.end('Not Found');
});

// ─── Debug Web UI Server ─────────────────────────────────────────────────────

async function handleDebugRequest(req, res) {
  const url = new URL(req.url, `http://${req.headers.host}`);

  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');

  if (req.method === 'OPTIONS') {
    res.writeHead(204);
    res.end();
    return;
  }

  // List all tracked processes
  if (req.method === 'GET' && url.pathname === '/debug/api/processes') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify(registry.list()));
    return;
  }

  // SSE stream for a process's logs
  if (req.method === 'GET' && url.pathname.startsWith('/debug/api/logs/')) {
    const name = decodeURIComponent(url.pathname.slice('/debug/api/logs/'.length));
    res.writeHead(200, {
      'Content-Type': 'text/event-stream',
      'Cache-Control': 'no-cache',
      'Connection': 'keep-alive',
      'X-Accel-Buffering': 'no',
    });
    // Send existing log (last 256KB max to avoid browser overload)
    const existing = registry.getLog(name, 262144);
    if (existing) {
      res.write(`data: ${JSON.stringify({ reset: true, text: existing })}\n\n`);
    }
    // Subscribe to new data
    const unsub = registry.subscribe(name, (ev) => {
      try { res.write(`data: ${JSON.stringify(ev)}\n\n`); } catch {}
    });
    res.on('close', unsub);
    return;
  }

  // SSE stream for process list changes
  if (req.method === 'GET' && url.pathname === '/debug/api/process-list') {
    res.writeHead(200, {
      'Content-Type': 'text/event-stream',
      'Cache-Control': 'no-cache',
      'Connection': 'keep-alive',
      'X-Accel-Buffering': 'no',
    });
    res.write(`data: update\n\n`);
    const unsub = registry.onListChange(() => {
      try { res.write(`data: update\n\n`); } catch {}
    });
    res.on('close', unsub);
    return;
  }

  // Send stdin to a process
  if (req.method === 'POST' && url.pathname.startsWith('/debug/api/stdin/')) {
    const name = decodeURIComponent(url.pathname.slice('/debug/api/stdin/'.length));
    let body = '';
    req.on('data', (chunk) => { body += chunk; });
    req.on('end', () => {
      const ok = registry.writeStdin(name, body);
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ ok }));
    });
    return;
  }

  // System info endpoint
  if (req.method === 'GET' && url.pathname === '/debug/api/sysinfo') {
    const cpus = os.cpus();
    const cpuModel = cpus.length ? cpus[0].model.trim() : 'Unknown';
    const totalMem = os.totalmem();
    const freeMem = os.freemem();
    const usedMem = totalMem - freeMem;
    const repoProcs = getRepoProcesses();
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({
      hostname: os.hostname(),
      platform: os.platform(),
      arch: os.arch(),
      cpuModel,
      cpuCores: cpus.length,
      totalMem,
      usedMem,
      freeMem,
      uptime: os.uptime(),
      nodeVersion: process.version,
      repoProcessCount: repoProcs.length,
    }));
    return;
  }

  // Get executor list with enabled status
  if (req.method === 'GET' && url.pathname === '/debug/api/config/executors') {
    const allExecutors = listAllExecutors();
    const enabledExecutors = loadEnabledExecutors();

    const executors = allExecutors.map(name => {
      const config = readRunConfig(path.join(EXECUTORS_DIR, name));
      return {
        name,
        accessCode: config.accessCode || null,
        enabled: enabledExecutors.includes(name),
        running: isExecutorRunning(name),
      };
    });

    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ executors }));
    return;
  }

  // Enable an executor
  if (req.method === 'POST' && url.pathname.startsWith('/debug/api/config/executors/') && url.pathname.endsWith('/enable')) {
    const name = decodeURIComponent(url.pathname.slice('/debug/api/config/executors/'.length, -'/enable'.length));
    const enabled = loadEnabledExecutors();
    if (!enabled.includes(name)) {
      enabled.push(name);
      const success = saveEnabledExecutors(enabled);
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ success, message: success ? 'Executor enabled' : 'Failed to save config' }));
    } else {
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ success: true, message: 'Executor already enabled' }));
    }
    return;
  }

  // Disable an executor
  if (req.method === 'POST' && url.pathname.startsWith('/debug/api/config/executors/') && url.pathname.endsWith('/disable')) {
    const name = decodeURIComponent(url.pathname.slice('/debug/api/config/executors/'.length, -'/disable'.length));
    let enabled = loadEnabledExecutors();
    enabled = enabled.filter(e => e !== name);
    const success = saveEnabledExecutors(enabled);

    // Gracefully stop the executor (if running) without restarting start.js.
    if (startProc) {
      try { startProc.send({ type: 'exec-stop', folder: name }); } catch {}
    }

    const stopped = await waitForExecutorState(
      () => isExecutorStopped(name),
      true,
    );

    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ success: success && stopped, message: success && stopped ? 'Executor disabled and stopped' : success ? 'Executor disabled, but it is still stopping' : 'Failed to save config' }));
    return;
  }

  // Start an executor — enable in config.yaml and launch its worker in the
  // already-running start.js (no full restart).
  if (req.method === 'POST' && url.pathname.startsWith('/debug/api/config/executors/') && url.pathname.endsWith('/start')) {
    const name = decodeURIComponent(url.pathname.slice('/debug/api/config/executors/'.length, -'/start'.length));
    const enabled = loadEnabledExecutors();
    if (!enabled.includes(name)) {
      enabled.push(name);
      saveEnabledExecutors(enabled);
    }

    let requested = false;
    if (startProc) {
      try { startProc.send({ type: 'exec-start', folder: name }); requested = true; } catch {}
    }

    const started = requested && await waitForExecutorState(
      () => isExecutorRunning(name),
      true,
      { timeoutMs: 60000, intervalMs: 250 },
    );

    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ success: started, message: started ? 'Executor enabled and running' : requested ? 'Executor did not spawn a verifiable process within the timeout' : 'Services not running yet' }));
    return;
  }

  // Stop an executor — gracefully terminate its worker (Ctrl+C, force-kill
  // after 10s) without changing config or restarting start.js.
  if (req.method === 'POST' && url.pathname.startsWith('/debug/api/config/executors/') && url.pathname.endsWith('/stop')) {
    const name = decodeURIComponent(url.pathname.slice('/debug/api/config/executors/'.length, -'/stop'.length));

    let requested = false;
    if (startProc) {
      try { startProc.send({ type: 'exec-stop', folder: name }); requested = true; } catch {}
    }

    const stopped = !requested || await waitForExecutorState(
      () => isExecutorStopped(name),
      true,
    );

    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ success: requested && stopped, message: requested && stopped ? 'Executor stopped' : requested ? 'Executor did not stop within the timeout' : 'Services not running' }));
    return;
  }

  // The React Console is now the only user-facing launcher UI.
  if (url.pathname === '/') {
    res.writeHead(404, { 'Content-Type': 'text/plain' });
    res.end('Launcher Console is available from the Kuwa app.');
    return;
  }

  res.writeHead(404, { 'Content-Type': 'text/plain' });
  res.end('Not Found');
}

const debugServer = http.createServer((req, res) => {
  Promise.resolve(handleDebugRequest(req, res)).catch((err) => {
    try { console.error('Debug API request error:', err && err.stack ? err.stack : err); } catch {}
    if (res.headersSent) {
      try { res.destroy(); } catch {}
      return;
    }
    res.writeHead(500, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ success: false, message: 'Internal launcher error' }));
  });
});

// ─── Startup Sequence ─────────────────────────────────────────────────────────

function needsBuild() {
  // Check if critical packages are present
  const packagesDir = path.join(BASE_DIR, 'packages');
  const checks = [
    'php.exe',    // PHP
    'redis-server.exe', // Redis
  ];
  // Use variables.bat-derived folder names via the known package folder names
  const phpFolder = fs.readdirSync(packagesDir).find(d => d.startsWith('php-') && fs.statSync(path.join(packagesDir, d)).isDirectory());
  const redisFolder = fs.readdirSync(packagesDir).find(d => d.startsWith('Redis-') && fs.statSync(path.join(packagesDir, d)).isDirectory());
  const nginxFolder = fs.readdirSync(packagesDir).find(d => d.startsWith('nginx-') && fs.statSync(path.join(packagesDir, d)).isDirectory());
  const pythonFolder = fs.readdirSync(packagesDir).find(d => d.startsWith('python-') && fs.statSync(path.join(packagesDir, d)).isDirectory());

  if (!phpFolder || !redisFolder || !nginxFolder || !pythonFolder) return true;
  if (!fs.existsSync(path.join(packagesDir, phpFolder, 'php.exe'))) return true;
  if (!fs.existsSync(path.join(packagesDir, redisFolder, 'redis-server.exe'))) return true;
  if (!fs.existsSync(path.join(packagesDir, nginxFolder, 'nginx.exe'))) return true;
  if (!fs.existsSync(path.join(packagesDir, pythonFolder, 'python.exe'))) return true;
  return false;
}

function startup() {
  // 1. Stop all existing processes (clean slate)
  runStop();

  // 2. Start API server + Debug UI early so they're available during build
  server.listen(PORT, '127.0.0.1', () => {
    console.log(`Launcher API server running at http://127.0.0.1:${PORT}`);
  });
  if (DEBUG_API_ENABLED) {
    debugServer.listen(DEBUG_PORT, '127.0.0.1', () => {
      console.log(`Debug UI available at http://127.0.0.1:${DEBUG_PORT}/debug`);
    });
  } else {
    console.log('Launcher debug API disabled (set KUWA_LAUNCHER_DEBUG_API=1 or remove the opt-out to enable).');
  }

  // 3. Check if packages are missing — if so, run a one-shot build first
  const buildNeeded = needsBuild();

  if (buildNeeded) {
    console.log('Required packages missing. Running initial build...');
    buildRunning = true;
    const proc = spawn(process.execPath, [path.join(SCRIPT_DIR, 'build.js')], {
      cwd: BASE_DIR,
      env: { ...process.env, KUWA_LAUNCHER: '1' },
      stdio: ['ignore', 'pipe', 'pipe'],
      windowsHide: true,
    });
    registry.register('build (initial)', proc);
    // Forward output to launcher console
    registry.subscribe('build (initial)', (ev) => {
      if (ev && ev.text) process.stdout.write(ev.text);
    });
    proc.on('exit', (code) => {
      buildRunning = false;
      console.log(`Initial build finished (code ${code}). Starting monitor mode...`);
      // Now spawn persistent build in monitor mode and start services
      spawnPersistentBuild();
      setupBuildCompleteSubscription();
      console.log('Starting services via start.js...');
      runStart();
    });
  } else {
    // 4. All packages present — go straight to monitor mode + start services
    spawnPersistentBuild();
    setupBuildCompleteSubscription();
    console.log('Starting services via start.js...');
    runStart();
  }
  console.log('Press Ctrl+C to exit. Type "help" for available commands.\n');
}

function setupBuildCompleteSubscription() {
  // Auto-start services when persistent build finishes
  registry.subscribe('build (persistent)', (ev) => {
    const text = typeof ev === 'string' ? ev : (ev && ev.text) || '';
    if (text.includes('Build complete!') && !startRunning && !startProc) {
      console.log('Build complete detected. Starting services via start.js...');
      runStart();
    }
  });
}

// ─── Graceful Shutdown ───────────────────────────────────────────────────────

let shuttingDown = false;
function shutdown() {
  if (shuttingDown) return;
  shuttingDown = true;
  console.log('\nShutting down launcher...');
  if (buildProc) { try { buildProc.kill(); } catch {} }
  server.close();
  if (DEBUG_API_ENABLED) debugServer.close();
  // Run stop.js synchronously so all processes are killed before we exit.
  // Use 'inherit' stdio so it shares this console instead of opening new ones.
  try {
    const env = { ...process.env, KUWA_EXCLUDE_PIDS: String(process.pid) };
    execFileSync(process.execPath, [path.join(SCRIPT_DIR, 'stop.js')], {
      cwd: BASE_DIR,
      env,
      stdio: 'inherit',
      timeout: 30000,
      windowsHide: true,
    });
  } catch {}
  console.log('Shutdown complete.');
}

// All signals just trigger the single shutdown, then exit
process.on('SIGINT', () => { shutdown(); process.exit(0); });
process.on('SIGTERM', () => { shutdown(); process.exit(0); });
process.on('SIGHUP', () => { shutdown(); process.exit(0); });
// 'exit' is the last-resort for window X button — shutdown guard prevents double spawn
process.on('exit', () => {
  if (!handingOffToElevatedLauncher) shutdown();
});

// On a crash, still force-kill every spawned process before dying so nothing is
// left orphaned (stop.js kills all processes under the repo directory).
process.on('uncaughtException', (err) => {
  try { console.error('Uncaught exception:', err && err.stack ? err.stack : err); } catch {}
  shutdown();
  process.exit(1);
});
process.on('unhandledRejection', (reason) => {
  try { console.error('Unhandled rejection:', reason); } catch {}
  shutdown();
  process.exit(1);
});

// ─── Interactive CLI ──────────────────────────────────────────────────────────

function startCLI() {
  if (!process.stdin.isTTY) return; // skip when piped/non-interactive

  const rl = readline.createInterface({
    input: process.stdin,
    output: process.stdout,
    prompt: 'launcher> ',
  });

  // Redraw the prompt cleanly after async log output disrupts the line.
  // Guard against re-entrancy: readline.clearLine/cursorTo internally call
  // process.stdout.write, which would otherwise recurse indefinitely.
  const _origWrite = process.stdout.write.bind(process.stdout);
  let _inPromptRedraw = false;
  const promptRedrawWrite = function (chunk, encoding, callback) {
    if (_inPromptRedraw) return _origWrite(chunk, encoding, callback);
    _inPromptRedraw = true;
    try {
      readline.clearLine(process.stdout, 0);
      readline.cursorTo(process.stdout, 0);
      const result = _origWrite(chunk, encoding, callback);
      rl.prompt(true);
      return result;
    } finally {
      _inPromptRedraw = false;
    }
  };
  process.stdout.write = promptRedrawWrite;

  // Run an interactive command that needs full control of the terminal (e.g.
  // the admin-account seeder or `hf login`). The CLI readline and the
  // prompt-redraw wrapper are suspended so the child's stdin/stdout behave
  // normally; the CLI is restored once the child exits.
  let _interactiveRunning = false;
  function runInteractive(displayName, file, args, cwd) {
    if (_interactiveRunning) {
      console.log('Another interactive command is already running.');
      return;
    }
    _interactiveRunning = true;
    process.stdout.write = _origWrite;
    rl.pause();
    const restore = (msg) => {
      if (msg) _origWrite(msg + '\n');
      
      // Forcefully re-assert the title. Interactive shell commands (like cmd.exe
      // or pwsh.exe) often hijack the console title. We restore it here so the
      // prompt remains correct.
      process.title = CONSOLE_TITLE;
      try { execFileSync('cmd.exe', ['/c', `title ${CONSOLE_TITLE}`], { windowsHide: true, stdio: 'ignore' }); } catch {}

      process.stdout.write = promptRedrawWrite;
      _interactiveRunning = false;
      rl.resume();
      rl.prompt();
    };
    let child;
    try {
      child = spawn(file, args, { cwd, stdio: 'inherit', windowsHide: false, env: process.env });
    } catch (e) {
      restore(`Failed to start ${displayName}: ${e.message}`);
      return;
    }
    child.on('exit', (code) => restore(`\n${displayName} finished${code ? ` (exit code ${code})` : ''}.`));
    child.on('error', (err) => restore(`\n${displayName} error: ${err.message}`));
  }

  rl.prompt();

  rl.on('line', (line) => {
    const cmd = line.trim().toLowerCase();
    switch (cmd) {
      case 'start':
        if (startRunning) {
          console.log('Services already running.');
        } else {
          console.log('Starting services...');
          runStart();
        }
        break;
      case 'stop':
        console.log('Shutting down launcher...');
        shutdown();
        process.exit(0);
        break;
      case 'restart':
        console.log('Restarting services...');
        reloadServices();
        break;
      case 'build':
        if (!buildProc) {
          console.log('No persistent build process running.');
        } else {
          console.log('Restarting build process and triggering a rebuild...');
          restartPersistentBuild('1');
        }
        break;
      case 'status': {
        const procs = getRepoProcesses();
        if (procs.length === 0) {
          console.log('No repo processes running.');
        } else {
          console.log(`Running processes (${procs.length}):`);
          for (const p of procs) {
            console.log(`  [${p.pid}] ${p.name}`);
          }
        }
        break;
      }
      case 'seed':
        runInteractive(
          'admin account setup',
          process.env.ComSpec || 'cmd.exe',
          ['/c', 'AdminSeeder.bat'],
          path.join(ROOT_DIR, 'src', 'multi-chat', 'executables', 'bat')
        );
        break;
      case 'hf login':
        runInteractive('Hugging Face login', 'hf.exe', ['login'], BASE_DIR);
        break;
      case 'reload':
        console.log('Reloading services...');
        reloadServices();
        break;
      case 'help':
      case '?':
        console.log('Commands:');
        console.log('  start    — start services (start.js)');
        console.log('  stop     — stop all services and exit the launcher');
        console.log('  restart  — stop then restart services (launcher stays running)');
        console.log('  reload   — restart services (alias of restart)');
        console.log('  seed     — create/seed the admin account (interactive)');
        console.log('  hf login — log in to Hugging Face (interactive)');
        console.log('  build    — restart the build process (reloads its code) and rebuild');
        console.log('  status   — list running repo processes');
        console.log('  help     — show this help');
        console.log('  Ctrl+C   — shutdown and exit');
        break;
      case '':
        break;
      default:
        console.log(`Unknown command: "${cmd}". Type "help" for available commands.`);
    }
    rl.prompt();
  });

  rl.on('close', () => {
    // stdin closed (e.g. EOF) — restore stdout and exit gracefully
    process.stdout.write = _origWrite;
  });
}

startup();
startCLI();
