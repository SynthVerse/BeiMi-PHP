#!/usr/bin/env node
'use strict';

const assert = require('assert');
const core = require('./migrate_probe_core.js');
assert.deepStrictEqual(Object.keys(core), ['runStaticProbe', 'runFixedRuntimeProbe']);
assert.strictEqual(core.runStaticProbe.fixedTarget, 'beimi_r4_probe_20260726_plan020');
assert.deepStrictEqual(Object.getOwnPropertyDescriptor(core.runStaticProbe, 'fixedTarget'), { value: 'beimi_r4_probe_20260726_plan020', writable: false, enumerable: false, configurable: false });
assert.throws(() => core.runFixedRuntimeProbe('unexpected'), error => error && error.probeCode === 'runtime_arguments_not_allowed');
console.log('migrate_probe_core_contract_passed');
