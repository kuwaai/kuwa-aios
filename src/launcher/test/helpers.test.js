const test = require('node:test');
const assert = require('node:assert');
const path = require('node:path');

const os = require('node:os');
// Mock context before requiring helpers
const contextPath = path.resolve(__dirname, '../lib/context');
require.cache[contextPath] = {
  id: contextPath,
  filename: contextPath,
  loaded: true,
  exports: {
    SCRIPT_DIR: __dirname,
    ROOT_DIR: path.resolve(__dirname, '..'),
    LOG_DIR: path.resolve(__dirname, 'logs'),
    logFd: null,
    activeChildren: new Set(),
    tuiActive: false,
  }
};

const helpers = require('../lib/helpers');

test('commandExists searches explicitly trusted launcher directories', () => {
  const tempDir = require('node:fs').mkdtempSync(path.join(os.tmpdir(), 'kuwa-command-'));
  const commandName = 'kuwa-test-command.cmd';
  const commandPath = path.join(tempDir, commandName);

  try {
    require('node:fs').writeFileSync(commandPath, '@echo off\r\n', 'utf8');
    assert.strictEqual(helpers.commandExists(commandName), false);
    assert.strictEqual(helpers.commandExists(commandName, [tempDir]), true);
  } finally {
    require('node:fs').rmSync(tempDir, { recursive: true, force: true });
  }
});

test('urlFilename extracts filename from URL', () => {
  assert.strictEqual(helpers.urlFilename('https://example.com/file.zip'), 'file.zip');
  assert.strictEqual(helpers.urlFilename('http://test.org/path/to/archive.7z'), 'archive.7z');
});

test('folderFromFilename removes extensions', () => {
  assert.strictEqual(helpers.folderFromFilename('file.zip'), 'file');
  assert.strictEqual(helpers.folderFromFilename('archive.7z'), 'archive');
  assert.strictEqual(helpers.folderFromFilename('portable.7z.exe'), 'portable');
  assert.strictEqual(helpers.folderFromFilename('linux.tar.xz'), 'linux');
  assert.strictEqual(helpers.folderFromFilename('noextension'), 'noextension');
});

test('versionFromFilename extracts version', () => {
  assert.strictEqual(helpers.versionFromFilename('node-v22.22.0-win-x64.zip'), 'v22.22.0');
  assert.strictEqual(helpers.versionFromFilename('noversion'), '');
});

test('parseKeyValueFile parses env-like files', (t) => {
  const fs = require('node:fs');
  const tempFile = path.join(__dirname, 'test.env');
  fs.writeFileSync(tempFile, 'KEY1=VALUE1\nKEY2 = VALUE2\nINVALID LINE');
  
  const data = helpers.parseKeyValueFile(tempFile);
  assert.strictEqual(data['KEY1'], 'VALUE1');
  assert.strictEqual(data['KEY2'], 'VALUE2');
  
  fs.unlinkSync(tempFile);
});
