#!/usr/bin/env node
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const read = (relativePath) => fs.readFileSync(path.join(root, relativePath), 'utf8');

const migration = read('database/migrations/20260810_000001_add_platform_default_goods_category.sql');
assert.match(migration, /tenant_id`?,\s*`?name`?.*is_default/s);
assert.match(migration, /SELECT\s+0,\s*'默认分类'.*1/s);
assert.match(migration, /WHERE\s+`?tenant_id`?\s*=\s*0/i);
assert.doesNotMatch(migration, /tenant_id\s*>\s*0/i);
assert.match(migration, /NOT\s+EXISTS/i);
assert.match(migration, /is_show`?\s*=\s*0/i);
assert.match(migration, /delete_time`?\s*=\s*NULL/i);
assert.match(migration, /COALESCE\(\s*@platform_default_goodscat_id,\s*@platform_named_goodscat_id\s*\)/i);

const platformLogic = read('app/platformapi/logic/goods/TenantGoodscatLogic.php');
const platformLists = read('app/platformapi/lists/goods/TenantGoodscatLists.php');
assert.match(platformLogic, /平台默认分类由系统维护/);
assert.match(platformLogic, /field\(\['id',\s*'name',\s*'is_default'\]\)/);
assert.match(platformLists, /'is_default'/);
assert.match(platformLists, /'is_default'\s*=>\s*'desc'/);

const apiController = read('app/api/jxc/controller/GoodsController.php');
const apiRoutes = read('app/api/route/jxc.php');
const categoryLogic = read('app/api/jxc/logic/GoodsCategoryLogic.php');
assert.match(apiController, /function\s+categoryAdd\s*\(/);
assert.match(apiRoutes, /Route::post\(['"]goods\/categories\/add['"],\s*['"]jxc\.Goods\/categoryAdd['"]\)/);
assert.match(categoryLogic, /GoodsMaintenancePermissionService::canMaintain\(\)/);
assert.match(categoryLogic, /request\(\)->tenantId/);
assert.doesNotMatch(categoryLogic, /\$params\s*\[\s*['"]tenant_id['"]\s*\]/);
assert.match(categoryLogic, /分类名称已存在/);

console.log('platform_and_tenant_category_contract_passed');
