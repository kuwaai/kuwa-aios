const test = require('node:test');
const assert = require('node:assert');
const process = require('node:process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const { Worker } = require('node:worker_threads');
const { createApi } = require('../lib/executor-api');

function waitForWorkerMessage(worker, type) {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error(`Timed out waiting for worker message: ${type}`)), 5000);
    const onMessage = (message) => {
      if (message?.type !== type) return;
      clearTimeout(timer);
      worker.off('message', onMessage);
      resolve(message);
    };
    worker.on('message', onMessage);
  });
}

function findFreePort() {
  return new Promise((resolve, reject) => {
    const server = net.createServer();
    server.once('error', reject);
    server.listen(0, '127.0.0.1', () => {
      const { port } = server.address();
      server.close(() => resolve(port));
    });
  });
}

test('stopChildren force-kills a running child process', async () => {
  const messages = [];
  const api = createApi({
    folderPath: process.cwd(),
    accessCode: '.test/executor',
    name: 'test-executor',
    cfg: {},
    dirs: {},
    apiBaseUrl: null,
    apiToken: null,
  }, { postMessage(message) { messages.push(message); } });
  const child = api.spawnBg(process.execPath, ['-e', 'setInterval(() => {}, 1000)']);
  await new Promise((resolve) => child.once('spawn', resolve));
  const exitPromise = new Promise((resolve) => child.once('exit', () => resolve(true)));

  try {
    assert.ok(child.pid > 0);
    assert.strictEqual(process.kill(child.pid, 0), true);
    assert.ok(messages.some((message) => message.type === 'process' && message.pid === child.pid));

    await api.stopChildren(500);
    const exited = await Promise.race([exitPromise, new Promise((resolve) => setTimeout(() => resolve(false), 1000))]);
    assert.strictEqual(exited, true);
    assert.throws(() => process.kill(child.pid, 0));
    assert.ok(messages.some((message) => message.type === 'process-exit' && message.pid === child.pid));
  } finally {
    if (!child.killed && child.exitCode === null) child.kill();
  }
});

test('stopChildren calls the executor shutdown endpoint before killing', async () => {
  const port = await findFreePort();
  const marker = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'kuwa-shutdown-')), 'unregistered');
  const script = [
    "const http = require('http');",
    `const marker = ${JSON.stringify(marker)};`,
    `const server = http.createServer((req, res) => { if (req.url === '/shutdown') { require('fs').writeFileSync(marker, 'called'); res.end('ok'); setTimeout(() => server.close(() => process.exit(0)), 25); return; } res.statusCode = 404; res.end(); });`,
    `setTimeout(() => server.listen(${port}, '127.0.0.1'), 250);`,
  ].join('');
  const api = createApi({
    folderPath: process.cwd(),
    accessCode: '.test/shutdown',
    name: 'shutdown-test',
    cfg: {},
    dirs: {},
    apiBaseUrl: null,
    apiToken: null,
  }, { postMessage() {} });
  const child = api.spawnBg(process.execPath, ['-e', script]);
  child._executorPort = port;
  await new Promise((resolve) => child.once('spawn', resolve));

  try {
    await api.sleep(100);
    await api.stopChildren(2000);
    assert.strictEqual(fs.readFileSync(marker, 'utf8'), 'called');
    assert.throws(() => process.kill(child.pid, 0));
  } finally {
    if (!child.killed && child.exitCode === null) child.kill();
    fs.rmSync(path.dirname(marker), { recursive: true, force: true });
  }
});

test('executor worker exits after stop so a new worker can start', async () => {
  const folderPath = fs.mkdtempSync(path.join(os.tmpdir(), 'kuwa-executor-worker-'));
  const runJsPath = path.join(folderPath, 'run.js');
  fs.writeFileSync(runJsPath, `module.exports = async (api) => { api.spawnBg(process.execPath, ['-e', 'setInterval(() => {}, 1000)']); };`);
  const workerPath = path.resolve(__dirname, '../lib/executor-runner.js');
  const workerData = {
    folderPath,
    runJsPath,
    executor: null,
    accessCode: '.test/worker',
    name: 'test-worker',
    cfg: {},
    dirs: {},
    apiBaseUrl: null,
    apiToken: null,
  };
  const startWorker = () => new Worker(workerPath, { workerData });
  const first = startWorker();
  const processMessage = await waitForWorkerMessage(first, 'process');
  const firstDone = waitForWorkerMessage(first, 'done');
  const firstExit = new Promise((resolve) => first.once('exit', resolve));

  try {
    assert.strictEqual(process.kill(processMessage.pid, 0), true);
    first.postMessage({ type: 'stop', timeoutMs: 100 });
    await firstDone;
    await firstExit;
    assert.throws(() => process.kill(processMessage.pid, 0));

    const second = startWorker();
    const secondProcess = await waitForWorkerMessage(second, 'process');
    const secondDone = waitForWorkerMessage(second, 'done');
    const secondExit = new Promise((resolve) => second.once('exit', resolve));
    try {
      assert.strictEqual(process.kill(secondProcess.pid, 0), true);
      second.postMessage({ type: 'stop', timeoutMs: 100 });
      await secondDone;
      await secondExit;
      assert.throws(() => process.kill(secondProcess.pid, 0));
    } finally {
      await second.terminate();
    }
  } finally {
    await first.terminate();
    fs.rmSync(folderPath, { recursive: true, force: true });
  }
});

test('spawnBg logs the complete command and working directory', async () => {
  const messages = [];
  const folderPath = fs.mkdtempSync(path.join(os.tmpdir(), 'kuwa-command-log-'));
  const api = createApi({
    folderPath,
    accessCode: '.test/logging',
    name: 'logging-test',
    cfg: {},
    dirs: {},
    apiBaseUrl: null,
    apiToken: null,
  }, { postMessage(message) { messages.push(message); } });
  const child = api.spawnBg(process.execPath, ['-e', 'process.exit(0)']);

  try {
    await new Promise((resolve) => child.once('exit', resolve));
    const log = messages
      .filter((message) => message.type === 'log')
      .map((message) => message.text)
      .join('');
    assert.match(log, new RegExp(`cwd=${folderPath.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`));
    assert.match(log, /\(bg\).*node.*-e.*process\.exit\(0\)/);
  } finally {
    fs.rmSync(folderPath, { recursive: true, force: true });
  }
});