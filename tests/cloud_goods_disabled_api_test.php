<?php

declare(strict_types=1);

/**
 * 当前版本必须在公开 HTTP 边界拒绝云端商品加载。
 *
 * 使用方法：
 *   php tests/cloud_goods_disabled_api_test.php [BASE_URL]
 */

require __DIR__ . '/support/api_test_helper.php';

$runtime = new TestRuntime();
$baseUrl = test_base_url($argv);
$login = login_default_admin($baseUrl);
if (!$runtime->assertCode($login, 1, '超级管理员登录')) {
    exit($runtime->printSummary());
}

$token = (string)($login['data']['token'] ?? '');
$response = http_request('POST', $baseUrl . '/api/goods/cloud/load', [
    'cloud_goods_id' => 1,
    'category_id' => 1,
    'unit_id' => 1,
], $token);

$runtime->assertCode($response, 0, '当前版本拒绝云端商品加载');
$runtime->assertTrue(
    (string)($response['msg'] ?? '') === '云端商品功能当前版本暂未开放',
    '返回明确的功能禁用提示',
    (string)($response['msg'] ?? '')
);

exit($runtime->printSummary());
