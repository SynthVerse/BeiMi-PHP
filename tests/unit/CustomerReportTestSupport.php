<?php

declare(strict_types=1);

namespace tests\unit;

use BeiMi\Migration\MigrationSqlPreprocessor;
use think\facade\Db;

require_once dirname(__DIR__, 2) . '/scripts/lib/MigrationSqlPreprocessor.php';

/** 为客户报货到标准销售单的新工作流准备和清理测试数据。 */
trait CustomerReportTestSupport
{
    protected const TENANT_ID = 882101;
    protected const OTHER_TENANT_ID = 882102;
    protected const ADMIN_ID = 992101;
    private static bool $customerReportSchemaReady = false;

    protected function prepareCustomerReportRequestContext(int $tenantId = self::TENANT_ID): void
    {
        request()->tenantId = $tenantId;
        request()->adminId = self::ADMIN_ID;
        request()->userId = self::ADMIN_ID;
    }

    protected function ensureCustomerReportTables(): void
    {
        if (self::$customerReportSchemaReady) {
            return;
        }
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `la_goods` (
  `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `name` varchar(200) NOT NULL DEFAULT '', `product_code` varchar(100) NOT NULL DEFAULT '',
  `units` varchar(50) NOT NULL DEFAULT '', `unit_id` int unsigned NOT NULL DEFAULT 0,
  `price` decimal(18,2) NOT NULL DEFAULT 0.00, `cost` decimal(18,2) NOT NULL DEFAULT 0.00,
  `stock` decimal(18,4) NOT NULL DEFAULT 0.0000, `category_id` int unsigned NOT NULL DEFAULT 0,
  `is_disabled` tinyint unsigned NOT NULL DEFAULT 0, `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0, PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `la_goods_units_binding` (
  `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `goods_id` int unsigned NOT NULL DEFAULT 0, `unit_id` int unsigned NOT NULL DEFAULT 0,
  `unit_name` varchar(50) NOT NULL DEFAULT '', `is_base_unit` tinyint NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1, `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0, PRIMARY KEY (`id`),
  UNIQUE KEY `uk_customer_report_goods_unit` (`tenant_id`,`goods_id`,`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `la_warehouse` (
  `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `name` varchar(100) NOT NULL DEFAULT '', `is_enabled` tinyint unsigned NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0, `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `la_customer` (
  `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `customer_name` varchar(100) NOT NULL DEFAULT '', `parent_id` int unsigned NOT NULL DEFAULT 0,
  `order_receivable` decimal(18,2) NOT NULL DEFAULT 0.00, `order_money` decimal(18,2) NOT NULL DEFAULT 0.00,
  `is_disabled` tinyint unsigned NOT NULL DEFAULT 0, `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0, PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `la_tenant` (
  `id` int unsigned NOT NULL AUTO_INCREMENT, `sn` varchar(50) NOT NULL,
  `name` varchar(32) NOT NULL DEFAULT '', `disable` tinyint unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0, `update_time` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `la_sales_order` (
  `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `order_sn` varchar(64) NOT NULL DEFAULT '', `customer_id` int unsigned NOT NULL DEFAULT 0,
  `customer_name` varchar(100) NOT NULL DEFAULT '', `warehouse_id` int unsigned NOT NULL DEFAULT 0,
  `order_money` decimal(18,2) NOT NULL DEFAULT 0.00, `order_pay_money` decimal(18,2) NOT NULL DEFAULT 0.00,
  `order_arrears_money` decimal(18,2) NOT NULL DEFAULT 0.00, `datetimesingle` int unsigned NOT NULL DEFAULT 0,
  `source_type` varchar(32) DEFAULT NULL, `source_id` int unsigned DEFAULT NULL,
  `source_version` int unsigned DEFAULT NULL,
  `status` tinyint unsigned NOT NULL DEFAULT 1, `purpose_type` varchar(50) NOT NULL DEFAULT 'sales',
  `remarks` varchar(500) NOT NULL DEFAULT '', `admin_id` int unsigned NOT NULL DEFAULT 0,
  `idempotent_key` varchar(96) NOT NULL DEFAULT '', `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0, PRIMARY KEY (`id`),
  UNIQUE KEY `uk_customer_report_sales_order_sn` (`tenant_id`,`order_sn`),
  UNIQUE KEY `uk_customer_report_sales_order_source` (`tenant_id`,`source_type`,`source_id`,`warehouse_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `la_order_goods` (
  `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `order_id` int unsigned NOT NULL DEFAULT 0, `order_type` varchar(30) NOT NULL DEFAULT '',
  `goods_id` int unsigned NOT NULL DEFAULT 0, `name` varchar(200) NOT NULL DEFAULT '',
  `units` varchar(50) NOT NULL DEFAULT '', `number` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `base_quantity` decimal(18,4) NOT NULL DEFAULT 0.0000, `price` decimal(18,2) NOT NULL DEFAULT 0.00,
  `amount` decimal(18,2) NOT NULL DEFAULT 0.00, `pricing_unit_id` int unsigned NOT NULL DEFAULT 0,
  `source_line_type` varchar(32) NOT NULL DEFAULT '', `source_line_id` int unsigned NOT NULL DEFAULT 0,
  `remark` varchar(500) NOT NULL DEFAULT '', `sort` int NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0, `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `la_stock_flow` (
  `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `warehouse_id` int unsigned NOT NULL DEFAULT 0, `goods_id` int unsigned NOT NULL DEFAULT 0,
  `sku_id` int unsigned NOT NULL DEFAULT 0, `batch_id` int unsigned NOT NULL DEFAULT 0,
  `order_id` int unsigned NOT NULL DEFAULT 0, `order_type` varchar(30) NOT NULL DEFAULT '',
  `order_sn` varchar(64) NOT NULL DEFAULT '', `flow_type` tinyint unsigned NOT NULL DEFAULT 0,
  `quantity` decimal(18,4) NOT NULL DEFAULT 0.0000, `before_stock` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `after_stock` decimal(18,4) NOT NULL DEFAULT 0.0000, `admin_id` int unsigned NOT NULL DEFAULT 0,
  `remark` varchar(255) NOT NULL DEFAULT '', `create_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `la_receivable_flow` (
  `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `customer_id` int unsigned NOT NULL DEFAULT 0, `order_id` int unsigned NOT NULL DEFAULT 0,
  `order_type` varchar(30) NOT NULL DEFAULT '', `order_sn` varchar(64) NOT NULL DEFAULT '',
  `flow_type` tinyint unsigned NOT NULL DEFAULT 0, `amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `before_amount` decimal(18,2) NOT NULL DEFAULT 0.00, `after_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `admin_id` int unsigned NOT NULL DEFAULT 0, `remark` varchar(255) NOT NULL DEFAULT '',
  `create_time` int unsigned NOT NULL DEFAULT 0, PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
        $this->runStatements($sql);
        foreach ([
            'ALTER TABLE `la_customer` ADD COLUMN `order_receivable` decimal(18,2) NOT NULL DEFAULT 0.00',
            'ALTER TABLE `la_customer` ADD COLUMN `order_money` decimal(18,2) NOT NULL DEFAULT 0.00',
            "ALTER TABLE `la_sales_order` ADD COLUMN `source_type` varchar(32) NOT NULL DEFAULT ''",
            'ALTER TABLE `la_sales_order` ADD COLUMN `source_id` int unsigned NOT NULL DEFAULT 0',
            'ALTER TABLE `la_sales_order` ADD COLUMN `source_version` int unsigned NOT NULL DEFAULT 0',
            'ALTER TABLE `la_order_goods` ADD COLUMN `base_quantity` decimal(18,4) NOT NULL DEFAULT 0.0000',
            'ALTER TABLE `la_order_goods` ADD COLUMN `pricing_unit_id` int unsigned NOT NULL DEFAULT 0',
            "ALTER TABLE `la_order_goods` ADD COLUMN `source_line_type` varchar(32) NOT NULL DEFAULT ''",
            'ALTER TABLE `la_order_goods` ADD COLUMN `source_line_id` int unsigned NOT NULL DEFAULT 0',
            'ALTER TABLE `la_stock_flow` ADD COLUMN `sku_id` int unsigned NOT NULL DEFAULT 0',
            'ALTER TABLE `la_stock_flow` ADD COLUMN `batch_id` int unsigned NOT NULL DEFAULT 0',
            'ALTER TABLE `la_sales_order` MODIFY COLUMN `source_type` varchar(32) NULL DEFAULT NULL',
            'ALTER TABLE `la_sales_order` MODIFY COLUMN `source_id` int unsigned NULL DEFAULT NULL',
            'ALTER TABLE `la_sales_order` MODIFY COLUMN `source_version` int unsigned NULL DEFAULT NULL',
        ] as $statement) {
            try {
                Db::execute($statement);
            } catch (\Throwable) {
            }
        }
        Db::execute(
            "INSERT INTO `la_tenant` (`id`,`sn`,`name`,`disable`,`create_time`) VALUES ("
            . self::TENANT_ID
            . ",'customer-report-test','客户报货测试租户',0,"
            . time()
            . ') ON DUPLICATE KEY UPDATE `disable`=0'
        );
        $root = dirname(__DIR__, 2);
        $this->runStatements($this->prepareMigration((string)file_get_contents($root . '/database/migrations/20260419_000002_create_audit_log.sql')));
        foreach ([
            '{{prefix}}warehouse_goods_balance',
            '{{prefix}}goods_sku_spec_value',
            '{{prefix}}goods_spec_value',
            '{{prefix}}goods_sku',
            '{{prefix}}goods_spec',
            '{{prefix}}goods_spec_template',
        ] as $literalPlaceholderTable) {
            Db::execute('DROP TABLE IF EXISTS `' . $literalPlaceholderTable . '`');
        }
        Db::execute('DROP TABLE IF EXISTS `la_goods_sku_spec_value`');
        Db::execute('DROP TABLE IF EXISTS `la_goods_spec_value`');
        Db::execute('DROP TABLE IF EXISTS `la_goods_sku`');
        Db::execute('DROP TABLE IF EXISTS `la_goods_spec`');
        Db::execute('DROP TABLE IF EXISTS `la_goods_spec_template`');
        $goodsSpecStatements = array_values(array_filter(array_map('trim', explode(
            ';',
            $this->prepareMigration((string)file_get_contents($root . '/database/migrations/20260603_000002_aquatic_goods_v1.sql'))
        ))));
        $this->runStatements(implode(";\n", array_slice($goodsSpecStatements, 0, 5)) . ';');
        $this->runStatements($this->prepareMigration((string)file_get_contents($root . '/database/migrations/20260614_000002_spec_value_goods_id.sql')));
        Db::execute('DROP TABLE IF EXISTS `la_warehouse_goods_balance`');
        $this->runStatements($this->prepareMigration((string)file_get_contents($root . '/database/migrations/20260729_000001_create_warehouse_goods_balance.sql')));
        Db::execute('DROP TABLE IF EXISTS `la_customer_report_reservation`');
        Db::execute('DROP TABLE IF EXISTS `la_customer_report_item`');
        Db::execute('DROP TABLE IF EXISTS `la_customer_report`');
        Db::execute('DROP TABLE IF EXISTS `la_customer_goods_report_preference`');
        $this->runStatements($this->prepareMigration((string)file_get_contents($root . '/database/migrations/20260729_000002_create_customer_report_workflow.sql')));
        self::$customerReportSchemaReady = true;
    }

    protected function cleanCustomerReportData(): void
    {
        foreach (['audit_log', 'receivable_flow', 'stock_flow', 'order_goods', 'sales_order', 'customer_report_reservation', 'customer_report_item', 'customer_report', 'customer_goods_report_preference', 'warehouse_goods_balance', 'goods_sku_spec_value', 'goods_spec_value', 'goods_sku', 'goods_spec', 'goods_spec_template', 'goods_units_binding', 'warehouse', 'goods', 'customer'] as $table) {
            try {
                Db::name($table)->where('tenant_id', self::TENANT_ID)->delete();
            } catch (\Throwable) {
            }
        }
    }

    protected function createCustomer(string $name, int $parentId = 0): int
    {
        return (int)Db::name('customer')->insertGetId([
            'tenant_id' => self::TENANT_ID, 'customer_name' => $name, 'parent_id' => $parentId,
            'is_disabled' => 0, 'create_time' => time(), 'update_time' => time(),
        ]);
    }

    protected function createCustomerReportGoods(string $name, string $code, string $unit = '件'): int
    {
        return (int)Db::name('goods')->insertGetId([
            'tenant_id' => self::TENANT_ID, 'name' => $name, 'product_code' => $code . '-' . uniqid(),
            'units' => $unit, 'unit_id' => 0, 'price' => '1.00', 'cost' => '1.00', 'stock' => '0.0000',
            'category_id' => 0, 'is_disabled' => 0, 'create_time' => time(), 'update_time' => time(),
        ]);
    }

    protected function createCustomerReportWarehouse(string $name): int
    {
        return (int)Db::name('warehouse')->insertGetId([
            'tenant_id' => self::TENANT_ID, 'name' => $name, 'is_enabled' => 1,
            'create_time' => time(), 'update_time' => time(),
        ]);
    }

    private function runStatements(string $sql): void
    {
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            Db::execute($statement);
        }
    }

    protected function prepareMigration(string $sql): string
    {
        return MigrationSqlPreprocessor::prepare($sql, 'la_');
    }
}
