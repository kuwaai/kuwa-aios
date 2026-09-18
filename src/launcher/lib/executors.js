// Executor and bot processing: folder scanning, bot import with retries.
//
// Executors are configured by a per-folder run.yaml (copied from the committed
// _run.yaml template) that references a run.js file. Each run.js is executed in
// its own worker thread (see executor-runner.js / executor-api.js).

const fs = require('fs');
const path = require('path');
const { Worker } = require('worker_threads');
const { spawn } = require('child_process');
const { sleep } = require('./helpers');
const { loadSelectionList, importBotsFromDir } = require('./bot-selection');

// ─── Config Parsers ──────────────────────────────────────────────────────────

function parseYamlScalar(raw) {
  let value = (raw || '').trim();
  if (!value) return '';
  if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
    return value.slice(1, -1);
  }
  // Strip a trailing inline comment (" #...") from unquoted scalars.
  const commentIdx = value.search(/\s#/);
  if (commentIdx !== -1) value = value.slice(0, commentIdx).trim();
  return value;
}

// Split an inline YAML flow sequence ("[a, b, c]") into scalar items, honouring
// quotes so values containing commas survive.
function parseInlineList(raw) {
  const items = [];
  let current = '';
  let quote = null;
  for (const ch of raw) {
    if (quote) {
      if (ch === quote) quote = null;
      else current += ch;
      continue;
    }
    if (ch === '"' || ch === "'") { quote = ch; continue; }
    if (ch === ',') { items.push(current.trim()); current = ''; continue; }
    current += ch;
  }
  if (current.trim()) items.push(current.trim());
  return items.map((s) => parseYamlScalar(s)).filter((s) => s !== '');
}

