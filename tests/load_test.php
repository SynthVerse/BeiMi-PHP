<?php
/**
 * BeiMi JXC 性能基准测试脚本
 * 纯 PHP + cURL，不依赖任何外部库
 *
 * 使用方法：
 *   php load_test.php              -- 运行所有场景
 *   php load_test.php list_query   -- 只运行列表查询场景
 *   php load_test.php batch_create -- 只运行批量创建场景
 *   php load_test.php concurrent_pay -- 只运行并发支付场景
 */

// ─────────────────────────────────────────────
// 配置
// ─────────────────────────────────────────────
$BASE_URL  = 'http://127.0.0.1:8787';
$SCENARIOS = ['list_query', 'batch_create', 'concurrent_pay'];

// 场景参数
$LIST_QUERY_COUNT     = 50;   // 列表查询次数
$LIST_QUERY_DATA_SIZE = 100;  // 预创建客户数量
$BATCH_CREATE_COUNT   = 50;   // 批量创建订单数
$CONCURRENT_PAY_NUM   = 5;    // 并发支付数

// 测试数据前缀（便于识别和清理）
$PREFIX = 'PERF_';

// ─────────────────────────────────────────────
// 解析命令行参数
// ─────────────────────────────────────────────
$runScenarios = $SCENARIOS;
if (isset($argv[1])) {
    $requested = $argv[1];
    if (in_array($requested, $SCENARIOS, true)) {
        $runScenarios = [$requested];
    } else {
        echo "错误: 未知场景 '{$requested}'，可选值: " . implode(', ', $SCENARIOS) . "\n";
        exit(1);
    }
}

// ─────────────────────────────────────────────
// 辅助函数
// ─────────────────────────────────────────────

/**
 * 发起 HTTP 请求（带计时）
 * @param string $method  GET / POST / DELETE
 * @param string $url     完整 URL
 * @param array  $data    请求体或查询参数
 * @param string $token   认证 token
 * @return array ['response' => array, 'time_ms' => float]
 */
function httpRequest(string $method, string $url, array $data = [], string $token = ''): array
{
    $ch = curl_init();

    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token !== '') {
        $headers[] = 'token: ' . $token;
    }

    if ($method === 'GET' && !empty($data)) {
        $url .= '?' . http_build_query($data);
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    switch ($method) {
        case 'POST':
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
            break;
        case 'DELETE':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
            break;
    }

    $start  = microtime(true);
    $raw    = curl_exec($ch);
    $end    = microtime(true);
    $err    = curl_error($ch);
    curl_close($ch);

    $timeMs = round(($end - $start) * 1000, 2);

    if ($err) {
        return ['response' => ['code' => -1, 'msg' => 'cURL error: ' . $err, 'data' => []], 'time_ms' => $timeMs];
    }

    $decoded = json_decode($raw, true);
    if ($decoded === null) {
        return ['response' => ['code' => -1, 'msg' => 'JSON decode error', 'data' => []], 'time_ms' => $timeMs];
    }

    return ['response' => $decoded, 'time_ms' => $timeMs];
}

/**
 * 测量执行时间（毫秒）
 * @param callable $fn  待测函数，返回 mixed
 * @return array ['result' => mixed, 'time_ms' => float]
 */
function measure_time(callable $fn): array
{
    $start  = microtime(true);
    $result = $fn();
    $end    = microtime(true);
    return ['result' => $result, 'time_ms' => round(($end - $start) * 1000, 2)];
}

/**
 * 计算百分位数
 * @param array $times  响应时间数组（毫秒）
 * @return array ['p50' => float, 'p95' => float, 'p99' => float, 'avg' => float, 'min' => float, 'max' => float]
 */
function calculate_percentiles(array $times): array
{
    if (empty($times)) {
        return ['p50' => 0, 'p95' => 0, 'p99' => 0, 'avg' => 0, 'min' => 0, 'max' => 0];
    }

    sort($times, SORT_NUMERIC);
    $count = count($times);
    $sum   = array_sum($times);

    $percentile = function (float $p) use ($times, $count): float {
        $idx = ($p / 100) * ($count - 1);
        $lower = (int)floor($idx);
        $upper = (int)ceil($idx);
        if ($lower === $upper) {
            return round($times[$lower], 2);
        }
        // 线性插值
        $frac  = $idx - $lower;
        return round($times[$lower] + $frac * ($times[$upper] - $times[$lower]), 2);
    };

    return [
        'p50' => $percentile(50),
        'p95' => $percentile(95),
        'p99' => $percentile(99),
        'avg' => round($sum / $count, 2),
        'min' => round($times[0], 2),
        'max' => round($times[$count - 1], 2),
    ];
}

