<?php

declare(strict_types=1);

namespace tests\unit;

use BeiMi\Migration\MigrationSqlPreprocessor;
use app\api\jxc\logic\WarehouseSkuBalanceService;
use app\common\service\goods\GoodsAliasService;
use app\common\service\goods\GoodsBaseSkuService;
use think\facade\Db;

require_once dirname(__DIR__, 2) . '/scripts/lib/MigrationSqlPreprocessor.php';

/** 让旧工作流测试用商品 ID 简洁地定位该商品的唯一/首个有效 SKU。 */
final class WarehouseSkuBalanceForGoodsTestAdapter
{
    public static function inbound(int $warehouseId, int $goodsId, string $quantity)
    {
        return WarehouseSkuBalanceService::inbound($warehouseId, self::skuId($goodsId), $quantity);
    }

    public static function available(int $warehouseId, int $goodsId): string
    {
        return WarehouseSkuBalanceService::available($warehouseId, self::skuId($goodsId));
    }

    public static function onHand(int $warehouseId, int $goodsId): string
    {
        return WarehouseSkuBalanceService::onHand($warehouseId, self::skuId($goodsId));
    }

    public static function reserved(int $warehouseId, int $goodsId): string
    {
        return WarehouseSkuBalanceService::reserved($warehouseId, self::skuId($goodsId));
    }