// Parse a run.yaml file. Supported fields:
//   access_code: '<code>'   (optional) — used for model pruning/bot exclusion
//   run: run.js              — (optional) JS module executed in a worker thread.
//                              When omitted, a declarative `executor:` block is
//                              used instead (see below).
//   dependencies:            (optional) — shared dependencies that must be
//     - ffmpeg_latest           provisioned before this executor runs. May also
//                              be written inline: dependencies: [ffmpeg_latest]
//   executor:                (optional) — declarative startup for simple
//                              executors, mirroring docker compose naming:
//     type: chatgpt            #   kuwa-executor <type>   (omit / 'python' to
//                              #   run a Python source executor; see source)
//     source: pipe             #   src/executor/<source>/main.py (Python mode)
//     name: ChatGPT            #   model display name (configured via the API)
//     order: 401100            #   model ordering
//     image: chatgpt.png       #   model image (relative to the folder)
//     create_bot: true         #   create the default bot for the model
//     count: 1                 #   number of executor processes to launch
//     command: ["--temperature", "0.2"]   #   extra CLI args (inline or block)
function parseRunYaml(filePath) {
  const content = fs.readFileSync(filePath, 'utf-8').replace(/^\uFEFF/, '');
  const lines = content.split(/\r?\n/);

  let accessCode = null;
  let runJs = null;
  const dependencies = [];
  const executor = {};
  let hasExecutorBlock = false;
  let inDepsList = false;
  let inExecutor = false;
  let inCommandList = false;
  let commandFlowBuffer = null; // Accumulates a multi-line "[...]" command.

  const indentOf = (line) => (line.match(/^(\s*)/)[1] || '').length;

  for (const line of lines) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#')) continue;

    // Accumulating a multi-line flow sequence: command: [\n  "a",\n  "b",\n]
    if (commandFlowBuffer !== null) {
      const end = trimmed.indexOf(']');
      if (end !== -1) {
        commandFlowBuffer += ' ' + trimmed.slice(0, end);
        executor.command.push(...parseInlineList(commandFlowBuffer));
        commandFlowBuffer = null;
      } else {
        commandFlowBuffer += ' ' + trimmed;
      }
      continue;
    }

    // Inside a "command:" block (under executor:), collect "- arg" items.
    if (inCommandList) {
      const itemMatch = line.match(/^\s+-\s*(.*)$/);
      if (itemMatch) {
        executor.command.push(parseYamlScalar(itemMatch[1]));
        continue;
      }
      inCommandList = false; // Block ended; fall through.
    }

    // Inside the "executor:" block, keys are indented. A non-indented line
    // (that isn't empty/comment) ends the block.
    if (inExecutor) {
      const indent = indentOf(line);
      if (indent > 0) {
        const kv = trimmed.match(/^([A-Za-z0-9_]+)\s*:\s*(.*)$/);
        if (kv) {
          const key = kv[1].toLowerCase();
          const rawVal = kv[2];
          if (key === 'command') {
            executor.command = [];
            const valueOnly = parseYamlScalar(rawVal);
            const inline = valueOnly.match(/^\[(.*)\]$/);
            if (inline) {
              executor.command.push(...parseInlineList(inline[1]));
            } else if (valueOnly.startsWith('[')) {
              // Multi-line flow sequence beginning on this line.
              commandFlowBuffer = valueOnly.slice(1);
            } else if (valueOnly === '') {
              inCommandList = true;
            }
          } else {
            executor[key] = parseYamlScalar(rawVal);
          }
        }
        continue;
      } else {
        inExecutor = false; // De-indented; fall through to top-level handling.
      }
    }

    // Inside a "dependencies:" block, collect "- name" items.
    if (inDepsList) {
      const itemMatch = line.match(/^\s+-\s*(.*)$/);
      if (itemMatch) {
        const value = parseYamlScalar(itemMatch[1]);
        if (value) dependencies.push(value);
        continue;
      }
      inDepsList = false; // Block ended; fall through to other key handling.
    }

    const accessMatch = trimmed.match(/^access_code\s*:\s*(.*)$/i);
    if (accessMatch) {
      accessCode = parseYamlScalar(accessMatch[1]);
      continue;
    }

    const runMatch = trimmed.match(/^run\s*:\s*(.*)$/i);
    if (runMatch) {
      runJs = parseYamlScalar(runMatch[1]);
      continue;
    }

    // Declarative startup block: executor:\n  type: ...\n  name: ...
    if (/^executor\s*:\s*$/i.test(trimmed)) {
      inExecutor = true;
      hasExecutorBlock = true;
      continue;
    }

    // Inline form: dependencies: [a, b]
    const depInline = trimmed.match(/^dependencies\s*:\s*\[(.*)\]\s*$/i);
    if (depInline) {
      parseInlineList(depInline[1]).forEach((d) => dependencies.push(d));
      continue;
    }

    // Block form: dependencies:\n  - a\n  - b
    if (/^dependencies\s*:\s*$/i.test(trimmed)) {
      inDepsList = true;
      continue;
    }
  }

  return {
    accessCode: accessCode || null,
    runJs: runJs || null,
    dependencies,
    executor: hasExecutorBlock ? executor : null,
  };
}

// Copy a default template file (e.g. _run.yaml) to its runtime counterpart
// (run.yaml) when the runtime file does not yet exist. The runtime file is
// gitignored and user-editable; the template is the committed default.
function ensureFileFromTemplate(templatePath, targetPath) {
  try {
    if (fs.existsSync(targetPath)) return;
    if (!fs.existsSync(templatePath)) return;
    fs.copyFileSync(templatePath, targetPath);
    console.log(`Created ${path.basename(targetPath)} from ${path.basename(templatePath)} in ${path.dirname(targetPath)}`);
  } catch (e) {
    console.log(`Error creating ${targetPath} from template: ${e.message}`);
  }
}

