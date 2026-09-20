// build.js - Kuwa Windows build script
// Uses only Node.js built-in modules. No npm dependencies required.
// Parallelized with TUI progress display.

const fs = require('fs');
const path = require('path');

const ctx = require('./lib/context');
const {
  logToFile, log, runAsync, runCapture,
  commandExists, ensureDir, fileExists, copyFileIfNotExists,
  pause, install7zipBin,
} = require('./lib/helpers');
const { setupOutputMirror } = require('./lib/terminal-utils');
const { downloadExtractAsync } = require('./lib/download');
const { TUI, formatTime } = require('./lib/tui');
const { detectAndApplyPlatform, initVariables } = require('./lib/config');
const { killAllRepoProcesses, getRepoProcesses } = require('./stop');

// Detect packages that are installed according to their metadata but whose
// actual files are missing on disk (partial/corrupted extraction). This
// happens e.g. when antivirus quarantines a package's native binaries
// (onnxruntime's capi/*.pyd) or when an extraction is interrupted. `uv pip
// sync` only checks *.dist-info metadata, so it never notices the missing
// files and reports "Already up to date" — leaving the env broken. Returns
// an array of distribution names that need to be force-reinstalled.
function findBrokenPackages(sitePackages) {
  const broken = [];
  if (!fs.existsSync(sitePackages)) return broken;

  for (const entry of fs.readdirSync(sitePackages)) {
    if (!entry.endsWith('.dist-info')) continue;
    const distInfoDir = path.join(sitePackages, entry);
    const recordPath = path.join(distInfoDir, 'RECORD');
    if (!fs.existsSync(recordPath)) continue;

    // Resolve the distribution name from METADATA (falls back to dir name).
    let pkgName = entry.replace(/\.dist-info$/, '').replace(/-[^-]+$/, '');
    try {
      const metaName = fs
        .readFileSync(path.join(distInfoDir, 'METADATA'), 'utf8')
        .match(/^Name:\s*(.+)$/m);
      if (metaName) pkgName = metaName[1].trim();
    } catch { /* keep fallback name */ }

    let isBroken = false;
    const lines = fs.readFileSync(recordPath, 'utf8').split(/\r?\n/);
    for (const line of lines) {
      if (!line) continue;
      // RECORD is CSV: "path,sha256=...,size". Split from the right so paths
      // containing commas are handled correctly.
      const sizeComma = line.lastIndexOf(',');
      const hashComma = line.lastIndexOf(',', sizeComma - 1);
      const relPath = hashComma > 0 ? line.slice(0, hashComma) : line;
      if (!relPath) continue;
      // Skip entries outside site-packages (scripts/data), bytecode caches,
      // and the RECORD file itself — none of these indicate corruption.
      if (relPath.startsWith('..') || relPath.endsWith('.pyc')) continue;
      if (relPath.includes('__pycache__')) continue;
      const abs = path.join(sitePackages, relPath);
      if (!fs.existsSync(abs)) { isBroken = true; break; }
    }
    if (isBroken) broken.push(pkgName);
  }
  return broken;
}

// ─── Argument Parsing ───
const _args = process.argv.slice(2);

// Show help if requested
if (_args.includes('--help') || _args.includes('-h')) {
  displayHelp();
  process.exit(0);
}

let stdinFile = null;
let stdoutFile = null;
let logFile = null;
let noColor = false;
let platformOverride = null;
let pythonOnly = false;
let uiOnly = false;
let isDev = false;

for (let i = 0; i < _args.length; i++) {
  if (_args[i] === '--stdin' && i + 1 < _args.length) {
    stdinFile = path.resolve(_args[++i]);
  } else if (_args[i] === '--stdout' && i + 1 < _args.length) {
    stdoutFile = path.resolve(_args[++i]);
  } else if (_args[i] === '--log' && i + 1 < _args.length) {
    logFile = path.resolve(_args[++i]);
  } else if (_args[i] === '--no-color') {
    noColor = true;
  } else if (_args[i] === '--ui-only') {
    uiOnly = true;
  } else if (_args[i] === '--python-only') {
    pythonOnly = true;
  } else if (_args[i] === '--dev') {
    isDev = true;
  } else if (!platformOverride) {
    platformOverride = _args[i];
  }
}

