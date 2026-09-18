// Migration shim for executors still using an old-style run.bat.
// Drop the legacy run.bat into this folder; this just executes it as-is.
module.exports = async function run(api) {
  api.spawnBg(api.inFolder('run.bat'), [], { shell: true });
};
