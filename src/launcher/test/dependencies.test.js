const test = require('node:test');
const assert = require('node:assert');
const path = require('node:path');
const fs = require('node:fs');

const { createDependencyManager, DEPENDENCY_DIR } = require('../lib/dependencies');

test('DependencyManager caches and dedupes dependencies', async (t) => {
  const testDepName = '_test_mock_dep';
  const testDepFile = path.join(DEPENDENCY_DIR, `${testDepName}.js`);
  
  let callCount = 0;
  global._test_mock_dep_calls = 0;
  
  fs.writeFileSync(testDepFile, `
    module.exports = {
      ensure: async () => {
        global._test_mock_dep_calls++;
        await new Promise(resolve => setTimeout(resolve, 10));
      }
    };
  `);

  const dm = createDependencyManager({
    log: () => {},
  });

  try {
    // Run multiple times in parallel
    await Promise.all([
      dm.ensure(testDepName),
      dm.ensure(testDepName),
      dm.ensureAll([testDepName], 'requester')
    ]);

    assert.strictEqual(global._test_mock_dep_calls, 1, 'ensure should only be called once');
  } finally {
    if (fs.existsSync(testDepFile)) fs.unlinkSync(testDepFile);
    delete require.cache[require.resolve(testDepFile)];
    delete global._test_mock_dep_calls;
  }
});

test('DependencyManager handles unknown dependencies gracefully', async () => {
  const messages = [];
  const dm = createDependencyManager({
    log: (m) => messages.push(m),
  });

  await dm.ensure('non-existent');
  assert.ok(messages.some(m => m.includes("Unknown dependency 'non-existent'")));
});
