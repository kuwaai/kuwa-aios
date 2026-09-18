const test = require('node:test');
const assert = require('node:assert');
const { stripAnsi, ScreenBuffer } = require('../lib/terminal-utils');

test('stripAnsi removes color codes', () => {
  const input = '\x1b[31mRed Text\x1b[0m and \x1b[1mBold\x1b[0m';
  assert.strictEqual(stripAnsi(input), 'Red Text and Bold');
});

test('ScreenBuffer processes simple text', () => {
  const sb = new ScreenBuffer();
  sb.processChunk('Hello\nWorld');
  assert.strictEqual(sb.getOutput(), 'Hello\nWorld');
});

test('ScreenBuffer processes ANSI cursor movement', () => {
  const sb = new ScreenBuffer();
  sb.processChunk('Line 1\nLine 2\nLine 3');
  assert.strictEqual(sb.row, 2);
  
  // Move up 1 line
  sb.processChunk('\x1b[1A');
  assert.strictEqual(sb.row, 1);
  
  // Erase line 2
  sb.processChunk('\x1b[2K');
  assert.strictEqual(sb.lines[1], '');
});

test('ScreenBuffer handles carriage returns (skips them)', () => {
  const sb = new ScreenBuffer();
  sb.processChunk('Line 1\r\nLine 2');
  assert.strictEqual(sb.getOutput(), 'Line 1\nLine 2');
});

test('ScreenBuffer handles color codes (keeps them by default)', () => {
  const sb = new ScreenBuffer();
  const colored = '\x1b[31mRed\x1b[0m';
  sb.processChunk(colored);
  assert.strictEqual(sb.getOutput(), colored);
});

test('ScreenBuffer can strip color codes during processing', () => {
  const sb = new ScreenBuffer();
  const colored = '\x1b[31mRed\x1b[0m';
  sb.processChunk(colored, true); // noColor = true
  assert.strictEqual(sb.getOutput(), 'Red');
});