/**
 * 打印性能报告
 * @param string $scenario  场景名称
 * @param array  $stats     统计数据
 */
function print_report(string $scenario, array $stats): void
{
    echo "\n--- {$scenario} ---\n";
    if (isset($stats['request_count'])) {
        echo "  请求数: {$stats['request_count']}\n";
    }
    if (isset($stats['success_count']) && isset($stats['request_count'])) {
        $rate = $stats['request_count'] > 0
            ? round($stats['success_count'] / $stats['request_count'] * 100, 1)
            : 0;
        echo "  成功率: {$rate}%\n";
    }
    if (isset($stats['p50'])) {
        echo "  P50: {$stats['p50']}ms\n";
        echo "  P95: {$stats['p95']}ms\n";
        echo "  P99: {$stats['p99']}ms\n";
        echo "  平均: {$stats['avg']}ms\n";
    }
    if (isset($stats['throughput'])) {
        echo "  吞吐量: {$stats['throughput']} req/sec\n";
    }
    if (isset($stats['concurrency'])) {
        echo "  并发数: {$stats['concurrency']}\n";
    }
    if (isset($stats['slowest'])) {
        echo "  最慢: {$stats['slowest']}ms\n";
    }
    if (isset($stats['fastest'])) {
        echo "  最快: {$stats['fastest']}ms\n";
    }
}

/**
 * 使用 curl_multi 并发执行多个请求
 * @param array  $requests  请求数组，每个元素 ['method', 'url', 'data', 'token']
 * @return array 每个请求的结果 ['response' => array, 'time_ms' => float]
 */
function concurrent_request(array $requests): array
{
    $handles  = [];
    $mh       = curl_multi_init();
    $startMap = [];

    foreach ($requests as $i => $req) {
        $ch = curl_init();

        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if (!empty($req['token'])) {
            $headers[] = 'token: ' . $req['token'];
        }

        $url = $req['url'];
        if ($req['method'] === 'GET' && !empty($req['data'])) {
            $url .= '?' . http_build_query($req['data']);
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if ($req['method'] === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($req['data'], JSON_UNESCAPED_UNICODE));
        } elseif ($req['method'] === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($req['data'], JSON_UNESCAPED_UNICODE));
        }

        $handles[$i]  = $ch;
        $startMap[$i]  = microtime(true);
        curl_multi_add_handle($mh, $ch);
    }

    // 执行并发请求
    $globalStart = microtime(true);
    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) {
            curl_multi_select($mh, 1.0);
        }
    } while ($active && $status === CURLM_OK);
    $globalEnd = microtime(true);

    // 收集结果
    $results = [];
    foreach ($handles as $i => $ch) {
        $raw       = curl_multi_getcontent($ch);
        $err       = curl_error($ch);
        $elapsed   = round((microtime(true) - $startMap[$i]) * 1000, 2);

        if ($err) {
            $results[$i] = ['response' => ['code' => -1, 'msg' => 'cURL error: ' . $err], 'time_ms' => $elapsed];
        } else {
            $decoded = json_decode($raw, true);
            $results[$i] = [
                'response' => $decoded ?? ['code' => -1, 'msg' => 'JSON decode error'],
                'time_ms'  => $elapsed,
            ];
        }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);

    return $results;
}

