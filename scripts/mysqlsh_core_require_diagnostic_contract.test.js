#!/usr/bin/env node
'use strict';

const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
function assert(condition, detail) { if (!condition) throw new Error(detail); }
function read(name) { return fs.readFileSync(path.join(__dirname, name)); }
function sha256(bytes) { return crypto.createHash('sha256').update(bytes).digest('hex').toUpperCase(); }

const core = read('migrate_probe_core.js');
const hashbang = Buffer.from('#!/usr/bin/env node\n');
assert(core[0] !== 0x23, 'core_must_not_start_with_hashbang');
assert(sha256(Buffer.concat([hashbang, core])) === 'E629FED879EB620D874C71B3C17CA080CE7A52D16359CAABAC2E80F8AD47D09B', 'core_must_only_remove_hashbang');

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

assert(sha256(read('mysqlsh_local_require_diagnostic.js')) === 'EF846ED968F626F7B1632BE7CD75B1B3BDFDDD060413709EF90C5FFCABE1BB16', 'local_require_diagnostic_changed');
const fixedRuntime = read('mysqlsh_fixed_runtime_probe.js').toString('utf8');
assert(sha256(Buffer.from(fixedRuntime)) === '90A33C5A8F3B65CFA115879D6CA2163F495E0DC38D4113F557C31B33D956CDA7', 'fixed_runtime_probe_changed');
assert(fixedRuntime.indexOf('missing.length !== 0') < fixedRuntime.indexOf("require('./migrate_probe_core.js')"), 'fixed_runtime_capability_gate_must_precede_core_require');

console.log('mysqlsh_core_require_diagnostic_contract_passed');
