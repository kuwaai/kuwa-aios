// Migration shim for executors still using an old-style run.bat.
// The launcher copies this into legacy executor folders before startup.
module.exports = async function run(api) {
  const fs = require('fs');
  const path = require('path');
  const batchFile = fs.readdirSync(api.dirs.executorDir)
    .filter((file) => file.toLowerCase().endsWith('.bat'))
    .sort((left, right) => {
      if (left.toLowerCase() === 'run.bat') return -1;
      if (right.toLowerCase() === 'run.bat') return 1;
      return left.localeCompare(right);
    })[0];

  if (!batchFile) {
    api.log('No legacy batch file found.');
    return;
  }

  api.spawnBg(path.join(api.dirs.executorDir, batchFile), [], { shell: true });
};
