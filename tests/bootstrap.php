<?php

declare(strict_types=1);

$autoload = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "Missing vendor/autoload.php. Run composer install before PHPUnit.\n");
    exit(1);
}

require $autoload;

// PHPUnit must never inherit the root .env connection. The dedicated file is
// intentionally untracked and is checked before ThinkPHP initializes facades.
$rootPath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
if (($_SERVER['JXC_PHPUNIT_ENV'] ?? '') !== 'testing') {
    fwrite(STDERR, "Refusing PHPUnit: testing environment marker is required.\n");
    exit(1);
}

$testingEnvFile = $rootPath . '.env.testing';
if (!is_file($testingEnvFile)) {
    fwrite(STDERR, "Refusing PHPUnit: create an untracked .env.testing from .example.env first.\n");
    exit(1);
}

$testingEnv = parse_ini_file($testingEnvFile, true, INI_SCANNER_RAW) ?: [];
$isolated = strtolower(trim((string)($testingEnv['PHPUNIT']['ISOLATED_DATABASE'] ?? '')));
$database = trim((string)($testingEnv['DATABASE']['DATABASE'] ?? ''));
$hostname = strtolower(trim((string)($testingEnv['DATABASE']['HOSTNAME'] ?? '')));
$driver = strtolower(trim((string)($testingEnv['DATABASE']['TYPE'] ?? '')));
$port = trim((string)($testingEnv['DATABASE']['HOSTPORT'] ?? $testingEnv['DATABASE']['PORT'] ?? ''));
if (!in_array($isolated, ['1', 'true', 'yes'], true)
    || !preg_match('/^beimi_test_[a-z0-9_]+$/i', $database)
    || !in_array($hostname, ['127.0.0.1', 'localhost', '::1'], true)
    || $driver !== 'mysql'
    || $port !== '3307') {
    fwrite(STDERR, "Refusing PHPUnit: require local MySQL on port 3307, a beimi_test_* database, and an explicit isolated marker.\n");
    exit(1);
}

// Select .env.testing before initialize; do not load the root .env fallback.
$app = (new \think\App($rootPath))->setEnvName('testing');
$app->initialize();