// ─────────────────────────────────────────────
// 场景 1: 列表查询性能
// ─────────────────────────────────────────────
function scenario_list_query(string $baseUrl, string $token, string $prefix, int $dataSize, int $queryCount): array
{
    echo "  [1/3] 批量创建 {$dataSize} 条客户数据...\n";
    $createdIds = [];
    $batchStart = microtime(true);

    for ($i = 0; $i < $dataSize; $i++) {
        $res = httpRequest('POST', "{$baseUrl}/api/customer/add", [
            'customer_name' => "{$prefix}查询客户_{$i}",
            'contact'       => "{$prefix}联系人_{$i}",
            'phone'         => '138' . str_pad((string)($i), 8, '0', STR_PAD_LEFT),
        ], $token);

        if (($res['response']['code'] ?? 0) === 1) {
            $id = $res['response']['data']['id'] ?? null;
            if ($id) $createdIds[] = (int)$id;
        }
    }
    $batchTime = round((microtime(true) - $batchStart) * 1000, 2);
    echo "  创建完成: " . count($createdIds) . "/{$dataSize} 条 (耗时 {$batchTime}ms)\n";

    // 执行列表查询
    echo "  [2/3] 执行 {$queryCount} 次列表查询...\n";
    $times   = [];
    $success = 0;

    for ($i = 0; $i < $queryCount; $i++) {
        $page = ($i % 5) + 1;  // 轮询前5页
        $res  = httpRequest('GET', "{$baseUrl}/api/customer/index", [
            'page'     => $page,
            'pagesize' => 20,
        ], $token);

        $times[] = $res['time_ms'];
        if (($res['response']['code'] ?? 0) === 1) {
            $success++;
        }
    }

    // 清理
    echo "  [3/3] 清理测试数据...\n";
    $cleanCount = 0;
    foreach ($createdIds as $id) {
        $delRes = httpRequest('DELETE', "{$baseUrl}/api/customer/del", ['id' => $id], $token);
        if (($delRes['response']['code'] ?? 0) === 1) {
            $cleanCount++;
        }
    }
    echo "  清理完成: {$cleanCount}/" . count($createdIds) . " 条\n";

    $percentiles = calculate_percentiles($times);

    return [
        'scenario'       => '场景1: 列表查询',
        'request_count'  => $queryCount,
        'success_count'  => $success,
        'data_size'      => $dataSize,
        'p50'            => $percentiles['p50'],
        'p95'            => $percentiles['p95'],
        'p99'            => $percentiles['p99'],
        'avg'            => $percentiles['avg'],
        'min'            => $percentiles['min'],
        'max'            => $percentiles['max'],
    ];
}

// ─────────────────────────────────────────────
// 场景 2: 批量创建订单
// ─────────────────────────────────────────────
function scenario_batch_create(string $baseUrl, string $token, string $prefix, int $createCount): array
{
    echo "  [1/4] 创建前置数据（客户、仓库、商品）...\n";

    // 创建客户
    $custRes = httpRequest('POST', "{$baseUrl}/api/customer/add", [
        'customer_name' => "{$prefix}批量订单客户",
        'contact'       => "{$prefix}批量订单联系人",
        'phone'         => '13900000001',
    ], $token);
    $custId = $custRes['response']['data']['id'] ?? null;
    if (!$custId) {
        echo "  [ERROR] 创建客户失败，跳过场景\n";
        return ['scenario' => '场景2: 批量创建', 'request_count' => 0, 'success_count' => 0];
    }
    $custId = (int)$custId;

    // 创建仓库
    $whRes = httpRequest('POST', "{$baseUrl}/api/warehouse/add", [
        'name'    => "{$prefix}批量订单仓库",
        'address' => "{$prefix}批量订单地址",
    ], $token);
    $whId = $whRes['response']['data']['id'] ?? null;
    if (!$whId) {
        echo "  [ERROR] 创建仓库失败，跳过场景\n";
        httpRequest('DELETE', "{$baseUrl}/api/customer/del", ['id' => $custId], $token);
        return ['scenario' => '场景2: 批量创建', 'request_count' => 0, 'success_count' => 0];
    }
    $whId = (int)$whId;

    // 创建商品
    $goodsRes = httpRequest('POST', "{$baseUrl}/api/goods/add", [
        'name'         => "{$prefix}批量订单商品",
        'product_code' => "{$prefix}BATCH001",
        'price'        => 10.00,
        'units'        => '个',
    ], $token);
    $goodsId = $goodsRes['response']['data']['id'] ?? null;
    if (!$goodsId) {
        echo "  [ERROR] 创建商品失败，跳过场景\n";
        httpRequest('DELETE', "{$baseUrl}/api/customer/del", ['id' => $custId], $token);
        httpRequest('POST', "{$baseUrl}/api/warehouse/del", ['id' => $whId], $token);
        return ['scenario' => '场景2: 批量创建', 'request_count' => 0, 'success_count' => 0];
    }
    $goodsId = (int)$goodsId;
    echo "  前置数据创建完成: 客户ID={$custId}, 仓库ID={$whId}, 商品ID={$goodsId}\n";

    // 批量创建销售单
    echo "  [2/4] 批量创建 {$createCount} 个销售单...\n";
    $times       = [];
    $success     = 0;
    $orderIds    = [];
    $overallStart = microtime(true);

    for ($i = 0; $i < $createCount; $i++) {
        $itemCount = rand(1, 3);  // 每单 1-3 个商品行
        $goods     = [];
        for ($j = 0; $j < $itemCount; $j++) {
            $goods[] = [
                'goods_id' => $goodsId,
                'name'     => "{$prefix}批量订单商品",
                'number'   => rand(1, 10),
                'price'    => round(rand(500, 2000) / 100, 2), // 5.00 ~ 20.00
                'units'    => '个',
            ];
        }

        $res = httpRequest('POST', "{$baseUrl}/api/order/publish", [
            'customer_id'  => $custId,
            'warehouse_id' => $whId,
            'goods'        => $goods,
        ], $token);

        $times[] = $res['time_ms'];
        if (($res['response']['code'] ?? 0) === 1) {
            $success++;
            $orderId = $res['response']['data']['id'] ?? null;
            if ($orderId) $orderIds[] = (int)$orderId;
        }
    }

    $overallEnd   = microtime(true);
    $overallSec   = $overallEnd - $overallStart;
    $throughput   = $overallSec > 0 ? round($success / $overallSec, 2) : 0;

    echo "  创建完成: {$success}/{$createCount} 单\n";

    // 清理销售单
    echo "  [3/4] 清理销售单...\n";
    $cleanOrderCount = 0;
    foreach ($orderIds as $oid) {
        $delRes = httpRequest('DELETE', "{$baseUrl}/api/order/remove", ['id' => $oid], $token);
        if (($delRes['response']['code'] ?? 0) === 1) {
            $cleanOrderCount++;
        }
    }
    echo "  销售单清理: {$cleanOrderCount}/" . count($orderIds) . " 条\n";

    // 清理前置数据
    echo "  [4/4] 清理前置数据...\n";
    httpRequest('DELETE', "{$baseUrl}/api/goods/del", ['id' => $goodsId], $token);
    httpRequest('POST', "{$baseUrl}/api/warehouse/del", ['id' => $whId], $token);
    httpRequest('DELETE', "{$baseUrl}/api/customer/del", ['id' => $custId], $token);
    echo "  前置数据清理完成\n";

    $percentiles = calculate_percentiles($times);

    return [
        'scenario'       => '场景2: 批量创建',
        'request_count'  => $createCount,
        'success_count'  => $success,
        'p50'            => $percentiles['p50'],
        'p95'            => $percentiles['p95'],
        'p99'            => $percentiles['p99'],
        'avg'            => $percentiles['avg'],
        'min'            => $percentiles['min'],
        'max'            => $percentiles['max'],
        'throughput'     => $throughput,
    ];
}

