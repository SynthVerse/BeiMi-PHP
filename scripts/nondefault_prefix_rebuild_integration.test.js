#!/usr/bin/env node
'use strict';

const childProcess = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

function iniValue(value) {
  return `"${String(value).replaceAll('\\', '\\\\').replaceAll('"', '\\"')}"`;
}

function run(executable, args, options = {}) {
  return childProcess.spawnSync(executable, args, {
    encoding: 'utf8',
    ...options,
  });
}

const projectRoot = path.resolve(__dirname, '..');
const rebuildScript = path.join(__dirname, 'rebuild-dev-database.ps1');
const prefix = 'tenantx_';
const mysqlBin = process.env.BEIMI_MYSQL_BIN || 'E:\\object\\BeiMi\\.local\\mysql\\mysql-8.4.10-winx64\\bin';
const mysql = path.join(mysqlBin, 'mysql.exe');
const mysqldump = path.join(mysqlBin, 'mysqldump.exe');
const php = process.env.BEIMI_PHP_PATH || 'C:\\Users\\ASUS\\AppData\\Local\\Programs\\PHP\\8.2\\php.exe';
const tempDirectory = fs.mkdtempSync(path.join(os.tmpdir(), 'beimi-nondefault-prefix-'));
const clientDefaults = path.join(tempDirectory, 'mysql.cnf');
const configPath = path.join(tempDirectory, 'rebuild.env');

const testingConfig = run(php, [
  '-r',
  "$env=parse_ini_file($argv[1], true, INI_SCANNER_TYPED); echo json_encode($env['DATABASE'] ?? []);",
  path.join(projectRoot, '.env.testing'),
]);
assert(testingConfig.status === 0, 'testing_database_config_read_failed');
const database = JSON.parse(testingConfig.stdout);
const host = String(database.HOSTNAME || '');
const port = String(database.HOSTPORT || '3306');
const username = String(database.USERNAME || '');
const password = String(database.PASSWORD || '');
const charset = String(database.CHARSET || 'utf8mb4');
const targetDatabase = String(database.DATABASE || '');

assert(['127.0.0.1', 'localhost'].includes(host), 'integration_test_requires_local_test_host');
assert(port === '3307', 'integration_test_requires_isolated_test_port');
assert(/^beimi_test_[A-Za-z0-9_]+$/.test(targetDatabase), 'integration_test_requires_named_test_database');
assert(fs.existsSync(mysql), 'mysql_client_missing');
assert(fs.existsSync(mysqldump), 'mysqldump_binary_missing');
assert(fs.existsSync(php), 'php_binary_missing');

fs.writeFileSync(clientDefaults, [
  '[client]',
  `host=${iniValue(host)}`,
  `port=${port}`,
  `user=${iniValue(username)}`,
  `password=${iniValue(password)}`,
  `default-character-set=${charset}`,
  '',
].join('\n'), { mode: 0o600 });

fs.writeFileSync(configPath, [
  '[DATABASE]',
  `HOSTNAME = ${iniValue(host)}`,
  `HOSTPORT = ${port}`,
  `DATABASE = ${targetDatabase}`,
  `USERNAME = ${iniValue(username)}`,
  `PASSWORD = ${iniValue(password)}`,
  `CHARSET = ${charset}`,
  `PREFIX = ${prefix}`,
  '',
].join('\n'), { mode: 0o600 });

function mysqlExecute(sql, database) {
  const args = [
    `--defaults-extra-file=${clientDefaults}`,
    '--protocol=TCP',
    `--host=${host}`,
    `--port=${port}`,
  ];
  if (database) args.push(database);
  args.push('--batch', '--skip-column-names', '--execute', sql);
  const result = run(mysql, args);
  assert(result.status === 0, `mysql_command_failed: ${result.stderr || result.stdout}`);
  return result.stdout;
}

function mysqlArguments() {
  return [
    `--defaults-extra-file=${clientDefaults}`,
    '--protocol=TCP',
    `--host=${host}`,
    `--port=${port}`,
  ];
}

const originalBackup = path.join(tempDirectory, 'before-nondefault-prefix-rebuild.sql');
let originalDatabaseExists = false;

try {
  originalDatabaseExists = mysqlExecute(
    `SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = '${targetDatabase}';`
  ).trim() === '1';
  if (originalDatabaseExists) {
    const dump = run(mysqldump, [
      ...mysqlArguments(),
      '--single-transaction',
      '--routines',
      '--triggers',
      '--events',
      '--databases',
      `--result-file=${originalBackup}`,
      targetDatabase,
    ]);
    assert(dump.status === 0 && fs.existsSync(originalBackup) && fs.statSync(originalBackup).size > 0, 'test_database_backup_failed');
  }

  const rebuild = run('pwsh', [
    '-NoProfile',
    '-NonInteractive',
    '-File', rebuildScript,
    '-ConfirmRebuild',
    '-ExpectedDatabase', targetDatabase,
    '-SkipSeed',
    '-ConfigPath', configPath,
    '-MySqlBin', mysqlBin,
    '-PhpPath', php,
  ], {
    env: { ...process.env, APP_ENV: 'development' },
  });
  assert(rebuild.status === 0, `nondefault_prefix_rebuild_failed: ${rebuild.stderr || rebuild.stdout}`);

  const tables = mysqlExecute(
    `SELECT table_name FROM information_schema.tables WHERE table_schema = '${targetDatabase}' ORDER BY table_name;`
  ).trim().split(/\r?\n/).filter(Boolean);
  const required = [
    'tenantx_customer_report', 'tenantx_sales_order', 'tenantx_order_goods',
    'tenantx_stock_flow', 'tenantx_receivable_flow', 'tenantx_audit_log',
    'tenantx_migration_history', 'tenantx_goods_alias',
  ];

  assert(tables.length === 99, `nondefault_prefix_table_count_mismatch: ${tables.length}`);
  assert(tables.every(table => table.startsWith(prefix)), 'nondefault_prefix_table_leak');
  assert(required.every(table => tables.includes(table)), 'nondefault_prefix_required_table_missing');
  assert(!tables.some(table => table.startsWith('la_') || table.includes('{')), 'default_or_template_prefix_leak');
  assert(
    mysqlExecute(`SELECT COUNT(*) FROM \`${prefix}migration_history\`;`, targetDatabase).trim() === '26',
    'nondefault_prefix_migration_history_mismatch'
  );

  process.stdout.write('nondefault_prefix_rebuild_integration_passed\n');
} finally {
  try {
    mysqlExecute(`DROP DATABASE IF EXISTS \`${targetDatabase}\`;`);
    if (originalDatabaseExists) {
      const restore = run(mysql, [
        ...mysqlArguments(),
        '--execute', `source ${originalBackup.replaceAll('\\', '/')}`,
      ]);
      assert(restore.status === 0, `test_database_restore_failed: ${restore.stderr || restore.stdout}`);
    }
  } finally {
    fs.rmSync(tempDirectory, { recursive: true, force: true });
  }
}
