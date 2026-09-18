// Built-in dependency: downloads/extracts FFmpeg per cfg.url_ffmpeg / cfg.ffmpeg_folder.
// This file is tracked in git as the reference template for custom dependency modules
// dropped into this directory (see src/launcher/lib/dependencies.js).

const path = require('path');

module.exports = {
  async ensure({ cfg, dirs, run, log }) {
    if (!cfg.url_ffmpeg || !cfg.ffmpeg_folder) {
      log("Dependency 'ffmpeg' skipped: url_ffmpeg/ffmpeg_folder not configured.");
      return;
    }
    const downloadScript = path.join(dirs.rootDir, 'src', 'launcher', 'lib', 'download-7z.js');
    log('Ensuring FFmpeg is available...');
    await run('node', [downloadScript, cfg.url_ffmpeg, cfg.ffmpeg_folder, dirs.packagesDir]);
  },
};
