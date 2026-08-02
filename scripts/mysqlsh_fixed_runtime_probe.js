'use strict';

const required = [
  ['print', typeof print === 'function'],
  ['os_load_text_file', typeof os !== 'undefined' && os !== null && typeof os.loadTextFile === 'function'],
  ['session_run_sql', typeof session !== 'undefined' && session !== null && typeof session.runSql === 'function'],
  ['mysql_split_script', typeof mysql !== 'undefined' && mysql !== null && typeof mysql.splitScript === 'function'],
  ['mysql_quote_identifier', typeof mysql !== 'undefined' && mysql !== null && typeof mysql.quoteIdentifier === 'function'],
];
const missing = required.filter(([, available]) => !available).map(([name]) => name);
if (typeof print !== 'function') throw new Error('runtime_capability_missing');
if (missing.length !== 0) {
  print(JSON.stringify({ status: 'blocked', code: 'runtime_capability_missing', stage: 'bootstrap', migration_count: 0, createdByThisRun: false, missing_capabilities: missing }));
} else {
  let core;
  try { core = require('./migrate_probe_core.js'); }
  catch (error) { print(JSON.stringify({ status: 'blocked', code: 'fixed_core_load_failed', stage: 'bootstrap', migration_count: 0, createdByThisRun: false })); core = null; }
  if (core !== null) {
    if (Object.keys(core).length !== 3 || typeof core.prepareMigrationSql !== 'function' || typeof core.runStaticProbe !== 'function' || typeof core.runFixedRuntimeProbe !== 'function') {
      print(JSON.stringify({ status: 'blocked', code: 'fixed_core_contract_invalid', stage: 'bootstrap', migration_count: 0, createdByThisRun: false }));
    } else {
      try { print(JSON.stringify(core.runFixedRuntimeProbe())); }
      catch (error) { print(JSON.stringify({ status: 'blocked', code: error && error.probeCode ? error.probeCode : 'runtime_failed', stage: 'runtime', migration_count: 0, createdByThisRun: false })); }
    }
  }
}
