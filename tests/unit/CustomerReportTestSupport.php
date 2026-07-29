<?php

declare(strict_types=1);

namespace tests\unit;

use think\facade\Db;

/** 仅为独立客户报货链路准备和清理测试数据，绝不触及旧订单或销售预定表。 */
trait CustomerReportTestSupport
{
    protected const TENANT_ID = 882101;
    protected const ADMIN_ID = 992101;
    private static bool $customerReportSchemaReady = false;

    protected function prepareCustomerReportRequestContext(): void
    {
        request()->tenantId = self::TENANT_ID;
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
  `is_disabled` tinyint unsigned NOT NULL DEFAULT 0, `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0, PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
        $this->runStatements($sql);
        $root = dirname(__DIR__, 2);
        Db::execute('DROP TABLE IF EXISTS `la_goods_sku_spec_value`');
        Db::execute('DROP TABLE IF EXISTS `la_goods_spec_value`');
        Db::execute('DROP TABLE IF EXISTS `la_goods_sku`');
        Db::execute('DROP TABLE IF EXISTS `la_goods_spec`');
        Db::execute('DROP TABLE IF EXISTS `la_goods_spec_template`');
        $goodsSpecStatements = array_values(array_filter(array_map('trim', explode(';', (string)file_get_contents($root . '/database/migrations/20260603_000002_aquatic_goods_v1.sql')))));
        $this->runStatements(implode(";\n", array_slice($goodsSpecStatements, 0, 5)) . ';');
        $this->runStatements((string)file_get_contents($root . '/database/migrations/20260614_000002_spec_value_goods_id.sql'));
        $this->runStatements((string)file_get_contents($root . '/database/migrations/20260729_000001_create_warehouse_goods_balance.sql'));
        Db::execute('DROP TABLE IF EXISTS `la_customer_report_sale_item`');
        Db::execute('DROP TABLE IF EXISTS `la_customer_report_sale`');
        Db::execute('DROP TABLE IF EXISTS `la_customer_report_reservation`');
        Db::execute('DROP TABLE IF EXISTS `la_customer_report_item`');
        Db::execute('DROP TABLE IF EXISTS `la_customer_report`');
        Db::execute('DROP TABLE IF EXISTS `la_customer_goods_report_preference`');
        $this->runStatements((string)file_get_contents($root . '/database/migrations/20260729_000002_create_customer_report_workflow.sql'));
        self::$customerReportSchemaReady = true;
    }

    protected function cleanCustomerReportData(): void
    {
        foreach (['customer_report_sale_item', 'customer_report_sale', 'customer_report_reservation', 'customer_report_item', 'customer_report', 'customer_goods_report_preference', 'warehouse_goods_balance', 'goods_sku_spec_value', 'goods_spec_value', 'goods_sku', 'goods_spec', 'goods_spec_template', 'goods_units_binding', 'warehouse', 'goods', 'customer'] as $table) {
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
}
