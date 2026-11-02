<?php

declare(strict_types=1);

// 仍由原 bootstrap 严格验证 testing/3307/beimi_test_*，只隔离本轮运行时文件。
require dirname(__DIR__) . '/bootstrap.php';
$app->setRuntimePath(sys_get_temp_dir() . '/beimi-preparation-regression-' . getmypid() . '/');
