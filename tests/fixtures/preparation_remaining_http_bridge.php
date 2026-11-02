<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '0');
// 准入失败时不加载应用，不连接 DB，不启动 HTTP；目标、客户端和核心编号均固定。
$projectRoot = dirname(__DIR__, 2);
$frontendRoot = dirname($projectRoot) . '/BeiMi-uniapp';
require_once dirname(__DIR__) . '/support/IsolatedDatabaseGuard.php';
require_once dirname(__DIR__) . '/support/PreparationHttpBridgeProcess.php';
require_once dirname(__DIR__) . '/support/PreparationCurrentHttpSource.php';
require_once dirname(__DIR__) . '/support/PreparationRemainingHttpFixture.php';

use tests\support\IsolatedDatabaseGuard;
use tests\support\PreparationHttpBridgeProcess;
use tests\support\PreparationCurrentHttpSource;
use tests\support\PreparationRemainingHttpFixture as Fixture;

$mode = null; $target = null;
foreach (array_slice($argv, 1) as $argument) {
    if (in_array($argument, ['--preflight', '--execute'], true) && $mode === null) { $mode = $argument; }
    elseif (preg_match('/^--target=([a-z_]+)$/D', $argument, $match) === 1 && $target === null
        && in_array($match[1], Fixture::TARGETS, true)) { $target = $match[1]; }
    else { fwrite(STDERR, "bridge_invalid_arguments\n"); exit(2); }
}
if ($target === null) { fwrite(STDERR, "bridge_invalid_arguments\n"); exit(2); }
$mode ??= '--preflight';
$nodeBinary = (string)getenv('JXC_PREPARATION_BRIDGE_NODE');
$clientPath = $frontendRoot . '/' . Fixture::CLIENT;
$contractPath = $frontendRoot . '/' . Fixture::CONTRACT;
$expectedIds = Fixture::expectedIds($target);
$contract = is_file($contractPath) ? json_decode((string)file_get_contents($contractPath), true) : null;
$sourceAdmission = null; $sourceWhitelist = [];
try {
    $sourceAdmission = PreparationCurrentHttpSource::forTarget($target, $frontendRoot, $nodeBinary, $expectedIds);
    $sourceWhitelist = $sourceAdmission['sources']; unset($sourceAdmission['sources']);
} catch (\Throwable) { /* 显式来源门失败必须关闭，不回退历史指纹。 */ }
$environment = is_file($projectRoot . '/.env.testing') ? (parse_ini_file($projectRoot . '/.env.testing', true, INI_SCANNER_RAW) ?: []) : [];
$checks = [
    ['id' => 'isolated-environment-contract', 'passed' => IsolatedDatabaseGuard::acceptsEnvironment($environment)
        && ($environment['DATABASE']['DATABASE'] ?? null) === 'beimi_test_inventory'],
    ['id' => 'frontend-client-present', 'passed' => is_file($clientPath)],
    ['id' => 'node-binary-present', 'passed' => $nodeBinary !== '' && is_file($nodeBinary)],
    ['id' => 'phpunit-autoload-present', 'passed' => is_file($projectRoot . '/vendor/autoload.php')],
    ['id' => 'scope-lock-directory-present', 'passed' => is_dir($projectRoot . '/.local')],
    ['id' => 'remaining-fixture-contract', 'passed' => is_array($contract) && Fixture::acceptsContract($contract, $target)],
    ['id' => 'remaining-frozen-sources', 'passed' => $sourceAdmission !== null],
];
$sources = ['tests/fixtures/preparation_remaining_http_bridge.php', 'tests/fixtures/preparation_http_router.php',
    'tests/fixtures/preparation_test_bootstrap.php', 'tests/support/PreparationHttpBridgeProcess.php',
    'tests/support/PreparationCurrentHttpSource.php', 'tests/support/PreparationIsolatedHttpFixture.php',
    'tests/support/PreparationRemainingHttpFixture.php', 'tests/support/IsolatedDatabaseGuard.php', 'tests/bootstrap.php',
    'app/api/route/jxc.php'];
