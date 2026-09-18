// Terminal UI: spinner, progress display, runSection orchestrator.

const ctx = require('./context');
const { logToFile } = require('./helpers');

function formatTime(ms) {
  if (ms < 1000) return `${ms}ms`;
  const s = ms / 1000;
  if (s < 60) return `${s.toFixed(1)}s`;
  const m = Math.floor(s / 60);
  const rs = Math.round(s % 60);
  return `${m}m${rs}s`;
}

class TUI {
  constructor(options = {}) {
    this.noColor = options.noColor || false;
    this.currentSection = '';
    this.jobs = [];
    this.spinnerFrame = 0;
    this.spinnerChars = ['◜', '◠', '◝', '◞', '◡', '◟'];
    this.barFrames = this.noColor ? [
      '░▒▓█▓▒░',
      '▒▓█▓▒░░',
      '▓█▓▒░░▒',
      '█▓▒░░▒▓',
      '▓▒░░▒▓█',
      '▒░░▒▓█▓',
      '░░▒▓█▓▒',
      '░▒▓█▓▒░',
    ] : [
      '\x1b[36m░▒▓█▓▒░\x1b[0m',
      '\x1b[36m▒▓█▓▒░░\x1b[0m',
      '\x1b[36m▓█▓▒░░▒\x1b[0m',
      '\x1b[36m█▓▒░░▒▓\x1b[0m',
      '\x1b[36m▓▒░░▒▓█\x1b[0m',
      '\x1b[36m▒░░▒▓█▓\x1b[0m',
      '\x1b[36m░░▒▓█▓▒\x1b[0m',
      '\x1b[36m░▒▓█▓▒░\x1b[0m',
    ];
    this.interval = null;
    this.lineCount = 0;
    this.startTime = Date.now();
    this.sectionStart = Date.now();
    this.jobGroups = [];
    this._renderFn = null;
  }

  _color(code, text, reset = true) {
    if (this.noColor) return text;
    return `${code}${text}${reset ? '\x1b[0m' : ''}`;
  }

  banner(title) {
    const line = '\u2500'.repeat(50);
    ctx.origStdoutWrite(`\n${this._color('\x1b[36m', `  ${line}`)}\n`);
    ctx.origStdoutWrite(`${this._color('\x1b[1m\x1b[36m', `  ${title}`)}\n`);
    ctx.origStdoutWrite(`${this._color('\x1b[36m', `  ${line}`)}\n\n`);
    logToFile(title);
  }

  printInfo(msg) {
    ctx.origStdoutWrite(`${this._color('\x1b[90m', `  \u25B8 ${msg}`)}\n`);
    logToFile(msg);
  }

  sectionDone(name, elapsed) {
    const checkmark = this._color('\x1b[32m', `  \u2714 ${name}`);
    const time = this._color('\x1b[90m', `(${elapsed})`);
    ctx.origStdoutWrite(`${checkmark} ${time}\n`);
    logToFile(`\u2714 ${name} (${elapsed})`);
  }

  setSection(name) {
    this.currentSection = name;
    this.sectionStart = Date.now();
    this.jobs = [];
  }

  addJob(id, label) {
    this.jobs.push({ id, label, status: 'queued', elapsed: null, startTime: null });
  }

  updateJob(id, status) {
    const job = this.jobs.find(j => j.id === id);
    if (!job) return;
    if (status === 'running' && !job.startTime) job.startTime = Date.now();
    if (status === 'done' || status === 'failed' || status === 'skipped') {
      job.elapsed = job.startTime ? formatTime(Date.now() - job.startTime) : '0s';
    }
    job.status = status;
  }

  startRendering(renderFn) {
    this._renderFn = renderFn || (() => this.render());
    ctx.tuiActive = true;
    ctx.origStdoutWrite('\x1b[?25l'); // hide cursor
    this.lineCount = 0;
    this._renderFn();
    this.interval = setInterval(() => {
      this.spinnerFrame = (this.spinnerFrame + 1) % this.spinnerChars.length;
      this._renderFn();
    }, 80);
  }

  stopRendering() {
    if (this.interval) {
      clearInterval(this.interval);
      this.interval = null;
    }
    if (this.lineCount > 0) {
      ctx.origStdoutWrite(`\x1b[${this.lineCount}A`);
      for (let i = 0; i < this.lineCount; i++) {
        ctx.origStdoutWrite('\x1b[2K\n');
      }
      ctx.origStdoutWrite(`\x1b[${this.lineCount}A`);
    }
    this.lineCount = 0;
    ctx.origStdoutWrite('\x1b[?25h'); // show cursor
    ctx.tuiActive = false;
  }

