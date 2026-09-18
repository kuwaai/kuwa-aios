// Doc QA / Web QA executor (migrated from the legacy ipex-llm run.bat).
// A single docqa.py process serves two models/access codes at once
// (document QA and web QA), so this needs custom run.js instead of the
// simple declarative `executor:` block (which only supports one access code).
module.exports = async function run(api) {
  await api.modelConfig('web_qa', 'Web QA', { image: 'webQA.png' });
  await api.modelConfig('doc_qa', 'Document QA', { image: 'docQA.png' });

  api.startPython('docqa.py', [
    '--access_code', 'web_qa', 'doc_qa',
    '--model', 'taide',
    '--mmr_k', '6',
    '--mmr_fetch_k', '12',
    '--limit', '3072',
  ], {
    cwd: api.srcExecutor('docqa'),
  });
};
