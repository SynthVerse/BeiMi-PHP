<?php

declare(strict_types=1);

namespace tests\unit;

use BeiMi\Migration\MigrationSqlPreprocessor;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/scripts/lib/MigrationSqlPreprocessor.php';

final class GoodsSupplierCleanupMigrationTest extends TestCase
{
    private PDO $pdo;
    private string $prefix;
    private array $tables = [];

    protected function setUp(): void
    {
        // tests/bootstrap.php has already required the isolated 127.0.0.1:3307 environment.
        $env = parse_ini_file(dirname(__DIR__, 2) . '/.env.testing', true, INI_SCANNER_RAW);
        $db = $env['DATABASE'];
        $this->pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $db['HOSTNAME'], $db['HOSTPORT'], $db['DATABASE']),
            $db['USERNAME'],
            $db['PASSWORD'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $this->prefix = 'cleanup_' . bin2hex(random_bytes(4)) . '_';
    }

    protected function tearDown(): void
    {
        foreach ($this->tables as $table) {
            $this->pdo->exec("DROP TABLE IF EXISTS `{$table}`");
        }
    }

    public function test_cleanup_preserves_relations_and_transaction_prices_and_can_run_twice(): void
    {
        $this->createLegacyRelations();
        $this->createTable('goods_supplier_price_history', 'id int PRIMARY KEY, purchase_price decimal(12,2)');
        $this->pdo->exec("INSERT INTO `{$this->prefix}goods_supplier_price_history` VALUES (1, 8.50)");
        $this->createTable('goods', 'id int PRIMARY KEY, cost decimal(12,2)');
        $this->pdo->exec("INSERT INTO `{$this->prefix}goods` VALUES (57, 6.50)");
        $this->createTable('order_goods', 'id int PRIMARY KEY, price decimal(12,2), total_price decimal(12,2)');
        $this->pdo->exec("INSERT INTO `{$this->prefix}order_goods` VALUES (101, 12.50, 25.00)");
        $this->createTable('finance_purchase_price', 'id int PRIMARY KEY, tenant_id int, document_id int, price decimal(16,2)');
        $this->pdo->exec("INSERT INTO `{$this->prefix}finance_purchase_price` VALUES (201, 1, 301, 13.25)");
        $before = $this->pdo->query("SELECT * FROM `{$this->prefix}goods_supplier`")->fetchAll();
        foreach ($before as &$row) {
            unset($row['purchase_price'], $row['daily_capacity_qty'], $row['min_purchase_qty'], $row['lead_time_days']);
        }
        unset($row);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $report = $this->runCleanup();
            self::assertSame(0, (int)$report['retired_columns_remaining']);
            self::assertSame(0, (int)$report['retired_history_tables_remaining']);
            self::assertSame($before, $this->pdo->query("SELECT * FROM `{$this->prefix}goods_supplier`")->fetchAll());
            self::assertSame('6.50', $this->pdo->query("SELECT cost FROM `{$this->prefix}goods`")->fetchColumn());
            self::assertSame(['price' => '12.50', 'total_price' => '25.00'], $this->pdo->query("SELECT price, total_price FROM `{$this->prefix}order_goods`")->fetch());
            self::assertSame('13.25', $this->pdo->query("SELECT price FROM `{$this->prefix}finance_purchase_price` WHERE id=201 AND document_id=301")->fetchColumn());
        }
    }

    public function test_partially_removed_columns_and_absent_history_can_be_cleaned(): void
    {
        $this->createLegacyRelations();
        $this->pdo->exec("ALTER TABLE `{$this->prefix}goods_supplier` DROP COLUMN purchase_price, DROP COLUMN daily_capacity_qty");

        $report = $this->runCleanup();

        self::assertSame(0, (int)$report['retired_columns_remaining']);
        self::assertSame(0, (int)$report['retired_history_tables_remaining']);
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM `{$this->prefix}goods_supplier`")->fetchColumn());
    }

    public function test_missing_relation_table_stops_before_deleting_history(): void
    {
        $this->createTable('goods_supplier_price_history', 'id int PRIMARY KEY, purchase_price decimal(12,2)');
        $this->pdo->exec("INSERT INTO `{$this->prefix}goods_supplier_price_history` VALUES (1, 8.50)");

        try {
            $this->runCleanup();
            self::fail('缺失供应关系表时必须停止，不能误报清理成功');
        } catch (PDOException $error) {
            self::assertSame('42S02', $error->getCode());
        }
        self::assertSame('8.50', $this->pdo->query("SELECT purchase_price FROM `{$this->prefix}goods_supplier_price_history`")->fetchColumn());
    }

    private function createLegacyRelations(): void
    {
        $this->createTable('goods_supplier', 'id int PRIMARY KEY, tenant_id int, goods_id int, sku_id int, supplier_id int,
            purchase_unit_id int, settlement_unit_id int, is_preferred tinyint, status tinyint,
            supplier_product_code varchar(40), remark varchar(100), last_purchase_price decimal(12,2),
            purchase_price decimal(12,2), daily_capacity_qty decimal(12,4), min_purchase_qty decimal(12,4), lead_time_days int');
        $this->pdo->exec("INSERT INTO `{$this->prefix}goods_supplier` VALUES
            (49, 1, 57, 67, 15, 40, 40, 1, 1, 'SUP-01', '保留备注', 12.50, 8.50, 100, 5, 2),
            (50, 2, 58, 68, 16, 41, 40, 0, 0, 'SUP-02', '停用关系', 9.00, 7.00, 50, 3, 1)");
    }

    private function createTable(string $suffix, string $columns): void
    {
        $table = $this->prefix . $suffix;
        $this->pdo->exec("CREATE TABLE `{$table}` ({$columns}) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->tables[] = $table;
    }

    private function runCleanup(): array
    {
        $path = dirname(__DIR__, 2) . '/database/migrations/20261108_000001_retire_goods_supplier_defaults.sql';
        self::assertFileExists($path);
        $sql = MigrationSqlPreprocessor::prepare((string)file_get_contents($path), $this->prefix);
        $statements = array_filter(array_map('trim', preg_split('/;\s*[\r\n]+/', $sql) ?: []));
        $lastResult = [];
        foreach ($statements as $statement) {
            $handle = $this->pdo->prepare($statement);
            $handle->execute();
            try {
                do {
                    $rows = $handle->fetchAll();
                    if ($rows !== []) {
                        $lastResult = $rows[0];
                    }
                } while ($handle->nextRowset());
            } finally {
                $handle->closeCursor();
            }
        }
        return $lastResult;
    }
}
