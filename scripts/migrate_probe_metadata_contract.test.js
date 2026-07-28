#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');
const childProcess = require('child_process');
const wrapper = fs.readFileSync(path.join(__dirname, 'migrate_probe.js'), 'utf8');
const core = fs.readFileSync(path.join(__dirname, 'migrate_probe_core.js'), 'utf8');
function assert(condition, message) { if (!condition) throw new Error(message); }
assert((wrapper.match(/const TARGET/g) || []).length === 0, 'wrapper_must_not_define_target');
assert((wrapper.match(/beimi_r4_probe_20260726_plan020/g) || []).length === 0, 'wrapper_must_not_contain_target_literal');
assert(wrapper.indexOf("args['--target'] !== core.runStaticProbe.fixedTarget") < wrapper.indexOf('core.runStaticProbe()'), 'target_comparator_must_precede_static_call');
assert((core.match(/const TARGET = 'beimi_r4_probe_20260726_plan020';/g) || []).length === 1, 'core_target_must_have_single_authority');
assert(/Object\.defineProperty\(runStaticProbe, 'fixedTarget', \{ value: TARGET, enumerable: false, writable: false, configurable: false \}\);/.test(core), 'fixed_target_descriptor_mismatch');
assert(/module\.exports = \{ runStaticProbe, runFixedRuntimeProbe \};/.test(core), 'core_exports_mismatch');
assert(!/process\.argv|os\.argv|process\.env/.test(core), 'core_must_not_have_dynamic_top_level_input');
assert(/if \(!IS_NODE\) fail\('node_runtime_required'\);[\s\S]*?const fs = require\('fs'\);[\s\S]*?nodeDependencies = \{ fs, path, crypto: require\('crypto'\), root: path\.resolve\(__dirname, '\.\.'\) \};/.test(core), 'node_dependencies_must_be_delayed_and_guarded');
assert(/function metadataQuery\(operation, params\)/.test(core), 'missing_metadata_selector');
assert(/function runtimeMetadataScalar\(operation, params\)/.test(core), 'missing_metadata_executor');
assert(/function runtimeSafeSql\(statement\) \{[\s\S]*?locked_sql_cross_schema/.test(core), 'cross_schema_guard_missing');
assert(/function runFixedRuntimeProbe\(\) \{\s*if \(arguments\.length !== 0\) runtimeFail\('runtime_arguments_not_allowed'\);/.test(core), 'fixed_runtime_must_reject_arguments');
function runWrapper(args) {
  return childProcess.spawnSync(process.execPath, [path.join(__dirname, 'migrate_probe.js')].concat(args), { encoding: 'utf8' });
}
const fixed = runWrapper(['--mode', 'static', '--target', 'beimi_r4_probe_20260726_plan020']);
assert(fixed.status === 0 && fixed.stderr === '', 'fresh_fixed_cli_exit_contract_mismatch');
const fixedResult = JSON.parse(fixed.stdout);
assert(fixed.stdout === JSON.stringify(fixedResult) + '\n', 'fresh_fixed_cli_stdout_must_be_single_json');
assert(fixedResult.status === 'static_passed' && fixedResult.code === 'static_passed' && fixedResult.migration_count === 26 && fixedResult.statement_count === 204 && fixedResult.baseline_tables === 75 && fixedResult.final_tables === 102, 'fresh_fixed_cli_result_contract_mismatch');
const wrongTarget = runWrapper(['--mode', 'static', '--target', 'not_allowed']);
assert(wrongTarget.status === 1 && wrongTarget.stdout === '{"status":"blocked","code":"target_not_allowed"}\n', 'target_gate_must_reject_before_core');
const wrongMode = runWrapper(['--mode', 'runtime', '--target', 'beimi_r4_probe_20260726_plan020']);
assert(wrongMode.status === 1 && wrongMode.stdout === '{"status":"blocked","code":"runtime_not_enabled_pending_independent_verify"}\n', 'mode_gate_must_reject_before_core');
console.log('migrate_probe_metadata_contract_passed');
