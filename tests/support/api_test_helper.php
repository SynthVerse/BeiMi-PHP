<?php

declare(strict_types=1);

final class TestRuntime
{
    public int $total = 0;
    public int $passed = 0;
    public int $failed = 0;

    public function assertCode(array $response, int $expectedCode, string $testName): bool
    {
        $this->total++;

        $actual = (int)($response['code'] ?? -999999);
        if ($actual === $expectedCode) {
            echo "[PASS] {$testName}\n";
            $this->passed++;
            return true;
        }

        $reason = (string)($response['msg'] ?? json_encode($response, JSON_UNESCAPED_UNICODE));
        echo "[FAIL] {$testName}: 期望 code={$expectedCode}, 实际 code={$actual}, msg={$reason}\n";
        $this->failed++;
        return false;
    }

    public function assertTrue(bool $condition, string $testName, string $detail = ''): bool
    {
        $this->total++;

        if ($condition) {
            echo "[PASS] {$testName}\n";
            $this->passed++;
            return true;
        }

        $suffix = $detail !== '' ? ": {$detail}" : '';
        echo "[FAIL] {$testName}{$suffix}\n";
        $this->failed++;
        return false;
    }

    public function assertMoney($actual, $expected, string $testName): bool
    {
        $this->total++;

        $actualText = number_format((float)$actual, 2, '.', '');
        $expectedText = number_format((float)$expected, 2, '.', '');
        if ($actualText === $expectedText) {
            echo "[PASS] {$testName} (值={$actualText})\n";
            $this->passed++;
            return true;
        }

        echo "[FAIL] {$testName}: 实际={$actualText}, 期望={$expectedText}\n";
        $this->failed++;
        return false;
    }

    public function assertInt($actual, $expected, string $testName): bool
    {
        $this->total++;

        if ((int)$actual === (int)$expected) {
            echo "[PASS] {$testName} (值=" . (int)$actual . ")\n";
            $this->passed++;
            return true;
        }

        echo "[FAIL] {$testName}: 实际=" . (int)$actual . ", 期望=" . (int)$expected . "\n";
        $this->failed++;
        return false;
    }

    public function printSummary(): int
    {
        echo "\n=== 结果: {$this->passed}/{$this->total} 通过 ===\n";
        return $this->failed > 0 ? 1 : 0;
    }
}

function test_base_url(array $argv, string $default = 'http://127.0.0.1:8787'): string
{
    return isset($argv[1]) ? rtrim((string)$argv[1], '/') : $default;
}

function test_run_id(): string
{
    static $runId = null;
    if ($runId === null) {
        $runId = date('YmdHis') . '_' . random_int(1000, 9999);
    }

    return $runId;
}

function test_name(string $name): string
{
    return $name . '_' . test_run_id();
}

function short_test_name(string $prefix): string
{
    return $prefix . substr(preg_replace('/\D/', '', test_run_id()), -8);
}

function http_request(string $method, string $url, array $data = [], string $token = ''): array
{
    $ch = curl_init();

    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token !== '') {
        $headers[] = 'token: ' . $token;
    }

    if (($method === 'GET' || $method === 'DELETE') && !empty($data)) {
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
            break;
        case 'GET':
        default:
            break;
    }

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err !== '') {
        return ['code' => -1, 'msg' => 'cURL error: ' . $err, 'data' => []];
    }

    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        return ['code' => -1, 'msg' => 'JSON decode error: ' . (string)$raw, 'data' => []];
    }

    return $decoded;
}

