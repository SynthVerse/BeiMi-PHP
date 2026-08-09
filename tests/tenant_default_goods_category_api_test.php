<?php

declare(strict_types=1);

/**
 * 默认商品分类租户后台接口验收测试。
 *
 * 使用方法：
 *   php tests/tenant_default_goods_category_api_test.php [BASE_URL]
 */

require __DIR__ . '/support/api_test_helper.php';

$runtime = new TestRuntime();
$baseUrl = test_base_url($argv);
$login = http_request('POST', $baseUrl . '/tenantapi/login/account', [
    'account' => 'jxcadmin',
    'password' => '123456',
    'terminal' => 1,
]);

if (!$runtime->assertCode($login, 1, '租户管理员登录')) {
    exit($runtime->printSummary());
}

$token = (string)($login['data']['token'] ?? '');
$listsUrl = $baseUrl . '/tenantapi/goods.tenant_goodscat/lists';
$list = http_request('GET', $listsUrl, ['page_no' => 1, 'page_size' => 100], $token);
$runtime->assertCode($list, 1, '读取商品分类列表');

$defaultCategory = null;
foreach (($list['data']['lists'] ?? []) as $category) {
    if ((int)($category['is_default'] ?? 0) === 1) {
        $defaultCategory = $category;
        break;
    }
}
$runtime->assertTrue(is_array($defaultCategory), '列表包含唯一默认商品分类');

if (is_array($defaultCategory)) {
    $categoryId = (int)$defaultCategory['id'];
    $rename = http_request('POST', $baseUrl . '/tenantapi/goods.tenant_goodscat/edit', [
        'id' => $categoryId,
        'name' => '被拒绝的分类名',
        'sort' => (int)($defaultCategory['sort'] ?? 0),
        'is_show' => 0,
    ], $token);
    $runtime->assertCode($rename, 0, '默认商品分类改名被接口拒绝');
    $runtime->assertTrue(
        (string)($rename['msg'] ?? '') === '默认商品分类不可改名或隐藏',
        '改名接口返回明确错误'
    );

    $delete = http_request('POST', $baseUrl . '/tenantapi/goods.tenant_goodscat/delete', [
        'id' => $categoryId,
    ], $token);
    $runtime->assertCode($delete, 0, '默认商品分类删除被接口拒绝');
    $runtime->assertTrue(
        (string)($delete['msg'] ?? '') === '默认商品分类不可删除',
        '删除接口返回明确错误'
    );

    $after = http_request('GET', $listsUrl, ['page_no' => 1, 'page_size' => 100], $token);
    $afterDefault = array_values(array_filter(
        $after['data']['lists'] ?? [],
        static fn(array $category): bool => (int)($category['is_default'] ?? 0) === 1
    ));
    $runtime->assertTrue(
        count($afterDefault) === 1
            && (int)$afterDefault[0]['id'] === $categoryId
            && (string)$afterDefault[0]['name'] === '默认分类',
        '拒绝操作后默认商品分类保持不变'
    );
}

exit($runtime->printSummary());
