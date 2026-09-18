// Setup functions: package extraction, symlink management, temp cleanup.

const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');
const {
  runAndLog, existsInDir, forceRemoveLink, createJunction,
  copyFilesWithoutOverride, syncFiles, rmtree, removeFilesRecursive, parseKeyValueFile,
} = require('./helpers');

// ─── Temp File Cleanup ───────────────────────────────────────────────────────

function cleanupTempBats(executorsDir) {
  console.log('--- Cleaning up temporary run batch files ---');
  removeFilesRecursive(executorsDir, 'temp_run.bat');
}

// ─── Storage Link Management ─────────────────────────────────────────────────

function deleteStorageLinks(rootDir, kuwaRoot) {
  console.log('--- Deleting existing Laravel storage links ---');
  const multiChat = path.resolve(rootDir, 'src', 'multi-chat');

  const links = [
    path.join(multiChat, 'public', 'storage'),
    path.join(multiChat, 'storage', 'app', 'public', 'root', 'custom'),
    path.join(multiChat, 'storage', 'app', 'public', 'root', 'database'),
    path.join(multiChat, 'storage', 'app', 'public', 'root', 'bin'),
    path.join(multiChat, 'storage', 'app', 'public', 'root', 'bootstrap'),
    path.join(kuwaRoot, 'homes'),
    path.join(kuwaRoot, 'custom'),
    path.join(multiChat, 'public', 'frontend'),
  ];

  for (const linkPath of links) {
    const exists = fs.existsSync(linkPath);
    let isLink = false;
    try { isLink = fs.lstatSync(linkPath).isSymbolicLink(); } catch {}

    if (!exists && !isLink) continue;

    const parentDir = path.dirname(linkPath);
    const linkName = path.basename(linkPath);
    if (!existsInDir(parentDir, linkName)) continue;

    try {
      if (forceRemoveLink(linkPath)) {
        console.log(`\u2713 Deleted symlink: ${linkPath}`);
      } else {
        try { fs.rmSync(linkPath, { recursive: true, force: true }); } catch {}

        if (!fs.existsSync(linkPath)) {
          console.log(`\u2713 Deleted symlink: ${linkPath}`);
        } else {
          console.log(`\u26A0 Warning: Could not fully delete ${linkPath}, storage:link will handle it`);
        }
      }
    } catch (e) {
      console.log(`\u26A0 Error deleting ${linkPath}: ${e.message}`);
    }
  }

  console.log('--- Storage links deletion completed ---');
}

function recreateNginxHtmlLink(baseDir, rootDir) {
  console.log('--- Recreating nginx html symlink ---');

  const nginxFolder = process.env.nginx_folder || 'nginx';
  const nginxHtml = path.join(baseDir, 'packages', nginxFolder, 'html');
  const nginxParent = path.dirname(nginxHtml);
  const multiChatPublic = path.resolve(rootDir, 'src', 'multi-chat', 'public');

  if (existsInDir(nginxParent, 'html')) {
    if (!forceRemoveLink(nginxHtml)) {
      console.log(`\u26A0 Could not remove existing ${nginxHtml}, skipping symlink creation`);
      return;
    }
  }

  if (createJunction(multiChatPublic, nginxHtml)) {
    console.log('\u2713 Successfully created nginx html symlink');
  } else {
    console.log(`\u26A0 Failed to create nginx html symlink`);
    console.log(`  Target: ${nginxHtml}`);
    console.log(`  Source: ${multiChatPublic}`);
    console.log(`  nginx_folder="${nginxFolder}", parent exists=${fs.existsSync(nginxParent)}`);
  }
}

function cleanupRootSymlinks(kuwaRoot) {
  console.log('--- Cleaning up Kuwa root symlinks ---');

  for (const linkName of ['homes', 'custom']) {
    const linkPath = path.join(kuwaRoot, linkName);

    if (!existsInDir(kuwaRoot, linkName)) continue;

    forceRemoveLink(linkPath);

    if (!existsInDir(kuwaRoot, linkName)) {
      console.log(`\u2713 Cleaned up symlink: ${linkPath}`);
    } else {
      console.log(`\u26A0 Warning: Could not fully clean ${linkPath}`);
    }
  }

  console.log('--- Kuwa root symlinks cleanup completed ---');
}

// ─── Package Extraction ──────────────────────────────────────────────────────

