const fs = require('fs');
const path = require('path');

async function main() {
  const yamlPathInput = process.argv[2];
  if (!yamlPathInput) {
    console.error('Usage: node start-executor.js <path to run.yaml>');
    process.exit(1);
  }

  // Resolve absolute path relative to CWD *before* requiring context.js,
  // because context.js changes the process's working directory.
  const absoluteYamlPath = path.resolve(yamlPathInput);

  // Now we can safely require context.js and other modules
  const { Worker } = require('worker_threads');
  const { initVariables } = require('./lib/config');
  const { configureProxy } = require('./lib/getproxy');
  const { parseRunYaml } = require('./lib/executors');
  const { createPortState } = require('./lib/executor-api');
  const { SCRIPT_DIR: baseDir, ROOT_DIR: rootDir } = require('./lib/context');

  if (!fs.existsSync(absoluteYamlPath)) {
    console.error(`File not found: ${absoluteYamlPath}`);
    process.exit(1);
  }

  const folderPath = path.dirname(absoluteYamlPath);
  const name = path.basename(folderPath);

  configureProxy();
  const cfg = initVariables(false);

  const runConfig = parseRunYaml(absoluteYamlPath);

  let runJsPath = null;
  if (runConfig.runJs) {
    runJsPath = path.isAbsolute(runConfig.runJs)
      ? runConfig.runJs
      : path.join(folderPath, runConfig.runJs);

    if (!fs.existsSync(runJsPath)) {
      console.error(`[${name}] run.js not found: ${runJsPath}`);
      process.exit(1);
    }
  } else if (!runConfig.executor) {
    console.error(`[${name}] Neither a run.js nor a declarative executor block found in ${absoluteYamlPath}`);
    process.exit(1);
  }

  const executorDirs = {
    baseDir,
    rootDir,
    multiChatDir: path.resolve(rootDir, 'src', 'multi-chat'),
    srcExecutorDir: path.resolve(rootDir, 'src', 'executor'),
    packagesDir: path.join(baseDir, 'packages'),
    executorDir: folderPath,
  };

  const workerData = {
    folderPath,
    runJsPath,
    executor: runConfig.executor || null,
    accessCode: runConfig.accessCode,
    name,
    cfg: cfg || {},
    dirs: executorDirs,
    portState: createPortState(),
  };

  console.log(`[${name}] Starting executor from ${absoluteYamlPath}...`);

  const worker = new Worker(path.join(__dirname, 'lib', 'executor-runner.js'), { workerData });

  worker.on('message', (msg) => {
    if (!msg) return;
    if (msg.type === 'log') {
      process.stdout.write(msg.text);
    } else if (msg.type === 'initialized') {
      console.log(`[${name}] Executor is initialized.`);
    } else if (msg.type === 'done') {
      console.log(`[${name}] Executor setup phase finished.`);
      if (msg.error) {
        console.error(`[${name}] Error: ${msg.error}`);
        process.exit(1);
      }
    }
  });

  worker.on('error', (e) => {
    console.error(`[${name}] Worker error: ${e.message}`);
    process.exit(1);
  });

  worker.on('exit', (code) => {
    console.log(`[${name}] Worker exited with code ${code}`);
    process.exit(code);
  });

  // Handle graceful termination
  let isStopping = false;
  const stop = async () => {
    if (isStopping) return;
    isStopping = true;
    console.log(`\n[${name}] Stopping executor...`);
    worker.postMessage({ type: 'stop' });
    
    // Give it a few seconds to shut down gracefully
    const timeout = setTimeout(() => {
      console.log(`[${name}] Graceful shutdown timed out, terminating worker...`);
      worker.terminate();
    }, 5000);

    worker.on('exit', () => {
      clearTimeout(timeout);
    });
  };

  process.on('SIGINT', stop);
  process.on('SIGTERM', stop);
}

main().catch((err) => {
  console.error(`Fatal error: ${err.message}`);
  console.error(err.stack);
  process.exit(1);
});