// Convert legacy executor folders before the launcher filters the enabled list.
// Existing YAML/JS executors are left untouched so user configuration wins.
function migrateLegacyExecutors(executorsDir) {
  if (!fs.existsSync(executorsDir)) return [];

  const migratorDir = path.join(executorsDir, 'migrate');
  const templateYaml = path.join(migratorDir, '_run.yaml');
  const templateJs = path.join(migratorDir, 'run.js');
  if (!fs.existsSync(templateYaml) || !fs.existsSync(templateJs)) return [];

  const migrated = [];
  for (const entry of fs.readdirSync(executorsDir, { withFileTypes: true })) {
    if (!entry.isDirectory() || entry.name === 'migrate') continue;

    const folderPath = path.join(executorsDir, entry.name);
    const files = fs.readdirSync(folderPath);
    const hasLegacyBatch = files.some((file) => file.toLowerCase().endsWith('.bat'));
    const hasNewConfig = files.some((file) => ['run.yaml', '_run.yaml', 'run.js'].includes(file));
    if (!hasLegacyBatch || hasNewConfig) continue;

    try {
      fs.copyFileSync(templateYaml, path.join(folderPath, '_run.yaml'));
      fs.copyFileSync(templateJs, path.join(folderPath, 'run.js'));
      fs.writeFileSync(
        path.join(folderPath, 'run.yaml'),
        `version: 1\naccess_code: '${entry.name}'\nrun: run.js\n`,
      );
      migrated.push(entry.name);
    } catch (error) {
      console.log(`Could not migrate legacy executor ${entry.name}: ${error.message}`);
    }
  }

  if (migrated.length > 0) {
    console.log(`Migrated legacy executors: ${migrated.join(', ')}`);
  }
  return migrated;
}

function readRunConfig(folderPath) {
  const runYamlPath = path.join(folderPath, 'run.yaml');
  const templatePath = path.join(folderPath, '_run.yaml');

  ensureFileFromTemplate(templatePath, runYamlPath);

  if (fs.existsSync(runYamlPath)) {
    try {
      return { ...parseRunYaml(runYamlPath), source: 'run.yaml' };
    } catch (e) {
      console.log(`Error parsing ${runYamlPath}: ${e.message}`);
    }
  }

  return {
    accessCode: null,
    runJs: null,
    dependencies: [],
    executor: null,
    source: 'none',
  };
}

function loadExecutorSelectionConfig(executorsDir) {
  // The selection config lives in the parent directory (windows/), one level
  // above the executor folders (windows/executors/).
  const configDir = path.dirname(executorsDir);
  const configPath = path.join(configDir, 'config.yaml');
  const templatePath = path.join(configDir, '_config.yaml');

  ensureFileFromTemplate(templatePath, configPath);

  if (!fs.existsSync(configPath)) {
    return null;
  }

  try {
    const lines = fs.readFileSync(configPath, 'utf-8').replace(/^\uFEFF/, '').split(/\r?\n/);
    const enabled = [];
    let inEnabledList = false;

    for (const line of lines) {
      const trimmed = line.trim();
      if (!trimmed || trimmed.startsWith('#')) continue;

      // Top-level array item: "- name"
      const topItem = line.match(/^\s*-\s*(.*)$/);
      if (topItem && !inEnabledList) {
        const value = parseYamlScalar(topItem[1]);
        if (value) enabled.push(value);
        continue;
      }

      // Backward-compatible: "enabled_executors: [a, b]"
      const enabledInline = trimmed.match(/^enabled_executors\s*:\s*\[(.*)\]\s*$/i);
      if (enabledInline) {
        const items = enabledInline[1]
          .split(',')
          .map((s) => parseYamlScalar(s))
          .filter(Boolean);
        enabled.push(...items);
        inEnabledList = false;
        continue;
      }

      // Backward-compatible: "enabled_executors:" followed by a list
      if (/^enabled_executors\s*:\s*$/i.test(trimmed)) {
        inEnabledList = true;
        continue;
      }

      if (inEnabledList) {
        const itemMatch = line.match(/^\s*-\s*(.*)$/);
        if (!itemMatch) {
          inEnabledList = false;
          continue;
        }
        const value = parseYamlScalar(itemMatch[1]);
        if (value) enabled.push(value);
      }
    }

    return enabled;
  } catch (e) {
    console.log(`Error parsing executor selection config: ${e.message}`);
    return null;
  }
}