    private static function skuId(int $goodsId): int
    {
        return (int)Db::name('goods_sku')
            ->where('tenant_id', (int)(request()->tenantId ?? 0))
            ->where('goods_id', $goodsId)
            ->where('status', 1)
            ->where('dimension_disabled_snapshot', 0)
            ->order(['sort' => 'asc', 'id' => 'asc'])
            ->value('id');
    }
}

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
        request()->jxcFromUserToken = false;
        request()->adminInfo = [
            'admin_id' => self::ADMIN_ID,
            'tenant_id' => $tenantId,
            'root' => 1,
        ];
    }

    protected function ensureCustomerReportTables(): void
    {
        if (self::$customerReportSchemaReady) {
            return;
        }
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `la_goods` (
  `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `name` varchar(200) NOT NULL DEFAULT '', `normalized_name` varchar(200) NOT NULL DEFAULT '',
  `product_code` varchar(100) NOT NULL DEFAULT '',
  `units` varchar(50) NOT NULL DEFAULT '', `unit_id` int unsigned NOT NULL DEFAULT 0,
  `price` decimal(18,2) NOT NULL DEFAULT 0.00, `cost` decimal(18,2) NOT NULL DEFAULT 0.00,
  `stock` decimal(18,4) NOT NULL DEFAULT 0.0000, `category_id` int unsigned NOT NULL DEFAULT 0,
  `primary_supplier_id` int unsigned NOT NULL DEFAULT 0, `is_disabled` tinyint unsigned NOT NULL DEFAULT 0,
  `is_archived` tinyint unsigned NOT NULL DEFAULT 0, `remark` varchar(500) NOT NULL DEFAULT '',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0, PRIMARY KEY (`id`),
  KEY `idx_customer_report_tenant_normalized_name` (`tenant_id`,`normalized_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `la_goods_unit` (
  `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `name` varchar(50) NOT NULL DEFAULT '', `status` tinyint NOT NULL DEFAULT 1,
  `sort` int NOT NULL DEFAULT 0, `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0, PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `la_goods_alias` (
  `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `goods_id` int unsigned NOT NULL DEFAULT 0, `cloud_goods_id` int unsigned NOT NULL DEFAULT 0,
  `alias` varchar(200) NOT NULL DEFAULT '', `normalized_alias` varchar(200) NOT NULL DEFAULT '',
  `source` varchar(20) NOT NULL DEFAULT 'tenant', `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0, PRIMARY KEY (`id`),
  UNIQUE KEY `uk_customer_report_normalized_alias` (`tenant_id`,`normalized_alias`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `la_goods_units_binding` (
  `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `goods_id` int unsigned NOT NULL DEFAULT 0, `unit_id` int unsigned NOT NULL DEFAULT 0,
  `unit_name` varchar(50) NOT NULL DEFAULT '', `is_base_unit` tinyint NOT NULL DEFAULT 0,
  `sort` int NOT NULL DEFAULT 0, `status` tinyint NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
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
  `phone` varchar(20) NOT NULL DEFAULT '', `address` varchar(255) NOT NULL DEFAULT '',
  `order_receivable` decimal(18,2) NOT NULL DEFAULT 0.00, `order_money` decimal(18,2) NOT NULL DEFAULT 0.00,
  `is_disabled` tinyint unsigned NOT NULL DEFAULT 0, `is_archived` tinyint unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
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
  `goods_id` int unsigned NOT NULL DEFAULT 0, `sku_id` int unsigned NOT NULL DEFAULT 0,
  `sku_name` varchar(200) NOT NULL DEFAULT '', `supplier_relation_id` int unsigned NOT NULL DEFAULT 0,
  `name` varchar(200) NOT NULL DEFAULT '',
  `units` varchar(50) NOT NULL DEFAULT '', `number` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `base_quantity` decimal(18,4) NOT NULL DEFAULT 0.0000, `price` decimal(18,2) NOT NULL DEFAULT 0.00,
  `amount` decimal(18,2) NOT NULL DEFAULT 0.00, `pricing_unit_id` int unsigned NOT NULL DEFAULT 0,
  `source_line_type` varchar(32) NOT NULL DEFAULT '', `source_line_id` int unsigned NOT NULL DEFAULT 0,
  `remark` varchar(500) NOT NULL DEFAULT '', `sort` int NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0, `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), KEY `idx_customer_report_order_goods_sku` (`tenant_id`,`sku_id`),
  KEY `idx_customer_report_order_goods_supplier_relation` (`tenant_id`,`supplier_relation_id`)
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
        $root = dirname(__DIR__, 2);
        Db::execute('DROP TABLE IF EXISTS `la_goods_supplier`');
        Db::execute('DROP TABLE IF EXISTS `la_goods_supplier_price_history`');
        Db::execute('DROP TABLE IF EXISTS `la_goods_unit_conversion_rule`');
        Db::execute('DROP TABLE IF EXISTS `la_sales_return_order`');
        $this->runStatements($sql);
        $this->runStatements($this->authoritativeCreateTable(
            (string)file_get_contents($root . '/database/sql/jxc_phase1_schema.sql'),
            'goods_supplier'
        ));
        $this->runStatements($this->authoritativeCreateTable(
            (string)file_get_contents($root . '/database/sql/jxc_phase1_schema.sql'),
            'sales_return_order'
        ));
        foreach ([
            'ALTER TABLE `la_customer` ADD COLUMN `order_receivable` decimal(18,2) NOT NULL DEFAULT 0.00',
            'ALTER TABLE `la_customer` ADD COLUMN `order_money` decimal(18,2) NOT NULL DEFAULT 0.00',
            "ALTER TABLE `la_customer` ADD COLUMN `phone` varchar(20) NOT NULL DEFAULT ''",
            "ALTER TABLE `la_customer` ADD COLUMN `address` varchar(255) NOT NULL DEFAULT ''",
            'ALTER TABLE `la_goods` ADD COLUMN `is_archived` tinyint unsigned NOT NULL DEFAULT 0',
            'ALTER TABLE `la_goods` ADD COLUMN `primary_supplier_id` int unsigned NOT NULL DEFAULT 0',
            "ALTER TABLE `la_goods` ADD COLUMN `remark` varchar(500) NOT NULL DEFAULT ''",
            "ALTER TABLE `la_goods` ADD COLUMN `normalized_name` varchar(200) NOT NULL DEFAULT ''",
            'ALTER TABLE `la_goods` ADD KEY `idx_customer_report_tenant_normalized_name` (`tenant_id`,`normalized_name`)',
            'ALTER TABLE `la_goods_units_binding` ADD COLUMN `sort` int NOT NULL DEFAULT 0',
            "ALTER TABLE `la_sales_order` ADD COLUMN `source_type` varchar(32) NOT NULL DEFAULT ''",
            'ALTER TABLE `la_sales_order` ADD COLUMN `source_id` int unsigned NOT NULL DEFAULT 0',
            'ALTER TABLE `la_sales_order` ADD COLUMN `source_version` int unsigned NOT NULL DEFAULT 0',
            'ALTER TABLE `la_order_goods` ADD COLUMN `base_quantity` decimal(18,4) NOT NULL DEFAULT 0.0000',
            'ALTER TABLE `la_order_goods` ADD COLUMN `actual_base_qty` decimal(18,4) NOT NULL DEFAULT 0.0000',
            'ALTER TABLE `la_order_goods` ADD COLUMN `pricing_unit_id` int unsigned NOT NULL DEFAULT 0',
            "ALTER TABLE `la_order_goods` ADD COLUMN `source_line_type` varchar(32) NOT NULL DEFAULT ''",
            'ALTER TABLE `la_order_goods` ADD COLUMN `source_line_id` int unsigned NOT NULL DEFAULT 0',
            'ALTER TABLE `la_order_goods` ADD COLUMN `sku_id` int unsigned NOT NULL DEFAULT 0',
            "ALTER TABLE `la_order_goods` ADD COLUMN `sku_name` varchar(200) NOT NULL DEFAULT ''",
            'ALTER TABLE `la_order_goods` ADD COLUMN `supplier_relation_id` int unsigned NOT NULL DEFAULT 0',
            'ALTER TABLE `la_order_goods` ADD KEY `idx_customer_report_order_goods_sku` (`tenant_id`,`sku_id`)',
            'ALTER TABLE `la_order_goods` ADD KEY `idx_customer_report_order_goods_supplier_relation` (`tenant_id`,`supplier_relation_id`)',
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
        $this->runStatements($this->prepareMigration((string)file_get_contents($root . '/database/migrations/20260419_000002_create_audit_log.sql')));
        foreach ([
            '{{prefix}}warehouse_sku_balance',
            '{{prefix}}warehouse_goods_balance',
            '{{prefix}}goods_dimension_setting',
            '{{prefix}}goods_sku_spec_value',
            '{{prefix}}goods_spec_value',
            '{{prefix}}goods_sku',
            '{{prefix}}goods_spec',
            '{{prefix}}goods_spec_template',
        ] as $literalPlaceholderTable) {
            Db::execute('DROP TABLE IF EXISTS `' . $literalPlaceholderTable . '`');
        }
        Db::execute('DROP TABLE IF EXISTS `la_goods_sku_spec_value`');
        Db::execute('DROP TABLE IF EXISTS `la_goods_dimension_setting`');
        Db::execute('DROP TABLE IF EXISTS `la_goods_spec_value`');
        Db::execute('DROP TABLE IF EXISTS `la_goods_sku`');
        Db::execute('DROP TABLE IF EXISTS `la_goods_spec`');
        Db::execute('DROP TABLE IF EXISTS `la_goods_spec_template`');
        try {
            Db::execute('ALTER TABLE `la_goods` DROP COLUMN `dimension_mode`');
        } catch (\Throwable) {
        }
        $aquaticMigration = $this->prepareMigration(
            (string)file_get_contents($root . '/database/migrations/20260603_000002_aquatic_goods_v1.sql')
        );
        $orderGoodsMigrationOffset = strpos($aquaticMigration, 'ALTER TABLE `la_order_goods`');
        if ($orderGoodsMigrationOffset === false) {
            throw new \RuntimeException('aquatic_goods_order_goods_boundary_missing');
        }
        $this->runStatements(substr($aquaticMigration, 0, $orderGoodsMigrationOffset));
        $this->runStatements($this->prepareMigration((string)file_get_contents($root . '/database/migrations/20260614_000001_quality_spec_separation.sql')));
        $this->runStatements($this->prepareMigration((string)file_get_contents($root . '/database/migrations/20260614_000002_spec_value_goods_id.sql')));
        $this->runStatements($this->prepareMigration((string)file_get_contents($root . '/database/migrations/20260811_000001_create_goods_dimensions.sql')));
        $this->runStatements($this->prepareMigration((string)file_get_contents($root . '/database/migrations/20260817_000001_create_goods_dimension_setting.sql')));
        Db::execute('DROP TABLE IF EXISTS `la_warehouse_goods_balance`');
        $this->runStatements($this->prepareMigration((string)file_get_contents($root . '/database/migrations/20260729_000001_create_warehouse_goods_balance.sql')));
        $this->runStatements($this->prepareMigration((string)file_get_contents($root . '/database/migrations/20260817_000002_create_warehouse_sku_balance.sql')));
        Db::execute('DROP TABLE IF EXISTS `la_customer_report_reservation`');
        Db::execute('DROP TABLE IF EXISTS `la_customer_report_item`');
        Db::execute('DROP TABLE IF EXISTS `la_customer_report`');
        Db::execute('DROP TABLE IF EXISTS `la_customer_goods_report_preference`');
        Db::execute('DROP TABLE IF EXISTS `la_line_vehicle_trip_report`');
        Db::execute('DROP TABLE IF EXISTS `la_line_vehicle_trip`');
        Db::execute('DROP TABLE IF EXISTS `la_line_vehicle_schedule`');
        $this->runStatements($this->prepareMigration((string)file_get_contents($root . '/database/migrations/20260729_000002_create_customer_report_workflow.sql')));
        $this->runStatements($this->prepareMigration((string)file_get_contents($root . '/database/migrations/20260811_000002_add_customer_report_sku_snapshot.sql')));
        $this->runStatements($this->prepareMigration((string)file_get_contents($root . '/database/migrations/20260817_000003_add_customer_report_reservation_sku.sql')));
        $this->runStatements($this->prepareMigration((string)file_get_contents($root . '/database/migrations/20260810_000001_create_fulfillment_workflow.sql')));
        $this->runStatements($this->prepareMigration((string)file_get_contents($root . '/database/migrations/20260818_000001_customer_report_batch_and_cancellation.sql')));
        $this->runStatements($this->prepareMigration((string)file_get_contents($root . '/database/migrations/20260818_000002_fulfillment_paper_control.sql')));
        $this->runStatements($this->prepareMigration((string)file_get_contents($root . '/database/migrations/20260818_000003_self_delivery_negative_inventory.sql')));
        $this->runStatements($this->prepareMigration((string)file_get_contents($root . '/database/migrations/20260819_000001_line_vehicle_trip.sql')));
        $this->runStatements($this->prepareMigration((string)file_get_contents($root . '/database/migrations/20260819_000002_delivery_variants.sql')));
        $this->runStatements($this->prepareMigration((string)file_get_contents($root . '/database/migrations/20260819_000003_sales_settlement_versions.sql')));
        $this->runStatements($this->prepareMigration((string)file_get_contents($root . '/database/migrations/20260819_000004_customer_sales_print_receipts.sql')));
        $this->runStatements($this->prepareMigration((string)file_get_contents($root . '/database/migrations/20260922_000001_customer_report_weight_requirements.sql')));
        $this->runStatements($this->prepareMigration((string)file_get_contents($root . '/database/migrations/20260925_000001_procedure_generation_modes.sql')));
        $this->runStatements($this->authoritativeCreateTable(
            (string)file_get_contents($root . '/database/sql/jxc_phase1_schema.sql'),
            'supply_order'
        ));
        $purchaseBatchSchema = (string)file_get_contents($root . '/database/migrations/20260827_000001_create_purchase_batch.sql');
        $this->runStatements($this->authoritativeCreateTable($purchaseBatchSchema, 'purchase_batch'));
        $this->runStatements($this->authoritativeCreateTable($purchaseBatchSchema, 'purchase_batch_supply_order'));
        $this->runStatements($this->prepareMigration((string)file_get_contents($root . '/database/migrations/20260925_000002_processing_groups_and_purchase_plans.sql')));
        self::$customerReportSchemaReady = true;
    }

    protected function cleanCustomerReportData(): void
    {
        try {
            Db::name('sales_delivery_correction')->where('tenant_id', self::TENANT_ID)->delete();
        } catch (\Throwable) {
        }
        foreach (['purchase_plan_allocation', 'purchase_plan_arrival', 'purchase_plan_source', 'purchase_plan', 'purchase_batch_supply_order', 'purchase_batch', 'customer_report_processing_group_process', 'customer_report_processing_group', 'customer_sales_print_log', 'sales_weight_difference_todo', 'sales_settlement_action', 'sales_order_version', 'customer_sales_preference', 'sales_settlement_setting', 'negative_inventory_action', 'negative_inventory_todo', 'negative_inventory_attribution', 'fulfillment_delivery_loss', 'fulfillment_delivery_remainder', 'line_vehicle_return_event', 'line_vehicle_trip_report', 'line_vehicle_trip', 'line_vehicle_schedule', 'fulfillment_delivery_item', 'fulfillment_delivery_event', 'third_party_driver', 'negative_inventory_setting', 'fulfillment_ticket_control', 'fulfillment_paper_copy', 'fulfillment_item_change', 'fulfillment_print_log', 'fulfillment_task', 'fulfillment_task_group', 'employee_permission', 'employee_process', 'employee', 'work_process', 'audit_log', 'receivable_flow', 'stock_flow', 'order_goods', 'supply_order', 'sales_order', 'sales_return_order', 'customer_report_reservation', 'customer_report_item', 'customer_report', 'customer_report_batch', 'customer_goods_report_preference', 'customer_delivery_vehicle', 'warehouse_sku_balance', 'warehouse_goods_balance', 'goods_supplier_price_history', 'goods_supplier', 'goods_sku_spec_value', 'goods_dimension_setting', 'goods_spec_value', 'goods_sku', 'goods_spec', 'goods_spec_template', 'goods_alias', 'goods_units_binding', 'goods_unit', 'warehouse', 'goods', 'customer'] as $table) {
            try {
                Db::name($table)->where('tenant_id', self::TENANT_ID)->delete();
            } catch (\Throwable) {
            }
        }
    }

    protected function createCustomer(string $name, int $parentId = 0, string $phone = '', string $address = ''): int
    {
        return (int)Db::name('customer')->insertGetId([
            'tenant_id' => self::TENANT_ID, 'customer_name' => $name, 'parent_id' => $parentId,
            'phone' => $phone, 'address' => $address,
            'is_disabled' => 0, 'create_time' => time(), 'update_time' => time(),
        ]);
    }

    protected function createCustomerReportGoods(string $name, string $code, string $unit = '件'): int
    {
        $goodsId = (int)Db::name('goods')->insertGetId([
            'tenant_id' => self::TENANT_ID, 'name' => $name,
            'normalized_name' => GoodsAliasService::normalize($name),
            'product_code' => $code . '-' . uniqid(),
            'units' => $unit, 'unit_id' => 0, 'price' => '1.00', 'cost' => '1.00', 'stock' => '0.0000',
            'category_id' => 0, 'is_disabled' => 0, 'is_archived' => 0,
            'create_time' => time(), 'update_time' => time(),
        ]);
        GoodsBaseSkuService::ensure(self::TENANT_ID, $goodsId, $name, 0, $unit);
        return $goodsId;
    }

    protected function createCustomerReportWarehouse(string $name): int
    {
        return (int)Db::name('warehouse')->insertGetId([
            'tenant_id' => self::TENANT_ID, 'name' => $name, 'is_enabled' => 1,
            'create_time' => time(), 'update_time' => time(),
        ]);
    }

    protected function customerReportSkuId(int $goodsId): int
    {
        return (int)Db::name('goods_sku')
            ->where('tenant_id', self::TENANT_ID)
            ->where('goods_id', $goodsId)
            ->where('status', 1)
            ->where('dimension_disabled_snapshot', 0)
            ->order(['sort' => 'asc', 'id' => 'asc'])
            ->value('id');
    }

    /** @return array<string,mixed> */
    protected function fulfillmentPayload(
        int $customerId,
        int $goodsId,
        int $warehouseId,
        string $key,
        string $quantity,
        string $processing
    ): array {
        $processId = (int)Db::name('work_process')->where('tenant_id', self::TENANT_ID)
            ->where('trigger_type', 'report_selection')->where('is_enabled', 1)
            ->whereNull('delete_time')->order(['sort' => 'asc', 'id' => 'asc'])->value('id');
        if ($processId <= 0) {
            $now = time();
            $processId = (int)Db::name('work_process')->insertGetId([
                'tenant_id' => self::TENANT_ID,
                'code' => 'test_processing_' . substr(sha1(uniqid('', true)), 0, 12),
                'name' => '测试加工',
                'trigger_type' => 'report_selection',
                'trigger_keywords' => '[]',
                'sort' => 10,
                'is_enabled' => 1,
                'is_system' => 0,
                'create_time' => $now,
                'update_time' => $now,
                'delete_time' => null,
            ]);
        }
        return [
            'main_customer_id' => $customerId,
            'delivery_date' => '2026-08-10',
            'is_supplement' => 0,
            'idempotency_key' => $key,
            'remark' => '',
            'items' => [[
                'goods_id' => $goodsId,
                'warehouse_id' => $warehouseId,
                'unit_id' => 0,
                'unit_name' => '件',
                'order_qty' => $quantity,
                'piece_weight_confirmed' => 1,
                'piece_weight_min' => '1.00',
                'piece_weight_max' => '1.00',
                'price_status' => 'unpriced',
                'processing_requirement' => $processing,
                'processing' => $processing,
                'line_remark' => $processing,
                'processing_groups' => [[
                    'group_key' => 'default',
                    'name' => $processing !== '' ? $processing : '默认加工',
                    'planned_qty' => $quantity,
                    'process_ids' => [$processId],
                ]],
            ]],
        ];
    }

    private function runStatements(string $sql): void
    {
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            Db::execute($statement);
        }
    }

    private function authoritativeCreateTable(string $schemaSql, string $table): string
    {
        $prepared = $this->prepareMigration($schemaSql);
        $marker = 'CREATE TABLE IF NOT EXISTS `la_' . $table . '`';
        $start = strpos($prepared, $marker);
        $end = $start === false ? false : strpos($prepared, ';', $start);
        if ($start === false || $end === false) {
            throw new \RuntimeException('authoritative_create_table_missing:' . $table);
        }
        return substr($prepared, $start, $end - $start + 1);
    }

    protected function createCustomerReportUnit(string $name, int $status = 1): int
    {
        return (int)Db::name('goods_unit')->insertGetId([
            'tenant_id' => self::TENANT_ID, 'name' => $name, 'status' => $status, 'sort' => 0,
            'create_time' => time(), 'update_time' => time(),
        ]);
    }

    protected function createCustomerReportAlias(int $goodsId, string $alias): int
    {
        return (int)Db::name('goods_alias')->insertGetId([
            'tenant_id' => self::TENANT_ID, 'goods_id' => $goodsId, 'cloud_goods_id' => 0,
            'alias' => $alias, 'normalized_alias' => mb_strtolower(preg_replace('/\s+/u', '', $alias) ?? ''),
            'source' => 'tenant', 'create_time' => time(), 'update_time' => time(),
        ]);
    }

    protected function prepareMigration(string $sql): string
    {
        return MigrationSqlPreprocessor::prepare($sql, 'la_');
    }
}
