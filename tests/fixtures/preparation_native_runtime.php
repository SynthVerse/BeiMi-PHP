<?php

declare(strict_types=1);

ini_set('display_errors', '0'); ini_set('log_errors', '0');
if (PHP_SAPI !== 'cli' || count($argv) !== 2 || !in_array($argv[1], ['--check', '--serve'], true)) {
    fwrite(STDERR, "native_runtime_invalid_arguments\n"); exit(2);
}
$_SERVER['JXC_PHPUNIT_ENV'] = 'testing';
require __DIR__ . '/preparation_test_bootstrap.php';
require_once dirname(__DIR__) . '/support/PreparationNativeRuntimePrivacy.php';
\tests\support\PreparationNativeRuntimePrivacy::install($app);
require_once dirname(__DIR__) . '/unit/PreparationHttpWorkflowTest.php';
require_once dirname(__DIR__) . '/support/PreparationNativeRuntimeGate.php';
require_once dirname(__DIR__) . '/support/PreparationNativeRuntimeSupervisor.php';
$directory = dirname(__DIR__, 2) . '/.local/final-runtime-backend-20261026/service-attempt-03';
$checkOnly = $argv[1] === '--check'; $evidence = []; $success = false; $failure = null; $failureCode = null;
try {
    \tests\unit\PreparationHttpWorkflowTest::runNativeRuntimeFixture(static function (array $context, array $checks, array $peer, array $runtimeScope, array $privacyPaths) use ($checkOnly): void {
        \tests\support\PreparationNativeRuntimeSupervisor::run($context, $checks, $peer, $checkOnly, $runtimeScope, $privacyPaths);
    }, $evidence);
    $success = ($evidence['peerRestored'] ?? false) === true;
} catch (\Throwable $error) {
    $failure = get_class($error);
    $failureCode = preg_match('/^native_runtime_[a-z0-9_]+$/D', $error->getMessage()) === 1 ? $error->getMessage() : 'native_runtime_failed';
}
$result = ['runId' => \tests\support\PreparationNativeRuntimeGate::RUN, 'dispatchId' => \tests\support\PreparationNativeRuntimeGate::DISPATCH,
    'finishedAt' => gmdate('c'), 'checkOnly' => $checkOnly, 'passed' => $success, 'failureClass' => $failure, 'failureCode' => $failureCode,
    'fixtureEvidence' => $evidence, 'independentCleanupStillRequired' => true];
\tests\unit\PreparationHttpWorkflowTest::writeNativeRuntimeJson($directory . ($checkOnly ? '/selfcheck-final.json' : '/service-final.json'), $result);
echo json_encode(['kind' => 'stopped', 'passed' => $success, 'checkOnly' => $checkOnly, 'independentCleanupStillRequired' => true], JSON_THROW_ON_ERROR) . "\n";
exit($success ? 0 : 1);
