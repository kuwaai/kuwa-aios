// Terminal/ANSI utilities for output capture and formatting

const fs = require('fs');
const ctx = require('./context');

// ─── ANSI Escape Code Handling ───────────────────────────────────────────────

/**
 * Strip ANSI color and formatting codes from a string
 * @param {string} str - String potentially containing ANSI escape sequences
 * @returns {string} String with ANSI codes removed
 */
function stripAnsi(str) {
  return str.replace(/\x1b\[[0-9;?]*[A-Za-z]/g, '');
}

// ─── Output Mirroring (File Capture) ─────────────────────────────────────────

/**
 * Virtual screen buffer for maintaining console output state while mirroring to file
 * Handles ANSI escape sequences, cursor movement, line clearing, etc.
 */
class ScreenBuffer {
  constructor() {
    this.lines = [''];
    this.row = 0;
  }

  reset() {
    this.lines = [''];
    this.row = 0;
  }

  processChunk(chunk, noColor = false) {
    const str = Buffer.isBuffer(chunk) ? chunk.toString() : String(chunk);
    let i = 0;

    while (i < str.length) {
      if (str[i] === '\x1b' && i + 1 < str.length && str[i + 1] === '[') {
        // Parse ANSI escape: \x1b[<params><command>
        let j = i + 2;
        while (j < str.length && ((str[j] >= '0' && str[j] <= '9') || str[j] === ';' || str[j] === '?')) {
          j++;
        }
        if (j >= str.length) { i = j; break; }
        const params = str.slice(i + 2, j);
        const cmd = str[j];
        j++;

        if (cmd === 'A') {
          // Cursor up N lines
          const n = parseInt(params) || 1;
          this.row = Math.max(0, this.row - n);
        } else if (cmd === 'K') {
          // Erase in line (\x1b[2K = clear entire line)
          while (this.row >= this.lines.length) this.lines.push('');
          this.lines[this.row] = '';
        } else if (cmd === 'h' || cmd === 'l') {
          // Cursor visibility (\x1b[?25h / \x1b[?25l) - skip for file
        } else {
          // Color/style codes - keep unless noColor
          if (!noColor) {
            while (this.row >= this.lines.length) this.lines.push('');
            this.lines[this.row] += str.slice(i, j);
          }
        }
        i = j;
      } else if (str[i] === '\n') {
        this.row++;
        while (this.row >= this.lines.length) this.lines.push('');
        i++;
      } else if (str[i] === '\r') {
        i++; // skip carriage returns
      } else {
        while (this.row >= this.lines.length) this.lines.push('');
        this.lines[this.row] += str[i];
        i++;
      }
    }
  }

  getOutput() {
    return this.lines.join('\n');
  }

  writeToFile(fd) {
    const output = this.getOutput();
    const buf = Buffer.from(output, 'utf8');
    try {
      fs.writeSync(fd, buf, 0, buf.length, 0);
      fs.ftruncateSync(fd, buf.length);
    } catch {}
  }
}

/**
 * Setup output mirroring to capture stdout/stderr to a file while displaying in console
 * Handles ANSI escape codes intelligently
 * @param {string} outputFile - Path to file to write output to
 * @param {boolean} noColor - Whether to strip color codes from file output
 * @returns {object} Object with screenBuffer and cleanup function
 */
function setupOutputMirror(outputFile, noColor = false) {
  let outputFd = null;
  const screenBuffer = new ScreenBuffer();

  // Every stdout/stderr chunk (including the TUI's 80ms spinner ticks) used to
  // trigger a synchronous rewrite+truncate of the WHOLE accumulated buffer.
  // As the build log grows that becomes an increasingly expensive blocking
  // call, freezing the event loop (and the visible spinner) for longer and
  // longer stretches. Coalesce bursts into a single async flush instead.
  let flushTimer = null;
  let flushPending = false;
  let flushInFlight = false;

  function doFlush() {
    if (outputFd === null || flushInFlight) return;
    flushInFlight = true;
    const buf = Buffer.from(screenBuffer.getOutput(), 'utf8');
    fs.write(outputFd, buf, 0, buf.length, 0, (err) => {
      if (err) { flushInFlight = false; return; }
      fs.ftruncate(outputFd, buf.length, () => {
        flushInFlight = false;
        if (flushPending) { flushPending = false; scheduleFlush(); }
      });
    });
  }

  function scheduleFlush() {
    if (flushTimer) { flushPending = true; return; }
    flushTimer = setTimeout(() => {
      flushTimer = null;
      doFlush();
    }, 200);
  }

  function mirrorToFile(chunk, encoding) {
    if (outputFd === null) return;
    screenBuffer.processChunk(chunk, noColor);
    scheduleFlush();
  }

  try {
    outputFd = fs.openSync(outputFile, 'w');

    const realOrigStdoutWrite = ctx.origStdoutWrite;
    const realOrigStderrWrite = ctx.origStderrWrite;

    // Wrap process.stdout.write
    const currentStdoutWrite = process.stdout.write.bind(process.stdout);
    process.stdout.write = function (chunk, encoding, callback) {
      mirrorToFile(chunk, encoding);
      return currentStdoutWrite(chunk, encoding, callback);
    };

    // Wrap process.stderr.write
    const currentStderrWrite = process.stderr.write.bind(process.stderr);
    process.stderr.write = function (chunk, encoding, callback) {
      mirrorToFile(chunk, encoding);
      return currentStderrWrite(chunk, encoding, callback);
    };

    // Wrap ctx.origStdoutWrite (TUI direct writes)
    ctx.origStdoutWrite = function (chunk, encoding, callback) {
      mirrorToFile(chunk, encoding);
      return realOrigStdoutWrite(chunk, encoding, callback);
    };

    ctx.origStderrWrite = function (chunk, encoding, callback) {
      mirrorToFile(chunk, encoding);
      return realOrigStderrWrite(chunk, encoding, callback);
    };

    process.on('exit', () => {
      cleanup();
    });
  } catch (err) {
    throw new Error(`Failed to setup output mirror: ${err.message}`);
  }

  function cleanup() {
    if (flushTimer) { clearTimeout(flushTimer); flushTimer = null; }
    if (outputFd !== null) {
      screenBuffer.writeToFile(outputFd); // final sync flush so no output is lost on exit
      try { fs.closeSync(outputFd); } catch {}
      outputFd = null;
    }
  }

  function reset() {
    screenBuffer.reset();
    flushPending = false;
    if (outputFd !== null) {
      try { fs.ftruncateSync(outputFd, 0); } catch {}
    } else if (outputFile) {
      try { outputFd = fs.openSync(outputFile, 'w'); } catch {}
    }
  }

  return { screenBuffer, cleanup, reset };
}

module.exports = {
  stripAnsi,
  ScreenBuffer,
  setupOutputMirror,
};