// Validate that stdin and stdout appear together
if ((stdinFile && !stdoutFile) || (!stdinFile && stdoutFile)) {
  console.error('\x1b[31mError:\x1b[0m --stdin and --stdout must be used together');
  console.error('Use: node build.js --stdin <file> --stdout <file>');
  process.exit(1);
}

// ─── Help Display ───
function displayHelp() {
  console.log(`
  Kuwa Windows Build Script

USAGE:
  node build.js [OPTIONS] [PLATFORM]

OPTIONS:
  --help              Show this help message
  --stdin <file>      Watch file for build triggers (0=idle, 1=full, 2=ui-only, 3=python-only)
  --stdout <file>     Write output to file instead of console
  --log <file>        Write detailed build log to file (plain text, no TUI)
  --no-color          Disable ANSI color codes in output
  --ui-only           Skip Python installation, build only UI/Laravel
  --python-only       Skip Laravel/PHP and Node packages, build only Python
  --dev               Include development dependencies (e.g. composer install without --no-dev)

PLATFORM:
  cu121               CUDA 12.1
  cu128               CUDA 12.8 (default if CUDA available)
  npu                 Qualcomm NPU
  cpu                 CPU-only build
  (Leave empty to auto-detect: CUDA → NPU → CPU)
STDIN FILE VALUES (binary permissions):
  Binary format: [bit4: isDev][bit3: gitUpdate][bit2: color][bit1: ui][bit0: python]
  
  Bit meanings:
    Bit 0 (rightmost):  1=build Python,   0=skip Python
    Bit 1:              1=build UI,       0=skip UI
    Bit 2:              1=with color,     0=no color
    Bit 3:              0=rebuild only (no git update), 1=run git stash+pull first
    Bit 4:              1=dev mode (install dev deps),  0=production mode
  
  Examples:
    0 (00000): nothing (ignored)       7 (00111): Full build, rebuild only (Python + UI, with color)
    6 (00110): UI only, with color     15 (01111): Full build + git stash/pull (Python + UI, with color)
    23 (10111): Full build + dev mode, rebuild only
EXAMPLES:
  node build.js                          # Full build with auto-detected platform
  node build.js cu128                    # Build with CUDA 12.8
  node build.js npu                      # Build for Qualcomm NPU
  node build.js --python-only            # Build only Python
  node build.js --stdin stdin.txt --stdout stdout.txt
  node build.js --no-color --ui-only --dev
  `);
}

// ─── Output mirroring via terminal utilities ───
let outputMirror = null;

function setupMirror() {
  if (!stdoutFile) return;
  outputMirror = setupOutputMirror(stdoutFile, noColor);
}

function resetStdoutFile() {
  if (outputMirror) {
    outputMirror.reset();
  }
}

