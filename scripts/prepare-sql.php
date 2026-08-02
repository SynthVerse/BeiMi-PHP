#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/MigrationSqlPreprocessor.php';

use BeiMi\Migration\MigrationSqlPreprocessor;

$options = getopt('', ['input:', 'output:', 'prefix:']);
$input = (string)($options['input'] ?? '');
$output = (string)($options['output'] ?? '');
$prefix = (string)($options['prefix'] ?? '');

if ($input === '' || $output === '') {
    fwrite(STDERR, "Usage: php scripts/prepare-sql.php --input=<sql> --output=<sql> --prefix=<prefix>\n");
    exit(2);
}

$sql = file_get_contents($input);
if ($sql === false) {
    fwrite(STDERR, "Unable to read SQL input: {$input}\n");
    exit(1);
}

try {
    $prepared = MigrationSqlPreprocessor::prepare($sql, $prefix);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}

if (file_put_contents($output, $prepared) === false) {
    fwrite(STDERR, "Unable to write SQL output: {$output}\n");
    exit(1);
}
