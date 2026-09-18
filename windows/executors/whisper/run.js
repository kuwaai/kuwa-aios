// Whisper speech-recognition executor.
// FFmpeg (required by torchcodec) is provisioned by the launcher's dependency
// system — see `dependencies: [ffmpeg]` in run.yaml — before this runs. Here we
// only copy the FFmpeg DLLs next to torchcodec, which needs them on Windows.
const fs = require('fs');
const path = require('path');

module.exports = async function run(api) {
  const { cfg, dirs } = api;

  if (cfg.ffmpeg_folder) {
    try {
      const torchcodecDir = path.join(dirs.packagesDir, cfg.python_folder, 'Lib', 'site-packages', 'torchcodec');
      const ffmpegBin = path.join(dirs.packagesDir, cfg.ffmpeg_folder, 'bin');
      const hasAvcodec = fs.existsSync(torchcodecDir)
        && fs.readdirSync(torchcodecDir).some((f) => /^avcodec.*\.dll$/i.test(f));
      if (fs.existsSync(torchcodecDir) && fs.existsSync(ffmpegBin) && !hasAvcodec) {
        api.log('Copying FFmpeg DLLs into torchcodec...');
        for (const f of fs.readdirSync(ffmpegBin)) {
          if (f.toLowerCase().endsWith('.dll') || f.toLowerCase() === 'ffmpeg.exe') {
            fs.copyFileSync(path.join(ffmpegBin, f), path.join(torchcodecDir, f));
          }
        }
      }
    } catch (e) {
      api.log('FFmpeg DLL copy skipped: ' + e.message);
    }
  }

  await api.modelConfig('.model/openai/whisper', 'Local Whisper', { image: 'whisper.png', order: 130001 });
  await api.startPython('main.py', [
    '--access_code', '.model/openai/whisper',
    '--model', api.inFolder('src', 'Breeze-ASR-25-ct2'),
    '--log', 'debug',
  ], {
    cwd: api.srcExecutor('speech_recognition'),
  });
};