// ─── Build Logic ───
async function runBuild({ pythonOnly: _pythonOnly = false, uiOnly: _uiOnly = false, skipGit: _skipGit = true, isDev: _isDev = false } = {}) {
  const tui = new TUI({ noColor });

  tui.banner('Kuwa GenAI OS \u2014 Windows Build');

  // ─── Phase -1: Git update (when requested) ───
  if (!_skipGit) {
    log('Running git stash && git pull before build...');

    // Apply SSH key if present (same key used by the web:config Laravel setting)
    const gitPrivKey = path.join(ctx.ROOT_DIR, '.git', 'test_pack_perm.priv');
    const gitEnv = fs.existsSync(gitPrivKey)
      ? { GIT_SSH_COMMAND: `ssh -i "${gitPrivKey}" -o IdentitiesOnly=yes -o StrictHostKeyChecking=no` }
      : {};

    try {
      await runAsync('git stash', { cwd: ctx.ROOT_DIR, env: gitEnv });
    } catch (err) {
      log('Warning: git stash failed (continuing): ' + (err.message || err));
    }
    try {
      await runAsync('git pull', { cwd: ctx.ROOT_DIR, env: gitEnv });
    } catch (err) {
      log('Warning: git pull failed (continuing): ' + (err.message || err));
    }
    log('Git update complete.');
  }

  // ─── Phase 0: Stop running processes ───
  // Exclude parent process (launcher.js / tool.js) from being killed
  if (process.ppid) {
    const existing = process.env.KUWA_EXCLUDE_PIDS;
    process.env.KUWA_EXCLUDE_PIDS = existing ? `${existing},${process.ppid}` : String(process.ppid);
  }
  log('Stopping all running Kuwa processes before build...');
  killAllRepoProcesses();
  // Reset stdin file to 0 after killing processes (consumed the trigger)
  if (stdinFile) {
    try { fs.writeFileSync(stdinFile, '0', 'utf-8'); } catch {}
  }
  const remaining = getRepoProcesses();
  if (remaining.length === 0) {
    log('All processes stopped.');
  } else {
    log(`Warning: ${remaining.length} process(es) still running.`);
  }

  // ─── Phase 1: Initialization ───
  // initVariables() assembles PATH (including the bundled node folder), so it
  // must run before install7zipBin() — the latter invokes npm.cmd from the
  // bundled node and would otherwise fail with "'npm.cmd' is not recognized"
  // on a fresh launcher process where the bundled node isn't yet on PATH.
  const platform = detectAndApplyPlatform(platformOverride);
  const cfg = initVariables(false);
  install7zipBin();
  logToFile(`PWD: ${process.cwd()}`);

  // VCredist check
  const vcOut = runCapture('reg query "HKLM\\SOFTWARE\\Microsoft\\VisualStudio" /s /f "Installed" 2>nul');
  if (!vcOut) {
    log('No Visual C++ Redistributable found, Please download vcredist from https://learn.microsoft.com/zh-tw/cpp/windows/latest-supported-vc-redist?view=msvc-170');
    pause('Press any key to continue building...');
  }

  tui.sectionDone('Initialization', `Platform: ${platform}, VC++: ${vcOut ? 'yes' : 'no'}`);

  ensureDir(path.join(ctx.SCRIPT_DIR, 'packages'));
  const pythonDir = path.join(ctx.SCRIPT_DIR, 'packages', cfg.python_folder);
  const multiChatDir = path.join(ctx.ROOT_DIR, 'src', 'multi-chat');
  const frontendDir = path.join(multiChatDir, 'frontend');
  const frontendPackagePath = path.join(frontendDir, 'package.json');
  const hasFrontendPackage = fs.existsSync(frontendPackagePath)
    && fs.statSync(frontendPackagePath).isFile();
  if (!hasFrontendPackage) {
    logToFile('Frontend package.json not found; skipping Frontend App build.');
  }
  const envFile = path.join(multiChatDir, '.env');
  const kuwaRoot = cfg.KUWA_ROOT;

  // ─── Pre-job setup ───
  if (!fileExists(envFile)) {
    logToFile('Copying environment configuration file (.env) of multi-chat');
    fs.copyFileSync(path.join(multiChatDir, '.env.dev'), envFile);
  }

  logToFile('Initializing the filesystem hierarchy of Kuwa.');
  ensureDir(path.join(kuwaRoot, 'bin'));
  ensureDir(path.join(kuwaRoot, 'database'));
  ensureDir(path.join(kuwaRoot, 'custom'));
  ensureDir(path.join(kuwaRoot, 'bootstrap', 'bot'));

  // Cross-job synchronization: RunHiddenConsole download
  let resolveRhcDownload;
  const rhcDownloaded = new Promise(r => { resolveRhcDownload = r; });

  // Cross-job synchronization: pnpm availability
  let resolvePnpmReady;
  const pnpmReady = new Promise(r => { resolvePnpmReady = r; });

  // ─── Main Build (all jobs in parallel) ───
  const allJobs = [
    {
      id: 'laravel', label: 'Init Laravel',
      processes: [
        {
          id: 'php-backend', label: 'PHP + Backend', steps: 12,
          fn: async (step) => {
            // Download PHP and composer.phar in parallel
            step('downloading PHP + composer');
            const phpDownload = (async () => {
              await downloadExtractAsync(cfg.url_PHP, `packages/${cfg.php_folder}`, `packages/${cfg.php_folder}`, 'php.zip');
              if (!fileExists(path.join(ctx.SCRIPT_DIR, 'packages', cfg.php_folder))) {
                logToFile('Primary PHP download failed, trying fallback...');
                const originalPhpFolder = cfg.php_folder;
                await downloadExtractAsync(
                  cfg.url_PHP_Fallback,
                  `packages/${cfg.php_folder_Fallback}`,
                  `packages/${cfg.php_folder_Fallback}`,
                  'php-fallback.zip'
                );
                cfg.php_folder = cfg.php_folder_Fallback;
                process.env.PATH = process.env.PATH.replace(
                  path.join(ctx.SCRIPT_DIR, 'packages', originalPhpFolder),
                  path.join(ctx.SCRIPT_DIR, 'packages', cfg.php_folder_Fallback)
                );
              }
            })();

            const composerDownload = (async () => {
              const composerPath = path.join(ctx.SCRIPT_DIR, 'packages', 'composer.phar');
              if (!fileExists(composerPath)) {
                logToFile('Downloading composer');
                await runAsync(`curl -s -o "${composerPath}" https://getcomposer.org/download/latest-stable/composer.phar`);
              }
            })();

            await Promise.all([phpDownload, composerDownload]);

            // PHP configuration
            step('configuring PHP');
            const phpDir = path.join(ctx.SCRIPT_DIR, 'packages', cfg.php_folder);
            copyFileIfNotExists(
              path.join(ctx.ROOT_DIR, 'src', 'multi-chat', 'php.ini'),
              path.join(phpDir, 'php.ini'),
              'php.ini'
            );
            ensureDir(path.join(phpDir, 'ext'));
            copyFileIfNotExists(
              path.join(ctx.SCRIPT_DIR, 'src', 'php_redis.dll'),
              path.join(phpDir, 'ext', 'php_redis.dll'),
              'php_redis.dll'
            );

            // Wait for RunHiddenConsole (downloaded in Init Misc Tools job)
            step('waiting for RunHiddenConsole');
            await rhcDownloaded;
            const rhcDest = path.join(phpDir, 'RunHiddenConsole.exe');
            if (!fileExists(rhcDest)) {
              fs.copyFileSync(
                path.join(ctx.SCRIPT_DIR, 'packages', cfg.RunHiddenConsole_folder, 'x64', 'RunHiddenConsole.exe'),
                rhcDest
              );
            }

            // Composer install
            step('composer install');
            process.env.HTTP_PROXY_REQUEST_FULLURI = '0';
            const composerPharAbs = path.join(ctx.SCRIPT_DIR, 'packages', 'composer.phar');
            const composerArgs = _isDev ? '' : '--no-dev';
            await runAsync(`php "${composerPharAbs}" install ${composerArgs} --optimize-autoloader --no-interaction`, { cwd: multiChatDir });

            // Filesystem hierarchy
            step('filesystem hierarchy');
            await runAsync(`xcopy /s /y /q "${path.join(ctx.ROOT_DIR, 'src', 'bot', 'init')}" "${path.join(kuwaRoot, 'bootstrap', 'bot')}"`);
            await runAsync(`xcopy /s /y /q "${path.join(ctx.ROOT_DIR, 'src', 'tools')}" "${path.join(kuwaRoot, 'bin')}"`);
            const testDir = path.join(kuwaRoot, 'bin', 'test');
            if (fileExists(testDir)) fs.rmSync(testDir, { recursive: true, force: true });
            const binDir = path.join(kuwaRoot, 'bin');
            if (fileExists(binDir)) {
              const binFiles = fs.readdirSync(binDir).filter(f => fs.statSync(path.join(binDir, f)).isFile());
              for (const f of binFiles) {
                const filePath = path.join(binDir, f);
                await runAsync(`attrib +r "${filePath}"`);
                await runAsync(`icacls "${filePath}" /grant Everyone:RX`);
              }
            }

            // Database setup
            step('key:generate');
            await runAsync('php artisan key:generate --force', { cwd: multiChatDir });
            step('db:migrate');
            await runAsync('php artisan migrate --force', { cwd: multiChatDir });
            step('db:seed');
            await runAsync('php artisan db:seed --class=InitSeeder --force', { cwd: multiChatDir });

            // Admin user setup
            step('admin user setup');
            const initTxt = path.join(ctx.SCRIPT_DIR, 'init.txt');
            if (fileExists(initTxt)) {
              const initContent = fs.readFileSync(initTxt, 'utf-8');
              const initVars = {};
              for (const line of initContent.split(/\r?\n/)) {
                const eqIdx = line.indexOf('=');
                if (eqIdx > 0) {
                  initVars[line.slice(0, eqIdx).trim()] = line.slice(eqIdx + 1).trim();
                }
              }
              const username = initVars.username || '';
              const password = initVars.password || '';
              const autologin = initVars.autologin || '';
              const name = username.split('@')[0];
              await runAsync(`php artisan create:admin-user --name=${name} --email=${username} --password=${password}`, { cwd: multiChatDir });
              if (autologin.toLowerCase() === 'true') {
                fs.appendFileSync(envFile, `\nAPP_AUTO_EMAIL=${username}\n`);
              }
              fs.unlinkSync(initTxt);
            }

            // Symlink cleanup
            step('symlink cleanup');
            for (const d of ['homes', 'custom']) {
              const target = path.join(kuwaRoot, d);
              if (fileExists(target)) await runAsync(`rmdir /s /q "${target}"`, { cwd: ctx.SCRIPT_DIR });
            }
            const symlinkDirs = [
              path.join(multiChatDir, 'public', 'storage'),
              path.join(multiChatDir, 'storage', 'app', 'public', 'root', 'custom'),
              path.join(multiChatDir, 'storage', 'app', 'public', 'root', 'database'),
              path.join(multiChatDir, 'storage', 'app', 'public', 'root', 'bin'),
              path.join(multiChatDir, 'storage', 'app', 'public', 'root', 'bootstrap'),
              path.join(multiChatDir, 'public', 'frontend'),
            ];
            for (const d of symlinkDirs) {
              if (fileExists(d)) await runAsync(`rmdir /s /q "${d}"`);
            }

            // Laravel optimization
            step('laravel optimization');
            await runAsync('php artisan storage:link', { cwd: multiChatDir });
            await runAsync('php artisan optimize', { cwd: multiChatDir });
            await runAsync('php artisan route:cache', { cwd: multiChatDir });
            await runAsync('php artisan view:cache', { cwd: multiChatDir });
            await runAsync('php artisan config:cache', { cwd: multiChatDir });

            // Git SSH key config
            step('git SSH config');
            const gitPrivKey = path.join(ctx.ROOT_DIR, '.git', 'test_pack_perm.priv');
            if (fileExists(gitPrivKey)) {
              await runAsync('php artisan web:config --settings="updateweb_git_ssh_command=ssh -i .git/test_pack_perm.priv -o IdentitiesOnly=yes -o StrictHostKeyChecking=no"', { cwd: multiChatDir });
            }
          },
        },
        {
          id: 'nginx', label: 'Nginx', steps: 2,
          fn: async (step) => {
            const nginxDir = path.join(ctx.SCRIPT_DIR, 'packages', cfg.nginx_folder);
            if (fileExists(nginxDir)) {
              return 'skipped';
            }
            step('downloading');
            await downloadExtractAsync(cfg.url_Nginx, `packages/${cfg.nginx_folder}`, 'packages/.', 'nginx.zip');
            const nginxConfPath = path.join(nginxDir, 'conf', 'nginx.conf');
            if (fileExists(nginxConfPath)) {
              fs.renameSync(nginxConfPath, path.join(nginxDir, 'conf', 'nginx.conf.old'));
            }
            const nginxConf = path.join(nginxDir, 'conf', 'nginx.conf');
            if (!fileExists(nginxConf)) {
              step('copying config');
              logToFile('Copying default nginx configuration.');
              fs.copyFileSync(path.join(ctx.SCRIPT_DIR, 'src', 'nginx.conf'), nginxConf);
            }
          },
        },
        {
          id: 'redis', label: 'Redis',
          fn: () => downloadExtractAsync(cfg.url_Redis, `packages/${cfg.redis_folder}`, 'packages/.', 'redis.zip'),
        },
      ],
    },
    {
      id: 'node', label: 'Init Node Packages',
      processes: [
        {
          id: 'pnpm', label: 'pnpm',
          fn: async () => {
            try {
              const nodeDir = path.join(ctx.SCRIPT_DIR, 'packages', cfg.node_folder);
              if (commandExists('pnpm', [nodeDir])) {
                return 'skipped';
              }
              await runAsync('npm.cmd install -g pnpm@10.30.3 --no-audit --no-fund');
            } finally {
              resolvePnpmReady();
            }
          },
        },
        {
          id: 'multichat-ssr', label: 'Multi-chat SSR', steps: 2,
          fn: async (step) => {
            await pnpmReady;
            // CI=true makes pnpm run non-interactively. Without it, pnpm aborts when it
            // needs to recreate node_modules because there is no TTY to confirm the prompt.
            const pnpmEnv = { CI: 'true' };
            step('pnpm install');
            if (await runAsync('pnpm install --frozen-lockfile', { cwd: multiChatDir, env: pnpmEnv }) !== 0) {
              throw new Error('pnpm install failed for Multi-chat SSR');
            }
            step('pnpm run build');
            if (await runAsync('pnpm run build', { cwd: multiChatDir, env: pnpmEnv }) !== 0) {
              throw new Error('pnpm run build failed for Multi-chat SSR');
            }
          },
        },
        ...(hasFrontendPackage ? [{
          id: 'frontend', label: 'Frontend App', steps: 2,
          fn: async (step) => {
            await pnpmReady;
            // CI=true makes pnpm run non-interactively. Without it, pnpm aborts when it
            // needs to recreate node_modules because there is no TTY to confirm the prompt.
            const pnpmEnv = { CI: 'true' };
            step('pnpm install');
            if (await runAsync('pnpm install --frozen-lockfile', { cwd: frontendDir, env: pnpmEnv }) !== 0) {
              throw new Error('pnpm install failed for Frontend App');
            }
            step('pnpm run build');
            if (await runAsync('pnpm run build', { cwd: frontendDir, env: pnpmEnv }) !== 0) {
              throw new Error('pnpm run build failed for Frontend App');
            }
          },
        }] : []),
        {
          id: 'mermaid', label: 'Mermaid CLI',
          fn: async () => {
            const nodeDir = path.join(ctx.SCRIPT_DIR, 'packages', cfg.node_folder);
            if (!commandExists('mmdc', [nodeDir])) {
              await runAsync('npm.cmd install -g "@mermaid-js/mermaid-cli" --no-audit --no-fund');
            } else {
              return 'skipped';
            }
          },
        },
      ],
    },
    {
      id: 'python', label: 'Init Python',
      processes: [
        {
          id: 'python-pip', label: 'Python + pip', steps: 4,
          fn: async (step) => {
            step('downloading Python');
            if (!fileExists(pythonDir)) {
              await downloadExtractAsync(cfg.url_Python, `packages/${cfg.python_folder}`, `packages/${cfg.python_folder}`, 'python.zip');
              logToFile('Overwrite the python310._pth file.');
              fs.copyFileSync(
                path.join(ctx.SCRIPT_DIR, 'src', 'python310._pth'),
                path.join(pythonDir, 'python310._pth')
              );
            }
            const getPipPath = path.join(pythonDir, 'get-pip.py');
            if (!fileExists(getPipPath)) {
              step('downloading get-pip');
              logToFile('Downloading get-pip.py');
              await runAsync(`curl -s -o "${getPipPath}" https://bootstrap.pypa.io/get-pip.py`);
            }
            logToFile('Installing updated version of pip and uv');
            step('installing pip + uv');
            const pipExe = path.join(pythonDir, 'Scripts', 'pip.exe');
            const uvExe = path.join(pythonDir, 'Scripts', 'uv.exe');
            if (!fileExists(pipExe)) {
              await runAsync('python get-pip.py --no-warn-script-location', { cwd: pythonDir });
            }
            if (!fileExists(uvExe)) {
              await runAsync('python -m pip install -U pip uv');
            }

            step('uv pip sync');
            // Clean up dangling temp directories (e.g. ~package_name.dist-info)
            // left by interrupted pip/uv uninstalls to prevent corruption.
            const sitePackages = path.join(pythonDir, 'Lib', 'site-packages');
            if (fs.existsSync(sitePackages)) {
              for (const entry of fs.readdirSync(sitePackages)) {
                if (entry.startsWith('~')) {
                  const danglingPath = path.join(sitePackages, entry);
                  logToFile(`Removing dangling temp directory: ${entry}`);
                  fs.rmSync(danglingPath, { recursive: true, force: true });
                }
              }
            }
            const forceReinstallReqs = path.join(ctx.SCRIPT_DIR, 'src', 'force-reinstall-requirements.txt');
            const lockFile = path.join(ctx.SCRIPT_DIR, 'src', 'requirements.txt.lock');
            await runAsync(`uv pip uninstall --system -r "${forceReinstallReqs}"`, { cwd: ctx.ROOT_DIR });
            await runAsync(`uv pip sync --refresh --system "${lockFile}"`, { cwd: ctx.ROOT_DIR });

            // Repair packages whose files were partially extracted/corrupted on
            // disk. `uv pip sync` above only validates metadata, so it cannot
            // detect this; we must force-reinstall the affected packages to
            // restore their missing files (e.g. onnxruntime's native binaries).
            const brokenPkgs = findBrokenPackages(sitePackages);
            if (brokenPkgs.length > 0) {
              logToFile(`Detected corrupted package(s), repairing: ${brokenPkgs.join(', ')}`);
              const reinstallFlags = brokenPkgs
                .map(p => `--reinstall-package "${p}"`)
                .join(' ');
              await runAsync(`uv pip sync --refresh --system ${reinstallFlags} "${lockFile}"`, { cwd: ctx.ROOT_DIR });
            }
          },
        },
      ],
    },
    {
      id: 'misc', label: 'Init Misc Tools',
      processes: [
        {
          id: 'pandoc', label: 'Pandoc',
          fn: () => downloadExtractAsync(cfg.url_Pandoc, `packages/${cfg.pandoc_folder}`, 'packages/.', 'pandoc.zip'),
        },
        {
          id: 'typst', label: 'Typst',
          fn: () => downloadExtractAsync(cfg.url_Typst, `packages/${cfg.typst_folder}`, 'packages/.', 'typst.zip'),
        },
        {
          id: 'ffmpeg', label: 'FFmpeg',
          fn: () => downloadExtractAsync(cfg.url_ffmpeg, `packages/${cfg.ffmpeg_folder}`, 'packages/.', 'ffmpeg.7z'),
        },
        {
          id: 'rhc', label: 'RunHiddenConsole',
          fn: async () => {
            try {
              return await downloadExtractAsync(
                cfg.url_RunHiddenConsole,
                `packages/${cfg.RunHiddenConsole_folder}`,
                `packages/${cfg.RunHiddenConsole_folder}`,
                'RunHiddenConsole.zip'
              );
            } finally {
              resolveRhcDownload();
            }
          },
        },
        {
          id: 'gitbash', label: 'Git Bash', steps: 2,
          fn: async (step) => {
            const gitbashDir = path.join(ctx.SCRIPT_DIR, 'packages', cfg.gitbash_folder);
            if (fileExists(gitbashDir)) {
              return 'skipped';
            }
            step('downloading');
            await downloadExtractAsync(
              cfg.url_gitbash,
              `packages/${cfg.gitbash_folder}`,
              `packages/${cfg.gitbash_folder}`,
              'gitbash.7z.exe'
            );
            step('git check');
            if (commandExists('git')) {
              logToFile('Git is available.');
              await runAsync('git rev-parse HEAD');
            } else {
              logToFile("Git is not found or not available in the system's PATH.");
            }
          },
        },
      ],
    },
  ].filter(j => {
    if (_pythonOnly && (j.id === 'node' || j.id === 'laravel')) return false;
    if (_uiOnly && j.id === 'python') return false;
    if (j.id === 'frontend' && !hasFrontendPackage) return false;
    return true;
  });

  // Resolve cross-job promises if their producer was skipped
  if (_pythonOnly) try { resolveRhcDownload(); resolvePnpmReady(); } catch {}

  await tui.runJobs(allJobs);

  // Postinstall scripts
  const postinstallJobs = [];
  const postinstallDir = path.join(ctx.SCRIPT_DIR, 'postinstall');
  if (fileExists(postinstallDir)) {
    const postinstallFiles = fs.readdirSync(postinstallDir)
      .filter(f => f.toLowerCase().endsWith('.bat'))
      .sort();
    for (const batFile of postinstallFiles) {
      postinstallJobs.push({
        id: `post-${batFile}`, label: batFile,
        fn: () => runAsync(`"${path.join(postinstallDir, batFile)}"`),
      });
    }
  }
  if (postinstallJobs.length > 0) {
    await tui.runSection('Postinstall', postinstallJobs, { parallel: false });
  }

  // ─── Done ───
  const totalElapsed = formatTime(Date.now() - tui.startTime);
  if (noColor) {
    ctx.origStdoutWrite(`\n  \u2714 Build complete! (${totalElapsed})\n`);
    ctx.origStdoutWrite(`  Log saved to: ${path.join(ctx.LOG_DIR, 'build.log')}\n\n`);
  } else {
    ctx.origStdoutWrite(`\n\x1b[1m\x1b[32m  \u2714 Build complete!\x1b[0m \x1b[90m(${totalElapsed})\x1b[0m\n`);
    ctx.origStdoutWrite(`\x1b[90m  Log saved to: ${path.join(ctx.LOG_DIR, 'build.log')}\x1b[0m\n\n`);
  }
  logToFile(`Build complete! Total time: ${totalElapsed}`);
}

