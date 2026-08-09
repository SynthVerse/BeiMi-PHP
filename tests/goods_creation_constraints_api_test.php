<?php

declare(strict_types=1);

/**
 * 商品创建权限、去重与不可用商品恢复语义 HTTP 验收测试。
 *
 * 使用方法：
 *   php tests/goods_creation_constraints_api_test.php [BASE_URL]
 */

require __DIR__ . '/support/api_test_helper.php';

$runtime = new TestRuntime();
$baseUrl = test_base_url($argv);
$rootLogin = login_default_admin($baseUrl);
if (!$runtime->assertCode($rootLogin, 1, '超级管理员登录')) {
    exit($runtime->printSummary());
}

$rootToken = (string)($rootLogin['data']['token'] ?? '');
$tenantId = (int)($rootLogin['data']['user_info']['tenant_id'] ?? 0);
$prefix = db_prefix();
$goodsTable = db_table('goods');
$aliasTable = db_table('goods_alias');
$bindingTable = db_table('goods_units_binding');
$unitTable = db_table('goods_unit');
$categoryTable = db_table('tenant_goodscat');
$cloudGoodsTable = db_table('cloud_goods');
$cloudImportTable = db_table('cloud_goods_import');
$adminTable = db_table('tenant_admin');
$sessionTable = db_table('tenant_admin_session');
$tenantTable = db_table('tenant');
$runSuffix = preg_replace('/\D/', '', test_run_id()) ?: (string)time();
$normalizeName = static fn(string $name): string => mb_strtolower(preg_replace('/\s+/u', '', trim($name)) ?? '');
$goodsIds = [];
$cloudGoodsIds = [];
$createdUnitId = 0;
$deniedAdminId = 0;
$otherTenantId = 0;
$otherCategoryId = 0;
$otherUnitId = 0;

