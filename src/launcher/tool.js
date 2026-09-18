// tool.js - Kuwa Windows repair & tool CLI
// Called via repair.bat for debugging, repair, and manual operations.

const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

const { ask } = require('./lib/helpers');

const baseDir = path.resolve(__dirname, '..', '..', 'windows');
const rootDir = path.resolve(baseDir, '..');

// Absolute path to cmd.exe — avoids relying on PATH for system binary lookup.
const CMD_EXE = path.join(process.env.SystemRoot || 'C:\\Windows', 'System32', 'cmd.exe');

// ─── Environment Setup ──────────────────────────────────────────────────────

function initEnvironment() {
  const { initVariables } = require('./lib/config');
  initVariables(true); // no_migrate
}

// ─── Commands ────────────────────────────────────────────────────────────────

function runStop() {
  console.log('Stopping everything');
  const { showRunningProcesses, killAllRepoProcesses } = require('./stop');
  showRunningProcesses();
  killAllRepoProcesses();
  console.log('Done.');
}

function runSeed() {
  console.log('Running seed command...');
  const webPath = path.resolve(rootDir, 'src', 'multi-chat');
  spawnSync('php', ['artisan', 'create:admin-user'], {
    cwd: webPath,
    stdio: 'inherit',
    shell: true,
  });
}

function runHfLogin() {
  console.log('Running huggingface login command...');
  spawnSync('hf', ['auth', 'login'], {
    cwd: baseDir,
    stdio: 'inherit',
    shell: true,
  });
}

function runCmd() {
  console.log('Opening shell');
  if (process.platform === 'win32') {
    const powershell = spawnSync('powershell', ['-Command', 'exit'], { shell: true });
    if (powershell.status === 0) {
      spawnSync('powershell', ['-NoLogo'], {
        cwd: baseDir,
        stdio: 'inherit',
        shell: true,
      });
    } else {
      spawnSync(CMD_EXE, [], {
        cwd: baseDir,
        stdio: 'inherit',
        shell: false,
      });
    }
  } else {
    spawnSync(process.env.SHELL || 'sh', [], {
      cwd: rootDir,
      stdio: 'inherit',
      shell: true,
    });
  }
}

function runBuild(version, extraArgs = []) {
  console.log('Running build...');
  const buildArgs = [path.join(__dirname, 'build.js')];
  if (version) buildArgs.push(version);
  buildArgs.push(...extraArgs);
  const result = spawnSync(process.execPath, buildArgs, {
    cwd: baseDir,
    stdio: 'inherit',
  });
  console.log(result.status === 0 ? 'Build finished.' : 'Build exited with code ' + result.status);
  return result.status;
}

function runStartApp() {
  console.log('Running start...');
  const result = spawnSync(process.execPath, [path.join(__dirname, 'start.js')], {
    cwd: baseDir,
    stdio: 'inherit',
  });
  console.log(result.status === 0 ? 'Start finished.' : 'Start exited with code ' + result.status);
}

function runBuildAndStart(version, extraArgs = []) {
  const code = runBuild(version, extraArgs);
  if (code === 0 || code === null) {
    runStartApp();
  } else {
    console.log('Build failed, skipping start.');
  }
}

function runPrune() {
  console.log('Running prune command...');
  const webPath = path.resolve(rootDir, 'src', 'multi-chat');
  spawnSync('php', ['artisan', 'model:prune'], {
    cwd: webPath,
    stdio: 'inherit',
    shell: true,
  });
}

function runResetConfig() {
  console.log('Removing all run.yaml files (resetting executor configs to defaults)...');
  const executorsDir = path.join(baseDir, 'executors');
  if (!fs.existsSync(executorsDir)) {
    console.log(`Executors directory not found: ${executorsDir}`);
    return;
  }
  let removed = 0;
  for (const entry of fs.readdirSync(executorsDir, { withFileTypes: true })) {
    if (!entry.isDirectory()) continue;
    const runYamlPath = path.join(executorsDir, entry.name, 'run.yaml');
    if (fs.existsSync(runYamlPath)) {
      try {
        fs.unlinkSync(runYamlPath);
        console.log(`Removed ${path.join(entry.name, 'run.yaml')}`);
        removed++;
      } catch (e) {
        console.log(`Error removing ${runYamlPath}: ${e.message}`);
      }
    }
  }
  console.log(`Done. Removed ${removed} run.yaml file(s). They will be recreated from _run.yaml on next start.`);
}

