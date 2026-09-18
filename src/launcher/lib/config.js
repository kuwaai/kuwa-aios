// Build configuration: package URLs, environment setup, platform detection, migrations.

const fs = require('fs');
const path = require('path');
const os = require('os');
const ctx = require('./context');
const { log, logToFile, run, runCapture, ensureDir, fileExists, urlFilename, folderFromFilename } = require('./helpers');

// ─── Platform Detection (CUDA / NPU / CPU) ──────────────────────────────────

function detectAndApplyPlatform(overrideVersion) {
  let version = 'cpu';

  if (overrideVersion) {
    version = overrideVersion;
  } else {
    // 1. Try CUDA
    let cudaDetected = false;
    try {
      const nvccOut = runCapture('nvcc --version');
      if (nvccOut) {
        const match = nvccOut.match(/release\s+(\d+)\.(\d+)/i);
        if (match) {
          const major = parseInt(match[1], 10);
          logToFile(`Found CUDA ${match[1]}.${match[2]} installed.`);
          if (major >= 12) {
            version = 'cu128';
            cudaDetected = true;
          } else {
            logToFile('[WARNING] CUDA < 12.0 are not supported.');
          }
        }
      } else {
        logToFile('CUDA is not installed.');
      }
    } catch (err) {
      logToFile('CUDA detection failed: ' + (err && err.message ? err.message : String(err)));
    }

    // 2. If no CUDA, try Qualcomm NPU via PowerShell
    if (!cudaDetected) {
      try {
        const npuCmd = "powershell -NoProfile -NonInteractive -Command \"try { $n = Get-CimInstance -ClassName Win32_PnPEntity -ErrorAction Stop | Where-Object { $_.PNPClass -eq 'ComputeAccelerator' -and $_.Name -match 'Qualcomm|Hexagon' }; if ($n) { Write-Output 'npu' } } catch {}\"";
        const npuOut = runCapture(npuCmd);
        if (npuOut && npuOut.includes('npu')) {
          version = 'qnn';
          logToFile('Qualcomm NPU detected.');
        } else {
          logToFile('No NPU detected, using CPU.');
        }
      } catch (err) {
        logToFile('NPU detection failed: ' + (err && err.message ? err.message : String(err)));
      }
    }
  }

  logToFile(`Picked version: ${version}`);
  const patchDir = path.join(ctx.SCRIPT_DIR, 'src', 'version_patch', version);
  if (fileExists(patchDir)) {
    run(`xcopy /s /e /i /Y /Q "${patchDir}\\*" "${ctx.ROOT_DIR}\\" >nul 2>nul`);
  }

  return version;
}

// ─── Package Variables ───────────────────────────────────────────────────────