try {
    $categoryId = (int)db_value(
        "SELECT id FROM {$categoryTable} WHERE tenant_id = ? AND is_default = 1 AND delete_time IS NULL ORDER BY id ASC LIMIT 1",
        [$tenantId]
    );
    $runtime->assertTrue($tenantId > 0 && $categoryId > 0, '读取当前租户默认商品分类');

    $unitId = (int)db_value(
        "SELECT id FROM {$unitTable} WHERE tenant_id = ? AND status = 1 ORDER BY id ASC LIMIT 1",
        [$tenantId]
    );
    if ($unitId <= 0) {
        db_exec(
            "INSERT INTO {$unitTable} (tenant_id, name, status, sort, create_time, update_time) VALUES (?, '件', 1, 0, ?, ?)",
            [$tenantId, time(), time()]
        );
        $unitId = $createdUnitId = (int)test_pdo()->lastInsertId();
    }
    $unitName = (string)db_value("SELECT name FROM {$unitTable} WHERE id = ? AND tenant_id = ?", [$unitId, $tenantId]);

    db_exec(
        "INSERT INTO {$tenantTable} (sn, name, avatar, tel, disable, tactics, notes, domain_alias, domain_alias_enable, create_time, update_time, delete_time) VALUES (?, '商品约束其他租户', '', NULL, 0, 0, NULL, NULL, 1, ?, ?, NULL)",
        ['goods-constraint-other-' . $runSuffix, time(), time()]
    );
    $otherTenantId = (int)test_pdo()->lastInsertId();
    db_exec(
        "INSERT INTO {$categoryTable} (tenant_id, name, sort, is_show, is_default, create_time, update_time, delete_time) VALUES (?, '其他租户分类', 0, 0, NULL, ?, ?, NULL)",
        [$otherTenantId, time(), time()]
    );
    $otherCategoryId = (int)test_pdo()->lastInsertId();
    db_exec(
        "INSERT INTO {$unitTable} (tenant_id, name, status, sort, create_time, update_time) VALUES (?, '其他租户单位', 1, 0, ?, ?)",
        [$otherTenantId, time(), time()]
    );
    $otherUnitId = (int)test_pdo()->lastInsertId();

    foreach ([
        ['category_id' => $otherCategoryId, 'unit_id' => $unitId, 'units' => $unitName, 'message' => '商品分类不存在'],
        ['category_id' => $categoryId, 'unit_id' => $otherUnitId, 'units' => '其他租户单位', 'message' => '基础单位不存在'],
    ] as $index => $crossTenantMasterData) {
        $crossTenant = http_request('POST', $baseUrl . '/api/goods/add', [
            'name' => '跨租户主数据商品' . $index . $runSuffix,
            'product_code' => 'CROSS-TENANT-' . $index . '-' . $runSuffix,
            'category_id' => $crossTenantMasterData['category_id'],
            'unit_id' => $crossTenantMasterData['unit_id'],
            'units' => $crossTenantMasterData['units'],
        ], $rootToken);
        $runtime->assertCode($crossTenant, 0, '普通创建拒绝跨租户主数据 #' . $index);
        $runtime->assertTrue(
            (string)($crossTenant['msg'] ?? '') === $crossTenantMasterData['message'],
            '普通创建返回跨租户主数据提示 #' . $index
        );
    }

    $canonicalName = '创建约束桂鱼' . $runSuffix;
    $aliasName = '创建约束鳜鱼' . $runSuffix;
    $created = http_request('POST', $baseUrl . '/api/goods/add', [
        'name' => $canonicalName,
        'product_code' => 'CREATE-CONSTRAINT-' . $runSuffix,
        'category_id' => $categoryId,
        'unit_id' => $unitId,
        'units' => $unitName,
        'aliases' => [$aliasName],
        'price' => 0,
        'cost' => 0,
        'is_disabled' => 0,
    ], $rootToken);
    $runtime->assertCode($created, 1, '超级管理员创建商品');
    $goodsIds[] = (int)($created['data']['id'] ?? 0);
    $runtime->assertTrue(
        $goodsIds[0] > 0
            && ($created['data']['existing'] ?? true) === false
            && ($created['data']['reusable'] ?? false) === true,
        '新商品返回真实 ID 和可回填状态'
    );

    $duplicate = http_request('POST', $baseUrl . '/api/goods/add', [
        'name' => $aliasName,
        'product_code' => 'CREATE-DUPLICATE-' . $runSuffix,
        'category_id' => $categoryId,
        'unit_id' => $unitId,
        'units' => $unitName,
    ], $rootToken);
    $runtime->assertCode($duplicate, 1, '商品页别名命中已有商品');
    $runtime->assertTrue(
        (string)($duplicate['msg'] ?? '') === '商品已存在'
            && (int)($duplicate['data']['id'] ?? 0) === $goodsIds[0]
            && ($duplicate['data']['existing'] ?? false) === true,
        '商品页返回已有商品 ID 与明确消息'
    );

    $quickDuplicate = http_request('POST', $baseUrl . '/api/jxc/customer_report/quick_create_goods', [
        'name' => $aliasName,
        'category_id' => $categoryId,
        'unit_id' => $unitId,
    ], $rootToken);
    $runtime->assertCode($quickDuplicate, 1, '报货入口别名命中已有商品');
    $runtime->assertTrue(
        (string)($quickDuplicate['msg'] ?? '') === '商品已存在'
            && (int)($quickDuplicate['data']['goods_id'] ?? 0) === $goodsIds[0]
            && ($quickDuplicate['data']['existing'] ?? false) === true,
        '报货入口回填已有商品 ID'
    );

    $otherName = '创建约束鲈鱼' . $runSuffix;
    db_exec(
        "INSERT INTO {$goodsTable} (tenant_id, name, normalized_name, product_code, units, unit_id, price, cost, stock, category_id, is_disabled, is_archived, create_time, update_time) VALUES (?, ?, ?, ?, ?, ?, 0, 0, 0, ?, 0, 0, ?, ?)",
        [$tenantId, $otherName, $normalizeName($otherName), 'CREATE-AMBIGUOUS-' . $runSuffix, $unitName, $unitId, $categoryId, time(), time()]
    );
    $goodsIds[] = (int)test_pdo()->lastInsertId();
    $ambiguous = http_request('POST', $baseUrl . '/api/goods/add', [
        'name' => $canonicalName,
        'product_code' => 'CREATE-AMBIGUOUS-REQUEST-' . $runSuffix,
        'category_id' => $categoryId,
        'unit_id' => $unitId,
        'units' => $unitName,
        'aliases' => [$otherName],
    ], $rootToken);
    $runtime->assertCode($ambiguous, 0, '商品页拒绝多商品歧义命中');
    $runtime->assertTrue(
        (string)($ambiguous['msg'] ?? '') === '商品名称或别名同时命中多个既有商品，请选择已有商品',
        '商品页返回可见歧义提示'
    );

    $cloudName = '云端约束桂鱼' . $runSuffix;
    $cloudAlias = '云端约束鳜鱼' . $runSuffix;
    db_exec(
        "INSERT INTO {$cloudGoodsTable} (scope, tenant_id, owner_admin_id, owner_user_id, name, product_code, units, price, cost, stock, category_id, category_name, supplier_name, is_disabled, status, sort, remark, create_time, update_time) VALUES (1, 0, 0, 0, ?, ?, ?, 0, 0, 0, 0, '', '', 0, 1, 0, '', ?, ?)",
        [$cloudName, 'CLOUD-AMBIGUOUS-' . $runSuffix, $unitName, time(), time()]
    );
    $cloudGoodsIds[] = (int)test_pdo()->lastInsertId();
    db_exec(
        "INSERT INTO {$aliasTable} (tenant_id, goods_id, cloud_goods_id, alias, normalized_alias, source, create_time, update_time) VALUES (0, 0, ?, ?, ?, 'cloud', ?, ?)",
        [$cloudGoodsIds[0], $cloudAlias, mb_strtolower($cloudAlias), time(), time()]
    );
    foreach ([$cloudName, $cloudAlias] as $index => $localName) {
        db_exec(
            "INSERT INTO {$goodsTable} (tenant_id, name, normalized_name, product_code, units, unit_id, price, cost, stock, category_id, is_disabled, is_archived, create_time, update_time) VALUES (?, ?, ?, ?, ?, ?, 0, 0, 0, ?, 0, 0, ?, ?)",
            [
                $tenantId,
                $localName,
                $normalizeName($localName),
                'CLOUD-LOCAL-' . $index . '-' . $runSuffix,
                $unitName,
                $unitId,
                $categoryId,
                time(),
                time(),
            ]
        );
        $goodsIds[] = (int)test_pdo()->lastInsertId();
    }
    $cloudAmbiguous = http_request('POST', $baseUrl . '/api/goods/cloud/load', [
        'cloud_goods_id' => $cloudGoodsIds[0],
        'category_id' => $categoryId,
        'unit_id' => $unitId,
    ], $rootToken);
    $runtime->assertCode($cloudAmbiguous, 0, '云端下载拒绝名称与别名多商品歧义');
    $runtime->assertTrue(
        (string)($cloudAmbiguous['msg'] ?? '') === '云端商品名称或别名同时命中多个既有商品，请选择已有商品',
        '云端下载返回可见歧义提示'
    );
    foreach ([
        ['category_id' => $otherCategoryId, 'unit_id' => $unitId, 'message' => '商品分类不存在'],
        ['category_id' => $categoryId, 'unit_id' => $otherUnitId, 'message' => '请选择有效的本地单位'],
    ] as $index => $crossTenantMasterData) {
        $crossTenantCloud = http_request('POST', $baseUrl . '/api/goods/cloud/load', [
            'cloud_goods_id' => $cloudGoodsIds[0],
            'category_id' => $crossTenantMasterData['category_id'],
            'unit_id' => $crossTenantMasterData['unit_id'],
        ], $rootToken);
        $runtime->assertCode($crossTenantCloud, 0, '云端下载拒绝跨租户主数据 #' . $index);
        $runtime->assertTrue(
            (string)($crossTenantCloud['msg'] ?? '') === $crossTenantMasterData['message'],
            '云端下载返回跨租户主数据提示 #' . $index
        );
    }

    $inactiveCloudName = '云端归档桂鱼' . $runSuffix;
    db_exec(
        "INSERT INTO {$cloudGoodsTable} (scope, tenant_id, owner_admin_id, owner_user_id, name, product_code, units, price, cost, stock, category_id, category_name, supplier_name, is_disabled, status, sort, remark, create_time, update_time) VALUES (1, 0, 0, 0, ?, ?, ?, 0, 0, 0, 0, '', '', 0, 1, 0, '', ?, ?)",
        [$inactiveCloudName, 'CLOUD-INACTIVE-' . $runSuffix, $unitName, time(), time()]
    );
    $cloudGoodsIds[] = (int)test_pdo()->lastInsertId();
    db_exec(
        "INSERT INTO {$goodsTable} (tenant_id, name, normalized_name, product_code, units, unit_id, price, cost, stock, category_id, is_disabled, is_archived, create_time, update_time) VALUES (?, ?, ?, ?, ?, ?, 0, 0, 0, ?, 0, 1, ?, ?)",
        [
            $tenantId,
            $inactiveCloudName,
            $normalizeName($inactiveCloudName),
            'CLOUD-INACTIVE-LOCAL-' . $runSuffix,
            $unitName,
            $unitId,
            $categoryId,
            time(),
            time(),
        ]
    );
    $goodsIds[] = (int)test_pdo()->lastInsertId();
    $inactiveCloudGoodsId = $goodsIds[array_key_last($goodsIds)];
    $cloudInactive = http_request('POST', $baseUrl . '/api/goods/cloud/load', [
        'cloud_goods_id' => $cloudGoodsIds[1],
        'category_id' => $categoryId,
        'unit_id' => $unitId,
    ], $rootToken);
    $runtime->assertCode($cloudInactive, 1, '云端下载识别归档商品');
    $runtime->assertTrue(
        (string)($cloudInactive['msg'] ?? '') === '商品已归档，请先恢复'
            && (int)($cloudInactive['data']['existing_goods_id'] ?? 0) === $inactiveCloudGoodsId
            && ($cloudInactive['data']['reusable'] ?? true) === false
            && ($cloudInactive['data']['requires_activation'] ?? false) === true,
        '云端下载要求先恢复归档商品'
    );

    db_exec("UPDATE {$goodsTable} SET is_archived = 1 WHERE tenant_id = ? AND id = ?", [$tenantId, $goodsIds[0]]);
    $inactiveGoods = http_request('POST', $baseUrl . '/api/goods/add', [
        'name' => $aliasName,
        'product_code' => 'CREATE-ARCHIVED-' . $runSuffix,
        'category_id' => $categoryId,
        'unit_id' => $unitId,
        'units' => $unitName,
    ], $rootToken);
    $runtime->assertCode($inactiveGoods, 1, '商品页识别归档商品');
    $runtime->assertTrue(
        (string)($inactiveGoods['msg'] ?? '') === '商品已归档，请先恢复'
            && ($inactiveGoods['data']['reusable'] ?? true) === false
            && ($inactiveGoods['data']['requires_activation'] ?? false) === true,
        '商品页要求先恢复归档商品'
    );

    $inactiveQuick = http_request('POST', $baseUrl . '/api/jxc/customer_report/quick_create_goods', [
        'name' => $aliasName,
        'category_id' => $categoryId,
        'unit_id' => $unitId,
    ], $rootToken);
    $runtime->assertCode($inactiveQuick, 0, '报货入口拒绝直接回填归档商品');
    $runtime->assertTrue(
        (string)($inactiveQuick['msg'] ?? '') === '同名商品已归档，请先恢复'
            && (int)($inactiveQuick['data']['goods_id'] ?? 0) === $goodsIds[0]
            && ($inactiveQuick['data']['reusable'] ?? true) === false,
        '报货入口返回归档商品恢复路径'
    );

    $env = load_env_config();
    $salt = (string)($env['PROJECT']['UNIQUE_IDENTIFICATION'] ?? 'likeadmin');
    $deniedPassword = '123456';
    $deniedAccount = 'goods_denied_' . substr($runSuffix, -10);
    $hashedPassword = md5($salt . md5($deniedPassword . $salt));
    db_exec(
        "INSERT INTO {$adminTable} (tenant_id, root, name, avatar, account, password, login_time, login_ip, multipoint_login, disable, create_time, update_time, delete_time) VALUES (?, 0, '无商品权限测试员', '', ?, ?, NULL, '', 1, 0, ?, ?, NULL)",
        [$tenantId, $deniedAccount, $hashedPassword, time(), time()]
    );
    $deniedAdminId = (int)test_pdo()->lastInsertId();
    $deniedLogin = http_request('POST', $baseUrl . '/api/user/login', [
        'account' => $deniedAccount,
        'password' => $deniedPassword,
    ]);
    $runtime->assertCode($deniedLogin, 1, '无商品维护权限管理员登录');
    $deniedToken = (string)($deniedLogin['data']['token'] ?? '');

    foreach ([
        '/api/goods/add' => [
            'name' => '无权商品' . $runSuffix,
            'product_code' => 'CREATE-DENIED-' . $runSuffix,
            'category_id' => $categoryId,
            'unit_id' => $unitId,
            'units' => $unitName,
        ],
        '/api/jxc/customer_report/quick_create_goods' => [
            'name' => '无权报货商品' . $runSuffix,
            'category_id' => $categoryId,
            'unit_id' => $unitId,
        ],
        '/api/goods/cloud/load' => [
            'cloud_goods_id' => $cloudGoodsIds[0],
            'category_id' => $categoryId,
            'unit_id' => $unitId,
        ],
    ] as $path => $payload) {
        $denied = http_request('POST', $baseUrl . $path, $payload, $deniedToken);
        $runtime->assertCode($denied, 0, $path . ' 拒绝无商品维护权限账号');
        $runtime->assertTrue(
            (string)($denied['msg'] ?? '') === '当前账号没有商品维护权限',
            $path . ' 返回可见权限提示'
        );
    }
} finally {
    if ($deniedAdminId > 0) {
        db_exec("DELETE FROM {$sessionTable} WHERE admin_id = ?", [$deniedAdminId]);
        db_exec("DELETE FROM {$adminTable} WHERE id = ?", [$deniedAdminId]);
    }
    foreach ($goodsIds as $goodsId) {
        db_exec("DELETE FROM {$bindingTable} WHERE tenant_id = ? AND goods_id = ?", [$tenantId, $goodsId]);
        db_exec("DELETE FROM {$aliasTable} WHERE tenant_id = ? AND goods_id = ?", [$tenantId, $goodsId]);
        db_exec("DELETE FROM {$goodsTable} WHERE tenant_id = ? AND id = ?", [$tenantId, $goodsId]);
    }
    foreach ($cloudGoodsIds as $cloudGoodsId) {
        db_exec("DELETE FROM {$cloudImportTable} WHERE tenant_id = ? AND cloud_goods_id = ?", [$tenantId, $cloudGoodsId]);
        db_exec("DELETE FROM {$aliasTable} WHERE tenant_id = 0 AND cloud_goods_id = ?", [$cloudGoodsId]);
        db_exec("DELETE FROM {$cloudGoodsTable} WHERE tenant_id = 0 AND id = ?", [$cloudGoodsId]);
    }
    if ($createdUnitId > 0) {
        db_exec("DELETE FROM {$unitTable} WHERE tenant_id = ? AND id = ?", [$tenantId, $createdUnitId]);
    }
    if ($otherTenantId > 0) {
        db_exec("DELETE FROM {$categoryTable} WHERE tenant_id = ? AND id = ?", [$otherTenantId, $otherCategoryId]);
        db_exec("DELETE FROM {$unitTable} WHERE tenant_id = ? AND id = ?", [$otherTenantId, $otherUnitId]);
        db_exec("DELETE FROM {$tenantTable} WHERE id = ?", [$otherTenantId]);
    }
}

exit($runtime->printSummary());
