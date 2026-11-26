const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { inspectPurchaseRelease } = require('./check-purchase-release.cjs');

const root = path.resolve(__dirname, '..');
const manifest = JSON.parse(fs.readFileSync(path.join(__dirname, 'purchase-release-manifest.json'), 'utf8'));

function fixture(t) {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'beimi-purchase-release-'));
  t.after(() => {
    assert.ok(path.resolve(directory).startsWith(path.resolve(os.tmpdir()) + path.sep));
    fs.rmSync(directory, { recursive: true, force: true });
  });
  for (const file of [...manifest.files, ...manifest.dependencies]) {
    const target = path.join(directory, file);
    fs.mkdirSync(path.dirname(target), { recursive: true });
    fs.copyFileSync(path.join(root, file), target);
  }
  return directory;
}

test('真实采购集合给出审批摘要，但不声称已验证远端或数据库', () => {
  const result = inspectPurchaseRelease(root, manifest);
  assert.equal(result.ok, true, result.errors.join('\n'));
  assert.equal(result.files.length, 9);
  assert.ok(result.routes.some(([method, action, target]) => method === 'POST' && action === 'revise' && target === 'revise'));
  assert.ok(result.routes.every(([, action]) => !['buyers', 'recall'].includes(action)));
  assert.equal(result.remoteChecked, false);
  assert.equal(result.databaseChecked, false);
  assert.equal(result.deploymentReady, false);
  assert.match(result.digest, /^[a-f0-9]{64}$/);
});

test('新增路由及遗漏修订动作都会使审批清单失效', t => {
  const directory = fixture(t);
  const route = path.join(directory, 'app/api/route/jxc.php');
  const source = fs.readFileSync(route, 'utf8');
  for (const action of ['create', 'revise', 'attach_arrival']) {
    const incomplete = { ...manifest, routes: manifest.routes.filter(row => row[1] !== action) };
    fs.writeFileSync(route, source.split('\n').filter(line => !line.includes("'jxc/purchase_plans/" + action + "'")).join('\n'));
    assert.ok(inspectPurchaseRelease(directory, incomplete).errors.some(error => error.includes('公开契约动作：POST ' + action)));
  }
  fs.writeFileSync(route, source + "\nRoute::get('jxc/purchase_plans/extra', 'jxc.PurchasePlan/extra');\n");
  assert.equal(inspectPurchaseRelease(directory, manifest).ok, false);
});

test('遗漏协作文件、恢复旧验证器和旧路由均拒绝通过', t => {
  const directory = fixture(t);
  fs.unlinkSync(path.join(directory, 'app/api/jxc/logic/PendingGoodsLogic.php'));
  assert.ok(inspectPurchaseRelease(directory, manifest).errors.some(error => error.includes('PendingGoodsLogic.php')));
  fs.copyFileSync(path.join(root, 'app/api/jxc/logic/PendingGoodsLogic.php'), path.join(directory, 'app/api/jxc/logic/PendingGoodsLogic.php'));
  const retired = path.join(directory, manifest.remove[0]);
  fs.mkdirSync(path.dirname(retired), { recursive: true });
  fs.writeFileSync(retired, '<?php');
  assert.ok(inspectPurchaseRelease(directory, manifest).errors.some(error => error.includes('仍存在')));
  fs.unlinkSync(retired);
  const route = path.join(directory, 'app/api/route/jxc.php');
  fs.writeFileSync(route, fs.readFileSync(route, 'utf8').replaceAll('jxc/purchase_plans/', 'jxc/purchase_plan/'));
  const result = inspectPurchaseRelease(directory, manifest);
  assert.equal(result.ok, false);
  assert.ok(result.errors.some(error => error.includes('demands')));
});

test('清单不能漏掉发布文件或声明外部文件，内容变化使审批摘要失效', t => {
  const directory = fixture(t);
  const before = inspectPurchaseRelease(directory, manifest);
  const incomplete = structuredClone(manifest);
  incomplete.files.pop();
  assert.equal(inspectPurchaseRelease(directory, incomplete).ok, false);
  for (const dependency of ['FinanceAccess.php', 'JxcLoginMiddleware.php', 'StoreMembershipService.php']) {
    const missingAccess = { ...manifest, dependencies: manifest.dependencies.filter(file => !file.endsWith('/' + dependency)) };
    assert.equal(inspectPurchaseRelease(directory, missingAccess).ok, false);
  }
  const unsafe = structuredClone(manifest);
  unsafe.files[0] = '../.env';
  assert.equal(inspectPurchaseRelease(directory, unsafe).ok, false);
  fs.appendFileSync(path.join(directory, 'app/api/jxc/logic/PurchasePlanLogic.php'), '\n// reviewed change\n');
  assert.notEqual(inspectPurchaseRelease(directory, manifest).digest, before.digest);
  const changedNotes = structuredClone(manifest);
  changedNotes.releaseSteps.push('审批范围发生变化');
  assert.notEqual(inspectPurchaseRelease(directory, changedNotes).digest, before.digest);
});

test('公开命令只输出JSON，正确摘要通过，旧摘要与未知参数失败', () => {
  const command = path.join(__dirname, 'check-purchase-release.cjs');
  const run = args => spawnSync(process.execPath, [command, ...args], { encoding: 'utf8', cwd: os.tmpdir() });
  const initial = run([]);
  assert.equal(initial.status, 0, initial.stderr);
  const report = JSON.parse(initial.stdout);
  assert.equal(run(['--expect-digest', report.digest]).status, 0);
  assert.equal(run(['--expect-digest', '0'.repeat(64)]).status, 1);
  assert.equal(run(['--deploy']).status, 1);
});
