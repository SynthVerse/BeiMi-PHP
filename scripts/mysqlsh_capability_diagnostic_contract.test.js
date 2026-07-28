'use strict';

const fs = require('node:fs');
const path = require('node:path');

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

const source = fs.readFileSync(
  path.join(__dirname, 'mysqlsh_capability_diagnostic.js'),
  'utf8',
);
const expectedSource = [
  'const result = {',
  "  status: 'diagnostic_passed',",
  "  stage: 'capability_diagnostic',",
  '  zero_write: true,',
  '  capabilities: {',
  "    print: typeof print === 'function',",
  "    os_load_text_file: typeof os !== 'undefined' && typeof os.loadTextFile === 'function',",
  "    session_run_sql: typeof session !== 'undefined' && typeof session.runSql === 'function',",
  "    mysql_split_script: typeof mysql !== 'undefined' && typeof mysql.splitScript === 'function',",
  "    mysql_quote_identifier: typeof mysql !== 'undefined' && mysql !== null && typeof mysql.quoteIdentifier === 'function',",
  '  },',
  '};',
  '',
  "if (typeof print !== 'function') {",
  "  throw new Error('capability_diagnostic_print_unavailable');",
  '}',
  '',
  'print(JSON.stringify(result));',
  '',
];

assert(
  source.replace(/\r\n/g, '\n') === expectedSource.join('\n'),
  'diagnostic_source_contract_mismatch',
);

console.log('mysqlsh_capability_diagnostic_contract_passed');