function listExecutorFolders(executorsDir) {
  let folders = [];
  if (fs.existsSync(executorsDir)) {
    migrateLegacyExecutors(executorsDir);
    folders = fs.readdirSync(executorsDir)
      .filter((f) => fs.statSync(path.join(executorsDir, f)).isDirectory());
  }

  const configured = loadExecutorSelectionConfig(executorsDir);
  if (!configured || configured.length === 0 || configured.includes('*')) {
    return folders.map((f) => path.join(executorsDir, f));
  }

  const folderSet = new Set(folders);
  const selected = configured.filter((name) => folderSet.has(name));
  const missing = configured.filter((name) => !folderSet.has(name));
  if (missing.length > 0) {
    console.log(`Configured executor folders not found: ${missing.join(', ')}`);
  }
  return selected.map((f) => path.join(executorsDir, f));
}

function getBotAccessCode(filePath) {
  try {
    const content = fs.readFileSync(filePath, 'utf-8');
    for (const line of content.split(/\r?\n/)) {
      if (line.trim().toUpperCase().startsWith('KUWABOT BASE')) {
        const match = line.match(/KUWABOT\s+base\s+(.*)/i);
        if (match) {
          return match[1].trim().replace(/^["']|["']$/g, '');
        }
      }
    }
  } catch (e) {
    console.log(`Could not read or parse bot file ${filePath}: ${e.message}`);
  }
  return null;
}

// ─── Folder Processing ───────────────────────────────────────────────────────

// Launch a single executor's run.js inside a worker thread. The worker performs
// the executor's setup (model:config, bot import) and starts its background
// processes (python / kuwa-executor). Returns:
//   excludeCode:  '--exclude=<access_code>' or null (for model:prune / bot import)
//   dependencies: the executor's declared dependency names (may be empty)
//   initialized:  a Promise that resolves once the worker finished its setup
//                 phase (or failed / exited)
//
// Options:
//   gate:     a Promise awaited before the worker is created. Used to hold an
//             executor back until its shared dependencies are provisioned.
//   onWorker: callback invoked with the Worker instance once it is created, so
//             the caller can track it (e.g. for termination on shutdown).
function runExecutorWorker(folderPath, { cfg, dirs, gate, portState, apiBaseUrl, apiToken, onWorker, onLog, onProcess, onProcessExit, onExit } = {}) {
  const name = path.basename(folderPath);
  const runConfig = readRunConfig(folderPath);
  const excludeCode = runConfig.accessCode ? `--exclude=${runConfig.accessCode}` : null;
  const dependencies = runConfig.dependencies || [];

  // Resolve the startup module. A `run.js` (custom logic) takes priority; if it
  // is omitted, a declarative `executor:` block drives a built-in startup.
  let runJsPath = null;
  if (runConfig.runJs) {
    runJsPath = path.isAbsolute(runConfig.runJs)
      ? runConfig.runJs
      : path.join(folderPath, runConfig.runJs);

    if (!fs.existsSync(runJsPath)) {
      const message = `[${name}] run.js not found: ${runJsPath}`;
      console.log(message);
      if (typeof onLog === 'function') {
        try { onLog(`${message}\n`); } catch {}
      }
      return { excludeCode, dependencies, initialized: Promise.resolve() };
    }
  } else if (!runConfig.executor) {
    // Neither a run.js nor a declarative executor block — nothing to launch.
    return { excludeCode, dependencies, initialized: Promise.resolve() };
  }

  const workerData = {
    folderPath,
    runJsPath,
    executor: runConfig.executor || null,
    accessCode: runConfig.accessCode,
    name,
    cfg: cfg || {},
    dirs: { ...dirs, executorDir: folderPath },
    portState: portState || null,
    apiBaseUrl: apiBaseUrl || null,
    apiToken: apiToken || null,
  };

  // Wait for dependencies (the gate) before spawning the worker, then resolve
  // `initialized` once the worker reports its setup phase complete (or exits/fails).
  const initialized = Promise.resolve(gate).then(() => new Promise((resolveInitialized) => {
    let worker;
    try {
      worker = new Worker(path.join(__dirname, 'executor-runner.js'), { workerData });
    } catch (e) {
      console.log(`[${name}] Failed to start worker: ${e.message}`);
      resolveInitialized();
      return;
    }
    if (typeof onWorker === 'function') onWorker(worker);

    worker.on('message', (msg) => {
      if (!msg) return;
      if (msg.type === 'log') {
        process.stdout.write(msg.text);
        if (typeof onLog === 'function') { try { onLog(msg.text); } catch {} }
      } else if (msg.type === 'process') {
        if (typeof onProcess === 'function') { try { onProcess({ pid: msg.pid || null, port: msg.port || null }); } catch {} }
      } else if (msg.type === 'process-exit') {
        if (typeof onProcessExit === 'function') { try { onProcessExit({ pid: msg.pid || null, port: msg.port || null }); } catch {} }
      } else if (msg.type === 'initialized') {
        resolveInitialized();
      } else if (msg.type === 'done') {
        resolveInitialized();
      }
    });
    worker.on('error', (e) => {
      console.log(`[${name}] worker error: ${e.message}`);
      if (typeof onLog === 'function') { try { onLog(`[${name}] worker error: ${e.message}\n`); } catch {} }
      resolveInitialized();
    });
    worker.on('exit', () => { resolveInitialized(); if (typeof onExit === 'function') { try { onExit(); } catch {} } });
  }));

  return { excludeCode, dependencies, initialized };
}

// ─── Bot Import ──────────────────────────────────────────────────────────────

const DEFAULT_FAILURE_KEYWORDS = ['cannot be imported', 'does not exist'];

async function importBot(botFilePath, webPath, { maxRetries = 5, initialDelay = 2, failureKeywords } = {}) {
  const keywords = failureKeywords || DEFAULT_FAILURE_KEYWORDS;
  const name = path.basename(botFilePath);

  for (let attempt = 0; attempt < maxRetries; attempt++) {
    console.log(`--- Importing ${name} (Attempt ${attempt + 1}/${maxRetries}) ---`);
    try {
      // sqlite `busy_timeout`/`journal_mode=wal` (config/database.php) lets
      // concurrent writers wait out short lock contention on their own, so no
      // cross-process lock is needed here anymore.
      const { status, output } = await new Promise((resolve, reject) => {
        const child = spawn('php', ['artisan', 'bot:import', botFilePath], {
          cwd: webPath,
          shell: false,
          stdio: ['ignore', 'pipe', 'pipe'],
          windowsHide: true,
        });
        const chunks = [];
        child.stdout.on('data', (d) => chunks.push(d));
        child.stderr.on('data', (d) => chunks.push(d));
        child.on('error', reject);
        child.on('close', (code) => resolve({ status: code, output: Buffer.concat(chunks).toString('utf-8') }));
      });
      process.stdout.write(output);

      const isSuccess = status === 0 && !keywords.some(
        k => output.toLowerCase().includes(k)
      );

      if (isSuccess) {
        console.log(`--- SUCCESS: Finished import for ${name} ---`);
        return `Success: ${name}`;
      } else {
        console.log(`--- FAILED: Import for ${name} (Exit Code: ${status}) ---`);
      }
    } catch (e) {
      console.log(`--- EXCEPTION while importing ${name}: ${e.message} ---`);
    }

    if (attempt < maxRetries - 1) {
      const delay = initialDelay * Math.pow(2, attempt) + Math.random();
      console.log(`--- Retrying ${name} in ${delay.toFixed(2)} seconds... ---`);
      await sleep(delay * 1000);
    } else {
      console.log(`--- GIVING UP on ${name} after ${maxRetries} attempts. ---`);
      return `Failed: ${name}`;
    }
  }
}

module.exports = {
  getBotAccessCode,
  listExecutorFolders,
  migrateLegacyExecutors,
  readRunConfig,
  runExecutorWorker,
  importBot,
  importBotsFromDir,
  loadSelectionList,
  parseRunYaml,
};

