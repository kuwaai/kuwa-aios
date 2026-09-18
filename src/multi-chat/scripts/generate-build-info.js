#!/usr/bin/env node

import fs from 'fs';
import path from 'path';
import { execSync } from 'child_process';
import { fileURLToPath, pathToFileURL } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const projectRoot = path.join(__dirname, '..');
const generatedVersionFilePath = path.join(projectRoot, 'VERSION.json');

function getFixedVersion() {
    const versionPath = path.join(projectRoot, 'VERSION');

    try {
        const version = fs.readFileSync(versionPath, 'utf8').trim();
        if (version) return version;
    } catch (error) {
        console.warn(`Warning: Could not read ${versionPath}:`, error.message);
    }

    return '0.0.0-dev';
}

function getGitValue(command, fallback) {
    try {
        return execSync(command, { cwd: projectRoot, encoding: 'utf8' }).trim();
    } catch (error) {
        console.warn(`Warning: Could not run ${command}:`, error.message);
        return fallback;
    }
}

export function generateBuildInfo({
    versionFilePath = generatedVersionFilePath,
    now = () => new Date().toISOString(),
} = {}) {
    const buildInfo = {
        version: getFixedVersion(),
        commitCount: Number.parseInt(getGitValue('git rev-list --count HEAD', '0'), 10),
        commitHash: getGitValue('git rev-parse --short=7 HEAD', 'unknown'),
        buildTimestamp: now(),
    };

    fs.writeFileSync(versionFilePath, JSON.stringify(buildInfo, null, 2));
    return buildInfo;
}

if (import.meta.url === pathToFileURL(process.argv[1]).href) {
    const buildInfo = generateBuildInfo();
    console.log(`Build info generated: ${buildInfo.version} ${buildInfo.commitCount} (g${buildInfo.commitHash})`);
}