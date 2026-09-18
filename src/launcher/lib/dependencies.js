// dependencies.js — Declarative executor dependency system.
//
// Executors can declare shared dependencies in their run.yaml, e.g.:
//
//   dependencies:
//     - ffmpeg_latest
//
// Each dependency is a module file under src/launcher/dependency/<name_version>.js,
// e.g. dependency/ffmpeg_latest.js. This makes it easy to drop in a custom/private
// dependency without touching this file: only ffmpeg_latest.js (the reference
// template) is tracked in git, everything else in that directory is gitignored.
//
// The launcher (start.js) builds one DependencyManager and asks it to ensure
// each executor's dependencies before that executor's run.js is launched. A
// dependency is downloaded/installed only ONCE: the manager caches the
// in-flight promise per dependency name, so when several executors need the
// same dependency they all wait on the same single download instead of racing
// to fetch it multiple times.

const fs = require('fs');
const path = require('path');
const { spawn } = require('child_process');

// ─── Dependency Registry ─────────────────────────────────────────────────────
//
// Dependency modules live under src/launcher/dependency/<name>.js and export
// `{ ensure(ctx) }`. `ensure(ctx)` receives:
//   cfg  — the build/runtime config (URLs, package folder names)
//   dirs — resolved directories ({ rootDir, packagesDir, ... })
//   run  — async (cmd, args) => exitCode, output streamed to the launcher log
//   log  — (msg) => void
const DEPENDENCY_DIR = path.join(__dirname, '..', 'dependency');

function loadDependency(name) {
  const file = path.join(DEPENDENCY_DIR, `${name}.js`);
  if (!fs.existsSync(file)) return undefined;
  return require(file);
}

// Run a command to completion, streaming its output to the launcher console.
function runStreaming(cmd, args, log) {
  return new Promise((resolve) => {
    const child = spawn(cmd, args, {
      stdio: ['ignore', 'pipe', 'pipe'],
      windowsHide: true,
      env: process.env,
    });
    if (child.stdout) child.stdout.on('data', (d) => process.stdout.write(d.toString('utf-8')));
    if (child.stderr) child.stderr.on('data', (d) => process.stdout.write(d.toString('utf-8')));
    child.on('close', (code) => resolve(code || 0));
    child.on('error', (e) => { if (log) log(`${cmd} failed: ${e.message}`); resolve(1); });
  });
}

// Create a dependency manager bound to the current config/dirs.
function createDependencyManager({ cfg, dirs, log } = {}) {
  const logFn = log || ((m) => console.log(m));
  // name -> Promise that resolves once the dependency is ready. Cached so each
  // dependency is provisioned exactly once across all executors.
  const cache = new Map();
  const run = (cmd, args) => runStreaming(cmd, args, logFn);

  function ensure(name) {
    if (cache.has(name)) return cache.get(name);

    const def = loadDependency(name);
    let promise;
    if (!def) {
      logFn(`Unknown dependency '${name}', skipping.`);
      promise = Promise.resolve();
    } else {
      promise = Promise.resolve()
        .then(() => def.ensure({ cfg: cfg || {}, dirs: dirs || {}, run, log: logFn }))
        .then(() => { logFn(`Dependency '${name}' ready.`); })
        .catch((e) => { logFn(`Dependency '${name}' failed: ${e.message}`); });
    }
    cache.set(name, promise);
    return promise;
  }

  // Ensure all of `names`, deduping against in-flight downloads. If another
  // executor already started a dependency, `requester` simply waits on it.
  function ensureAll(names, requester) {
    if (!names || names.length === 0) return Promise.resolve();
    return Promise.all(names.map((name) => {
      if (cache.has(name)) {
        logFn(`[${requester}] waiting for dependency '${name}'...`);
      }
      return ensure(name);
    }));
  }

  return { ensure, ensureAll };
}

module.exports = { createDependencyManager, loadDependency, DEPENDENCY_DIR };