function initVariables(noMigrate) {
  const cfg = {};

  // RunHiddenConsole
  cfg.url_RunHiddenConsole = 'https://github.com/wenshui2008/RunHiddenConsole/releases/download/1.0/RunHiddenConsole.zip';
  cfg.RunHiddenConsole_folder = folderFromFilename(urlFilename(cfg.url_RunHiddenConsole));

  // Node.js
  cfg.url_NodeJS = 'https://nodejs.org/dist/v22.22.0/node-v22.22.0-win-x64.zip';
  cfg.node_folder = folderFromFilename(urlFilename(cfg.url_NodeJS));

  // PHP
  cfg.url_PHP = 'https://windows.php.net/downloads/releases/php-8.3.24-Win32-vs16-x64.zip';
  cfg.php_folder = folderFromFilename(urlFilename(cfg.url_PHP));

  // PHP Fallback
  cfg.url_PHP_Fallback = 'https://windows.php.net/downloads/releases/archives/php-8.3.24-Win32-vs16-x64.zip';
  cfg.php_folder_Fallback = folderFromFilename(urlFilename(cfg.url_PHP_Fallback));

  // Nginx
  cfg.url_Nginx = 'https://nginx.org/download/nginx-1.26.3.zip';
  cfg.nginx_folder = folderFromFilename(urlFilename(cfg.url_Nginx));

  // Python
  cfg.url_Python = 'https://www.python.org/ftp/python/3.10.11/python-3.10.11-embed-amd64.zip';
  cfg.python_folder = folderFromFilename(urlFilename(cfg.url_Python));

  // Redis
  cfg.url_Redis = 'https://github.com/redis-windows/redis-windows/releases/download/6.0.20/Redis-6.0.20-Windows-x64-msys2.zip';
  cfg.redis_folder = folderFromFilename(urlFilename(cfg.url_Redis));

  // Git bash
  cfg.url_gitbash = 'https://github.com/git-for-windows/git/releases/download/v2.45.1.windows.1/PortableGit-2.45.1-64-bit.7z.exe';
  const gitbashFilename = urlFilename(cfg.url_gitbash);
  cfg.gitbash_folder = gitbashFilename.slice(0, -7); // strip .7z.exe

  // FFmpeg
  cfg.url_ffmpeg = 'https://github.com/GyanD/codexffmpeg/releases/download/7.1.1/ffmpeg-7.1.1-full_build-shared.7z';
  cfg.ffmpeg_folder = folderFromFilename(urlFilename(cfg.url_ffmpeg));

  // Pandoc
  cfg.url_Pandoc = 'https://github.com/jgm/pandoc/releases/download/3.9.0.2/pandoc-3.9.0.2-windows-x86_64.zip';
  cfg.pandoc_folder = urlFilename(cfg.url_Pandoc).replace(/-windows-x86_64\.zip$/i, '');

  // Typst (pandoc's PDF engine for the generate_document tool) — a portable
  // single-binary, not a full LaTeX toolchain (see docker/executor/Dockerfile
  // for the equivalent Linux install).
  cfg.url_Typst = 'https://github.com/typst/typst/releases/download/v0.15.1/typst-x86_64-pc-windows-msvc.zip';
  cfg.typst_folder = folderFromFilename(urlFilename(cfg.url_Typst));

  // ─── Environment variables ───
  const kuwaCache = path.join(ctx.SCRIPT_DIR, 'cache');
  ensureDir(kuwaCache);
  process.env.KUWA_CACHE = kuwaCache;
  process.env.XDG_CACHE_HOME = kuwaCache;
  process.env.PIP_CACHE_DIR = path.join(kuwaCache, 'pip');
  process.env.TORCH_HOME = path.join(kuwaCache, 'torch');
  process.env.CSIDL_LOCAL_APPDATA = path.join(kuwaCache, 'appdata');
  process.env.HF_HOME = path.join(kuwaCache, 'huggingface');
  process.env.COMPOSER_HOME = path.join(ctx.SCRIPT_DIR, 'packages', 'Composer');
  process.env.CACHE_PATH_ENV = path.join(kuwaCache, 'selenium');
  process.env.PYANNOTE_CACHE = path.join(kuwaCache, 'torch', 'pyannote');
  process.env.HOME = ctx.SCRIPT_DIR;
  process.env.PYTHONUTF8 = '1';
  process.env.PYTHONIOENCODING = 'utf8';

  cfg.KUWA_ROOT = path.join(ctx.SCRIPT_DIR, 'root');
  process.env.KUWA_ROOT = cfg.KUWA_ROOT;

  // Prepare migration and packages folders
  ensureDir(path.join(ctx.SCRIPT_DIR, 'src', 'conf'));
  const migrationsFile = path.join(ctx.SCRIPT_DIR, 'src', 'conf', 'migrations.txt');
  if (!fileExists(migrationsFile)) {
    fs.writeFileSync(migrationsFile, '');
  }
  ensureDir(path.join(ctx.SCRIPT_DIR, 'packages'));

  // PATH assembly — always set so spawned processes inherit correct PATH
  const pathDirs = [
    path.join(ctx.SCRIPT_DIR, 'packages', 'Composer', 'vendor', 'bin'),
    path.join(ctx.SCRIPT_DIR, 'src', 'bin'),
    path.join(ctx.SCRIPT_DIR, 'packages'),
    path.join(ctx.SCRIPT_DIR, 'packages', cfg.python_folder, 'Scripts'),
    path.join(ctx.SCRIPT_DIR, 'packages', cfg.python_folder),
    path.join(ctx.SCRIPT_DIR, 'packages', cfg.php_folder),
    path.join(ctx.SCRIPT_DIR, 'packages', cfg.node_folder),
    path.join(ctx.SCRIPT_DIR, 'packages', cfg.gitbash_folder, 'cmd'),
    path.join(ctx.SCRIPT_DIR, 'packages', cfg.ffmpeg_folder, 'bin'),
    path.join(ctx.SCRIPT_DIR, 'packages', cfg.ffmpeg_folder, 'lib'),
    path.join(ctx.SCRIPT_DIR, 'packages', cfg.pandoc_folder, 'bin'),
    path.join(ctx.SCRIPT_DIR, 'packages', cfg.typst_folder),
  ];

  // Remove system-installed versions of bundled tools from PATH to avoid conflicts
  const conflictPatterns = [
    /\\python\b/i,
    /\\python\d/i,
    /\\nginx\b/i,
    /\\redis\b/i,
    /\\php\b/i,
    /\\node\b/i,
    /\\nodejs\b/i,
    /\\ffmpeg\b/i,
    /\\pandoc\b/i,
    /\\typst\b/i,
  ];
  const packagesDir = path.join(ctx.SCRIPT_DIR, 'packages');
  const systemPath = (process.env.PATH || '')
    .split(';')
    .filter(p => {
      if (!p) return false;
      // Always keep our own local package paths
      if (p.startsWith(packagesDir) || p.startsWith(ctx.SCRIPT_DIR)) return true;
      // Remove system entries that match conflicting tool names
      return !conflictPatterns.some(re => re.test(p));
    });

  // De-duplicate (case-insensitive, order-preserving). This makes PATH
  // assembly idempotent: initVariables runs again on every build trigger from
  // the stdin watcher, and the systemPath filter above intentionally keeps our
  // local package dirs — so without de-duplication, pathDirs would be
  // re-prepended on each build and PATH would grow without bound until it
  // exceeds the Windows command-line/environment limit. Once that happens,
  // child processes (e.g. pnpm's node_modules/.bin prepend) lose their PATH
  // additions and fail with "'vite'/'cross-env' is not recognized".
  const seen = new Set();
  const dedupedPath = [];
  for (const p of [...pathDirs, ...systemPath]) {
    if (!p) continue;
    const key = p.toLowerCase();
    if (seen.has(key)) continue;
    seen.add(key);
    dedupedPath.push(p);
  }

  process.env.PATH = dedupedPath.join(';');

  // Run migrations
  if (!noMigrate) {
    runMigrations(migrationsFile);
  }

  return cfg;
}

// ─── Migration Runner ────────────────────────────────────────────────────────

function runMigrations(migrationsFile) {
  const migrationDir = path.join(ctx.SCRIPT_DIR, 'src', 'migration');
  if (!fileExists(migrationDir)) return;

  const completed = fileExists(migrationsFile)
    ? fs.readFileSync(migrationsFile, 'utf-8').split(/\r?\n/).filter(Boolean)
    : [];

  const batFiles = fs.readdirSync(migrationDir)
    .filter(f => f.toLowerCase().endsWith('.bat'))
    .sort();

  for (const batFile of batFiles) {
    if (completed.some(c => c.toLowerCase() === batFile.toLowerCase())) continue;
    log(`Running ${batFile}`);
    const exitCode = run(`"${path.join(migrationDir, batFile)}"`, { cwd: ctx.SCRIPT_DIR });
    if (exitCode !== 0) {
      log(`${batFile} did not execute successfully.`);
    } else {
      log(`${batFile} executed successfully.`);
      fs.appendFileSync(migrationsFile, batFile + os.EOL);
    }
  }
}

module.exports = { detectAndApplyPlatform, initVariables };
