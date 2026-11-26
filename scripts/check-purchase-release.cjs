#!/usr/bin/env node
'use strict';

// 仅读取显式本地源码；不加载应用、环境变量、数据库或网络客户端。
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const purchaseContract = require('../tests/fixtures/purchase_public_routes.json');
const requiredFiles = [
  'app/api/route/jxc.php', 'app/api/jxc/controller/PurchasePlanController.php',
  ...['PurchasePlan', 'CustomerReport', 'FulfillmentChange', 'FulfillmentTask', 'PendingGoods', 'PurchaseBatch']
    .map(name => `app/api/jxc/logic/${name}Logic.php`),
  'database/migrations/20261117_000001_purchase_arrangements.sql',
];
const retired = 'app/api/jxc/validate/PurchasePlanValidate.php';
const membershipService = 'app/common/service/jxc/StoreMembershipService.php';
const sha = value => crypto.createHash('sha256').update(value).digest('hex');
const sameSet = (a, b) => Array.isArray(a) && a.length === b.length && new Set(a).size === a.length && b.every(value => a.includes(value));

function inspectPurchaseRelease(root, manifest) {
  const errors = [], files = [], dependencies = [], sources = new Map();
  if (manifest.version !== 1) errors.push('不支持的采购发布清单版本');
  if (!sameSet(manifest.files, requiredFiles)) errors.push('采购发布文件集合不完整或超出当前批准范围');
  if (!sameSet(manifest.remove, [retired])) errors.push('旧验证器删除项不完整');
  const dependencyNames = manifest.dependencies || [];
  for (const required of [
    'app/api/controller/jxc/PurchasePlanController.php', 'app/api/jxc/logic/CustomerReportSalesDocument.php',
    'app/api/jxc/logic/FinanceAccess.php', 'app/api/jxc/logic/WorkforceLogic.php',
    'app/api/jxc/middleware/JxcLoginMiddleware.php', membershipService,
    'database/migrations/20260925_000002_processing_groups_and_purchase_plans.sql',
  ]) {
    if (!dependencyNames.includes(required)) errors.push(`缺少基础依赖声明：${required}`);
  }
  const absoluteRoot = fs.realpathSync(root);
  const safePath = relative => {
    if (typeof relative !== 'string' || (relative !== membershipService && !/^(app\/api\/|database\/migrations\/)[A-Za-z0-9_/.]+$/.test(relative))
      || relative.split('/').some(part => part === '..' || part === '.')) {
      errors.push(`非法清单路径：${relative}`); return null;
    }
    const full = path.resolve(absoluteRoot, relative);
    if (fs.existsSync(full) && !fs.realpathSync(full).startsWith(absoluteRoot + path.sep)) {
      errors.push(`清单路径指向仓库外：${relative}`); return null;
    }
    return full;
  };
  for (const [entries, output] of [[manifest.files || [], files], [dependencyNames, dependencies]]) {
    for (const relative of entries) {
      const full = safePath(relative);
      if (!full) continue;
      if (!fs.existsSync(full) || !fs.statSync(full).isFile()) { errors.push(`缺少文件：${relative}`); continue; }
      const bytes = fs.readFileSync(full);
      sources.set(relative, bytes.toString('utf8'));
      output.push({ path: relative, sha256: sha(bytes), bytes: bytes.length });
    }
  }
  for (const relative of manifest.remove || []) {
    const full = safePath(relative);
    if (full && fs.existsSync(full)) errors.push(`应删除的旧文件仍存在：${relative}`);
  }
  const routeSource = sources.get('app/api/route/jxc.php') || '';
  const controller = sources.get('app/api/jxc/controller/PurchasePlanController.php') || '';
  const logic = sources.get('app/api/jxc/logic/PurchasePlanLogic.php') || '';
  const routes = Array.isArray(manifest.routes) ? manifest.routes : [];
  const declaredRoutes = [...routeSource.matchAll(/^\s*Route::(\w+)\(\s*['"]jxc\/purchase_plans\/([^'"]+)['"]\s*,\s*['"]([^'"]+)['"]\s*\)/gm)]
    .map(([, method, action, target]) => [method.toUpperCase(), action, target].join(' '));
  const approvedRoutes = [];
  for (const row of routes) {
    if (!Array.isArray(row) || row.length !== 3 || !/^(GET|POST)$/.test(row[0]) || !/^[a-z_]+$/.test(row[1]) || !/^[A-Za-z]+$/.test(row[2])) {
      errors.push('采购路由声明无效'); continue;
    }
    const [method, action, target] = row;
    const declaration = [method, action, 'jxc.PurchasePlan/' + target].join(' ');
    approvedRoutes.push(declaration);
    if (!declaredRoutes.includes(declaration)) errors.push('缺少当前路由：' + method + ' /api/jxc/purchase_plans/' + action);
    if (!new RegExp(`public\\s+function\\s+${target}\\s*\\(`).test(controller)) errors.push(`控制器缺少动作：${target}`);
    if (!new RegExp(`public\\s+static\\s+function\\s+${target}\\s*\\(`).test(logic)) errors.push(`采购逻辑缺少动作：${target}`);
  }
  if (!declaredRoutes.length || !sameSet(approvedRoutes, declaredRoutes) || new Set(declaredRoutes).size !== declaredRoutes.length) {
    errors.push('采购动作清单与实际发布路由集合不一致');
  }
  for (const [method, action, target] of Object.values(purchaseContract)) {
    if (!approvedRoutes.includes([method, action, 'jxc.PurchasePlan/' + target].join(' '))) {
      errors.push('采购清单缺少公开契约动作：' + method + ' ' + action);
    }
  }
  if (approvedRoutes.length !== Object.keys(purchaseContract).length) errors.push('采购清单动作数与公开契约不一致');
  if (/['"]jxc\/purchase_plans\/(?:buyers|recall)['"]/.test(routeSource)) errors.push('仍声明已退役的采购员或撤回接口');
  if (/['"]jxc\/purchase_plan\//.test(routeSource)) errors.push('仍声明已替换的旧单数采购路由');
  const adapter = sources.get('app/api/controller/jxc/PurchasePlanController.php') || '';
  if (!adapter.includes('extends \\app\\api\\jxc\\controller\\PurchasePlanController')) errors.push('公开采购控制器适配器未连接当前实现');
  const digest = sha(JSON.stringify({ manifest, files, dependencies, removed: manifest.remove }));
  return { ok: errors.length === 0, status: errors.length ? 'local_scope_failed' : 'local_scope_verified',
    title: manifest.title, digest, files, dependencies, removed: manifest.remove, routes,
    databasePrerequisites: manifest.databasePrerequisites, releaseSteps: manifest.releaseSteps,
    limitations: manifest.limitations, remoteChecked: false, databaseChecked: false, deploymentReady: false, errors };
}

if (require.main === module) {
  try {
    const args = process.argv.slice(2);
    if (args.length && !(args.length === 2 && args[0] === '--expect-digest' && /^[a-f0-9]{64}$/.test(args[1]))) {
      throw new Error('仅支持 --expect-digest <SHA256>；本命令不执行发布');
    }
    const manifest = JSON.parse(fs.readFileSync(path.join(__dirname, 'purchase-release-manifest.json'), 'utf8'));
    const result = inspectPurchaseRelease(path.resolve(__dirname, '..'), manifest);
    if (args.length && result.digest !== args[1]) { result.ok = false; result.status = 'review_digest_changed'; result.errors.push('审批后文件或清单已变化，请重新审核当前摘要'); }
    process.stdout.write(JSON.stringify(result, null, 2) + '\n');
    process.exitCode = result.ok ? 0 : 1;
  } catch (error) {
    process.stdout.write(JSON.stringify({ ok: false, status: 'preflight_failed', errors: [error.message] }) + '\n');
    process.exitCode = 1;
  }
}

module.exports = { inspectPurchaseRelease };
