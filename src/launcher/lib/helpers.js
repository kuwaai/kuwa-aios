// Utility functions: filesystem, URL parsing, process runners, 7zip.

const { spawn, execFileSync, spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const readline = require('readline');
const ctx = require('./context');
const http = require('http');

// Shared buffer for synchronous sleep via Atomics.wait
const _sleepBuf = new Int32Array(new SharedArrayBuffer(4));

// PATH restricted to fixed, read-only Windows system directories so that
// shadow-binary attacks via user-writable PATH entries are not possible.
const WIN_ROOT = process.env.SystemRoot || 'C:\\Windows';
const SYSTEM32 = path.join(WIN_ROOT, 'System32');
const SAFE_PATH = [
  SYSTEM32,
  path.join(SYSTEM32, 'WindowsPowerShell', 'v1.0'),
].join(path.delimiter);
const SAFE_ENV = { ...process.env, PATH: SAFE_PATH };
const WHERE_EXE     = path.join(SYSTEM32, 'where.exe');
const TASKKILL_EXE  = path.join(SYSTEM32, 'taskkill.exe');
const CMD_EXE       = path.join(SYSTEM32, 'cmd.exe');
const PS_EXE        = path.join(SYSTEM32, 'WindowsPowerShell', 'v1.0', 'powershell.exe');

// ─── Logging Helpers ─────────────────────────────────────────────────────────

function logToFile(msg) {
  const str = typeof msg === 'string' ? msg : String(msg);
  ctx.queueLogWrite(Buffer.from(str.endsWith('\n') ? str : str + '\n', 'utf8'));
}

function log(msg) {
  if (ctx.tuiActive) {
    logToFile(msg);
  } else {
    console.log(msg);
  }
}

// ─── Process Runners ─────────────────────────────────────────────────────────

function run(cmd, options = {}) {
  const result = spawnSync(`chcp 65001 >nul & ${cmd}`, {
    stdio: ['inherit', 'pipe', 'pipe'],
    shell: true,
    cwd: options.cwd || process.cwd(),
    env: { ...process.env, ...options.env },
    maxBuffer: 100 * 1024 * 1024,
    windowsHide: true,
    encoding: 'utf-8',
  });
  if (result.stdout && result.stdout.length > 0) {
    process.stdout.write(result.stdout);
  }
  if (result.stderr && result.stderr.length > 0) {
    process.stderr.write(result.stderr);
  }
  return result.status || 0;
}

function runAsync(cmd, options = {}) {
  return new Promise((resolve, reject) => {
    const child = spawn(`chcp 65001 >nul & ${cmd}`, {
      cwd: options.cwd || process.cwd(),
      env: { ...process.env, ...options.env },
      stdio: ['ignore', 'pipe', 'pipe'],
      shell: true,
      windowsHide: true,
    });
    ctx.activeChildren.add(child);
    let settled = false;
    const settle = (code) => {
      if (settled) return;
      settled = true;
      ctx.activeChildren.delete(child);
      resolve(code || 0);
    };
    child.stdout.on('data', (data) => {
      ctx.queueLogWrite(Buffer.isBuffer(data) ? data : Buffer.from(data));
    });
    child.stderr.on('data', (data) => {
      ctx.queueLogWrite(Buffer.isBuffer(data) ? data : Buffer.from(data));
    });
    // On Windows, package managers can leave descendant stdio handles open
    // after the shell has exited. Waiting only for `close` can hang the build
    // forever, so process completion is driven by `exit` instead.
    child.on('exit', settle);
    child.on('close', settle);
    child.on('error', (err) => {
      if (settled) return;
      settled = true;
      ctx.activeChildren.delete(child);
      reject(err);
    });
  });
}

function runCapture(cmd, options = {}) {
  try {
    const result = spawnSync(cmd, {
      shell: true,
      cwd: options.cwd || process.cwd(),
      env: { ...process.env, ...options.env },
      stdio: ['pipe', 'pipe', 'pipe'],
      windowsHide: true,
    });
    if (result.error || result.status !== 0 || !result.stdout) return null;
    return result.stdout.toString().trim();
  } catch {
    return null;
  }
}

function commandExists(cmd, trustedDirs = []) {
  if (process.platform !== 'win32') {
    const searchPath = [
      ...trustedDirs.filter(Boolean),
      ...(process.env.PATH || '').split(path.delimiter),
    ];
    return searchPath.some((dir) => fs.existsSync(path.join(dir, cmd)));
  }

  const trustedPath = trustedDirs.filter(Boolean).join(path.delimiter);
  const env = trustedPath
    ? { ...SAFE_ENV, PATH: `${trustedPath}${path.delimiter}${SAFE_PATH}` }
    : SAFE_ENV;
  const result = spawnSync(WHERE_EXE, [cmd], { shell: false, stdio: 'pipe', env });
  return result.status === 0;
}

// ─── Filesystem Helpers ──────────────────────────────────────────────────────

function ensureDir(dirPath) {
  fs.mkdirSync(dirPath, { recursive: true });
}

function fileExists(filePath) {
  return fs.existsSync(filePath);
}

function copyFileIfNotExists(src, dest, label) {
  if (!fileExists(dest)) {
    fs.copyFileSync(src, dest);
  } else {
    log(`${label || path.basename(dest)} already exists, skipping copy.`);
  }
}

function pause(msg) {
  if (msg) log(msg);
  spawnSync(CMD_EXE, ['/c', 'pause'], { stdio: 'inherit', shell: false, env: SAFE_ENV });
}

// Creates a fresh readline, asks one question, then fully tears it down.
// This ensures no lingering listeners or raw-mode state leak into child processes.
function ask(question) {
  return new Promise((resolve) => {
    const rl = readline.createInterface({
      input: process.stdin,
      output: process.stdout,
      terminal: true,
    });
    rl.question(question, (answer) => {
      rl.close();
      resolve(answer);
    });
  });
}

// ─── URL / Archive Helpers ───────────────────────────────────────────────────

function urlFilename(url) {
  return path.basename(new URL(url).pathname);
}

function folderFromFilename(filename) {
  if (filename.endsWith('.tar.xz')) return filename.slice(0, -7);
  if (filename.endsWith('.7z.exe')) return filename.slice(0, -7);
  if (filename.endsWith('.7z')) return filename.slice(0, -3);
  if (filename.endsWith('.zip')) return filename.slice(0, -4);
  return filename;
}

function versionFromFilename(filename) {
  const parts = filename.split('-');
  return parts.length >= 2 ? parts[1] : '';
}

// ─── 7zip ────────────────────────────────────────────────────────────────────

let _7zBinPath = null;

function get7zBinPath() {
  if (!_7zBinPath) throw new Error('7zip-bin not installed. Call install7zipBin() first.');
  return _7zBinPath;
}

function install7zipBin() {
  const globalRoot = runCapture('npm.cmd root -g');
  const globalModPath = globalRoot ? path.join(globalRoot, '7zip-bin') : null;
  if (globalModPath && fileExists(globalModPath)) {
    _7zBinPath = require(globalModPath).path7za;
    return;
  }
  logToFile('Installing 7zip-bin globally for archive extraction...');
  execFileSync('npm.cmd', ['install', '-g', '7zip-bin'], { stdio: 'pipe', env: process.env, shell: true, windowsHide: true });
  const updatedRoot = runCapture('npm.cmd root -g');
  _7zBinPath = require(path.join(updatedRoot, '7zip-bin')).path7za;
}

// ─── Timing ──────────────────────────────────────────────────────────────────

function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

function sleepSync(ms) {
  Atomics.wait(_sleepBuf, 0, 0, ms);
}

// ─── Extended File Operations ────────────────────────────────────────────────

function waitAndRemove(filePath, retryInterval = 500, timeout = 60000) {
  const start = Date.now();
  while (fs.existsSync(filePath)) {
    try {
      fs.unlinkSync(filePath);
      log(`Removed old log file: ${filePath}`);
      return;
    } catch (e) {
      if (e.code !== 'EBUSY' && e.code !== 'EPERM') throw e;
      if (Date.now() - start > timeout) {
        throw new Error(`Timed out waiting to delete: ${filePath}`);
      }
      sleepSync(retryInterval);
    }
  }
}

function forceRemoveLink(linkPath) {
  try {
    const stat = fs.lstatSync(linkPath);
    if (stat.isSymbolicLink()) {
      fs.rmSync(linkPath, { force: true });
      if (!fs.existsSync(linkPath)) return true;
    }
  } catch {}
  try { fs.rmSync(linkPath, { recursive: true, force: true }); } catch {}
  if (!fs.existsSync(linkPath)) return true;
  sleepSync(300);
  try { fs.rmSync(linkPath, { recursive: true, force: true }); } catch {}
  return !fs.existsSync(linkPath);
}

function existsInDir(parentDir, name) {
  try {
    return fs.readdirSync(parentDir).includes(name);
  } catch {
    return false;
  }
}

function rmtree(dirPath) {
  try { fs.rmSync(dirPath, { recursive: true, force: true }); } catch {}
}

function copyFilesWithoutOverrideRecursive(src, dst, stats) {
  fs.mkdirSync(dst, { recursive: true });
  let items;
  try { items = fs.readdirSync(src); } catch (e) {
    log(`\u2717 Error reading directory ${src}: ${e.message}`);
    return;
  }
  for (const item of items) {
    const srcPath = path.join(src, item);
    const dstPath = path.join(dst, item);
    try {
      const stat = fs.statSync(srcPath);
      if (stat.isDirectory()) {
        copyFilesWithoutOverrideRecursive(srcPath, dstPath, stats);
      } else {
        if (!fs.existsSync(dstPath)) {
          fs.copyFileSync(srcPath, dstPath);
          stats.copied++;
        } else {
          stats.skipped++;
        }
      }
    } catch (e) {
      log(`  \u2717 Error copying ${srcPath} to ${dstPath}: ${e.message}`);
    }
  }
}

function copyFilesWithoutOverride(src, dst) {
  src = path.resolve(src);
  dst = path.resolve(dst);
  if (!fs.existsSync(src) || !fs.statSync(src).isDirectory()) {
    log(`\u2717\u2717\u2717 ERROR: Source directory does not exist: ${src}`);
    return;
  }
  log(`--- Copying files from ${src} to ${dst} ---`);
  const stats = { copied: 0, skipped: 0 };
  copyFilesWithoutOverrideRecursive(src, dst, stats);
  log(`  \u2713 Copied ${stats.copied} file(s), skipped ${stats.skipped} existing file(s)`);
}

function syncFilesRecursive(src, dst, stats) {
  fs.mkdirSync(dst, { recursive: true });
  try { fs.chmodSync(dst, 0o777); } catch {}
  let items;
  try { items = fs.readdirSync(src); } catch (e) {
    log(`\u2717 Error reading directory ${src}: ${e.message}`);
    return;
  }
  for (const item of items) {
    // Coverage databases are local test artifacts, not runtime files. They
    // can also be read-only when produced by another test process, which
    // would otherwise make launcher startup fail during synchronization.
    if (item === '.coverage' || item.startsWith('.coverage.')) continue;
    const srcPath = path.join(src, item);
    const dstPath = path.join(dst, item);
    try {
      const stat = fs.statSync(srcPath);
      if (stat.isDirectory()) {
        syncFilesRecursive(srcPath, dstPath, stats);
      } else if (!fs.existsSync(dstPath) || !fs.readFileSync(srcPath).equals(fs.readFileSync(dstPath))) {
        if (fs.existsSync(dstPath)) {
          // Extracted Windows runtime files can retain the archive's
          // read-only bit; clear it before replacing shipped code.
          try { fs.chmodSync(dstPath, 0o666); } catch {}
        }
        fs.copyFileSync(srcPath, dstPath);
        stats.copied++;
      } else {
        try { fs.chmodSync(dstPath, 0o666); } catch {}
        stats.skipped++;
      }
    } catch (e) {
      log(`  \u2717 Error copying ${srcPath} to ${dstPath}: ${e.message}`);
    }
  }
}

// Like copyFilesWithoutOverride, but keeps `dst` in sync with `src`: files
// that already exist ARE overwritten when their content differs. Used for
// shipped, non-user-editable code (e.g. src/tools -> KUWA_ROOT/bin) so a
// stale prior copy of a shared helper module can't silently keep serving old
// code after the repo is updated. Never deletes files present only in `dst`.
function syncFiles(src, dst) {
  src = path.resolve(src);
  dst = path.resolve(dst);
  if (!fs.existsSync(src) || !fs.statSync(src).isDirectory()) {
    log(`\u2717\u2717\u2717 ERROR: Source directory does not exist: ${src}`);
    return;
  }
  log(`--- Syncing files from ${src} to ${dst} ---`);
  const stats = { copied: 0, skipped: 0 };
  syncFilesRecursive(src, dst, stats);
  log(`  \u2713 Updated ${stats.copied} file(s), ${stats.skipped} already up to date`);
}

function globFiles(dir) {
  if (!fs.existsSync(dir)) return [];
  try {
    return fs.readdirSync(dir)
      .filter(f => !fs.statSync(path.join(dir, f)).isDirectory())
      .map(f => path.join(dir, f));
  } catch { return []; }
}

function createJunction(target, linkPath) {
  try {
    fs.symlinkSync(target, linkPath, 'junction');
    return true;
  } catch {
    return false;
  }
}

// ─── Process Management ──────────────────────────────────────────────────────

function terminateProcess(pid) {
  try { execFileSync(TASKKILL_EXE, ['/pid', String(pid), '/T', '/F'], { stdio: 'ignore', env: SAFE_ENV, windowsHide: true }); } catch {}
}

// ─── Network ─────────────────────────────────────────────────────────────────

function httpGet(url, timeout = 1000) {
  return new Promise((resolve, reject) => {
    const req = http.get(url, { timeout }, (res) => {
      res.resume();
      resolve(res.statusCode);
    });
    req.on('error', reject);
    req.on('timeout', () => { req.destroy(); reject(new Error('timeout')); });
  });
}

// ─── Higher-Level Helpers ────────────────────────────────────────────────────

function runAndLog(cmd, cwd) {
  console.log(`--- Running command: ${cmd} in ${cwd || '.'} ---`);
  const code = run(cmd, { cwd });
  if (code !== 0) {
    console.log(`--- Command '${cmd}' finished with exit code: ${code} ---`);
  } else {
    console.log(`--- Command '${cmd}' finished successfully ---`);
  }
  return code;
}

async function runAndLogAsync(cmd, cwd) {
  console.log(`--- Running command: ${cmd} in ${cwd || '.'} ---`);
  const code = await runAsync(cmd, { cwd });
  if (code !== 0) {
    console.log(`--- Command '${cmd}' finished with exit code: ${code} ---`);
  } else {
    console.log(`--- Command '${cmd}' finished successfully ---`);
  }
  return code;
}

function spawnBackground(cmd, opts = {}) {
  const { cwd, env: extraEnv, tracker } = opts;
  console.log(`--- Starting background process: ${cmd} ---`);
  const proc = spawn(`chcp 65001 >nul & ${cmd}`, {
    cwd: cwd || process.cwd(),
    shell: true,
    stdio: ['ignore', 'pipe', 'pipe'],
    windowsHide: true,
    env: { ...process.env, ...(extraEnv || {}) },
  });
  if (tracker) tracker.push(proc);
  proc.stdout.on('data', (d) => { process.stdout.write(d); });
  proc.stderr.on('data', (d) => { process.stderr.write(d); });
  proc.on('error', (err) => {
    console.log(`--- EXCEPTION in background process '${cmd}': ${err.message} ---`);
  });
  return proc;
}

function parseKeyValueFile(filePath) {
  const data = {};
  try {
    for (const line of fs.readFileSync(filePath, 'utf-8').split(/\r?\n/)) {
      const idx = line.indexOf('=');
      if (idx !== -1) data[line.substring(0, idx).trim()] = line.substring(idx + 1).trim();
    }
  } catch {}
  return data;
}

function removeFilesRecursive(dir, filename) {
  if (!fs.existsSync(dir) || !fs.statSync(dir).isDirectory()) return;
  let entries;
  try { entries = fs.readdirSync(dir, { withFileTypes: true }); } catch { return; }
  for (const entry of entries) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      removeFilesRecursive(full, filename);
    } else if (entry.name === filename) {
      try {
        fs.unlinkSync(full);
        console.log(`Removed temporary file: ${full}`);
      } catch (e) {
        console.log(`Error removing temporary file ${full}: ${e.message}`);
      }
    }
  }
}

