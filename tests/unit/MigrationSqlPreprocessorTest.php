<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MigrationSqlPreprocessorTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $preprocessor = dirname(__DIR__, 2)
            . DIRECTORY_SEPARATOR . 'scripts'
            . DIRECTORY_SEPARATOR . 'lib'
            . DIRECTORY_SEPARATOR . 'MigrationSqlPreprocessor.php';

        self::assertFileExists($preprocessor);
        require_once $preprocessor;
    }

    public function test_it_resolves_every_prefix_placeholder_with_the_configured_prefix(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `{{prefix}}purchase_return_order` (`id` int NOT NULL);
ALTER TABLE `{{prefix}}supply_order` ADD COLUMN `return_status` tinyint NOT NULL DEFAULT 0;
SQL;

        $prepared = \BeiMi\Migration\MigrationSqlPreprocessor::prepare($sql, 'tenantx_');

        self::assertSame(
            <<<'SQL'
CREATE TABLE `tenantx_purchase_return_order` (`id` int NOT NULL);
ALTER TABLE `tenantx_supply_order` ADD COLUMN `return_status` tinyint NOT NULL DEFAULT 0;
SQL,
            $prepared
        );
        self::assertStringNotContainsString('{{prefix}}', $prepared);
        self::assertStringNotContainsString('la_', $prepared);
    }

    public function test_it_rejects_unresolved_template_placeholders(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unresolved migration template placeholder: {{schema}}');

        \BeiMi\Migration\MigrationSqlPreprocessor::prepare(
            'CREATE TABLE `{{prefix}}orders` (`source` varchar(32) DEFAULT \'{{schema}}\');',
            'tenantx_'
        );
    }

    public function test_it_rejects_a_prefix_that_is_not_a_safe_identifier_fragment(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid database table prefix');

        \BeiMi\Migration\MigrationSqlPreprocessor::prepare(
            'CREATE TABLE `{{prefix}}orders` (`id` int NOT NULL);',
            'tenant`; DROP TABLE users; --'
        );
    }

    public function test_database_backed_workflow_test_setup_uses_the_shared_preprocessor(): void
    {
        $support = (string)file_get_contents(__DIR__ . '/CustomerReportTestSupport.php');

        self::assertStringContainsString('MigrationSqlPreprocessor::prepare($sql, \'la_\')', $support);
        self::assertStringNotContainsString(
            'runStatements((string)file_get_contents($root . \'/database/migrations/',
            $support
        );

        foreach (glob(__DIR__ . '/*.php') ?: [] as $testFile) {
            $source = (string)file_get_contents($testFile);
            if (
                !str_contains($source, '/database/migrations/')
                || !str_contains($source, 'Db::execute(')
            ) {
                continue;
            }
            self::assertTrue(
                str_contains($source, 'prepareMigration(')
                || str_contains($source, 'MigrationSqlPreprocessor::prepare('),
                basename($testFile) . ' 直接读取迁移时必须经过共享预处理器'
            );
        }
    }

    public function test_default_goods_category_schema_is_present_in_migration_and_fresh_install(): void
    {
        $root = dirname(__DIR__, 2);
        $migration = (string)file_get_contents(
            $root . '/database/migrations/20260809_000001_add_default_goods_category.sql'
        );
        $prepared = \BeiMi\Migration\MigrationSqlPreprocessor::prepare($migration, 'tenantx_');
        $freshInstall = (string)file_get_contents($root . '/public/install/db/like.sql');

        self::assertStringContainsString('ALTER TABLE `tenantx_tenant_goodscat`', $prepared);
        self::assertStringContainsString('ADD UNIQUE KEY `uk_tenant_default_goodscat`', $prepared);
        self::assertStringContainsString("SELECT tenant.id, '默认分类'", $prepared);
        self::assertStringContainsString('information_schema.COLUMNS', $prepared);
        self::assertStringContainsString('information_schema.STATISTICS', $prepared);
        self::assertStringContainsString('`is_default`  tinyint(1) UNSIGNED', $freshInstall);
        self::assertStringContainsString('`uk_tenant_default_goodscat`', $freshInstall);
    }
}
