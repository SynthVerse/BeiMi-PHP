<?php

declare(strict_types=1);

namespace tests\support;

use BeiMi\Migration\MigrationSqlPreprocessor;
use think\facade\Db;

require_once __DIR__ . '/IsolatedDatabaseGuard.php';
require_once dirname(__DIR__, 2) . '/scripts/lib/MigrationSqlPreprocessor.php';

/** 商品分类测试共用正式迁移，避免复用手写夹具留下的不完整表。 */
final class GoodsCategoryTestSchema
{
    private static bool $ready = false;

    public static function ensure(): void
    {
        if (self::$ready) {
            return;
        }

        $connection = Db::connect();
        if (!IsolatedDatabaseGuard::acceptsConnection($connection->getConfig())) {
            throw new \RuntimeException('Refusing to rebuild goods category schema outside the isolated test database.');
        }

        // MySQL DDL commits implicitly: callers must prepare schema before starting a test transaction.
        Db::execute('DROP TABLE IF EXISTS `la_tenant_goodscat`');
        foreach ([
            '20260602_000002_create_tenant_goodscat.sql',
            '20260809_000001_add_default_goods_category.sql',
            '20260810_000001_add_platform_default_goods_category.sql',
        ] as $migration) {
            $sql = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/' . $migration);
            if ($sql === false) {
                throw new \RuntimeException('Missing goods category migration: ' . $migration);
            }
            $sql = MigrationSqlPreprocessor::prepare($sql, 'la_');
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
                Db::execute($statement);
            }
        }

        self::$ready = true;
    }
}