  render() {
    const lines = [];
    const spinner = this.spinnerChars[this.spinnerFrame % this.spinnerChars.length];
    const bar = this.barFrames[this.spinnerFrame % this.barFrames.length];
    const sectionElapsed = formatTime(Date.now() - this.sectionStart);

    lines.push('');
    const sectionTitle = this._color('\x1b[1m\x1b[36m', this.currentSection);
    const sectionTime = this._color('\x1b[90m', sectionElapsed);
    lines.push(`  ${bar} ${sectionTitle} ${sectionTime}`);
    lines.push('');

    const maxLabel = Math.max(...this.jobs.map(j => j.label.length), 10);

    for (const job of this.jobs) {
      let icon, suffix;
      switch (job.status) {
        case 'queued':
          icon = this._color('\x1b[90m', '\u25CB');
          suffix = this._color('\x1b[90m', 'waiting');
          break;
        case 'running':
          const spinnerIcon = this.noColor ? spinner : this._color('\x1b[36m', spinner, false);
          icon = spinnerIcon;
          suffix = this._color('\x1b[33m', 'running...');
          break;
        case 'done':
          icon = this._color('\x1b[32m', '\u2714');
          suffix = this._color('\x1b[32m', `done ${job.elapsed}`);
          break;
        case 'skipped':
          icon = this._color('\x1b[90m', '\u2013');
          suffix = this._color('\x1b[90m', 'skipped');
          break;
        case 'failed':
          icon = this._color('\x1b[31m', '\u2718');
          suffix = this._color('\x1b[31m', `FAILED ${job.elapsed || ''}`);
          break;
      }
      const label = job.label.padEnd(maxLabel + 2);
      lines.push(`  ${icon} ${label} ${suffix}`);
    }

    lines.push('');

    let buf = '';
    if (this.lineCount > 0) {
      buf += `\x1b[${this.lineCount}A`;
    }
    for (const line of lines) {
      buf += `\x1b[2K${line}\n`;
    }
    this.lineCount = lines.length;
    ctx.origStdoutWrite(buf);
  }

  async runSection(name, jobs, { parallel = true } = {}) {
    this.setSection(name);
    for (const job of jobs) this.addJob(job.id, job.label);
    this.startRendering();

    if (parallel) {
      const promises = jobs.map(async (job) => {
        this.updateJob(job.id, 'running');
        try {
          const result = await job.fn();
          this.updateJob(job.id, result === 'skipped' ? 'skipped' : result === 'failed' ? 'failed' : 'done');
        } catch (err) {
          logToFile(`[ERROR] Job ${job.id} failed: ${err.message}\n${err.stack}`);
          this.updateJob(job.id, 'failed');
        }
      });
      await Promise.all(promises);
    } else {
      for (const job of jobs) {
        this.updateJob(job.id, 'running');
        try {
          const result = await job.fn();
          this.updateJob(job.id, result === 'skipped' ? 'skipped' : result === 'failed' ? 'failed' : 'done');
        } catch (err) {
          logToFile(`[ERROR] Job ${job.id} failed: ${err.message}\n${err.stack}`);
          this.updateJob(job.id, 'failed');
        }
      }
    }

    const elapsed = formatTime(Date.now() - this.sectionStart);
    this.stopRendering();
    this.sectionDone(name, elapsed);
  }

  setStep(jobId, processId, stepText) {
    const job = this.jobGroups.find(j => j.id === jobId);
    if (!job) return;
    const proc = job.processes.find(p => p.id === processId);
    if (proc) {
      proc.currentStep = (proc.currentStep || 0) + 1;
      proc.step = stepText;
      const progress = proc.totalSteps ? ` (${proc.currentStep}/${proc.totalSteps})` : '';
      logToFile(`[${jobId}/${processId}]${progress} ${stepText}`);
    }
  }

  updateProcess(jobId, processId, status) {
    const job = this.jobGroups.find(j => j.id === jobId);
    if (!job) return;
    const proc = job.processes.find(p => p.id === processId);
    if (!proc) return;
    if (status === 'running' && !proc.startTime) proc.startTime = Date.now();
    if (status === 'done' || status === 'failed' || status === 'skipped') {
      proc.elapsed = proc.startTime ? formatTime(Date.now() - proc.startTime) : '0s';
      proc.step = null;
      if (status === 'done' || status === 'skipped') {
        logToFile(`\u2714 [${jobId}/${processId}] ${proc.label} ${status} (${proc.elapsed})`);
      } else {
        logToFile(`\u2718 [${jobId}/${processId}] ${proc.label} FAILED (${proc.elapsed})`);
      }
    }
    proc.status = status;

    // Auto-update job status
    const anyRunning = job.processes.some(p => p.status === 'running');
    const allDone = job.processes.every(p => ['done', 'skipped', 'failed'].includes(p.status));
    if (allDone) {
      job.status = job.processes.some(p => p.status === 'failed') ? 'failed' : 'done';
      job.elapsed = job.startTime ? formatTime(Date.now() - job.startTime) : '0s';
      if (job.status === 'done') {
        logToFile(`\u2714 [${jobId}] ${job.label} done (${job.elapsed})`);
      } else {
        logToFile(`\u2718 [${jobId}] ${job.label} FAILED (${job.elapsed})`);
      }
    } else if (anyRunning) {
      job.status = 'running';
    }
  }