function killProcessTree(repoDir) {
  try {
    // Trailing backslash prevents matching sibling folders
    const repoDirPrefix = (path.resolve(repoDir).toLowerCase().replace(/\//g, '\\') + '\\');
    const currentPid = process.pid;

    // Only match by ExecutablePath to avoid killing unrelated programs
    // (VS Code, cmd.exe, powershell.exe) that reference the repo in cmdline.
    const psScript = [
      'Get-CimInstance Win32_Process |',
      'Where-Object { $_.ExecutablePath } |',
      'ForEach-Object {',
      '  "$($_.ProcessId)`t$($_.ExecutablePath)"',
      '}',
    ].join(' ');
    const encoded = Buffer.from(psScript, 'utf16le').toString('base64');

    const output = execFileSync(
      PS_EXE, ['-NoProfile', '-EncodedCommand', encoded],
      { encoding: 'utf-8', stdio: ['pipe', 'pipe', 'pipe'], timeout: 15000, env: SAFE_ENV, windowsHide: true },
    );

    for (const line of output.split(/\r?\n/)) {
      const tab = line.indexOf('\t');
      if (tab === -1) continue;

      const pid = parseInt(line.substring(0, tab).trim(), 10);
      if (!pid || pid === currentPid || isNaN(pid)) continue;

      const exePath = line.substring(tab + 1).trim();
      const exeLower = exePath.toLowerCase().replace(/\//g, '\\');

      if (exeLower.startsWith(repoDirPrefix)) {
        console.log(`Terminating process ${pid}: ${exePath}`);
        terminateProcess(pid);
      }
    }
  } catch (e) {
    console.log(`Warning: process cleanup encountered an error: ${e.message}`);
  }
}

module.exports = {
  logToFile, log, run, runAsync, runCapture,
  commandExists, ensureDir, fileExists, copyFileIfNotExists,
  pause, ask, urlFilename, folderFromFilename, versionFromFilename,
  get7zBinPath, install7zipBin,
  sleep, sleepSync, waitAndRemove,
  forceRemoveLink, existsInDir, rmtree, copyFilesWithoutOverride, syncFiles,
  globFiles, createJunction, terminateProcess, httpGet,
  runAndLog, runAndLogAsync, spawnBackground, parseKeyValueFile, removeFilesRecursive,
  killProcessTree,
};
