// stop.js - Fast shutdown for all Kuwa processes with TUI
// Can be required from start.js or run standalone: node stop.js

const { execFileSync, spawn } = require('child_process');
const path = require('path');

const baseDir = path.resolve(__dirname, '..', '..', 'windows');

const WIN_ROOT = process.env.SystemRoot || 'C:\\Windows';
const SYSTEM32 = path.join(WIN_ROOT, 'System32');
const SAFE_PATH = [
  SYSTEM32,
  path.join(SYSTEM32, 'WindowsPowerShell', 'v1.0'),
].join(path.delimiter);
const SAFE_ENV = { ...process.env, PATH: SAFE_PATH };
const PS_EXE       = path.join(SYSTEM32, 'WindowsPowerShell', 'v1.0', 'powershell.exe');
const TASKKILL_EXE = path.join(SYSTEM32, 'taskkill.exe');
const CMD_EXE      = path.join(SYSTEM32, 'cmd.exe');
const repoDir = path.resolve(baseDir, '..');
// Trailing backslash prevents matching a sibling folder like "kuwa developer"
const repoDirPrefix = (repoDir.toLowerCase().replace(/\//g, '\\') + '\\');

// ─── ANSI Helpers ────────────────────────────────────────────────────────────

const c = {
  reset: '\x1b[0m', bold: '\x1b[1m', dim: '\x1b[2m',
  green: '\x1b[32m', yellow: '\x1b[33m', cyan: '\x1b[36m', white: '\x1b[37m',
};
function tag(color, text) { return `${color}${text}${c.reset}`; }
function header(text) { console.log(`\n${c.bold}${c.cyan}  ${text}${c.reset}`); }
function sep() { console.log(tag(c.dim, '  ' + '\u2500'.repeat(50))); }

// ─── Process Discovery ──────────────────────────────────────────────────────

/**
 * Find all processes whose ExecutablePath is under the repo directory.
 * Only matches by executable path — NOT by command-line arguments — to avoid
 * false positives (VS Code, cmd.exe, powershell.exe whose cmdline mentions
 * the repo but whose executable lives elsewhere).
 */
function getExcludePids() {
  const pids = new Set([process.pid]);
  const extra = process.env.KUWA_EXCLUDE_PIDS;
  if (extra) {
    for (const s of extra.split(',')) {
      const n = parseInt(s.trim(), 10);
      if (n && !isNaN(n)) pids.add(n);
    }
  }
  return pids;
}

function getRepoProcesses() {
  const excludePids = getExcludePids();
  const found = [];

  try {
    const psScript = [
      'Get-CimInstance Win32_Process |',
      'Where-Object { $_.ExecutablePath } |',
      'ForEach-Object {',
      '  "$($_.ProcessId)`t$($_.Name)`t$($_.ExecutablePath)"',
      '}',
    ].join(' ');
    const encoded = Buffer.from(psScript, 'utf16le').toString('base64');
    const output = execFileSync(
      PS_EXE, ['-NoProfile', '-EncodedCommand', encoded],
      { encoding: 'utf-8', stdio: ['pipe', 'pipe', 'pipe'], timeout: 15000, env: SAFE_ENV, windowsHide: true },
    );

    for (const line of output.split(/\r?\n/)) {
      const firstTab = line.indexOf('\t');
      if (firstTab === -1) continue;
      const secondTab = line.indexOf('\t', firstTab + 1);
      if (secondTab === -1) continue;

      const pid = parseInt(line.substring(0, firstTab).trim(), 10);
      if (!pid || excludePids.has(pid) || isNaN(pid)) continue;

      const name = line.substring(firstTab + 1, secondTab).trim();
      const exePath = line.substring(secondTab + 1).trim();
      const exeLower = exePath.toLowerCase().replace(/\//g, '\\');

      if (exeLower.startsWith(repoDirPrefix)) {
        found.push({ pid, name, exePath });
      }
    }
  } catch (e) {
    console.log(tag(c.yellow, `  Warning: process discovery error: ${e.message}`));
  }

  return found;
}

// ─── TUI Display ─────────────────────────────────────────────────────────────

function groupByName(procs) {
  const groups = {};
  for (const p of procs) {
    const key = p.name.toLowerCase();
    if (!groups[key]) groups[key] = { name: p.name, pids: [] };
    groups[key].pids.push(p.pid);
  }
  return Object.values(groups).sort((a, b) => b.pids.length - a.pids.length);
}

function showProcessSummary(procs) {
  if (procs.length === 0) {
    console.log(tag(c.green, '  No processes found running under this repository.'));
    return procs;
  }

  const groups = groupByName(procs);
  const maxNameLen = Math.max(...groups.map(g => g.name.length));

  for (const g of groups) {
    const count = String(g.pids.length).padStart(3);
    const bar = '\u2588'.repeat(Math.min(g.pids.length, 30));
    console.log(`  ${tag(c.bold, count)} x ${tag(c.cyan, g.name.padEnd(maxNameLen))}  ${tag(c.dim, bar)}`);
  }
  sep();
  console.log(`  ${tag(c.bold, `Total: ${procs.length}`)} process(es) to stop`);
  return procs;
}

function showRunningProcesses() {
  const procs = getRepoProcesses();
  showProcessSummary(procs);
  return procs;
}

// ─── Kill All Repo Processes ─────────────────────────────────────────────────

/**
 * Kill all processes whose ExecutablePath is under the repo directory.
 * The entire find-and-kill loop runs inside a single PowerShell invocation
 * so it completes even if the calling Node.js process is terminated mid-way.
 */
function killAllRepoProcesses() {
  const psScript = `
$repoPrefix = $env:KUWA_REPO_DIR
$excludePids = @($env:KUWA_EXCLUDE_PIDS -split ',' | ForEach-Object { [int]$_.Trim() })
Get-CimInstance Win32_Process | Where-Object {
  $_.ExecutablePath -and
  $_.ExecutablePath.StartsWith($repoPrefix, [System.StringComparison]::OrdinalIgnoreCase) -and
  ($excludePids -notcontains $_.ProcessId)
} | ForEach-Object {
  Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue
}
`.trim();

  const encoded = Buffer.from(psScript, 'utf16le').toString('base64');
  try {
    execFileSync(
      PS_EXE, ['-NoProfile', '-EncodedCommand', encoded],
      {
        encoding: 'utf-8',
        stdio: ['pipe', 'pipe', 'pipe'],
        timeout: 30000,
        env: {
          ...SAFE_ENV,
          KUWA_REPO_DIR: repoDir + '\\',
          KUWA_EXCLUDE_PIDS: [...getExcludePids()].join(','),
        },
        windowsHide: true,
      },
    );
  } catch (e) {
    if (e.stdout) console.log(e.stdout.toString().trim());
    console.log(tag(c.yellow, `  Warning: process cleanup error: ${e.message}`));
  }
}

// ─── Tracked Process Kill (single taskkill call) ─────────────────────────────

function killTrackedProcs(trackedProcs) {
  const pids = trackedProcs.filter(p => p.pid).map(p => p.pid);
  if (pids.length === 0) return;
  const args = pids.flatMap(pid => ['/PID', String(pid)]);
  try {
    execFileSync(TASKKILL_EXE, ['/F', ...args, '/T'], { stdio: 'pipe', timeout: 10000, env: SAFE_ENV, windowsHide: true });
  } catch {}
}

// ─── Worker Logs Cleanup ─────────────────────────────────────────────────────

function clearWorkerLogs() {
  try {
    const logsDir = path.join(repoDir, 'src', 'multi-chat', 'storage', 'logs');
    if (!require('fs').existsSync(logsDir)) return;

    const fs = require('fs');
    const files = fs.readdirSync(logsDir);

    // Clear worker and scheduler log files (including numbered versions)
    let clearedCount = 0;
    for (const file of files) {
      if (file === 'worker.log' || file.match(/^worker\.log\.\d+$/) ||
          file === 'scheduler.log' || file.match(/^scheduler\.log\.\d+$/) ||
          file === 'laravel.log') {
        try {
          fs.unlinkSync(path.join(logsDir, file));
          clearedCount++;
        } catch {}
      }
    }

    if (clearedCount > 0) {
      console.log(tag(c.green, `  \u2713 Cleared ${clearedCount} worker log file(s)`));
    }
  } catch {}
}

// ─── TUI Box ─────────────────────────────────────────────────────────────────

function printBanner() {
  console.log(`\n${c.bold}${c.white}  \u2554${'═'.repeat(40)}\u2557${c.reset}`);
  console.log(`${c.bold}${c.white}  \u2551   Turu Shutdown${' '.repeat(25)}\u2551${c.reset}`);
  console.log(`${c.bold}${c.white}  \u255A${'═'.repeat(40)}\u255D${c.reset}`);
}

// ─── Main Entry ──────────────────────────────────────────────────────────────

/**
 * Stop everything related to this repo.
 * @param {object}  [options]
 * @param {boolean} [options.restart]        - Relaunch start.bat after stopping
 * @param {object[]} [options.trackedProcs]  - Array of child_process objects to kill first
 */
function stopAll(options = {}) {
  const { restart = false, trackedProcs = [] } = options;

  printBanner();

  // Clean up temp bat files
  const executorsDir = path.join(baseDir, 'executors');
  try { const { cleanupTempBats } = require('./lib/setup'); cleanupTempBats(executorsDir); } catch {}

  // Discover processes
  header('Discovering processes...');
  const procs = getRepoProcesses();
  showProcessSummary(procs);

  // Kill tracked child processes in one call
  if (trackedProcs.length > 0) {
    header('Killing tracked processes...');
    killTrackedProcs(trackedProcs);
    console.log(tag(c.green, `  \u2713 ${trackedProcs.filter(p => p.pid).length} tracked process(es) signaled`));
  }

  // Kill everything under the repo
  header('Killing all processes...');
  killAllRepoProcesses();

  // Clear worker logs
  header('Clearing worker logs...');
  clearWorkerLogs();

  // Verify
  const remaining = getRepoProcesses();
  if (remaining.length === 0) {
    console.log(tag(c.green, '  \u2713 All processes stopped'));
  } else {
    console.log(tag(c.yellow, `  ! ${remaining.length} process(es) still running`));
    showProcessSummary(remaining);
  }

  if (restart) {
    header('Restarting...');
    spawn(CMD_EXE, ['/c', 'start', 'start.bat'], {
      cwd: baseDir, shell: false, stdio: 'ignore', windowsHide: true,
    }).unref();
  }

  console.log(`\n${tag(c.green, '  \u2713 Shutdown complete')}\n`);
  process.exit(0);
}

module.exports = { stopAll, showRunningProcesses, getRepoProcesses, killAllRepoProcesses, killTrackedProcs };

// Run standalone: node stop.js
if (require.main === module) {
  printBanner();

  header('Discovering processes...');
  const procs = getRepoProcesses();
  showProcessSummary(procs);

  header('Killing all processes...');
  killAllRepoProcesses();

  const remaining = getRepoProcesses();
  if (remaining.length === 0) {
    console.log(tag(c.green, '  \u2713 All processes stopped'));
  } else {
    console.log(tag(c.yellow, `  ! ${remaining.length} process(es) still running`));
    showProcessSummary(remaining);
  }

  console.log(`\n${tag(c.green, '  \u2713 Shutdown complete')}\n`);
}
