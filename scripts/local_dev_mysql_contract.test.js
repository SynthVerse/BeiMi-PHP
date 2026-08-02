const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const startScript = fs.readFileSync(path.join(root, 'scripts', 'start-local-dev-mysql.ps1'), 'utf8');
const stopScript = fs.readFileSync(path.join(root, 'scripts', 'stop-local-dev-mysql.ps1'), 'utf8');

assert.match(startScript, /BeginConnect\('127\.0\.0\.1', \$Port/, 'startup readiness must verify the bound TCP endpoint');
assert.match(startScript, /AsyncWaitHandle\.WaitOne\(1000\)/, 'startup readiness must have a one-second bound');
assert.doesNotMatch(startScript, /--user=root|--skip-password|--execute=SELECT 1/, 'startup readiness must not depend on bootstrap account authentication');
assert.match(startScript, /local_dev_mysql_exited_before_ready/, 'startup must fail if mysqld exits before readiness');
assert.match(stopScript, /local_dev_mysql_port_owned_by_different_instance/, 'stop must refuse a different MySQL instance');

process.stdout.write('local_dev_mysql_contract_passed\n');