// ─────────────────────────────────────────────
// 场景 3: 并发支付
// ─────────────────────────────────────────────
function scenario_concurrent_pay(string $baseUrl, string $token, string $prefix, int $concurrency): array
{
    echo "  [1/4] 创建前置数据（客户、仓库、商品）...\n";

    // 创建仓库
    $whRes = httpRequest('POST', "{$baseUrl}/api/warehouse/add", [
        'name'    => "{$prefix}并发支付仓库",
        'address' => "{$prefix}并发支付地址",
    ], $token);
    $whId = $whRes['response']['data']['id'] ?? null;
    if (!$whId) {
        echo "  [ERROR] 创建仓库失败，跳过场景\n";
        return ['scenario' => '场景3: 并发支付', 'concurrency' => $concurrency, 'success_count' => 0, 'request_count' => $concurrency];
    }
    $whId = (int)$whId;

    // 创建商品
    $goodsRes = httpRequest('POST', "{$baseUrl}/api/goods/add", [
        'name'         => "{$prefix}并发支付商品",
        'product_code' => "{$prefix}CONC001",
        'price'        => 100.00,
        'units'        => '个',
    ], $token);
    $goodsId = $goodsRes['response']['data']['id'] ?? null;
    if (!$goodsId) {
        echo "  [ERROR] 创建商品失败，跳过场景\n";
        httpRequest('POST', "{$baseUrl}/api/warehouse/del", ['id' => $whId], $token);
        return ['scenario' => '场景3: 并发支付', 'concurrency' => $concurrency, 'success_count' => 0, 'request_count' => $concurrency];
    }
    $goodsId = (int)$goodsId;

    // 创建 $concurrency 个客户并下单，制造应收款
    echo "  [2/4] 创建 {$concurrency} 个待支付客户并下单...\n";
    $custIds  = [];
    $orderIds = [];

    for ($i = 0; $i < $concurrency; $i++) {
        // 创建客户
        $custRes = httpRequest('POST', "{$baseUrl}/api/customer/add", [
            'customer_name' => "{$prefix}并发客户_{$i}",
            'contact'       => "{$prefix}并发联系人_{$i}",
            'phone'         => '137' . str_pad((string)($i), 8, '0', STR_PAD_LEFT),
        ], $token);
        $custId = $custRes['response']['data']['id'] ?? null;
        if (!$custId) continue;
        $custId   = (int)$custId;
        $custIds[] = $custId;

        // 创建销售单，产生应收款
        $orderRes = httpRequest('POST', "{$baseUrl}/api/order/publish", [
            'customer_id'  => $custId,
            'warehouse_id' => $whId,
            'goods'        => [[
                'goods_id' => $goodsId,
                'name'     => "{$prefix}并发支付商品",
                'number'   => 1,
                'price'    => 100.00,
                'units'    => '个',
            ]],
        ], $token);
        if (($orderRes['response']['code'] ?? 0) === 1) {
            $oid = $orderRes['response']['data']['id'] ?? null;
            if ($oid) $orderIds[] = (int)$oid;
        }
    }

    $readyCount = count($custIds);
    echo "  准备完成: {$readyCount}/{$concurrency} 个客户已下单\n";

    // 并发付款
    echo "  [3/4] 并发支付 ({$concurrency} 个请求)...\n";
    $payRequests = [];
    foreach ($custIds as $i => $cid) {
        $payRequests[] = [
            'method' => 'POST',
            'url'    => "{$baseUrl}/api/customer/paymoney",
            'data'   => [
                'customer_id' => $cid,
                'money'       => 50.00,  // 每笔付 50 元
            ],
            'token'  => $token,
        ];
    }

    $results = concurrent_request($payRequests);

    // 统计并发结果
    $times   = [];
    $success = 0;
    foreach ($results as $r) {
        $times[] = $r['time_ms'];
        if (($r['response']['code'] ?? 0) === 1) {
            $success++;
        }
    }

    $percentiles = calculate_percentiles($times);

    // 清理
    echo "  [4/4] 清理测试数据...\n";
    // 删除销售单
    foreach ($orderIds as $oid) {
        httpRequest('DELETE', "{$baseUrl}/api/order/remove", ['id' => $oid], $token);
    }
    // 删除客户
    foreach ($custIds as $cid) {
        httpRequest('DELETE', "{$baseUrl}/api/customer/del", ['id' => $cid], $token);
    }
    // 删除商品和仓库
    httpRequest('DELETE', "{$baseUrl}/api/goods/del", ['id' => $goodsId], $token);
    httpRequest('POST', "{$baseUrl}/api/warehouse/del", ['id' => $whId], $token);
    echo "  清理完成\n";

    return [
        'scenario'       => '场景3: 并发支付',
        'concurrency'    => $concurrency,
        'request_count'  => count($results),
        'success_count'  => $success,
        'p50'            => $percentiles['p50'],
        'p95'            => $percentiles['p95'],
        'p99'            => $percentiles['p99'],
        'avg'            => $percentiles['avg'],
        'slowest'        => $percentiles['max'],
        'fastest'        => $percentiles['min'],
    ];
}

