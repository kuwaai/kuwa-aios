function isProcessAlive(pid, processApi = process) {
  if (!pid) return false;
  try { processApi.kill(Number(pid), 0); return true; } catch { return false; }
}

function isExecutorRunning(entry, processApi = process) {
  return !!entry?.alive && !!entry?.pid && isProcessAlive(entry.pid, processApi);
}

function isExecutorStopped(entry, processApi = process) {
  if (!entry || !entry.alive || !entry.pid) return true;
  return !isProcessAlive(entry.pid, processApi);
}

function waitForExecutorState(readState, expected, { timeoutMs = 15000, intervalMs = 100 } = {}) {
  return new Promise((resolve) => {
    const deadline = Date.now() + timeoutMs;
    const check = () => {
      if (readState() === expected) {
        resolve(true);
        return;
      }
      if (Date.now() >= deadline) {
        resolve(false);
        return;
      }
      setTimeout(check, intervalMs);
    };
    check();
  });
}

module.exports = {
  isExecutorRunning,
  isExecutorStopped,
  waitForExecutorState,
};