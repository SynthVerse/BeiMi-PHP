<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\SalesReservationLogic;
use think\facade\Db;

trait TaskCenterTestSupport
{
    protected const TENANT_ID = 882001;
    protected const OTHER_TENANT_ID = 882002;
    protected const ADMIN_ID = 992001;

    protected function prepareRequestContext(int $tenantId = self::TENANT_ID, int $adminId = self::ADMIN_ID): void
    {
        request()->tenantId = $tenantId;
        request()->adminId = $adminId;
        request()->userId = $adminId;
        request()->adminInfo = [
            'admin_id' => $adminId,
            'tenant_id' => $tenantId,
        ];
    }

    protected function ensureTaskCenterTables(): void
    {
        $prefix = env('database.prefix', 'la_');
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `{$prefix}task_employee` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `name` varchar(100) NOT NULL DEFAULT '',
  `mobile` varchar(30) NOT NULL DEFAULT '',
  `user_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `admin_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `is_manager` tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
  `is_enabled` tinyint(1) UNSIGNED NOT NULL DEFAULT 1,
  `last_active_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `delete_time` int(11) UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_admin` (`tenant_id`, `admin_id`),
  KEY `idx_tenant_user` (`tenant_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{$prefix}task_employee_role` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `employee_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `role_code` varchar(50) NOT NULL DEFAULT '',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_employee_role` (`tenant_id`, `employee_id`, `role_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{$prefix}work_task` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `sn` varchar(64) NOT NULL DEFAULT '',
  `task_date` date NULL DEFAULT NULL,
  `task_kind` varchar(32) NOT NULL DEFAULT '',
  `role_code` varchar(50) NOT NULL DEFAULT '',
  `source_type` varchar(50) NOT NULL DEFAULT '',
  `source_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `parent_task_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `reservation_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `reservation_sn` varchar(64) NOT NULL DEFAULT '',
  `customer_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `customer_name` varchar(100) NOT NULL DEFAULT '',
  `goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `goods_name` varchar(200) NOT NULL DEFAULT '',
  `goods_code` varchar(100) NOT NULL DEFAULT '',
  `unit_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `unit_name` varchar(50) NOT NULL DEFAULT '',
  `demand_num` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `reserved_num` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `shortage_num` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `progress_num` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `stock_status` varchar(32) NOT NULL DEFAULT 'enough',
  `assignee_employee_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `assignee_employee_name` varchar(100) NOT NULL DEFAULT '',
  `assigned_by` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `assigned_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `status` varchar(32) NOT NULL DEFAULT 'pending',
  `priority` varchar(32) NOT NULL DEFAULT 'normal',
  `status_reason` varchar(500) NOT NULL DEFAULT '',
  `print_count` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `last_print_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `create_by` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_by` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `delete_time` int(11) UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_task_line_role` (`tenant_id`, `task_kind`, `role_code`, `source_type`, `source_id`),
  KEY `idx_tenant_date_status` (`tenant_id`, `task_date`, `status`),
  KEY `idx_tenant_reservation` (`tenant_id`, `reservation_id`),
  KEY `idx_tenant_goods_kind` (`tenant_id`, `goods_id`, `task_kind`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{$prefix}work_task_log` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `task_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `action` varchar(50) NOT NULL DEFAULT '',
  `status_from` varchar(32) NOT NULL DEFAULT '',
  `status_to` varchar(32) NOT NULL DEFAULT '',
  `content` varchar(500) NOT NULL DEFAULT '',
  `payload_json` text NULL,
  `operator_employee_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `operator_admin_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_task` (`tenant_id`, `task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{$prefix}task_print_log` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `print_no` varchar(64) NOT NULL DEFAULT '',
  `task_date` date NULL DEFAULT NULL,
  `scope` varchar(32) NOT NULL DEFAULT '',
  `employee_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `employee_name` varchar(100) NOT NULL DEFAULT '',
  `role_code` varchar(50) NOT NULL DEFAULT '',
  `task_ids_json` text NULL,
  `reservation_item_ids_json` text NULL,
  `device_id` varchar(100) NOT NULL DEFAULT '',
  `device_name` varchar(100) NOT NULL DEFAULT '',
  `result` varchar(32) NOT NULL DEFAULT 'simulated',
  `error_code` varchar(64) NOT NULL DEFAULT '',
  `error_message` varchar(255) NOT NULL DEFAULT '',
  `create_by` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_print_no` (`tenant_id`, `print_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{$prefix}sales_reservation` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `sn` varchar(64) NOT NULL DEFAULT '',
  `customer_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `customer_name` varchar(100) NOT NULL DEFAULT '',
  `status` varchar(32) NOT NULL DEFAULT 'draft',
  `total_num` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `reserved_num` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `shortage_num` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `converted_sales_order_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `remark` varchar(500) NOT NULL DEFAULT '',
  `create_by` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_by` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `delete_time` int(11) UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_sn` (`tenant_id`, `sn`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{$prefix}sales_reservation_item` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `reservation_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `goods_name` varchar(200) NOT NULL DEFAULT '',
  `goods_code` varchar(100) NOT NULL DEFAULT '',
  `unit_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `unit_name` varchar(50) NOT NULL DEFAULT '',
  `warehouse_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `sku_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `spec_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `num` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `reserved_num` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `shortage_num` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `status` varchar(32) NOT NULL DEFAULT 'reserved',
  `price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `delete_time` int(11) UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_reservation` (`tenant_id`, `reservation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{$prefix}inventory_reservation` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `reservation_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `reservation_item_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `warehouse_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `sku_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `spec_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `reserved_num` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `consumed_num` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `released_num` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `status` varchar(32) NOT NULL DEFAULT 'active',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `delete_time` int(11) UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_goods_status` (`tenant_id`, `goods_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            if ($statement !== '') {
                Db::execute($statement);
            }
        }

        $this->ensureCommonJxcTables($prefix);
        $this->ensureNewTaskColumns($prefix);
    }

    protected function cleanTaskCenterTenantData(int $tenantId = self::TENANT_ID): void
    {
        foreach ([
            'task_print_log', 'work_task_log', 'work_task', 'task_employee_role', 'task_employee',
            'inventory_reservation', 'sales_reservation_item', 'sales_reservation',
            'goods_loss_record', 'purchase_arrival_detail', 'purchase_arrival', 'goods_batch',
            'order_goods', 'supply_order', 'stock_flow', 'goods_supplier', 'goods_sku',
            'vendor', 'warehouse', 'goods', 'customer',
        ] as $table) {
            try {
                Db::name($table)->where('tenant_id', $tenantId)->delete();
            } catch (\Throwable) {
            }
        }
    }

    protected function createGoods(string $name, string $code, string $stock): int
    {
        return (int)Db::name('goods')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'name' => $name,
            'product_code' => $code . '-' . uniqid(),
            'units' => '件',
            'unit_id' => 0,
            'price' => '1.00',
            'cost' => '1.00',
            'stock' => $stock,
            'category_id' => 0,
            'is_disabled' => 0,
            'create_time' => time(),
            'update_time' => time(),
        ]);
    }

    protected function createEmployee(string $name, string $roleCode, int $adminId = 0): int
    {
        $employeeId = (int)Db::name('task_employee')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'name' => $name,
            'mobile' => '',
            'user_id' => 0,
            'admin_id' => $adminId,
            'is_manager' => $roleCode === 'manager' ? 1 : 0,
            'is_enabled' => 1,
            'last_active_time' => 0,
            'create_time' => time(),
            'update_time' => time(),
        ]);
        Db::name('task_employee_role')->insert([
            'tenant_id' => self::TENANT_ID,
            'employee_id' => $employeeId,
            'role_code' => $roleCode,
            'create_time' => time(),
        ]);
        return $employeeId;
    }

    protected function submitReservation(int $goodsId, float $num, string $customerName = '测试客户'): array
    {
        $result = SalesReservationLogic::submit([
            'customer_id' => 1,
            'customer_name' => $customerName,
            'items' => [['goods_id' => $goodsId, 'num' => $num]],
        ]);
        self::assertNotFalse($result);
        return $result;
    }

    protected function createWarehouse(string $name): int
    {
        return (int)Db::name('warehouse')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'name' => $name,
            'is_enabled' => 1,
            'create_time' => time(),
            'update_time' => time(),
        ]);
    }

    protected function createVendor(string $name): int
    {
        return (int)Db::name('vendor')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'supplier_name' => $name,
            'contact' => '',
            'phone' => '',
            'is_disabled' => 0,
            'create_time' => time(),
            'update_time' => time(),
        ]);
    }

    protected function createTaskCenterTask(array $overrides = []): int
    {
        $tenantId = (int)($overrides['tenant_id'] ?? self::TENANT_ID);
        $now = time();
        $row = array_merge([
            'tenant_id' => $tenantId,
            'sn' => 'TC' . uniqid(),
            'task_date' => '2026-07-05',
            'task_kind' => 'fulfillment',
            'role_code' => 'packing',
            'source_type' => 'test:' . uniqid(),
            'source_id' => 0,
            'parent_task_id' => 0,
            'reservation_id' => 0,
            'reservation_sn' => '',
            'customer_id' => 0,
            'customer_name' => '',
            'goods_id' => 0,
            'goods_name' => '测试商品',
            'goods_code' => '',
            'unit_id' => 0,
            'unit_name' => '件',
            'demand_num' => '1.0000',
            'reserved_num' => '0.0000',
            'shortage_num' => '0.0000',
            'progress_num' => '0.0000',
            'stock_status' => 'enough',
            'assignee_employee_id' => 0,
            'assignee_employee_name' => '',
            'assigned_by' => 0,
            'assigned_time' => 0,
            'status' => 'pending',
            'priority' => 'normal',
            'status_reason' => '',
            'print_count' => 0,
            'last_print_time' => 0,
            'create_by' => self::ADMIN_ID,
            'update_by' => self::ADMIN_ID,
            'create_time' => $now,
            'update_time' => $now,
            'delete_time' => null,
        ], $overrides);

        return (int)Db::name('work_task')->insertGetId($row);
    }

    private function ensureCommonJxcTables(string $prefix): void
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `{$prefix}goods` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `name` varchar(200) NOT NULL DEFAULT '',
  `product_code` varchar(100) NOT NULL DEFAULT '',
  `units` varchar(50) NOT NULL DEFAULT '',
  `unit_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `stock` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `category_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `is_disabled` tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{$prefix}warehouse` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `name` varchar(100) NOT NULL DEFAULT '',
  `is_enabled` tinyint(1) UNSIGNED NOT NULL DEFAULT 1,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{$prefix}vendor` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `supplier_name` varchar(100) NOT NULL DEFAULT '',
  `contact` varchar(100) NOT NULL DEFAULT '',
  `phone` varchar(30) NOT NULL DEFAULT '',
  `is_disabled` tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{$prefix}supply_order` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `order_sn` varchar(64) NOT NULL DEFAULT '',
  `supplier_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `supplier_name` varchar(100) NOT NULL DEFAULT '',
  `warehouse_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `order_money` decimal(12,2) NOT NULL DEFAULT 0.00,
  `order_pay_money` decimal(12,2) NOT NULL DEFAULT 0.00,
  `order_arrears_money` decimal(12,2) NOT NULL DEFAULT 0.00,
  `datetimesingle` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `purpose_type` varchar(50) NOT NULL DEFAULT 'supply',
  `remarks` varchar(500) NOT NULL DEFAULT '',
  `admin_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `idempotent_key` varchar(64) NOT NULL DEFAULT '',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_order_sn` (`tenant_id`, `order_sn`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{$prefix}order_goods` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `order_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `order_type` varchar(50) NOT NULL DEFAULT '',
  `goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `sku_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `sku_name` varchar(200) NOT NULL DEFAULT '',
  `supplier_relation_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `name` varchar(200) NOT NULL DEFAULT '',
  `units` varchar(50) NOT NULL DEFAULT '',
  `order_unit_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `order_unit_name` varchar(50) NOT NULL DEFAULT '',
  `order_qty` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `base_unit_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `base_unit_name` varchar(50) NOT NULL DEFAULT '',
  `conversion_rate` decimal(18,6) NOT NULL DEFAULT 1.000000,
  `conversion_source_type` varchar(50) NOT NULL DEFAULT '',
  `conversion_effective_date` date NULL DEFAULT NULL,
  `expected_base_qty` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `actual_base_qty` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `loss_base_qty` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `loss_rate` decimal(12,6) NOT NULL DEFAULT 0.000000,
  `batch_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `number` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `remark` varchar(500) NOT NULL DEFAULT '',
  `sort` int(11) NOT NULL DEFAULT 0,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{$prefix}stock_flow` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `warehouse_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `sku_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `batch_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `order_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `order_type` varchar(50) NOT NULL DEFAULT '',
  `order_sn` varchar(64) NOT NULL DEFAULT '',
  `flow_type` varchar(20) NOT NULL DEFAULT '',
  `quantity` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `before_stock` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `after_stock` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `admin_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `remark` varchar(500) NOT NULL DEFAULT '',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{$prefix}purchase_arrival` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `arrival_sn` varchar(64) NOT NULL DEFAULT '',
  `supply_order_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `supply_order_sn` varchar(64) NOT NULL DEFAULT '',
  `supplier_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `supplier_name` varchar(100) NOT NULL DEFAULT '',
  `warehouse_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `arrival_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `remark` varchar(500) NOT NULL DEFAULT '',
  `admin_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{$prefix}purchase_arrival_detail` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `arrival_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `supply_order_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `order_goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `sku_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `supplier_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `batch_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `order_qty` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `order_unit_name` varchar(50) NOT NULL DEFAULT '',
  `expected_base_qty` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `actual_base_qty` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `loss_base_qty` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `loss_rate` decimal(12,6) NOT NULL DEFAULT 0.000000,
  `conversion_rate` decimal(18,6) NOT NULL DEFAULT 1.000000,
  `conversion_source_type` varchar(50) NOT NULL DEFAULT '',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{$prefix}goods_batch` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `batch_sn` varchar(64) NOT NULL DEFAULT '',
  `goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `sku_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `supplier_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `warehouse_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `supply_order_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `order_goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `base_unit_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `base_unit_name` varchar(50) NOT NULL DEFAULT '',
  `expected_base_qty` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `actual_base_qty` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `loss_base_qty` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `conversion_snapshot` text NULL,
  `arrival_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{$prefix}goods_loss_record` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `loss_type` varchar(50) NOT NULL DEFAULT '',
  `goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `sku_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `supplier_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `batch_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `supply_order_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `order_goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `expected_base_qty` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `actual_base_qty` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `loss_base_qty` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `loss_rate` decimal(12,6) NOT NULL DEFAULT 0.000000,
  `reason` varchar(255) NOT NULL DEFAULT '',
  `record_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `admin_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            if ($statement !== '') {
                Db::execute($statement);
            }
        }
    }

    private function ensureNewTaskColumns(string $prefix): void
    {
        $columns = [
            "ALTER TABLE `{$prefix}task_employee` ADD COLUMN `is_manager` tinyint(1) UNSIGNED NOT NULL DEFAULT 0 AFTER `admin_id`",
            "ALTER TABLE `{$prefix}task_employee` ADD COLUMN `last_active_time` int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `is_enabled`",
            "ALTER TABLE `{$prefix}work_task` ADD COLUMN `task_date` date NULL DEFAULT NULL AFTER `sn`",
            "ALTER TABLE `{$prefix}work_task` ADD COLUMN `task_kind` varchar(32) NOT NULL DEFAULT '' AFTER `task_date`",
            "ALTER TABLE `{$prefix}work_task` ADD COLUMN `role_code` varchar(50) NOT NULL DEFAULT '' AFTER `task_kind`",
            "ALTER TABLE `{$prefix}work_task` ADD COLUMN `parent_task_id` int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `source_id`",
            "ALTER TABLE `{$prefix}work_task` ADD COLUMN `customer_id` int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `reservation_sn`",
            "ALTER TABLE `{$prefix}work_task` ADD COLUMN `customer_name` varchar(100) NOT NULL DEFAULT '' AFTER `customer_id`",
            "ALTER TABLE `{$prefix}work_task` ADD COLUMN `goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `customer_name`",
            "ALTER TABLE `{$prefix}work_task` ADD COLUMN `goods_name` varchar(200) NOT NULL DEFAULT '' AFTER `goods_id`",
            "ALTER TABLE `{$prefix}work_task` ADD COLUMN `goods_code` varchar(100) NOT NULL DEFAULT '' AFTER `goods_name`",
            "ALTER TABLE `{$prefix}work_task` ADD COLUMN `unit_id` int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `goods_code`",
            "ALTER TABLE `{$prefix}work_task` ADD COLUMN `unit_name` varchar(50) NOT NULL DEFAULT '' AFTER `unit_id`",
            "ALTER TABLE `{$prefix}work_task` ADD COLUMN `demand_num` decimal(12,4) NOT NULL DEFAULT 0.0000 AFTER `unit_name`",
            "ALTER TABLE `{$prefix}work_task` ADD COLUMN `reserved_num` decimal(12,4) NOT NULL DEFAULT 0.0000 AFTER `demand_num`",
            "ALTER TABLE `{$prefix}work_task` ADD COLUMN `shortage_num` decimal(12,4) NOT NULL DEFAULT 0.0000 AFTER `reserved_num`",
            "ALTER TABLE `{$prefix}work_task` ADD COLUMN `stock_status` varchar(32) NOT NULL DEFAULT 'enough' AFTER `progress_num`",
            "ALTER TABLE `{$prefix}work_task` ADD COLUMN `assigned_by` int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `assignee_employee_name`",
            "ALTER TABLE `{$prefix}work_task` ADD COLUMN `assigned_time` int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `assigned_by`",
            "ALTER TABLE `{$prefix}work_task` ADD COLUMN `priority` varchar(32) NOT NULL DEFAULT 'normal' AFTER `status`",
            "ALTER TABLE `{$prefix}work_task` ADD COLUMN `status_reason` varchar(500) NOT NULL DEFAULT '' AFTER `priority`",
            "ALTER TABLE `{$prefix}work_task` ADD COLUMN `print_count` int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `status_reason`",
            "ALTER TABLE `{$prefix}work_task` ADD COLUMN `last_print_time` int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `print_count`",
            "ALTER TABLE `{$prefix}work_task_log` ADD COLUMN `status_from` varchar(32) NOT NULL DEFAULT '' AFTER `action`",
            "ALTER TABLE `{$prefix}work_task_log` ADD COLUMN `status_to` varchar(32) NOT NULL DEFAULT '' AFTER `status_from`",
            "ALTER TABLE `{$prefix}work_task_log` ADD COLUMN `payload_json` text NULL AFTER `content`",
        ];

        foreach ($columns as $statement) {
            try {
                Db::execute($statement);
            } catch (\Throwable) {
            }
        }

        foreach ([
            "ALTER TABLE `{$prefix}work_task` DROP INDEX `uk_tenant_source`",
            "ALTER TABLE `{$prefix}work_task` ADD UNIQUE KEY `uk_task_line_role` (`tenant_id`, `task_kind`, `role_code`, `source_type`, `source_id`)",
        ] as $statement) {
            try {
                Db::execute($statement);
            } catch (\Throwable) {
            }
        }
    }
}
