<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class UserSessionSchemaContractTest extends TestCase
{
    public function test_existing_user_session_tables_receive_create_time_via_a_repeatable_migration(): void
    {
        $root = dirname(__DIR__, 2);
        $migrationPath = $root . '/database/migrations/20260731_000001_add_user_session_create_time.sql';
        $userTokenService = (string)file_get_contents($root . '/app/api/service/UserTokenService.php');
        $migrationRunner = (string)file_get_contents($root . '/scripts/migrate.php');

        self::assertFileExists($migrationPath);
        self::assertStringContainsString("Db::table('la_user_session')->insert([", $userTokenService);
        self::assertStringContainsString("'create_time' => \$time", $userTokenService);
        self::assertStringContainsString('MigrationSqlPreprocessor::prepare($sql, $prefix)', $migrationRunner);

        $migration = (string)file_get_contents($migrationPath);
        self::assertStringContainsString("TABLE_NAME = '{{prefix}}user_session'", $migration);
        self::assertStringContainsString("COLUMN_NAME = 'create_time'", $migration);
        self::assertStringContainsString(
            "ALTER TABLE `{{prefix}}user_session` ADD COLUMN `create_time` int(10) NULL DEFAULT NULL COMMENT ''创建时间'' AFTER `token`",
            $migration
        );
    }

    public function test_new_tenant_user_session_tables_include_create_time(): void
    {
        $root = dirname(__DIR__, 2);
        $tenantSchema = (string)file_get_contents($root . '/app/platformapi/db/tenant.sql');
        $userSessionTablePattern = '/CREATE TABLE `la_user_session_\\{tenantSn\\}`\\s*\\((.*?)\\) ENGINE/s';

        self::assertSame(
            1,
            preg_match(
                $userSessionTablePattern,
                $tenantSchema,
                $matches
            )
        );
        self::assertArrayHasKey(1, $matches);

        self::assertMatchesRegularExpression(
            '/`create_time`\\s+int\\(10\\)\\s+NULL DEFAULT NULL COMMENT \'创建时间\'/',
            $matches[1]
        );
    }
}
