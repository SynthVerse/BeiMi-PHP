#!/usr/bin/env node
'use strict';

const fs = require('fs');
const source = fs.readFileSync(require('path').join(__dirname, 'mysqlsh_fixed_runtime_probe.js'), 'utf8');
function assert(condition, message) { if (!condition) throw new Error(message); }
assert(!/argv|process|\.env|session\.runSql\(/.test(source), 'thin_runtime_must_not_accept_dynamic_input_or_sql');
assert(source.indexOf("require('./migrate_probe_core.js')") > source.indexOf('missing.length !== 0'), 'core_load_must_follow_capability_gate');
assert(/fixed_core_load_failed/.test(source) && /fixed_core_contract_invalid/.test(source), 'core_failure_contract_missing');
assert(/core\.runFixedRuntimeProbe\(\)/.test(source), 'fixed_runtime_call_missing');
console.log('mysqlsh_fixed_runtime_probe_contract_passed');
