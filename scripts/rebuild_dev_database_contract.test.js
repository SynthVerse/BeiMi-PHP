#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');
const childProcess = require('child_process');

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

const scriptPath = path.join(__dirname, 'rebuild-dev-database.ps1');
const safetyPath = path.join(__dirname, 'lib', 'RebuildDatabaseSafety.ps1');
assert(fs.existsSync(scriptPath), 'rebuild_script_missing');
assert(fs.existsSync(safetyPath), 'rebuild_safety_seam_missing');
const source = fs.readFileSync(scriptPath, 'utf8');

assert(/\[switch\]\$ConfirmRebuild/.test(source), 'explicit_confirmation_switch_missing');
assert(/\[string\]\$ExpectedDatabase/.test(source), 'expected_database_parameter_missing');
assert(/if \(-not \$ConfirmRebuild\)/.test(source), 'confirmation_guard_missing');
assert(/Assert-BeimiDevelopmentDatabaseTarget/.test(source), 'shared_rebuild_safety_guard_not_wired');
assert(/127\.0\.0\.1|localhost/.test(source), 'local_host_guard_missing');
assert(/information_schema|performance_schema|mysql|sys/.test(source), 'system_database_guard_missing');
assert(/\[string\]\$ConfigPath = ''/.test(source), 'isolated_config_parameter_missing');
assert(/\$config\.prefix -notmatch '\^\[A-Za-z0-9_\]\*\$'/.test(source), 'safe_prefix_guard_missing');
assert(/prepare-sql\.php/.test(source), 'shared_schema_preprocessor_missing');
assert(/--env=\$envPath/.test(source), 'migration_runner_must_receive_rebuild_config');
assert(/-ConfigPath 时必须同时传入 -SkipSeed/.test(source), 'isolated_seed_guard_missing');
assert(/mysqldump\.exe/.test(source), 'backup_tool_missing');
assert(/--result-file=/.test(source), 'backup_result_file_missing');
assert(/DROP DATABASE/.test(source), 'database_drop_missing');
assert(/public[\\/]install[\\/]db[\\/]like\.sql/.test(source), 'base_schema_missing');
assert(/database[\\/]sql[\\/]jxc_phase1_schema\.sql/.test(source), 'jxc_schema_missing');
assert(/scripts[\\/]migrate\.php/.test(source), 'migration_runner_missing');
assert(/finally\s*\{[\s\S]*Remove-Item[\s\S]*credentialsFile/.test(source), 'credential_cleanup_missing');
assert(/\$rebuildStarted = \$false/.test(source), 'rebuild_started_state_missing');
assert(/\$originalTableCount = 0/.test(source), 'original_table_count_snapshot_missing');
assert(/catch\s*\{[\s\S]*source \$backupSource[\s\S]*restoredTableCount[\s\S]*originalTableCount/.test(source), 'automatic_backup_restore_missing');
assert(/开发数据库已从备份恢复/.test(source), 'backup_restore_success_notice_missing');

const backupIndex = source.indexOf('Invoke-Checked -Executable $dumpPath');
const dropIndex = source.indexOf("Invoke-Checked -Executable $mysqlPath -Arguments @($defaultsArgument, '--execute', $recreateSql)");
const likeIndex = source.indexOf('"source $likeSource"');
const jxcIndex = source.indexOf('"source $jxcSource"');
const migrateIndex = source.indexOf('Invoke-Checked -Executable $PhpPath -Arguments @($migrateScript, "--env=$envPath")');
const prepareIndex = source.indexOf("--input=$likeSchema");
assert(backupIndex >= 0 && backupIndex < dropIndex, 'backup_must_precede_drop');
assert(dropIndex < prepareIndex && prepareIndex < likeIndex && likeIndex < jxcIndex, 'rebuild_stage_order_invalid');
assert(jxcIndex < migrateIndex, 'migration_must_follow_schemas');

function runSafetyGuard(configuredDatabase, expectedDatabase, runtimeEnvironment) {
  const quote = value => String(value).replaceAll("'", "''");
  const command = [
    `. '${quote(safetyPath)}'`,
    `Assert-BeimiDevelopmentDatabaseTarget -ConfiguredDatabase '${quote(configuredDatabase)}'`,
    `-ExpectedDatabase '${quote(expectedDatabase)}'`,
    `-RuntimeEnvironment '${quote(runtimeEnvironment)}'`,
  ];
  const invocation = `${command[0]}; ${command.slice(1).join(' ')}`;
  return childProcess.spawnSync(
    'pwsh',
    ['-NoProfile', '-NonInteractive', '-Command', invocation],
    { encoding: 'utf8' }
  );
}

const validTarget = runSafetyGuard('lantu', 'lantu', 'development');
assert(validTarget.status === 0, 'known_development_target_must_pass');
const wrongTarget = runSafetyGuard('lantu', 'another_database', 'development');
assert(wrongTarget.status !== 0, 'expected_database_mismatch_must_be_rejected');
const productionEnvironment = runSafetyGuard('lantu', 'lantu', 'production');
assert(productionEnvironment.status !== 0, 'non_development_environment_must_be_rejected');
const unlistedTarget = runSafetyGuard('production_copy', 'production_copy', 'development');
assert(unlistedTarget.status !== 0, 'database_outside_development_allowlist_must_be_rejected');
const isolatedTestTarget = runSafetyGuard('beimi_test_inventory', 'beimi_test_inventory', 'development');
assert(isolatedTestTarget.status === 0, 'isolated_test_database_must_pass');

process.stdout.write('rebuild_dev_database_contract_passed\n');
