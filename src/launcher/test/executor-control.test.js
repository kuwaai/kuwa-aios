const test = require('node:test');
const assert = require('node:assert');
const {
  isExecutorRunning,
  isExecutorStopped,
  waitForExecutorState,
} = require('../lib/executor-control');

test('executor state treats an unregistered or not-yet-started entry as stopped', () => {
  assert.strictEqual(isExecutorStopped(undefined), true);
  assert.strictEqual(isExecutorStopped({ alive: false, pid: null }), true);
  assert.strictEqual(isExecutorStopped({ alive: true, pid: null }), true);
});

test('executor state does not report a missing process as running', () => {
  const processApi = { kill() { throw new Error('missing process'); } };
  assert.strictEqual(isExecutorRunning({ alive: true, pid: 123 }, processApi), false);
  assert.strictEqual(isExecutorStopped({ alive: true, pid: 123 }, processApi), true);
});

test('waitForExecutorState resolves after the requested transition', async () => {
  let running = false;
  setTimeout(() => { running = true; }, 10);

  const result = await waitForExecutorState(() => running, true, { timeoutMs: 100, intervalMs: 2 });

  assert.strictEqual(result, true);
});

test('waitForExecutorState reports a transition timeout', async () => {
  const result = await waitForExecutorState(() => false, true, { timeoutMs: 10, intervalMs: 2 });

  assert.strictEqual(result, false);
});