function multi_json_post(array $requests, string $token = ''): array
{
    $mh = curl_multi_init();
    $handles = [];
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token !== '') {
        $headers[] = 'token: ' . $token;
    }

    foreach ($requests as $index => $request) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, (string)$request['url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request['data'] ?? [], JSON_UNESCAPED_UNICODE));
        curl_multi_add_handle($mh, $ch);
        $handles[$index] = $ch;
    }

    do {
        $status = curl_multi_exec($mh, $running);
        if ($running > 0) {
            curl_multi_select($mh, 1.0);
        }
    } while ($running > 0 && $status === CURLM_OK);

    $results = [];
    foreach ($handles as $index => $ch) {
        $raw = curl_multi_getcontent($ch);
        $err = curl_error($ch);
        if ($err !== '') {
            $results[$index] = ['code' => -1, 'msg' => 'cURL error: ' . $err, 'data' => []];
        } else {
            $decoded = json_decode((string)$raw, true);
            $results[$index] = is_array($decoded)
                ? $decoded
                : ['code' => -1, 'msg' => 'JSON decode error: ' . (string)$raw, 'data' => []];
        }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }

    curl_multi_close($mh);
    ksort($results);

    return array_values($results);
}

function extract_id(array $response, string $key = 'id'): ?int
{
    return isset($response['data'][$key]) ? (int)$response['data'][$key] : null;
}

function extract_list(array $response): array
{
    $items = $response['data']['data'] ?? $response['data']['customers'] ?? $response['data'] ?? [];
    return is_array($items) ? $items : [];
}

function list_contains(array $items, string $field, string $value): bool
{
    foreach ($items as $item) {
        if (isset($item[$field]) && (string)$item[$field] === $value) {
            return true;
        }
    }

    return false;
}

function list_contains_id(array $items, int $targetId, string $field = 'id'): bool
{
    foreach ($items as $item) {
        if (isset($item[$field]) && (int)$item[$field] === $targetId) {
            return true;
        }
    }

    return false;
}

function find_id_in_list(array $response, string $field, string $value): ?int
{
    foreach (extract_list($response) as $item) {
        if (isset($item[$field]) && (string)$item[$field] === $value) {
            return isset($item['id']) ? (int)$item['id'] : null;
        }
    }

    return null;
}

function detail_hidden_from_tenant(array $response, int $targetId): bool
{
    $data = $response['data'] ?? [];
    if ((int)($response['code'] ?? 0) !== 1) {
        return true;
    }

    if (!is_array($data) || empty($data)) {
        return true;
    }

    return (int)($data['id'] ?? 0) !== $targetId;
}

function customer_receivable(array $response): string
{
    return number_format((float)($response['data']['order_receivable'] ?? 0), 2, '.', '');
}

function customer_paid(array $response): string
{
    return number_format((float)($response['data']['order_pay_money'] ?? 0), 2, '.', '');
}

function goods_stock(array $response): string
{
    return number_format((float)($response['data']['stock'] ?? 0), 2, '.', '');
}

function purchase_status(array $response): string
{
    return (string)($response['data']['status'] ?? '');
}

function load_env_config(): array
{
    static $env = null;
    if ($env !== null) {
        return $env;
    }

    $envPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';
    $env = parse_ini_file($envPath, true, INI_SCANNER_TYPED);
    if (!is_array($env)) {
        throw new RuntimeException("Failed to read .env at {$envPath}");
    }

    return $env;
}

function test_pdo(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $env = load_env_config();
    $db = $env['DATABASE'] ?? [];
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $db['HOSTNAME'] ?? '127.0.0.1',
        $db['HOSTPORT'] ?? '3306',
        $db['DATABASE'] ?? '',
        $db['CHARSET'] ?? 'utf8mb4'
    );

    $pdo = new PDO($dsn, (string)($db['USERNAME'] ?? ''), (string)($db['PASSWORD'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}

function db_prefix(): string
{
    $env = load_env_config();
    return (string)($env['DATABASE']['PREFIX'] ?? 'la_');
}

function db_table(string $table): string
{
    return db_prefix() . $table;
}

function db_value(string $sql, array $params = [])
{
    $stmt = test_pdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function db_exec(string $sql, array $params = []): int
{
    $stmt = test_pdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

function login_default_admin(string $baseUrl): array
{
    return http_request('POST', $baseUrl . '/api/user/login', [
        'account' => 'jxcadmin',
        'password' => '123456',
        'terminal' => 1,
    ]);
}
