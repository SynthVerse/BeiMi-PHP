<?php

declare(strict_types=1);

$autoload = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "Missing vendor/autoload.php. Run composer install before PHPUnit.\n");
    exit(1);
}

require $autoload;

// Boot ThinkPHP application for tests that need facades (Db, Config, etc.)
$app = new \think\App(dirname(__DIR__));
$app->initialize();
