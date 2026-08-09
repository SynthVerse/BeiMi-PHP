<?php

declare(strict_types=1);

/**
 * 客户报货商品识别 HTTP 接口验收测试。
 *
 * 使用方法：
 *   php tests/customer_report_recognition_api_test.php [BASE_URL]
 */

require __DIR__ . '/support/api_test_helper.php';

$runtime = new TestRuntime();
$baseUrl = test_base_url($argv);
$login = login_default_admin($baseUrl);
if (!$runtime->assertCode($login, 1, 'JXC 管理员登录')) {
    exit($runtime->printSummary());
}

$token = (string)($login['data']['token'] ?? '');
$tenantAdminTable = db_table('tenant_admin');
$goodsTable = db_table('goods');
$aliasTable = db_table('goods_alias');
$unitTable = db_table('goods_unit');
$tenantId = (int)db_value(
    "SELECT tenant_id FROM {$tenantAdminTable} WHERE account = ? ORDER BY id ASC LIMIT 1",
    ['jxcadmin']
);
if (!$runtime->assertTrue($tenantId > 0, '读取测试管理员租户')) {
    exit($runtime->printSummary());
}

$runSuffix = preg_replace('/\D/', '', test_run_id()) ?: (string)time();
$goodsIds = [];
$createdUnitId = 0;

