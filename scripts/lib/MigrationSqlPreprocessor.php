<?php

declare(strict_types=1);

namespace BeiMi\Migration;

final class MigrationSqlPreprocessor
{
    public static function assertPrefixIsSafe(string $prefix): void
    {
        if (preg_match('/\A[A-Za-z0-9_]*\z/', $prefix) !== 1) {
            throw new \InvalidArgumentException('Invalid database table prefix.');
        }
    }

    public static function prepare(string $sql, string $prefix): string
    {
        self::assertPrefixIsSafe($prefix);

        $prepared = str_replace('{{prefix}}', $prefix, $sql);
        if (preg_match('/\{\{[^{}]+\}\}/', $prepared, $match) === 1) {
            throw new \RuntimeException(
                'Unresolved migration template placeholder: ' . $match[0]
            );
        }

        return $prepared;
    }
}
