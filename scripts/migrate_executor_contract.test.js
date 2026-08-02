const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, 'migrate.php'), 'utf8');

assert.match(
  source,
  /function executeMigrationStatement\(PDO \$pdo, string \$statement\): void/,
  'migration executor must have one statement execution boundary',
);
assert.match(
  source,
  /\$statementHandle->fetchAll\(\);/,
  'migration executor must consume dynamic statement result rows',
);
assert.match(
  source,
  /\$statementHandle->nextRowset\(\)/,
  'migration executor must consume every dynamic statement rowset',
);
assert.match(
  source,
  /\$statementHandle->closeCursor\(\);/,
  'migration executor must release the result cursor before the next SQL statement',
);

process.stdout.write('migrate_executor_contract_passed\n');
