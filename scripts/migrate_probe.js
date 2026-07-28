#!/usr/bin/env node
'use strict';

if (typeof process === 'undefined' || typeof require !== 'function') {
  print(JSON.stringify({ status: 'blocked', code: 'runtime_entry_requires_fixed_no_argv_entry', stage: 'runtime', migration_count: 0, createdByThisRun: false }));
} else {
  const core = require('./migrate_probe_core.js');
  const argv = process.argv.slice(2);
  function emit(result) { process.stdout.write(JSON.stringify(result) + '\n'); }
  function fail(code) { throw { probeCode: code }; }
  function code(error) { return error && error.probeCode ? error.probeCode : 'static_failed'; }
  try {
    if (!core || Object.keys(core).length !== 2 || typeof core.runStaticProbe !== 'function' || typeof core.runFixedRuntimeProbe !== 'function' || typeof core.runStaticProbe.fixedTarget !== 'string') fail('fixed_core_contract_invalid');
    const args = {}; for (let index = 0; index < argv.length; index += 2) args[argv[index]] = argv[index + 1];
    if (args['--target'] !== core.runStaticProbe.fixedTarget) fail('target_not_allowed');
    if (args['--mode'] !== 'static') fail('runtime_not_enabled_pending_independent_verify');
    emit(core.runStaticProbe());
  } catch (error) { emit({ status: 'blocked', code: code(error) }); process.exitCode = 1; }
}
