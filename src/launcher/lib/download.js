// Download and extract archives (sync and async variants).

const fs = require('fs');
const path = require('path');
const ctx = require('./context');
const { logToFile, log, run, runAsync, ensureDir, fileExists } = require('./helpers');
const { extract7z } = require('./download-7z');

function downloadExtract(url, checkLocation, extractDest, archiveName) {
  const absCheck = path.resolve(ctx.SCRIPT_DIR, checkLocation);
  const absArchive = path.resolve(ctx.SCRIPT_DIR, 'packages', archiveName);
  const absExtractDest = path.resolve(ctx.SCRIPT_DIR, extractDest);

  if (fileExists(absCheck)) {
    log(`"${checkLocation}" already exists, skipping download and extraction.`);
    return;
  }

  if (!fileExists(absArchive)) {
    log(`File "packages/${archiveName}" does not exist. Downloading now.`);
    run(`curl -L -# -o "${absArchive}" "${url}"`);
  }

  ensureDir(absExtractDest);
  if (archiveName.endsWith('.tar.xz')) {
    log(`Extracting packages/${archiveName}...`);
    run(`tar -xf "${absArchive}" -C "${absExtractDest}"`);
  } else if (archiveName.endsWith('.7z.exe')) {
    log(`Extracting packages/${archiveName}...`);
    extract7z(absArchive, absExtractDest, (cmd) => run(cmd));
  } else {
    log(`Extracting packages/${archiveName}...`);
    extract7z(absArchive, absExtractDest, (cmd) => run(cmd));
  }

  if (fileExists(absCheck)) {
    const entries = fs.readdirSync(absCheck);
    if (entries.length > 0) {
      log('Unzipping successful.');
    }
  } else {
    log(`Can't find ${checkLocation}`);
    log('Unzipping failed.');
    if (fileExists(absCheck)) {
      fs.rmSync(absCheck, { recursive: true, force: true });
    }
  }

  if (fileExists(absArchive)) {
    fs.unlinkSync(absArchive);
    log('Cleaning up...');
  }
}

async function downloadExtractAsync(url, checkLocation, extractDest, archiveName) {
  const absCheck = path.resolve(ctx.SCRIPT_DIR, checkLocation);
  const absArchive = path.resolve(ctx.SCRIPT_DIR, 'packages', archiveName);
  const absExtractDest = path.resolve(ctx.SCRIPT_DIR, extractDest);

  // Check if already extracted — verify directory actually has content
  if (fileExists(absCheck)) {
    try {
      const stat = fs.statSync(absCheck);
      if (!stat.isDirectory() || fs.readdirSync(absCheck).length > 0) {
        logToFile(`"${checkLocation}" already exists, skipping.`);
        return 'skipped';
      }
    } catch {}
  }

  if (!fileExists(absArchive)) {
    logToFile(`Downloading packages/${archiveName}...`);
    const dlCode = await runAsync(
      `curl -L -s --fail --connect-timeout 30 --speed-limit 1024 --speed-time 30 --retry 3 --retry-delay 2 --retry-connrefused -o "${absArchive}" "${url}"`
    );
    if (dlCode !== 0 || !fileExists(absArchive)) {
      logToFile(`Download of ${archiveName} failed (exit code ${dlCode}).`);
      if (fileExists(absArchive)) fs.unlinkSync(absArchive);
      return 'failed';
    }
    // Sanity-check: reject suspiciously small files (likely error pages)
    try {
      const size = fs.statSync(absArchive).size;
      if (size < 1024) {
        logToFile(`Download of ${archiveName} is too small (${size} bytes), likely an error page.`);
        fs.unlinkSync(absArchive);
        return 'failed';
      }
    } catch {}
  }

  ensureDir(absExtractDest);
  if (archiveName.endsWith('.tar.xz')) {
    logToFile(`Extracting packages/${archiveName}...`);
    await runAsync(`tar -xf "${absArchive}" -C "${absExtractDest}"`);
  } else if (archiveName.endsWith('.7z.exe')) {
    logToFile(`Extracting packages/${archiveName}...`);
    await extract7z(absArchive, absExtractDest, (cmd) => runAsync(cmd));
  } else {
    logToFile(`Extracting packages/${archiveName}...`);
    await extract7z(absArchive, absExtractDest, (cmd) => runAsync(cmd));
  }

  // Verify extraction — directory must exist and have content
  let extractOk = false;
  if (fileExists(absCheck)) {
    try {
      const stat = fs.statSync(absCheck);
      extractOk = stat.isDirectory() ? fs.readdirSync(absCheck).length > 0 : true;
    } catch {}
  }

  if (extractOk) {
    logToFile(`Extracted ${archiveName} successfully.`);
  } else {
    logToFile(`Extraction of ${archiveName} failed.`);
    if (fileExists(absArchive)) fs.unlinkSync(absArchive);
    try {
      const entries = fs.readdirSync(absCheck);
      if (entries.length === 0) fs.rmdirSync(absCheck);
    } catch {}
    return 'failed';
  }

  if (fileExists(absArchive)) {
    fs.unlinkSync(absArchive);
  }

  return 'done';
}

module.exports = { downloadExtract, downloadExtractAsync };