// ─── Main Entry Point ───
async function main() {
  // Open detailed log file if requested (used by launcher for the log terminal)
  if (logFile) {
    ctx.logFd = fs.openSync(logFile, 'w');
  }

  if (stdinFile && stdoutFile) {
    setupMirror();

    // Create stdin file with initial value if it doesn't exist
    if (!fileExists(stdinFile)) {
      ensureDir(path.dirname(stdinFile));
      fs.writeFileSync(stdinFile, '0', 'utf-8');
    }

    ctx.origStdoutWrite(`Watching ${stdinFile} for build triggers...\n`);
    ctx.origStdoutWrite(`Output will be written to ${stdoutFile}\n`);

    let building = false;

    function buildOptsFromCode(code) {
      // Parse 5-bit binary: bit0=python, bit1=ui, bit2=color, bit3=doGit, bit4=isDev
      const num = parseInt(code, 10);
      if (num < 0 || num > 31 || isNaN(num)) return null; // Invalid
      
      const buildPython = (num & 1) !== 0;
      const buildUI = (num & 2) !== 0;
      const withColor = (num & 4) !== 0;
      const doGit = (num & 8) !== 0;
      const isDevBit = (num & 16) !== 0;
      
      // Only proceed if at least one build component is set
      if (!buildPython && !buildUI) return null;
      
      return {
        uiOnly: !buildPython && buildUI,
        pythonOnly: buildPython && !buildUI,
        noColor: !withColor,
        skipGit: !doGit,
        isDev: isDevBit,
      };
    }

    // Check initial state
    let initialContent = '';
    try { initialContent = fs.readFileSync(stdinFile, 'utf-8').trim(); } catch {}
    const initialOpts = buildOptsFromCode(initialContent);
    if (initialOpts) {
      building = true;
      resetStdoutFile();
      try {
        // Override noColor if specified in opts
        const buildOpts = { ...initialOpts };
        if (buildOpts.noColor) noColor = true;
        delete buildOpts.noColor;
        await runBuild(buildOpts);
      } catch (err) {
        console.error(err);
      }
      building = false;
    }

    // Watch for changes
    fs.watchFile(stdinFile, { interval: 1000 }, async (curr, prev) => {
      if (building) return;
      if (curr.mtime.getTime() <= prev.mtime.getTime()) return;

      let content;
      try {
        content = fs.readFileSync(stdinFile, 'utf-8').trim();
      } catch {
        return;
      }
      const opts = buildOptsFromCode(content);
      if (!opts) return; // Invalid or no build requested

      building = true;
      resetStdoutFile();
      try {
        // Override noColor if specified in opts
        const buildOpts = { ...opts };
        if (buildOpts.noColor) noColor = true;
        delete buildOpts.noColor;
        await runBuild(buildOpts);
      } catch (err) {
        console.error(err);
      }
      building = false;
    });
  } else {
    await runBuild({ pythonOnly, uiOnly, isDev });
  }
}

main().catch(err => {
  ctx.origStdoutWrite('\x1b[?25h');
  console.error(err);
  process.exit(1);
});
