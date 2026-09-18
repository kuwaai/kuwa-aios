// executor-runner.js — Worker-thread entry point for running an executor's run.js.
//
// The launcher spawns one worker thread per enabled executor folder. This
// script builds the helper API (executor-api.js), loads the folder's run.js,
// and invokes its exported run(api) function. Background processes started by
// run.js keep the worker alive until they exit; once run() has finished its
// setup phase we notify the launcher with an 'initialized' message so it can proceed
// to model pruning and bot import.

const path = require('path');
const { parentPort, workerData } = require('worker_threads');
const { createApi } = require('./executor-api');
// NOTE: import from bot-selection.js (not executors.js) — executors.js pulls
// in ./context.js (via ./helpers.js), whose process.chdir() call throws when
// required inside a worker thread.
const { importBotsFromDir } = require('./bot-selection');

process.env.PYTHONUTF8 = '1';
process.env.PYTHONIOENCODING = 'utf8';

function finish(error) {
  parentPort.postMessage(error ? { type: 'done', error } : { type: 'done' });
  parentPort.close();
}

// Built-in startup for declarative executors (no run.js). Driven by the
// `executor:` block in run.yaml, mirroring the docker compose configuration.
async function runDeclarative(api, executor) {
  const accessCode = api.accessCode;
  const command = Array.isArray(executor.command) ? executor.command : [];
  const count = executor.count ? parseInt(executor.count, 10) || 1 : 1;

  // Configure the model (via POST /api/models/configure) unless there is no
  // name (some tools launch without a model). `create_bot: false` maps to
  // --do_not_create_bot.
  if (accessCode && executor.name) {
    const doNotCreateBot = String(executor.create_bot).toLowerCase() === 'false';
    await api.modelConfig(accessCode, executor.name, {
      image: executor.image,
      order: executor.order,
      doNotCreateBot,
    });
  }

  // Optionally import a bot definition shipped alongside the executor.
  if (executor.bot) {
    await api.importBot(executor.bot);
  }

  // Optionally import every enabled `.bot` file from a folder shipped
  // alongside the executor (e.g. windows/executors/pipe/bots/), filtered by
  // that folder's bots.yaml selection list. Lets a single executor process
  // (e.g. the shared "Pipe" executor) serve many bots, each configured via
  // its own `.bot` file's `pipe_program`/`pipe_argv` parameters.
  if (executor.bots_dir) {
    const botsDir = path.isAbsolute(executor.bots_dir)
      ? executor.bots_dir
      : path.join(api.folderPath, executor.bots_dir);
    const imported = await importBotsFromDir(botsDir, (f) => api.importBot(f));
    if (imported.length > 0) {
      api.log(`[${api.name}] Imported bots from ${botsDir}: ${imported.join(', ')}`);
    } else {
      api.log(`[${api.name}] No bots selected/found in ${botsDir}`);
    }
  }

  const args = accessCode ? ['--access_code', accessCode, ...command] : [...command];

  // `source` selects a Python source executor under src/executor/<source>;
  // `script` (default main.py) is the Python entry point. Otherwise `type`
  // selects a bundled kuwa-executor type.
  if (executor.source) {
    const script = executor.script || 'main.py';
    const sourceParts = String(executor.source).split('/').filter(Boolean);
    await api.startPython(script, args, { cwd: api.srcExecutor(...sourceParts), count });
  } else {
    const type = executor.type || 'debug';
    await api.startExecutor([type, ...args], { count });
  }
  }


async function main() {
  const api = createApi(workerData, parentPort);
  const { runJsPath, executor, name } = workerData;

  // Relay stdin sent from the launcher UI (via start.js) to the executor's
  // background child processes. Also handle a 'stop' request that gracefully
  // shuts the executor's children down (force-killing after a timeout).
  parentPort.on('message', (msg) => {
    if (!msg) return;
    if (msg.type === 'stdin') {
      try { api.writeStdin(msg.text); } catch {}
    } else if (msg.type === 'stop') {
      // Graceful stop; once children exit, waitForChildren() resolves and the
      // worker finishes naturally (posting 'done').
      try { api.stopChildren(typeof msg.timeoutMs === 'number' ? msg.timeoutMs : 10000); } catch {}
    }
  });

  // Declarative executor (no run.js): drive startup from the executor: block.
  if (!runJsPath) {
    try {
      await runDeclarative(api, executor || {});
    } catch (e) {
      api.log(`[${name}] Error in declarative startup: ${e.message}\n${e.stack || ''}`);
      parentPort.postMessage({ type: 'initialized' });
      finish(e.message);
      return;
    }
    parentPort.postMessage({ type: 'initialized' });
    await api.waitForChildren();
    finish();
    return;
  }


  let mod;
  try {
    mod = require(runJsPath);
  } catch (e) {
    api.log(`[${name}] Failed to load run.js (${runJsPath}): ${e.message}`);
    parentPort.postMessage({ type: 'initialized' });
    finish(e.message);
    return;
  }

  const fn = typeof mod === 'function' ? mod : (mod && mod.run);
  if (typeof fn !== 'function') {
    api.log(`[${name}] run.js does not export a run(api) function.`);
    parentPort.postMessage({ type: 'initialized' });
    finish();
    return;
  }

  try {
    await fn(api);
  } catch (e) {
    api.log(`[${name}] Error in run.js: ${e.message}\n${e.stack || ''}`);
    parentPort.postMessage({ type: 'initialized' });
    finish(e.message);
    return;
  }

  // Setup phase done (model configured, executors started).
  parentPort.postMessage({ type: 'initialized' });

  // Stay alive while background children run so their logs keep streaming.
  await api.waitForChildren();
  finish();
}

main();
