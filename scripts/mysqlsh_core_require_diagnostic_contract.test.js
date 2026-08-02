#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');
function assert(condition, detail) { if (!condition) throw new Error(detail); }
function read(name) { return fs.readFileSync(path.join(__dirname, name)); }

const core = read('migrate_probe_core.js');
assert(core[0] !== 0x23, 'core_must_not_start_with_hashbang');
assert(core.toString('utf8').includes("module.exports = { prepareMigrationSql, runStaticProbe, runFixedRuntimeProbe };"), 'core_commonjs_export_missing');

const diagnostic = read('mysqlsh_core_require_diagnostic.js').toString('utf8').replace(/\r\n/g, '\n');
const expectedDiagnostic = [
  'try {',
  "  require('./migrate_probe_core.js');",
  "  print('{\"status\":\"ok\",\"code\":\"core_require_ok\",\"stage\":\"core_require\",\"core_loaded\":true}');",
  '} catch {',
  "  print('{\"status\":\"blocked\",\"code\":\"core_require_failed\",\"stage\":\"core_require\",\"core_loaded\":false}');",
  '}',
  '',
].join('\n');
assert(diagnostic === expectedDiagnostic, 'diagnostic_source_contract_mismatch');
assert((diagnostic.match(/require\('\.\/migrate_probe_core\.js'\)/g) || []).length === 1, 'diagnostic_requires_core_once');
assert(!/\bsession\b|\bmysql\b|\bos\b|\bprocess\b|\benv\b|\.runSql\b|\bfs\b|\bhttp\b|\bchild_process\b|\beval\b|\bSELECT\b|\bINSERT\b|\bUPDATE\b|\bDELETE\b|\bCREATE\b|\bUSE\b|\bDROP\b|\bTARGET\b|\bmigration\b|\bcleanup\b|\berror\b|\bstack\b|\bname\b|\bmessage\b/i.test(diagnostic), 'diagnostic_must_be_zero_connection_and_exception_opaque');

const fixedRuntime = read('mysqlsh_fixed_runtime_probe.js').toString('utf8');
assert(fixedRuntime.indexOf('missing.length !== 0') < fixedRuntime.indexOf("require('./migrate_probe_core.js')"), 'fixed_runtime_capability_gate_must_precede_core_require');

console.log('mysqlsh_core_require_diagnostic_contract_passed');
