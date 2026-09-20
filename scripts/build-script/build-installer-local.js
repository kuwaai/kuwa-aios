// build-installer-local.js
// Build Kuwa GenAI OS Windows Installer Locally
// Uses Node.js built-ins only; parallelises independent tasks for speed.
'use strict';

const fs    = require('fs');
const path  = require('path');
const https = require('https');
const http  = require('http');
const { execFileSync, spawnSync, spawn } = require('child_process');

const ARGS         = process.argv.slice(2);
const SKIP_BUILDJS = ARGS.includes('--skip-buildjs');
const SSH_KEY      = (() => {
  const i = ARGS.indexOf('--ssh-key');
  return i !== -1 && ARGS[i + 1] ? ARGS[i + 1] : '';
})();

const SCRIPT_DIR   = __dirname;
const TEMP_FOLDER  = path.join(SCRIPT_DIR, 'temp');
const CACHE_FOLDER = path.join(SCRIPT_DIR, 'cache');
const OUTPUT_DIR   = path.join(SCRIPT_DIR, 'build');

const REPO_URL     = runCapture(['git', 'remote', 'get-url', 'origin'], { cwd: SCRIPT_DIR }) || die('Could not determine git remote URL');
const REPO_SSH_URL = (() => {
  const m = REPO_URL.match(/^https?:\/\/github\.com\/([^/]+\/[^/?#]+?)(?:\.git)?(?:[/?#].*)?$/);
  return m ? `git@github.com:${m[1]}.git` : REPO_URL;
})();
const ONLINE_REPO_HTTPS_URL = 'https://github.com/kuwaai/kuwa-aios.git';
const MODEL_URL    = 'https://huggingface.co/google/gemma-4-E2B-it-qat-q4_0-gguf/resolve/main/gemma-4-E2B_q4_0-it.gguf?download=true';
const MODEL_DIR    = path.join(CACHE_FOLDER, 'gemma4-e2b');
const MODEL_FILE   = path.join(MODEL_DIR, 'gemma-4-E2B_q4_0-it.gguf');
// Online .iss lives in the local workspace — no git clone required to compile it.
const ISS_DIR_ONLINE  = path.join(SCRIPT_DIR, '..', 'windows-setup-files');
const ISS_FILE_ONLINE = path.join(ISS_DIR_ONLINE, 'Kuwa-AIOS-Online-Installer.iss');
const ISS_DIR_FULL    = path.join(TEMP_FOLDER, 'scripts', 'windows-setup-files');
const ISS_FILE_FULL   = path.join(ISS_DIR_FULL,  'Kuwa-AIOS-Full-Installer.iss');
const ISS_COMPILER = findInnoCompiler();

const log  = (msg) => console.log(msg);
const ok   = (msg) => console.log(`[OK] ${msg}`);
const info = (msg) => console.log(`[*]  ${msg}`);
const die  = (msg) => { console.error(`[ERROR] ${msg}`); process.exit(1); };

function run(args, opts = {}) {
  const [cmd, ...rest] = args;
  const env = opts.env ? { ...process.env, ...opts.env } : undefined;
  const result = spawnSync(cmd, rest, { stdio: 'inherit', cwd: opts.cwd || process.cwd(), windowsHide: false, ...(env ? { env } : {}) });
  if (result.error) throw result.error;
  return result.status || 0;
}

function runAsync(args, opts = {}) {
  const [cmd, ...rest] = args;
  const stdio = opts.capture ? ['ignore', 'pipe', 'pipe'] : 'inherit';
  return new Promise((resolve, reject) => {
    const child = spawn(cmd, rest, { stdio, cwd: opts.cwd || process.cwd(), windowsHide: opts.capture || false, ...(opts.env ? { env: opts.env } : {}) });
    const chunks = [];
    if (opts.capture) {
      child.stdout.on('data', d => chunks.push(d));
      child.stderr.on('data', d => chunks.push(d));
    }
    child.on('error', reject);
    child.on('close', (code) => resolve({ code: code || 0, output: opts.capture ? Buffer.concat(chunks).toString() : '' }));
  });
}

function runCapture(args, opts = {}) {
  try {
    const [cmd, ...rest] = args;
    return execFileSync(cmd, rest, { cwd: opts.cwd || process.cwd(), encoding: 'utf8', windowsHide: true }).trim();
  } catch { return ''; }
}

function ensureDir(p) { fs.mkdirSync(p, { recursive: true }); }

function findInnoCompiler() {
  const candidates = [
    process.env.INNO_SETUP_COMPILER,
    path.join(process.env.ProgramFiles || '', 'Inno Setup 7', 'ISCC.exe'),
    path.join(process.env['ProgramFiles(x86)'] || '', 'Inno Setup 7', 'ISCC.exe'),
    path.join(process.env.ProgramFiles || '', 'Inno Setup 6', 'ISCC.exe'),
    path.join(process.env['ProgramFiles(x86)'] || '', 'Inno Setup 6', 'ISCC.exe'),
  ].filter(Boolean);
  return candidates.find(fs.existsSync) || candidates[0];
}

function detectBranch() {
  let branch = runCapture(['git', 'branch', '--show-current'], { cwd: SCRIPT_DIR });
  if (!branch) branch = runCapture(['git', 'rev-parse', '--abbrev-ref', 'HEAD'], { cwd: SCRIPT_DIR });
  branch = branch.replace(/^(refs\/)?heads\//, '');
  return branch || 'main';
}

function updateGitignore() {
  info('Configuring .gitignore...');
  const gitignore = path.join(SCRIPT_DIR, '.gitignore');
  const required  = ['temp/', 'cache/', 'build/'];
  let content = '';
  try { content = fs.readFileSync(gitignore, 'utf8'); } catch { /* new file */ }
  let changed = false;
  for (const entry of required) {
    const lines = content.split(/\r?\n/);
    if (!lines.some(l => l.trim() === entry || l.trim() === entry.replace('/', ''))) {
      content = content.endsWith('\n') ? content + entry + '\n' : content + '\n' + entry + '\n';
      changed = true;
      ok(`Added ${entry} to .gitignore`);
    } else ok(`${entry} already in .gitignore`);
  }
  if (changed) fs.writeFileSync(gitignore, content, 'utf8');
}

function downloadFile(url, dest) {
  return new Promise((resolve, reject) => {
    const file = fs.createWriteStream(dest);
    function request(u) {
      const mod = u.startsWith('https') ? https : http;
      mod.get(u, (res) => {
        if (res.statusCode >= 300 && res.statusCode < 400 && res.headers.location) { res.resume(); return request(res.headers.location); }
        if (res.statusCode !== 200) { file.close(() => fs.unlinkSync(dest)); return reject(new Error(`HTTP ${res.statusCode} for ${u}`)); }
        const total = parseInt(res.headers['content-length'] || '0', 10);
        let received = 0;
        res.on('data', (chunk) => {
          received += chunk.length;
          if (total > 0) process.stdout.write(`\r    ${((received / total) * 100).toFixed(1)}%  ${(received / 1024 / 1024).toFixed(1)} / ${(total / 1024 / 1024).toFixed(0)} MB   `);
        });
        res.pipe(file);
        file.on('finish', () => { process.stdout.write('\n'); file.close(resolve); });
        file.on('error', (e) => { fs.unlinkSync(dest); reject(e); });
      }).on('error', reject);
    }
    request(url);
  });
}

async function ensureModel() {
  if (fs.existsSync(MODEL_FILE)) { ok('Model file already cached'); return; }
  info('Downloading Gemma 4 E2B model (this may take a while, ~3.4 GB)...');
  ensureDir(MODEL_DIR);
  await downloadFile(MODEL_URL, MODEL_FILE);
  ok('Downloaded model file');
}

async function cloneOrReuse(branch) {
  const gitDir = path.join(TEMP_FOLDER, '.git');
  if (fs.existsSync(gitDir)) {
    const cachedRemote = runCapture(['git', 'remote', 'get-url', 'origin'], { cwd: TEMP_FOLDER });
    let cachedBranch = runCapture(['git', 'branch', '--show-current'], { cwd: TEMP_FOLDER });
    if (!cachedBranch) cachedBranch = runCapture(['git', 'rev-parse', '--abbrev-ref', 'HEAD'], { cwd: TEMP_FOLDER });
    cachedBranch = cachedBranch.replace(/^(refs\/)?heads\//, '');
    const remoteMatch = cachedRemote === REPO_URL;
    const branchMatch = cachedBranch === branch;
    const localSha = remoteMatch && branchMatch ? runCapture(['git', 'rev-parse', 'HEAD'], { cwd: TEMP_FOLDER }) : '';
    let remoteSha = '';
    if (localSha && SSH_KEY) {
      info('Checking remote for updates using the supplied SSH key...');
      const sshEnv = { ...process.env, GIT_SSH_COMMAND: `ssh -i "${SSH_KEY}" -o StrictHostKeyChecking=no` };
      const lsResult = spawnSync('git', ['ls-remote', 'origin', `refs/heads/${branch}`], { cwd: TEMP_FOLDER, encoding: 'utf8', stdio: ['inherit', 'pipe', 'inherit'], windowsHide: true, ...(sshEnv ? { env: sshEnv } : {}) });
      remoteSha = (lsResult.stdout || '').trim().split(/\s/)[0] || '';
    }
    const upToDate = !remoteSha || localSha === remoteSha;
    if (remoteMatch && branchMatch && upToDate) { ok(`Reusing cached clone  (branch: ${cachedBranch}, sha: ${localSha.slice(0, 8)})`); return; }
    info('Cached clone is stale or mismatched; deleting temp and re-cloning...');
    fs.rmSync(TEMP_FOLDER, { recursive: true, force: true });
    ensureDir(TEMP_FOLDER);
  } else if (fs.existsSync(TEMP_FOLDER) && fs.readdirSync(TEMP_FOLDER).length > 0) {
    info('Removing incomplete cached clone before re-cloning...');
    fs.rmSync(TEMP_FOLDER, { recursive: true, force: true });
    ensureDir(TEMP_FOLDER);
  }
  const workspaceRoot = path.resolve(SCRIPT_DIR, '..', '..');
  if (!SSH_KEY) {
    info('Preparing repository from local workspace...');
    const archivePath = path.join(CACHE_FOLDER, 'workspace-source.tar');
    const archiveResult = spawnSync('git', ['-C', workspaceRoot, 'archive', '--format=tar', '-o', archivePath, 'HEAD'], {
      stdio: 'inherit', windowsHide: true,
    });
    if (archiveResult.error || archiveResult.status !== 0 || !fs.existsSync(archivePath) || fs.statSync(archivePath).size === 0) {
      die('Failed to archive local workspace source');
    }
    const extractResult = spawnSync('tar', ['-xf', archivePath, '-C', TEMP_FOLDER], {
      stdio: 'inherit', windowsHide: true,
    });
    fs.rmSync(archivePath, { force: true });
    if (extractResult.error || extractResult.status !== 0) die('Failed to extract local workspace source');
    const gitInit = spawnSync('git', ['init', '-b', branch], { cwd: TEMP_FOLDER, stdio: 'inherit', windowsHide: true });
    if (gitInit.error || gitInit.status !== 0) die('Failed to initialize temporary Git metadata');
    const gitCommit = spawnSync('git', ['-c', 'user.name=Kuwa Builder', '-c', 'user.email=builder@localhost', 'add', '-A'], {
      cwd: TEMP_FOLDER, stdio: 'inherit', windowsHide: true,
    });
    if (gitCommit.error || gitCommit.status !== 0) die('Failed to stage temporary Git metadata');
    const gitSave = spawnSync('git', ['-c', 'user.name=Kuwa Builder', '-c', 'user.email=builder@localhost', 'commit', '-m', 'Kuwa temporary build source'], {
      cwd: TEMP_FOLDER, stdio: 'inherit', windowsHide: true,
    });
    if (gitSave.error || gitSave.status !== 0) die('Failed to save temporary Git metadata');
    const launcherSource = path.join(workspaceRoot, 'src', 'launcher');
    const launcherDestination = path.join(TEMP_FOLDER, 'src', 'launcher');
    ensureDir(launcherDestination);
    fs.copyFileSync(path.join(launcherSource, 'build.js'), path.join(launcherDestination, 'build.js'));
    const safetyGuardSource = path.join(workspaceRoot, 'src', 'executor', 'safety-guard', 'client', 'src', 'llm_safety_guard');
    const safetyGuardDestination = path.join(TEMP_FOLDER, 'src', 'executor', 'safety-guard', 'client', 'src', 'llm_safety_guard');
    if (fs.existsSync(safetyGuardSource)) fs.cpSync(safetyGuardSource, safetyGuardDestination, { recursive: true });
    ok('Repository prepared from local workspace');
    return;
  }
  info(`Cloning repository (branch: ${branch}) from ${REPO_URL}...`);
  const cloneEnv = { GIT_SSH_COMMAND: `ssh -i "${SSH_KEY}" -o StrictHostKeyChecking=no` };
  const cloneStatus = await runAsync(['git', 'clone', '--branch', branch, '--depth', '1', REPO_URL, '.'], { cwd: TEMP_FOLDER, env: { ...process.env, ...cloneEnv } }).then(r => r.code);
  if (cloneStatus !== 0) die('git clone failed');
  ok('Repository cloned');
}

function copyModel() {
  info('Copying cached model to build folder...');
  const executorDir = path.join(TEMP_FOLDER, 'windows', 'executors', 'gemma4-e2b');
  const workspaceExecutorDir = path.resolve(SCRIPT_DIR, '..', '..', 'windows', 'executors', 'gemma4-e2b');
  const modelDestination = path.join(executorDir, 'gemma-4-E2B_q4_0-it.gguf');
  const runConfigSource = path.join(workspaceExecutorDir, '_run.yaml');
  const runConfigDestination = path.join(executorDir, '_run.yaml');
  if (!fs.existsSync(runConfigSource)) die(`Gemma 4 executor config not found: ${runConfigSource}`);
  ensureDir(executorDir);
  fs.copyFileSync(MODEL_FILE, modelDestination);
  fs.copyFileSync(runConfigSource, runConfigDestination);
  ok('Gemma 4 model and executor config copied');
}

function copyRunBats() {
  info('Copying _run.bat files...');
  const executorsDir = path.join(TEMP_FOLDER, 'windows', 'executors');
  if (!fs.existsSync(executorsDir)) { ok('No executors dir found, skipping'); return; }
  const tasks = [];
  function walk(dir) {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
      const full = path.join(dir, entry.name);
      if (entry.isDirectory()) walk(full);
      else if (entry.name === '_run.bat') tasks.push({ src: full, dst: path.join(dir, 'run.bat'), name: path.basename(dir) });
    }
  }
  walk(executorsDir);
  return Promise.all(tasks.map(({ src, dst, name }) => { fs.copyFileSync(src, dst); ok(`Copied _run.bat in ${name}`); }));
}

function copyRequirementsLock() {
  info('Copying requirements.txt.lock...');
  const dst = path.join(TEMP_FOLDER, 'windows', 'src', 'requirements.txt.lock');
  const variants = ['cu128', 'cu121', 'cu118', 'cpu', 'ipex-llm', 'qnn'];
  const src = variants
    .map(variant => path.join(TEMP_FOLDER, 'windows', 'src', 'version_patch', variant, 'windows', 'src', 'requirements.txt.lock'))
    .find(fs.existsSync);
  if (!src) die('requirements.txt.lock not found in any supported version_patch directory');
  fs.copyFileSync(src, dst);
  ok(`Copied requirements.txt.lock from ${path.basename(path.dirname(path.dirname(path.dirname(src))))}`);
}

function syncOfflineAssets() {
  const workspaceRoot = path.resolve(SCRIPT_DIR, '..', '..');
  const assets = [
    ['windows', 'packages'],
    ['windows', 'cache'],
    ['src', 'multi-chat', 'node_modules'],
    ['src', 'multi-chat', 'vendor'],
  ];
  for (const parts of assets) {
    const source = path.join(workspaceRoot, ...parts);
    const destination = path.join(TEMP_FOLDER, ...parts);
    if (!fs.existsSync(source)) die(`Offline asset directory not found: ${source}`);
    info(`Syncing offline asset ${parts.join('\\')}...`);
    fs.rmSync(destination, { recursive: true, force: true });
    ensureDir(path.dirname(destination));
    try {
      fs.symlinkSync(source, destination, 'junction');
    } catch (error) {
      die(`Failed to link offline asset ${parts.join('\\')}: ${error.message}`);
    }
    continue;
  }
}

function syncLauncherBuildScript() {
  const source = path.resolve(SCRIPT_DIR, '..', '..', 'src', 'launcher');
  const destination = path.join(TEMP_FOLDER, 'src', 'launcher');
  if (!fs.existsSync(path.join(source, 'build.js'))) {
    die(`Workspace launcher build.js not found at ${path.join(source, 'build.js')}`);
  }
  ensureDir(destination);
  const result = spawnSync('robocopy', [
    source, destination, '/E', '/COPY:DAT', '/DCOPY:DAT',
    '/XJ', '/R:0', '/W:0', '/NFL', '/NDL', '/NJH', '/NJS', '/NP',
  ], { stdio: 'inherit', windowsHide: true });
  if (result.error || result.status > 7) die('Failed to sync launcher build scripts');
}

function syncMultiChatBuildInputs() {
  const workspaceMultiChat = path.resolve(SCRIPT_DIR, '..', '..', 'src', 'multi-chat');
  const cachedMultiChat = path.join(TEMP_FOLDER, 'src', 'multi-chat');
  for (const name of ['package.json', 'pnpm-lock.yaml']) {
    const source = path.join(workspaceMultiChat, name);
    if (!fs.existsSync(source)) die(`Workspace multi-chat file not found: ${source}`);
    fs.copyFileSync(source, path.join(cachedMultiChat, name));
  }
  const workspaceFrontend = path.join(workspaceMultiChat, 'frontend');
  const cachedFrontend = path.join(cachedMultiChat, 'frontend');
  for (const name of ['package.json', 'pnpm-lock.yaml']) {
    const source = path.join(workspaceFrontend, name);
    if (fs.existsSync(source)) fs.copyFileSync(source, path.join(cachedFrontend, name));
  }
}

async function compileInstaller(issFile, extraArgs = [], background = false) {
  info(`Compiling ${path.basename(issFile)}${background ? ' (background)' : ''}...`);
  syncOfflineAssets();
  const { code, output } = await runAsync([ISS_COMPILER, ...extraArgs, issFile, '/O+'], { capture: background });
  if (background && output.trim()) { log('--- Online installer compile output ---'); process.stdout.write(output); log('--- end ---'); }
  if (code !== 0) die(`Inno Setup compilation failed for ${path.basename(issFile)}`);
  ok(`${path.basename(issFile)} compiled`);
}

function getOutputBaseFilenameFromIss(issFile) {
  const content = fs.readFileSync(issFile, 'utf8');
  const match = content.match(/^OutputBaseFilename\s*=\s*(.+)$/m);
  if (!match) die(`OutputBaseFilename not found in ${issFile}`);
  return match[1].trim();
}

function saveOutput(issFile) {
  info('Finalizing build...');
  const issDir = path.dirname(issFile);
  const baseFilename = getOutputBaseFilenameFromIss(issFile);
  const now = new Date();
  const ts = [now.getFullYear(), String(now.getMonth() + 1).padStart(2, '0'), String(now.getDate()).padStart(2, '0')].join('') + '-' + [String(now.getHours()).padStart(2, '0'), String(now.getMinutes()).padStart(2, '0')].join('');
  const parts = fs.readdirSync(issDir).filter(name =>
    name === `${baseFilename}.exe` || /^.+-\d+\.bin$/i.test(name) && name.startsWith(baseFilename),
  );
  if (!parts.includes(`${baseFilename}.exe`)) die(`Installer file not found in ${issDir}`);
  for (const part of parts) {
    const suffix = part === `${baseFilename}.exe`
      ? `${ts}.exe`
      : `${ts}-${part.slice(baseFilename.length + 1, -4)}.bin`;
    const dst = path.join(OUTPUT_DIR, `${baseFilename}-${suffix}`);
    fs.copyFileSync(path.join(issDir, part), dst);
    ok(`Installer part saved to: ${dst}`);
  }
}

async function main() {
  const branch = detectBranch();
  log('');
  log('============================================');
  log(' Kuwa GenAI OS Builder (Node.js)');
  log('============================================');
  log('');
  log(`Script directory: ${SCRIPT_DIR}`);
  log(`Branch:           ${branch}`);
  if (SSH_KEY) log(`  [--ssh-key]         ${SSH_KEY}`);
  if (SKIP_BUILDJS) log('  [--skip-buildjs]    build.js will be skipped');
  log('');
  ensureDir(TEMP_FOLDER); ensureDir(CACHE_FOLDER); ensureDir(OUTPUT_DIR); updateGitignore(); log('');
  if (!fs.existsSync(ISS_COMPILER)) die('Inno Setup 7 or 6 not found. Set INNO_SETUP_COMPILER or install from: https://jrsoftware.org/isdl.php');
  ok(`Inno Setup compiler found: ${ISS_COMPILER}`); log('');
  const onlineDefines = [`/DRepoURL=${REPO_SSH_URL}`, `/DRepoHTTPSURL=${ONLINE_REPO_HTTPS_URL}`, `/DBranch=${branch}`];
  info('Compiling Online installer (no dependencies)...');
  log(`  RepoURL : ${REPO_SSH_URL}`); log(`  Branch  : ${branch}`);
  await compileInstaller(ISS_FILE_ONLINE, onlineDefines); saveOutput(ISS_FILE_ONLINE); log('');
  info('Stage 1/4 — Cloning repository...'); await cloneOrReuse(branch); log('');
  info('Stage 2/4 — Downloading model & preparing files (parallel)...');
  await Promise.all([ensureModel(), Promise.resolve().then(copyRunBats), Promise.resolve().then(copyRequirementsLock)]);
  copyModel(); log('');
  if (SKIP_BUILDJS) info('Stage 3/4 — Skipping build.js (--skip-buildjs)');
  else {
    info('Stage 3/4 — Running build.js...');
    syncOfflineAssets();
    syncLauncherBuildScript();
    syncMultiChatBuildInputs();
    const buildJs = path.join(TEMP_FOLDER, 'src', 'launcher', 'build.js');
    if (!fs.existsSync(buildJs)) die(`build.js not found at ${buildJs}`);
    const buildStatus = await runAsync(['node', buildJs], { cwd: path.join(TEMP_FOLDER, 'windows') }).then(r => r.code);
    if (buildStatus !== 0) die('build.js failed');
    ok('build.js completed');
  }
  log('');
  info('Stage 4/4 — Compressing modules (build.bat zip)...');
  const buildBat = path.join(ISS_DIR_FULL, 'build.bat');
  fs.copyFileSync(path.join(ISS_DIR_ONLINE, 'build.bat'), buildBat);
  if (!fs.existsSync(buildBat)) die(`build.bat not found at ${buildBat}`);
  const zipStatus = await runAsync(['cmd', '/c', buildBat, 'zip'], { cwd: ISS_DIR_FULL }).then(r => r.code);
  if (zipStatus !== 0) die('build.bat zip failed');
  const packageZip = path.join(ISS_DIR_FULL, 'package.zip');
  if (!fs.existsSync(packageZip) || fs.statSync(packageZip).size < 1024) {
    die('package.zip is missing or empty; offline installer would not contain Windows packages');
  }
  ok('Modules compressed into package.zip'); log('');
  // Compile the current workspace ISS file even when the repository clone is reused.
  fs.copyFileSync(
    path.join(ISS_DIR_ONLINE, 'Kuwa-AIOS-Full-Installer.iss'),
    ISS_FILE_FULL,
  );
  info('Compiling Full installer...'); await compileInstaller(ISS_FILE_FULL); saveOutput(ISS_FILE_FULL);
  log(''); log('============================================'); log('[SUCCESS] Build completed successfully!'); log('============================================');
  log(`Build output folder:    ${OUTPUT_DIR}`); log(`Temporary build folder: ${TEMP_FOLDER}`); log(`Cache folder:           ${CACHE_FOLDER}`); log('');
}

main().catch((e) => { die(String(e)); });
