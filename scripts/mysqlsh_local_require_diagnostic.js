'use strict';

if (typeof print !== 'function') throw new Error('local_require_diagnostic_print_unavailable');
try {
  const fixture = require('./mysqlsh_local_require_diagnostic_fixture.js');
  if (fixture && fixture.marker === 'mysqlsh_local_require_fixture') {
    print(JSON.stringify({ status: 'diagnostic_passed', stage: 'local_require_diagnostic', zero_write: true, capabilities: { print: true, local_require: true } }));
  } else {
    print(JSON.stringify({ status: 'blocked', stage: 'local_require_diagnostic', zero_write: true, code: 'local_require_fixture_invalid' }));
  }
} catch (error) {
  print(JSON.stringify({ status: 'blocked', stage: 'local_require_diagnostic', zero_write: true, code: 'local_require_unavailable' }));
}
