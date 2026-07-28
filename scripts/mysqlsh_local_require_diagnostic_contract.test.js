#!/usr/bin/env node
'use strict';

const fs = require('fs');
const source = fs.readFileSync(require('path').join(__dirname, 'mysqlsh_local_require_diagnostic.js'), 'utf8');
function assert(condition, message) { if (!condition) throw new Error(message); }
assert(/require\('\.\/mysqlsh_local_require_diagnostic_fixture\.js'\)/.test(source), 'literal_fixture_require_missing');
assert(!/\bsession\b|\bmysql\b|\bos\b|\bargv\b|\bprocess\b|\benv\b|\.runSql\b|\bfs\b|\bhttp\b|\bchild_process\b|\beval\b/.test(source), 'diagnostic_must_be_zero_connection');
const expectedSource = [
  "'use strict';",
  '',
  "if (typeof print !== 'function') throw new Error('local_require_diagnostic_print_unavailable');",
  'try {',
  "  const fixture = require('./mysqlsh_local_require_diagnostic_fixture.js');",
  "  if (fixture && fixture.marker === 'mysqlsh_local_require_fixture') {",
  "    print(JSON.stringify({ status: 'diagnostic_passed', stage: 'local_require_diagnostic', zero_write: true, capabilities: { print: true, local_require: true } }));",
  '  } else {',
  "    print(JSON.stringify({ status: 'blocked', stage: 'local_require_diagnostic', zero_write: true, code: 'local_require_fixture_invalid' }));",
  '  }',
  '} catch (error) {',
  "  print(JSON.stringify({ status: 'blocked', stage: 'local_require_diagnostic', zero_write: true, code: 'local_require_unavailable' }));",
  '}',
  '',
];
assert(source.replace(/\r\n/g, '\n') === expectedSource.join('\n'), 'local_require_diagnostic_source_contract_mismatch');
console.log('mysqlsh_local_require_diagnostic_contract_passed');