// ─────────────────────────────────────────────
// 主流程
// ─────────────────────────────────────────────

echo "=== BeiMi JXC 性能基准测试 ===\n";
echo "日期: " . date('Y-m-d') . "\n";
echo "环境: PHP " . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . " / MySQL 8.4 / Windows\n";
echo "后端: {$BASE_URL}\n\n";

// 登录
echo "── 登录获取 token ──\n";
$loginRes = httpRequest('POST', "{$BASE_URL}/api/user/login", [
    'account'  => 'jxcadmin',
    'password' => '123456',
    'terminal' => 1,
]);

if (($loginRes['response']['code'] ?? 0) !== 1) {
    $msg = $loginRes['response']['msg'] ?? '未知错误';
    echo "[FATAL] 登录失败: {$msg}\n";
    exit(1);
}

$token = $loginRes['response']['data']['token'] ?? '';
if (empty($token)) {
    echo "[FATAL] 无法获取 token，终止测试。\n";
    exit(1);
}
echo "登录成功\n\n";

// 运行场景
$allStats = [];

foreach ($runScenarios as $scenario) {
    echo "── 运行: {$scenario} ──\n";

    switch ($scenario) {
        case 'list_query':
            $stats = scenario_list_query($BASE_URL, $token, $PREFIX, $LIST_QUERY_DATA_SIZE, $LIST_QUERY_COUNT);
            break;
        case 'batch_create':
            $stats = scenario_batch_create($BASE_URL, $token, $PREFIX, $BATCH_CREATE_COUNT);
            break;
        case 'concurrent_pay':
            $stats = scenario_concurrent_pay($BASE_URL, $token, $PREFIX, $CONCURRENT_PAY_NUM);
            break;
        default:
            continue 2;
    }

    $allStats[] = $stats;
    print_report($stats['scenario'], $stats);
    echo "\n";
}

// 汇总
echo "=== 测试完成 ===\n";
echo "运行场景数: " . count($allStats) . "\n";
echo "结束时间: " . date('Y-m-d H:i:s') . "\n";