try {
    $activeUnitId = (int)db_value(
        "SELECT id FROM {$unitTable} WHERE tenant_id = ? AND name = '斤' AND status = 1 ORDER BY id ASC LIMIT 1",
        [$tenantId]
    );
    if ($activeUnitId <= 0) {
        db_exec(
            "INSERT INTO {$unitTable} (tenant_id, name, status, sort, create_time, update_time) VALUES (?, '斤', 1, 0, ?, ?)",
            [$tenantId, time(), time()]
        );
        $createdUnitId = (int)test_pdo()->lastInsertId();
    }

    $canonicalName = '接口桂鱼' . $runSuffix;
    $aliasName = '接口鳜鱼' . $runSuffix;
    db_exec(
        "INSERT INTO {$goodsTable} (tenant_id, name, product_code, units, unit_id, price, cost, stock, category_id, is_disabled, create_time, update_time) VALUES (?, ?, ?, '斤', 0, 1.00, 1.00, 0, 0, 0, ?, ?)",
        [$tenantId, $canonicalName, 'API-RECOGNITION-' . $runSuffix, time(), time()]
    );
    $goodsIds[] = (int)test_pdo()->lastInsertId();
    db_exec(
        "INSERT INTO {$aliasTable} (tenant_id, goods_id, cloud_goods_id, alias, normalized_alias, source, create_time, update_time) VALUES (?, ?, 0, ?, ?, 'tenant', ?, ?)",
        [$tenantId, $goodsIds[0], $aliasName, mb_strtolower($aliasName), time(), time()]
    );

    $recognized = http_request(
        'POST',
        $baseUrl . '/api/jxc/customer_report/recognize',
        ['text' => $aliasName . '15斤打氧'],
        $token
    );
    $runtime->assertCode($recognized, 1, '别名识别接口成功');
    $recognizedLine = $recognized['data']['lines'][0] ?? [];
    $runtime->assertTrue(
        (int)($recognizedLine['goods']['selected']['id'] ?? 0) === $goodsIds[0]
            && (string)($recognizedLine['goods']['selected']['name'] ?? '') === $canonicalName
            && (string)($recognizedLine['goods']['selected']['matched_name'] ?? '') === $aliasName,
        '别名唯一回填标准商品'
    );
    $runtime->assertTrue(
        (string)($recognizedLine['line_remark'] ?? '') === '15斤打氧'
            && (string)($recognizedLine['quantity']['status'] ?? '') === 'missing',
        '余文只进入报货行备注'
    );

    $canonical = http_request(
        'POST',
        $baseUrl . '/api/jxc/customer_report/recognize',
        ['text' => $canonicalName . '10斤加冰'],
        $token
    );
    $runtime->assertCode($canonical, 1, '标准名识别接口成功');
    $canonicalLine = $canonical['data']['lines'][0] ?? [];
    $runtime->assertTrue(
        (int)($canonicalLine['goods']['selected']['id'] ?? 0) === $goodsIds[0]
            && (string)($canonicalLine['goods']['selected']['name'] ?? '') === $canonicalName
            && (string)($canonicalLine['goods']['selected']['matched_name'] ?? '') === $canonicalName
            && (string)($canonicalLine['line_remark'] ?? '') === '10斤加冰',
        '标准名唯一回填标准商品并保留余文'
    );

    $conflictName = '接口冲突桂鱼' . $runSuffix;
    db_exec(
        "INSERT INTO {$goodsTable} (tenant_id, name, product_code, units, unit_id, price, cost, stock, category_id, is_disabled, create_time, update_time) VALUES (?, ?, ?, '斤', 0, 1.00, 1.00, 0, 0, 0, ?, ?)",
        [$tenantId, $conflictName, 'API-CONFLICT-A-' . $runSuffix, time(), time()]
    );
    $goodsIds[] = (int)test_pdo()->lastInsertId();
    db_exec(
        "INSERT INTO {$goodsTable} (tenant_id, name, product_code, units, unit_id, price, cost, stock, category_id, is_disabled, create_time, update_time) VALUES (?, ?, ?, '斤', 0, 1.00, 1.00, 0, 0, 0, ?, ?)",
        [$tenantId, '接口冲突鳜鱼' . $runSuffix, 'API-CONFLICT-B-' . $runSuffix, time(), time()]
    );
    $goodsIds[] = (int)test_pdo()->lastInsertId();
    db_exec(
        "INSERT INTO {$aliasTable} (tenant_id, goods_id, cloud_goods_id, alias, normalized_alias, source, create_time, update_time) VALUES (?, ?, 0, ?, ?, 'tenant', ?, ?)",
        [$tenantId, $goodsIds[2], $conflictName, mb_strtolower($conflictName), time(), time()]
    );

    $ambiguous = http_request(
        'POST',
        $baseUrl . '/api/jxc/customer_report/recognize',
        ['text' => $conflictName . '8斤'],
        $token
    );
    $runtime->assertCode($ambiguous, 1, '歧义识别接口成功');
    $ambiguousLine = $ambiguous['data']['lines'][0] ?? [];
    $runtime->assertTrue(
        (string)($ambiguousLine['goods']['status'] ?? '') === 'ambiguous'
            && ($ambiguousLine['goods']['selected'] ?? null) === null
            && ($ambiguousLine['can_submit'] ?? true) === false,
        '歧义匹配保持待处理'
    );

    $suggestedName = '接口石斑鱼' . $runSuffix;
    $suggested = http_request(
        'POST',
        $baseUrl . '/api/jxc/customer_report/recognize',
        ['text' => $suggestedName . '12斤加冰'],
        $token
    );
    $runtime->assertCode($suggested, 1, '待建名称建议接口成功');
    $suggestedLine = $suggested['data']['lines'][0] ?? [];
    $runtime->assertTrue(
        (string)($suggestedLine['goods']['status'] ?? '') === 'none'
            && (string)($suggestedLine['suggested_goods_name'] ?? '') === $suggestedName
            && (string)($suggestedLine['line_remark'] ?? '') === '12斤加冰',
        '数字加有效单位生成待建名称建议'
    );
} finally {
    foreach ($goodsIds as $goodsId) {
        db_exec("DELETE FROM {$aliasTable} WHERE tenant_id = ? AND goods_id = ?", [$tenantId, $goodsId]);
        db_exec("DELETE FROM {$goodsTable} WHERE tenant_id = ? AND id = ?", [$tenantId, $goodsId]);
    }
    if ($createdUnitId > 0) {
        db_exec("DELETE FROM {$unitTable} WHERE tenant_id = ? AND id = ?", [$tenantId, $createdUnitId]);
    }
}

exit($runtime->printSummary());
