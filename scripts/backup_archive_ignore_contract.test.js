#!/usr/bin/env node
'use strict';

const childProcess = require('child_process');
const path = require('path');

const projectRoot = path.resolve(__dirname, '..');
const protectedPaths = [
  'storage/backups/xampp-mysql-data-raw-20260730-1430.zip',
  '.env',
  '.env.testing',
  '.local',
  'vendor',
];

for (const protectedPath of protectedPaths) {
  const result = childProcess.spawnSync('git', ['check-ignore', '-q', protectedPath], {
    cwd: projectRoot,
    encoding: 'utf8',
  });
  if (result.status !== 0) {
    throw new Error(`sensitive_local_path_must_be_gitignored:${protectedPath}`);
  }
}

process.stdout.write('backup_archive_ignore_contract_passed\n');
