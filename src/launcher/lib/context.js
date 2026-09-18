// Shared mutable state: paths, logging, child process tracking, cleanup.
// This module is loaded once at startup and its side effects (stdout/stderr
// interception, process-exit handlers) run immediately on require().

const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

// ─── Paths ───────────────────────────────────────────────────────────────────

// This file lives at <repo>/src/launcher/lib. The Windows script root
// (the runtime root holding packages/, root/, logs/, executors/) is the
// sibling "windows" folder under the repo root.
const SCRIPT_DIR = path.resolve(__dirname, '..', '..', '..', 'windows');
const ROOT_DIR = path.resolve(SCRIPT_DIR, '..');

// ─── Environment Setup ───────────────────────────────────────────────────────

process.chdir(SCRIPT_DIR);

// Force UTF-8 codepage for all child processes to avoid garbled CJK output
// Use a fixed PATH so a malicious binary cannot shadow chcp.com via a writable PATH entry.
const WIN_ROOT = process.env.SystemRoot || 'C:\\Windows';
const SYSTEM32 = path.join(WIN_ROOT, 'System32');
const SAFE_PATH = [
  SYSTEM32,
  path.join(SYSTEM32, 'WindowsPowerShell', 'v1.0'),
].join(path.delimiter);
const SAFE_ENV = { ...process.env, PATH: SAFE_PATH };
const CHCP_EXE = path.join(SYSTEM32, 'chcp.com');
const TASKKILL_EXE = path.join(SYSTEM32, 'taskkill.exe');
try { execFileSync(CHCP_EXE, ['65001'], { stdio: 'ignore', env: SAFE_ENV, windowsHide: true }); } catch {}

// ─── Logging ─────────────────────────────────────────────────────────────────

const LOG_DIR = path.join(SCRIPT_DIR, 'logs');
fs.mkdirSync(LOG_DIR, { recursive: true });
const entryScript = path.basename(process.argv[1] || 'build', '.js');
let _logFd = (process.env.KUWA_LAUNCHER || entryScript === 'download-7z' || entryScript === 'tool' || entryScript === 'launcher') ? null : fs.openSync(path.join(LOG_DIR, `${entryScript}.log`), 'w');

const origStdoutWrite = process.stdout.write.bind(process.stdout);
const origStderrWrite = process.stderr.write.bind(process.stderr);

// Log writes are queued and flushed with async fs.write. Concurrent child
// processes (composer install, pip/uv sync, pnpm, etc.) can emit output
// fast enough that per-chunk fs.writeSync stalls the single-threaded event
// loop for seconds under disk/AV contention, which froze the TUI spinner.
let _writeQueue = [];
let _writing = false;
function _drainLogQueue() {
  if (_writing || _writeQueue.length === 0) return;
  const buf = _writeQueue.shift();
  _writing = true;
  fs.write(_logFd, buf, () => {
    _writing = false;
    _drainLogQueue();
  });
}
function queueLogWrite(buf) {
  if (_logFd === null) return;
  _writeQueue.push(buf);
  _drainLogQueue();
}
function flushLogQueueSync() {
  if (_logFd === null) return;
  for (const buf of _writeQueue) {
    try { fs.writeSync(_logFd, buf); } catch {}
  }
  _writeQueue = [];
}

process.stdout.write = function (chunk, encoding, callback) {
  const buf = Buffer.isBuffer(chunk) ? chunk : Buffer.from(String(chunk), typeof encoding === 'string' ? encoding : 'utf8');
  queueLogWrite(buf);
  return origStdoutWrite(chunk, encoding, callback);
};

process.stderr.write = function (chunk, encoding, callback) {
  const buf = Buffer.isBuffer(chunk) ? chunk : Buffer.from(String(chunk), typeof encoding === 'string' ? encoding : 'utf8');
  queueLogWrite(buf);
  return origStderrWrite(chunk, encoding, callback);
};

// ─── Child Process Tracking ──────────────────────────────────────────────────

const activeChildren = new Set();

function killAllChildren() {
  for (const child of activeChildren) {
    try { child.kill('SIGTERM'); } catch {}
    try { process.kill(child.pid, 'SIGTERM'); } catch {}
    try { execFileSync(TASKKILL_EXE, ['/pid', String(child.pid), '/T', '/F'], { stdio: 'ignore', env: SAFE_ENV, windowsHide: true }); } catch {}
  }
  activeChildren.clear();
}

function cleanup() {
  killAllChildren();
  flushLogQueueSync();
  try { if (_logFd !== null) fs.closeSync(_logFd); } catch {}
  origStdoutWrite('\x1b[?25h'); // ensure cursor visible
}

process.on('exit', cleanup);
process.on('SIGINT', () => { cleanup(); process.exit(1); });
process.on('SIGTERM', () => { cleanup(); process.exit(1); });

// ─── Shared Context ─────────────────────────────────────────────────────────

const ctx = {
  SCRIPT_DIR,
  ROOT_DIR,
  LOG_DIR,
  get logFd() { return _logFd; },
  set logFd(fd) { _logFd = fd; },
  origStdoutWrite,
  origStderrWrite,
  activeChildren,
  tuiActive: false,
  queueLogWrite,
};

module.exports = ctx;