function extractPackages(baseDir, rootDir, kuwaRoot) {
  const zipPath = path.resolve(rootDir, 'scripts', 'windows-setup-files', 'package.zip');
  const multiChat = path.resolve(rootDir, 'src', 'multi-chat');
  const envFile = path.join(multiChat, '.env');

  // Handle package.zip extraction via build.bat
  if (fs.existsSync(zipPath)) {
    console.log('Extracting all packages...');
    console.log('It may take a couple of minutes. Please wait.');
    const setupDir = path.resolve(rootDir, 'scripts', 'windows-setup-files');
    runAndLog('build.bat restore', setupDir);
    if (fs.existsSync(path.join(baseDir, 'packages', 'composer.phar'))) {
      console.log('Unzipping successful.');
    }
  }

  // Create bootstrap directories
  fs.mkdirSync(path.join(kuwaRoot, 'bootstrap', 'bot'), { recursive: true });
  fs.mkdirSync(path.join(kuwaRoot, 'bin'), { recursive: true });

  // Copy bots and tools
  copyFilesWithoutOverride(
    path.resolve(rootDir, 'src', 'bot', 'init'),
    path.join(kuwaRoot, 'bootstrap', 'bot')
  );
  syncFiles(
    path.resolve(rootDir, 'src', 'tools'),
    path.join(kuwaRoot, 'bin')
  );

  // Delete existing storage links
  deleteStorageLinks(rootDir, kuwaRoot);

  // Clean up root symlinks
  cleanupRootSymlinks(kuwaRoot);

  console.log('--- Recreating Laravel storage links ---');
  if (fs.existsSync(path.join(multiChat, 'vendor', 'autoload.php'))) {
    runAndLog('php artisan storage:link', multiChat);
  } else {
    console.log('--- Skipping storage:link: vendor/autoload.php not found (composer install not yet run) ---');
  }

  // Check pip status
  console.log('--- Checking pip status ---');
  try {
    const pipResult = spawnSync('pip', ['--version'], { shell: false, stdio: 'pipe', timeout: 5000, windowsHide: true });
    if (pipResult.status !== 0) {
      console.log('--- Pip is broken, updating pip ---');
      runAndLog('python -m pip install --force-reinstall pip');
    }
  } catch (e) {
    console.log(`--- Error checking pip: ${e.message}, attempting to upgrade pip ---`);
    runAndLog('python -m pip install --force-reinstall pip');
  }

  // Create .env if it doesn't exist
  if (!fs.existsSync(envFile)) {
    fs.copyFileSync(path.join(multiChat, '.env.dev'), envFile);
  }

  // Clear framework cache
  rmtree(path.join(multiChat, 'storage', 'framework', 'cache'));

  // Ensure database directory
  fs.mkdirSync(path.join(kuwaRoot, 'database'), { recursive: true });

  // Copy tools
  console.log('--- Initializing filesystem and copying tools ---');
  fs.mkdirSync(path.join(kuwaRoot, 'bin'), { recursive: true });
  syncFiles(
    path.resolve(rootDir, 'src', 'tools'),
    path.join(kuwaRoot, 'bin')
  );
  rmtree(path.join(kuwaRoot, 'bin', 'test'));

  // Re-copy tools after test deletion
  syncFiles(
    path.resolve(rootDir, 'src', 'tools'),
    path.join(kuwaRoot, 'bin')
  );

  // Copy bootstrap files
  fs.mkdirSync(path.join(kuwaRoot, 'bootstrap'), { recursive: true });
  copyFilesWithoutOverride(
    path.resolve(rootDir, 'src', 'kernel', 'bootstrap'),
    path.join(kuwaRoot, 'bootstrap')
  );

  // Run artisan setup if package.zip existed
  if (fs.existsSync(zipPath)) {
    console.log('--- Running database and artisan setup commands ---');
    const setupCommands = [
      'php artisan key:generate --force',
      'php artisan db:seed --class=InitSeeder --force',
      'php artisan migrate --force',
      'php artisan storage:link',
      'php ../../windows/packages/composer.phar dump-autoload --optimize',
      'php artisan route:cache',
      'php artisan view:cache',
      'php artisan optimize',
      'pnpm run build',
      'php artisan config:cache',
      'php artisan config:clear',
    ];
    for (const cmd of setupCommands) {
      runAndLog(cmd, multiChat);
    }
    fs.unlinkSync(zipPath);
  }

  // Process init.txt
  const initTxtPath = path.resolve(baseDir, 'init.txt');
  if (fs.existsSync(initTxtPath)) {
    console.log('--- Processing init.txt for admin user creation and auto-login ---');
    const configData = parseKeyValueFile(initTxtPath);

    const username = configData.username || '';
    const password = configData.password || '';
    const autologin = (configData.autologin || '').toLowerCase() === 'true';
    const name = username.includes('@') ? username.split('@')[0] : '';

    if (name && username && password) {
      runAndLog(
        `php artisan create:admin-user --name=${name} --email=${username} --password=${password}`,
        multiChat
      );
    }

    if (autologin && username) {
      let content = fs.readFileSync(envFile, 'utf-8');
      content = content.replace(/^APP_AUTO_EMAIL=.*$/m, '').trim();
      content += `\nAPP_AUTO_EMAIL=${username}\n`;
      fs.writeFileSync(envFile, content, 'utf-8');
      for (const cmd of [
        'php artisan config:clear',
        'php artisan cache:clear',
        'php artisan config:cache',
      ]) {
        runAndLog(cmd, multiChat);
      }
    }
    fs.unlinkSync(initTxtPath);
  }

  // Process init_models.txt
  const initModelsPath = path.join(baseDir, 'src', 'conf', 'init_models.txt');
  if (fs.existsSync(initModelsPath)) {
    spawnSync('python', ['src\\download_hf_models.py', 'src\\conf\\init_models.txt'], {
      cwd: baseDir,
      shell: false,
      stdio: 'inherit',
      windowsHide: true,
    });
    fs.unlinkSync(path.resolve(initModelsPath));
  }

  // Create composer.bat if it doesn't exist
  const composerBat = path.join(baseDir, 'packages', 'composer.bat');
  if (!fs.existsSync(composerBat)) {
    fs.writeFileSync(composerBat, 'php "%~dp0composer.phar" %*\n');
  }
}

module.exports = {
  cleanupTempBats,
  deleteStorageLinks,
  recreateNginxHtmlLink,
  cleanupRootSymlinks,
  extractPackages,
};
