const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const routeSource = fs.readFileSync(path.join(root, 'app/api/route/jxc.php'), 'utf8');
const controllerNames = Array.from(
  new Set(Array.from(routeSource.matchAll(/'jxc\.([A-Za-z][A-Za-z0-9_]*)\/[A-Za-z][A-Za-z0-9_]*'/g), match => match[1]))
).sort();

const failures = [];

for (const name of controllerNames) {
  const relativePath = `app/api/controller/jxc/${name}Controller.php`;
  const controllerPath = path.join(root, relativePath);
  if (!fs.existsSync(controllerPath)) {
    failures.push(`missing_route_controller:${relativePath}`);
    continue;
  }

  const source = fs.readFileSync(controllerPath, 'utf8');
  if (!source.includes('namespace app\\api\\controller\\jxc;')) {
    failures.push(`invalid_route_controller_namespace:${relativePath}`);
  }
  if (!new RegExp(`class\\s+${name}Controller\\b`).test(source)) {
    failures.push(`invalid_route_controller_class:${relativePath}`);
  }
}

if (failures.length > 0) {
  process.stderr.write(`${failures.join('\n')}\n`);
  process.exit(1);
}

process.stdout.write(`route_controller_contract_passed:${controllerNames.length}\n`);
