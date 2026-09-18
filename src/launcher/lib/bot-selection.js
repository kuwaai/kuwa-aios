// bot-selection.js — Folder-local `.bot` selection lists (e.g. a `bots/`
// folder shipped alongside an executor). Kept dependency-free (fs/path only)
// so it is safe to require from inside a worker thread — unlike executors.js,
// which pulls in ./context.js (via ./helpers.js), whose process.chdir() call
// throws when run outside the main thread.

const fs = require('fs');
const path = require('path');

function parseYamlScalar(raw) {
  let value = (raw || '').trim();
  if (!value) return '';
  if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
    return value.slice(1, -1);
  }
  const commentIdx = value.search(/\s#/);
  if (commentIdx !== -1) value = value.slice(0, commentIdx).trim();
  return value;
}

// Copy a default template file (e.g. _bots.yaml) to its runtime counterpart
// (bots.yaml) when the runtime file does not yet exist. The runtime file is
// gitignored and user-editable; the template is the committed default.
function ensureFileFromTemplate(templatePath, targetPath) {
  try {
    if (fs.existsSync(targetPath)) return;
    if (!fs.existsSync(templatePath)) return;
    fs.copyFileSync(templatePath, targetPath);
  } catch {
    // Best-effort; caller falls back to "no selection file" behavior.
  }
}

// Same convention as windows/config.yaml / _config.yaml: a committed
// `_<baseName>.yaml` template lists the shipped defaults; the runtime
// `<baseName>.yaml` (gitignored, user-editable) is copied from it on first
// run and is what's actually read. Returns null if no list file could be
// created/read (caller should then fall back to "select everything").
function loadSelectionList(dir, baseName) {
  const configPath = path.join(dir, `${baseName}.yaml`);
  const templatePath = path.join(dir, `_${baseName}.yaml`);

  ensureFileFromTemplate(templatePath, configPath);

  if (!fs.existsSync(configPath)) return null;

  try {
    const lines = fs.readFileSync(configPath, 'utf-8').replace(/^\uFEFF/, '').split(/\r?\n/);
    const selected = [];
    for (const line of lines) {
      const trimmed = line.trim();
      if (!trimmed || trimmed.startsWith('#')) continue;
      const match = line.match(/^\s*-\s*(.*)$/);
      if (match) {
        const value = parseYamlScalar(match[1]);
        if (value) selected.push(value);
      }
    }
    return selected;
  } catch {
    return null;
  }
}

// Import every `.bot` file inside `botsDir`, optionally filtered by a
// `bots.yaml` selection list (see loadSelectionList above) living in botsDir's
// PARENT folder (e.g. windows/executors/pipe/bots.yaml selects among
// windows/executors/pipe/bots/*.bot) — kept one level up so `bots/` only ever
// contains `.bot` files. A missing/empty selection (or a literal '*' entry)
// imports every `.bot` file found. `importFn` is called with each selected
// file's absolute path; returns the list of imported filenames.
async function importBotsFromDir(botsDir, importFn) {
  if (!fs.existsSync(botsDir)) return [];
  const allBots = fs.readdirSync(botsDir).filter((f) => f.endsWith('.bot')).sort();
  const selection = loadSelectionList(path.dirname(botsDir), 'bots');
  const names = (!selection || selection.length === 0 || selection.includes('*'))
    ? allBots
    : selection.filter((n) => allBots.includes(n));

  for (const n of names) {
    await importFn(path.join(botsDir, n));
  }
  return names;
}

module.exports = { loadSelectionList, importBotsFromDir, ensureFileFromTemplate };