foreach (['app/api/jxc/logic/Finance*.php', 'app/api/jxc/controller/Finance*.php'] as $pattern) {
    foreach (glob($projectRoot . '/' . $pattern) as $file) { $sources[] = substr($file, strlen($projectRoot) + 1); }
}
foreach (['CustomerLogic', 'SupplierLogic', 'WorkforceLogic', 'MasterCreationReceipt'] as $name) {
    $sources[] = 'app/api/jxc/logic/' . $name . '.php';
}
$fingerprints = static function () use ($sources, $projectRoot, $frontendRoot, $sourceWhitelist, $sourceAdmission): array {
    $hashes = [];
    foreach (array_unique($sources) as $source) { $hashes['BeiMi-PHP/' . $source] = is_file($projectRoot . '/' . $source) ? hash_file('sha256', $projectRoot . '/' . $source) : null; }
    foreach ($sourceWhitelist as $source => $expected) { $hashes['BeiMi-uniapp/' . $source] = is_file($frontendRoot . '/' . $source) ? hash_file('sha256', $frontendRoot . '/' . $source) : null; }
    if ($sourceAdmission) { $hashes['current-source-manifest'] = hash_file('sha256', $sourceAdmission['manifest']['path']); }
    ksort($hashes); return $hashes;
};
$result = ['revision' => 'remaining-joint-http-20261102-01', 'protocol' => PreparationHttpBridgeProcess::PROTOCOL,
    'runId' => Fixture::RUN_ID, 'target' => $target, 'phase' => 'preflight', 'jointExecuted' => false,
    'passed' => !in_array(false, array_column($checks, 'passed'), true), 'checks' => $checks,
    'expectedCheckIds' => $expectedIds, 'sourceAdmission' => $sourceAdmission,
    'frontendSourceWhitelist' => $sourceWhitelist, 'sourceFingerprints' => $fingerprints()];
if ($mode === '--execute' && $result['passed']) {
    $result['phase'] = 'execution'; $_SERVER['JXC_PHPUNIT_ENV'] = 'testing'; $fixtureEvidence = [];
    ob_start();
    try {
        require __DIR__ . '/preparation_test_bootstrap.php';
        \think\facade\Log::close(array_keys(\think\facade\Config::get('log.channels', [])));
        $result['jointExecutionAttempted'] = true;
        // 仅走已抽取的既有 schema 夹具，绝不调用旧 WorkflowTest 的 setUp/runFrontendHttpBridge。
        $result['clientResult'] = Fixture::run($target, $clientPath, $nodeBinary, $fixtureEvidence);
        $result['jointExecuted'] = true;
        $expectedMatch = PreparationHttpBridgeProcess::hasExactCheckIds($result['clientResult']['checks'], $expectedIds)
            && array_column($result['clientResult']['checks'], 'id') === $expectedIds;
        $unchanged = $result['sourceFingerprints'] === $fingerprints();
        $result['checks'][] = ['id' => 'remaining-expected-check-set', 'passed' => $expectedMatch];
        $result['checks'][] = ['id' => 'sources-unchanged-during-execution', 'passed' => $unchanged];
        $result['passed'] = $result['clientResult']['passed'] && $expectedMatch && $unchanged;
    } catch (\Throwable $error) {
        $result['passed'] = false; $message = $error->getMessage();
        $result['error'] = preg_match('/^bridge_[a-z_]+$/D', $message) === 1 ? $message : 'bridge_execution_failed';
        fwrite(STDERR, $result['error'] . "\n");
    } finally {
        if ($result['jointExecutionAttempted'] ?? false) {
            $result['fixtureEvidence'] = $fixtureEvidence;
            $restored = ($fixtureEvidence['verified'] ?? false) === true;
            $result['checks'][] = ['id' => 'remaining-fixture-restored', 'passed' => $restored];
            $result['passed'] = $result['passed'] && $restored;
        }
        ob_end_clean();
    }
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
exit($result['passed'] ? 0 : 1);
