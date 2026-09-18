// Custom module template executor.
module.exports = async function run(api) {
  await api.modelConfig('custom', 'Custom Module');
  api.spawnBg('python', ['worker.py', '--access_code', 'custom']);
};