function step(msg) {
  console.log(`\n\x1b[36m>>> ${msg}\x1b[0m`);
}

function getGitSshEnv() {
  const privKey = path.join(rootDir, '.git', 'test_pack_perm.priv');
  if (fs.existsSync(privKey)) {
    return { GIT_SSH_COMMAND: `ssh -i "${privKey}" -o IdentitiesOnly=yes -o StrictHostKeyChecking=no` };
  }
  return {};
}

function git(args, opts = {}) {
  const env = { ...process.env, ...getGitSshEnv(), ...(opts.env || {}) };
  return spawnSync('git', args, { cwd: rootDir, encoding: 'utf-8', shell: true, stdio: 'inherit', ...opts, env });
}

function runUpdate() {
  step('Stashing local changes...');
  git(['stash']);

  step('Pulling latest...');
  git(['pull']);

  step('Update complete!');
}

// ─── Command Loop ────────────────────────────────────────────────────────────

async function commandLoop() {
  while (true) {
    const answer = await ask('Enter a command (quit, update, build [version], start, build&start [version], stop, seed, hf login, prune, reset-config, cmd): ');
    const parts = (answer || '').trim().split(/\s+/);
    const cmd = parts[0].toLowerCase();
    const arg2 = parts[1] || '';

    if (cmd === 'quit') {
      console.log('Quit.');
      return;
    } else if (cmd === 'build&start' || cmd === 'build') {
      const version = arg2 || undefined;
      if (cmd === 'build&start') {
        runBuildAndStart(version);
      } else {
        runBuild(version);
      }
    } else if (cmd === 'start') {
      runStartApp();
    } else if (cmd === 'seed') {
      runSeed();
    } else if (cmd === 'hf' && arg2 === 'login') {
      runHfLogin();
    } else if (cmd === 'cmd') {
      runCmd();
    } else if (cmd === 'stop') {
      runStop();
    } else if (cmd === 'prune') {
      runPrune();
    } else if (cmd === 'reset-config') {
      runResetConfig();
    } else if (cmd === 'update') {
      runUpdate();
    }
  }
}

// ─── Main ────────────────────────────────────────────────────────────────────

function main() {
  initEnvironment();

  // Handle subcommands as first argument
  const arg = (process.argv[2] || '').toLowerCase();
  
  // Collect all remaining arguments (for flags like --no-color)
  const allArgs = process.argv.slice(3);
  
  if (arg === 'stop') {
    runStop();
    return;
  }
  if (arg === 'build') {
    // Extract version (first positional arg) from any flags
    let version = undefined;
    let extraArgs = [];
    for (const a of allArgs) {
      if (a.startsWith('--') || a.startsWith('-')) {
        extraArgs.push(a);
      } else if (!version) {
        version = a;
      } else {
        extraArgs.push(a);
      }
    }
    runBuild(version, extraArgs);
    return;
  }
  if (arg === 'start') {
    runStartApp();
    return;
  }
  if (arg === 'build&start') {
    // Extract version (first positional arg) from any flags
    let version = undefined;
    let extraArgs = [];
    for (const a of allArgs) {
      if (a.startsWith('--') || a.startsWith('-')) {
        extraArgs.push(a);
      } else if (!version) {
        version = a;
      } else {
        extraArgs.push(a);
      }
    }
    runBuildAndStart(version, extraArgs);
    return;
  }
  if (arg === 'update') {
    runUpdate();
    return;
  }
  if (arg === 'seed') {
    runSeed();
    return;
  }
  if (arg === 'reset-config') {
    runResetConfig();
    return;
  }

  commandLoop().catch(console.error);
}

main();
