// Extract 7z archives using 7zip-bin (7za).
// Used as a module by download.js AND as a standalone CLI script.

const path = require('path');
const { get7zBinPath } = require('./helpers');

/**
 * Extract a 7z/zip archive using 7za.
 * @param {string} archivePath - Absolute path to the archive file.
 * @param {string} destPath - Absolute path to the extraction destination.
 * @param {function} runner - Callback that runs a shell command: (cmd) => run(cmd)
 * @returns {*} Result of the runner call.
 */
function extract7z(archivePath, destPath, runner) {
  const sevenZa = get7zBinPath();
  return runner(`"${sevenZa}" x "${archivePath}" -o"${destPath}" -y`);
}

module.exports = { extract7z };

// ─── CLI Mode ────────────────────────────────────────────────────────────────
// Usage: node download-7z.js <url> <folderName> <packagesDir>
if (require.main === module) {
  const fs = require('fs');
  const ctx = require('./context');
  const { install7zipBin, logToFile, runAsync, fileExists, ensureDir } = require('./helpers');

  const [url, folderName, packagesDir] = process.argv.slice(2);
  if (!url || !folderName || !packagesDir) {
    console.error('Usage: node download-7z.js <url> <folderName> <packagesDir>');
    process.exit(1);
  }

  const destDir = path.resolve(packagesDir, folderName);
  const filename = path.basename(new URL(url).pathname);
  const archivePath = path.resolve(packagesDir, filename);

  (async () => {
    // Check if already extracted
    if (fileExists(destDir)) {
      try {
        const entries = fs.readdirSync(destDir);
        if (entries.length > 0) {
          logToFile(`"${folderName}" already exists, skipping download and extraction.`);
          return;
        }
      } catch {}
    }

    // Install 7zip-bin if needed
    install7zipBin();

    // Download if not present
    if (!fileExists(archivePath)) {
      logToFile(`Downloading ${filename}...`);
      // Bound the transfer so a stalled connection aborts and retries instead of
      // hanging forever (which would block the calling executor's startup):
      //   --connect-timeout : give up establishing the connection after 30s
      //   --speed-limit/--speed-time : abort if the transfer drops below
      //                                1 KB/s for 30s, then --retry kicks in
      //   --retry-connrefused : also retry when the connection is refused
      const dlCode = await runAsync(
        `curl -L -s --fail --connect-timeout 30 --speed-limit 1024 --speed-time 30 --retry 3 --retry-delay 2 --retry-connrefused -o "${archivePath}" "${url}"`
      );
      if (dlCode !== 0 || !fileExists(archivePath)) {
        console.error(`Download of ${filename} failed.`);
        process.exit(1);
      }
    }

    // Extract
    ensureDir(packagesDir);
    logToFile(`Extracting ${filename}...`);
    const code = await extract7z(archivePath, packagesDir, (cmd) => runAsync(cmd));
    if (code !== 0) {
      console.error(`Extraction of ${filename} failed.`);
      if (fileExists(archivePath)) fs.unlinkSync(archivePath);
      process.exit(1);
    }

    // Verify
    if (fileExists(destDir) && fs.readdirSync(destDir).length > 0) {
      logToFile(`Extracted ${filename} successfully.`);
    } else {
      console.error(`Extraction of ${filename} failed - folder "${folderName}" not found.`);
      if (fileExists(archivePath)) fs.unlinkSync(archivePath);
      process.exit(1);
    }

    // Clean up archive
    if (fileExists(archivePath)) {
      fs.unlinkSync(archivePath);
      logToFile('Cleaned up archive.');
    }
  })().catch(err => {
    console.error(err);
    process.exit(1);
  });
}
