const test = require('node:test');
const assert = require('node:assert');
const path = require('node:path');

// Mock child_process
const child_process = require('node:child_process');
const originalExecFileSync = child_process.execFileSync;

let mockOutput = {};
child_process.execFileSync = (cmd, args, opts) => {
  if (cmd.endsWith('reg.exe')) {
    const key = args[args.length - 1];
    if (mockOutput[key]) return mockOutput[key];
    throw new Error('Registry key not found');
  }
  return originalExecFileSync(cmd, args, opts);
};

const { configureProxy } = require('../lib/getproxy');

test('configureProxy sets environment variables from registry', (t) => {
  const originalEnv = { ...process.env };

  // Clear existing proxy vars
  delete process.env.http_proxy;
  delete process.env.https_proxy;
  delete process.env.no_proxy;

  mockOutput = {
    'ProxyEnable': 'ProxyEnable REG_DWORD 0x1',
    'ProxyServer': 'ProxyServer REG_SZ 127.0.0.1:8080',
    'ProxyOverride': 'ProxyOverride REG_SZ <local>;*.google.com'
  };

  try {
    configureProxy();
    
    assert.strictEqual(process.env.http_proxy, 'http://127.0.0.1:8080');
    assert.strictEqual(process.env.https_proxy, 'https://127.0.0.1:8080');
    assert.strictEqual(process.env.no_proxy, 'localhost,127.0.0.0/8,*.google.com');
  } finally {
    process.env = originalEnv;
  }
});

test('configureProxy handles protocol-specific settings', (t) => {
  const originalEnv = { ...process.env };

  delete process.env.http_proxy;
  delete process.env.ftp_proxy;

  mockOutput = {
    'ProxyEnable': 'ProxyEnable REG_DWORD 0x1',
    'ProxyServer': 'ProxyServer REG_SZ http=proxy:80;ftp=ftp-proxy:21'
  };

  try {
    configureProxy();
    assert.strictEqual(process.env.http_proxy, 'http://proxy:80');
    assert.strictEqual(process.env.ftp_proxy, 'ftp://ftp-proxy:21');
  } finally {
    process.env = originalEnv;
  }
});

// Restore original child_process.execFileSync after all tests
test.after(() => {
  child_process.execFileSync = originalExecFileSync;
});