  renderJobs() {
    const lines = [];
    const spinner = this.spinnerChars[this.spinnerFrame % this.spinnerChars.length];
    const bar = this.barFrames[this.spinnerFrame % this.barFrames.length];
    const totalElapsed = formatTime(Date.now() - this.sectionStart);

    lines.push('');
    const buildLabel = this._color('\x1b[1m\x1b[36m', 'Building');
    const buildTime = this._color('\x1b[90m', totalElapsed);
    lines.push(`  ${bar} ${buildLabel} ${buildTime}`);

    for (const job of this.jobGroups) {
      lines.push('');
      let jobIcon;
      switch (job.status) {
        case 'queued':   jobIcon = this._color('\x1b[90m', '\u25CB'); break;
        case 'running':  jobIcon = this.noColor ? spinner : this._color('\x1b[36m', spinner, false); break;
        case 'done':     jobIcon = this._color('\x1b[32m', '\u2714'); break;
        case 'failed':   jobIcon = this._color('\x1b[31m', '\u2718'); break;
      }
      const jobLabel = this._color('\x1b[1m', job.label);
      const jobSuffix = job.elapsed ? ` ${this._color('\x1b[90m', `(${job.elapsed})`)}` : '';
      lines.push(`  ${jobIcon} ${jobLabel}${jobSuffix}`);

      const maxLabel = Math.max(...job.processes.map(p => p.label.length), 10);

      for (const proc of job.processes) {
        let icon, suffix;
        switch (proc.status) {
          case 'queued':
            icon = this._color('\x1b[90m', '\u25CB');
            suffix = this._color('\x1b[90m', 'waiting');
            break;
          case 'running':
            icon = this.noColor ? spinner : this._color('\x1b[36m', spinner, false);
            let stepText = proc.step || 'running...';
            if (proc.totalSteps) stepText = `(${proc.currentStep || 0}/${proc.totalSteps}) ${stepText}`;
            suffix = this._color('\x1b[33m', stepText);
            break;
          case 'done':
            icon = this._color('\x1b[32m', '\u2714');
            suffix = this._color('\x1b[32m', `done ${proc.elapsed}`);
            break;
          case 'skipped':
            icon = this._color('\x1b[90m', '\u2013');
            suffix = this._color('\x1b[90m', 'skipped');
            break;
          case 'failed':
            icon = this._color('\x1b[31m', '\u2718');
            suffix = this._color('\x1b[31m', `FAILED ${proc.elapsed || ''}`);
            break;
        }
        const label = proc.label.padEnd(maxLabel + 2);
        lines.push(`    ${icon} ${label} ${suffix}`);
      }
    }

    lines.push('');

    let buf = '';
    if (this.lineCount > 0) {
      buf += `\x1b[${this.lineCount}A`;
    }
    for (const line of lines) {
      buf += `\x1b[2K${line}\n`;
    }
    this.lineCount = lines.length;
    ctx.origStdoutWrite(buf);
  }

  async runJobs(jobDefs) {
    this.jobGroups = jobDefs.map(j => ({
      id: j.id,
      label: j.label,
      status: 'queued',
      startTime: null,
      elapsed: null,
      processes: j.processes.map(p => ({
        id: p.id,
        label: p.label,
        status: 'queued',
        startTime: null,
        elapsed: null,
        step: null,
        currentStep: 0,
        totalSteps: p.steps || 0,
      })),
    }));

    this.sectionStart = Date.now();
    this.startRendering(() => this.renderJobs());

    const jobPromises = jobDefs.map(async (jobDef) => {
      const job = this.jobGroups.find(j => j.id === jobDef.id);
      job.status = 'running';
      job.startTime = Date.now();

      const processPromises = jobDef.processes.map(async (procDef) => {
        this.updateProcess(jobDef.id, procDef.id, 'running');
        const step = (text) => this.setStep(jobDef.id, procDef.id, text);
        try {
          const result = await procDef.fn(step);
          this.updateProcess(jobDef.id, procDef.id,
            result === 'skipped' ? 'skipped' : result === 'failed' ? 'failed' : 'done');
        } catch (err) {
          logToFile(`[ERROR] ${jobDef.id}/${procDef.id} failed: ${err.message}\n${err.stack}`);
          this.updateProcess(jobDef.id, procDef.id, 'failed');
        }
      });

      await Promise.all(processPromises);
    });

    await Promise.all(jobPromises);

    const elapsed = formatTime(Date.now() - this.sectionStart);
    this.stopRendering();
    this.sectionDone('Build', elapsed);
  }
}

module.exports = { formatTime, TUI };
