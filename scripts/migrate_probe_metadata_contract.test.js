#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');
const childProcess = require('child_process');
const vm = require('vm');
const wrapper = fs.readFileSync(path.join(__dirname, 'migrate_probe.js'), 'utf8');
const core = fs.readFileSync(path.join(__dirname, 'migrate_probe_core.js'), 'utf8');
const coreModule = require('./migrate_probe_core.js');
function assert(condition, message) { if (!condition) throw new Error(message); }
const migrationDirectory = path.join(__dirname, '..', 'database', 'migrations');
const migrationNames = fs.readdirSync(migrationDirectory).filter(name => name.endsWith('.sql')).sort();
assert((wrapper.match(/const TARGET/g) || []).length === 0, 'wrapper_must_not_define_target');
assert((wrapper.match(/beimi_r4_probe_20260726_plan020/g) || []).length === 0, 'wrapper_must_not_contain_target_literal');
assert(wrapper.indexOf("args['--target'] !== core.runStaticProbe.fixedTarget") < wrapper.indexOf('core.runStaticProbe()'), 'target_comparator_must_precede_static_call');
assert((core.match(/const TARGET = 'beimi_r4_probe_20260726_plan020';/g) || []).length === 1, 'core_target_must_have_single_authority');
assert(/Object\.defineProperty\(runStaticProbe, 'fixedTarget', \{ value: TARGET, enumerable: false, writable: false, configurable: false \}\);/.test(core), 'fixed_target_descriptor_mismatch');
assert(/module\.exports = \{ prepareMigrationSql, runStaticProbe, runFixedRuntimeProbe \};/.test(core), 'core_exports_mismatch');
assert(typeof coreModule.prepareMigrationSql === 'function', 'preprocessor_export_missing');
assert((core.match(/prepareMigrationSql\(/g) || []).length === 10, 'static_and_runtime_paths_must_share_preprocessor');
assert(/const like = prepareMigrationSql\(read\(LIKE\), 'la_'\);[\s\S]*?const jxc = prepareMigrationSql\(read\(JXC\), 'la_'\);[\s\S]*?const tenantLike = prepareMigrationSql\(read\(LIKE\), 'tenantx_'\);[\s\S]*?const tenantJxc = prepareMigrationSql\(read\(JXC\), 'tenantx_'\);/.test(core), 'baseline_static_preprocessor_missing');
assert(/const like = prepareMigrationSql\(runtimeRead\(LIKE\), 'la_'\);[\s\S]*?const jxc = prepareMigrationSql\(runtimeRead\(JXC\), 'la_'\);/.test(core), 'baseline_runtime_preprocessor_missing');
assert(!/if \(name === '20260630_000001_create_purchase_return_order\.sql'/.test(core), 'static_file_specific_prefix_rule_forbidden');
assert(!/if \(names\[index\] === '20260630_000001_create_purchase_return_order\.sql'/.test(core), 'runtime_file_specific_prefix_rule_forbidden');
assert(!/process\.argv|os\.argv|process\.env/.test(core), 'core_must_not_have_dynamic_top_level_input');
assert(/if \(!IS_NODE\) fail\('node_runtime_required'\);[\s\S]*?const fs = require\('fs'\);[\s\S]*?nodeDependencies = \{ fs, path, crypto: require\('crypto'\), root: path\.resolve\(__dirname, '\.\.'\) \};/.test(core), 'node_dependencies_must_be_delayed_and_guarded');
assert(/function metadataQuery\(operation, params\)/.test(core), 'missing_metadata_selector');
assert(/function runtimeMetadataScalar\(operation, params\)/.test(core), 'missing_metadata_executor');
assert(/function runtimeSafeSql\(statement\) \{[\s\S]*?locked_sql_cross_schema/.test(core), 'cross_schema_guard_missing');
const runtimeGuardSandbox = {
  module: { exports: {} },
  exports: {},
  require,
  process,
  __dirname,
};
vm.runInNewContext(
  core + '\nmodule.exports.runtimeSafeSqlForContract = runtimeSafeSql;',
  runtimeGuardSandbox,
  { filename: 'migrate_probe_core.contract.js' }
);
const runtimeSafeSqlForContract = runtimeGuardSandbox.module.exports.runtimeSafeSqlForContract;
assert(
  runtimeSafeSqlForContract("SET @column_exists := (SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_orders' AND COLUMN_NAME = 'source_id')") === true,
  'read_only_information_schema_contract_rejected'
);
for (const unsafeCrossSchemaSql of [
  'DROP TABLE information_schema.COLUMNS',
  "SET @row_count := (SELECT COUNT(*) FROM mysql.user WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user')",
  "SET @row_count := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_orders' OR 1 = 1)",
]) {
  let rejected = false;
  try { runtimeSafeSqlForContract(unsafeCrossSchemaSql); }
  catch (error) { rejected = error && error.probeCode === 'locked_sql_cross_schema'; }
  assert(rejected, 'unsafe_cross_schema_sql_must_be_rejected');
}
assert(
  /ALTER TABLE la_tenant_goodscat DROP INDEX uk_tenant_default_goodscat[\s\S]*?ALTER TABLE la_tenant_goodscat DROP COLUMN is_default[\s\S]*?default_goods_category_legacy_fixture_failed[\s\S]*?executeLocked\(text, true\)[\s\S]*?default_goods_category_schema_migration_failed[\s\S]*?executeLocked\(text, true\)/.test(core),
  'default_goods_category_runtime_probe_must_cover_legacy_ddl_and_repeat'
);
assert(
  /INSERT INTO la_tenant_goodscat \(tenant_id, name, sort, is_show, create_time, update_time\)/.test(core),
  'default_goods_category_legacy_fixture_must_not_reference_new_column'
);
assert(/function runFixedRuntimeProbe\(\) \{\s*if \(arguments\.length !== 0\) runtimeFail\('runtime_arguments_not_allowed'\);/.test(core), 'fixed_runtime_must_reject_arguments');
for (const name of migrationNames) {
  const raw = fs.readFileSync(path.join(migrationDirectory, name), 'utf8');
  assert(raw.includes('{{prefix}}'), `migration_prefix_placeholder_missing:${name}`);
  assert(!/\bla_[A-Za-z0-9_]+/.test(raw), `migration_hardcoded_default_prefix:${name}`);
  const tenantSql = coreModule.prepareMigrationSql(raw, 'tenantx_');
  assert(!tenantSql.includes('{{'), `migration_placeholder_leaked:${name}`);
  assert(!/\bla_[A-Za-z0-9_]+/.test(tenantSql), `migration_default_prefix_leaked:${name}`);
  assert(tenantSql.includes('tenantx_'), `migration_non_default_prefix_not_applied:${name}`);
}
function runWrapper(args) {
  return childProcess.spawnSync(process.execPath, [path.join(__dirname, 'migrate_probe.js')].concat(args), { encoding: 'utf8' });
}
const fixed = runWrapper(['--mode', 'static', '--target', 'beimi_r4_probe_20260726_plan020']);
assert(fixed.status === 0 && fixed.stderr === '', 'fresh_fixed_cli_exit_contract_mismatch');
const fixedResult = JSON.parse(fixed.stdout);
assert(fixed.stdout === JSON.stringify(fixedResult) + '\n', 'fresh_fixed_cli_stdout_must_be_single_json');
assert(fixedResult.status === 'static_passed' && fixedResult.code === 'static_passed' && fixedResult.migration_count === 29 && fixedResult.statement_count === 215 && fixedResult.baseline_tables === 74 && fixedResult.final_tables === 99, 'fresh_fixed_cli_result_contract_mismatch');
const contractSql = 'CREATE TABLE `{{prefix}}orders` (`id` int NOT NULL);';
const phpPreprocessor = path.join(__dirname, 'lib', 'MigrationSqlPreprocessor.php').replace(/\\/g, '/');
const phpContract = childProcess.spawnSync(
  'php',
  ['-r', `require ${JSON.stringify(phpPreprocessor)}; echo \\BeiMi\\Migration\\MigrationSqlPreprocessor::prepare(${JSON.stringify(contractSql)}, 'tenantx_');`],
  { encoding: 'utf8' }
);
assert(phpContract.status === 0 && phpContract.stderr === '', 'php_preprocessor_contract_failed');
assert(
  coreModule.prepareMigrationSql(contractSql, 'tenantx_') === phpContract.stdout,
  'php_js_preprocessor_contract_mismatch'
);
let unresolvedRejected = false;
try { coreModule.prepareMigrationSql('{{prefix}}orders {{schema}}', 'tenantx_'); }
catch (error) { unresolvedRejected = error && error.probeCode === 'unresolved_prefix'; }
assert(unresolvedRejected, 'js_preprocessor_must_reject_unresolved_placeholders');
let unsafePrefixRejected = false;
try { coreModule.prepareMigrationSql('{{prefix}}orders', 'tenant`; DROP TABLE users; --'); }
catch (error) { unsafePrefixRejected = error && error.probeCode === 'invalid_database_prefix'; }
assert(unsafePrefixRejected, 'js_preprocessor_must_reject_unsafe_prefix');
const wrongTarget = runWrapper(['--mode', 'static', '--target', 'not_allowed']);
assert(wrongTarget.status === 1 && wrongTarget.stdout === '{"status":"blocked","code":"target_not_allowed"}\n', 'target_gate_must_reject_before_core');
const wrongMode = runWrapper(['--mode', 'runtime', '--target', 'beimi_r4_probe_20260726_plan020']);
assert(wrongMode.status === 1 && wrongMode.stdout === '{"status":"blocked","code":"runtime_not_enabled_pending_independent_verify"}\n', 'mode_gate_must_reject_before_core');
console.log('migrate_probe_metadata_contract_passed');
