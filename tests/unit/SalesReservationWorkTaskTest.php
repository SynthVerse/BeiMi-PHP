<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\InventoryReservationService;
use app\api\jxc\logic\SalesReservationLogic;
use app\api\jxc\logic\SupplyOrderLogic;
use app\api\jxc\logic\WorkTaskService;
use app\common\model\jxc\InventoryReservation;
use app\common\model\jxc\SalesReservation;
use app\common\model\jxc\SalesReservationItem;
use app\common\model\jxc\WorkTask;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class SalesReservationWorkTaskTest extends TestCase
{
    private const TENANT_ID = 880001;
    private const ADMIN_ID = 990001;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertImplementationClassesExist();
        $this->prepareRequestContext();
        $this->ensureTables();
        $this->cleanTenantData();
    }

    protected function tearDown(): void
    {
        $this->cleanTenantData();
        parent::tearDown();
    }

    public function test_submit_reservation_uses_goods_level_available_stock_and_creates_shortage_work_task(): void
    {
        $goodsId = $this->createGoods('reservable goods', 'RSV-A', '10.00');

        InventoryReservation::create([
            'tenant_id' => self::TENANT_ID,
            'reservation_id' => 100,
            'reservation_item_id' => 100,
            'goods_id' => $goodsId,
            'warehouse_id' => 1,
            'sku_id' => 11,
            'spec_id' => 21,
            'reserved_num' => '3.0000',
            'consumed_num' => '0.0000',
            'released_num' => '0.0000',
            'status' => 'active',
            'create_time' => time(),
            'update_time' => time(),
        ]);

        $result = SalesReservationLogic::submit([
            'customer_id' => 1,
            'customer_name' => 'customer',
            'items' => [[
                'goods_id' => $goodsId,
                'warehouse_id' => 999,
                'sku_id' => 999,
                'spec_id' => 999,
                'num' => 8,
            ]],
        ]);

        self::assertNotFalse($result);
        self::assertSame('shortage', $result['status']);
        self::assertSame('7.0000', $result['reserved_num']);
        self::assertSame('1.0000', $result['shortage_num']);
        self::assertSame('0.0000', InventoryReservationService::availableForGoods($goodsId));

        $task = WorkTask::where('tenant_id', self::TENANT_ID)
            ->where('type_code', 'procurement')
            ->where('source_type', 'sales_reservation_item')
            ->where('source_id', (int)$result['items'][0]['id'])
            ->find();
        self::assertNotNull($task);
        self::assertSame('1.0000', (string)$task->target_num);
    }

    public function test_shortage_procurement_work_task_is_idempotent_per_reservation_item(): void
    {
        $goodsId = $this->createGoods('shortage goods', 'RSV-B', '0.00');

        $reservation = SalesReservationLogic::submit([
            'customer_id' => 1,
            'customer_name' => 'customer',
            'items' => [['goods_id' => $goodsId, 'num' => 5]],
        ]);

        self::assertNotFalse($reservation);
        $itemId = (int)$reservation['items'][0]['id'];
        $first = WorkTaskService::createProcurementWorkTaskForReservationItem($itemId);
        $second = WorkTaskService::createProcurementWorkTaskForReservationItem($itemId);

        self::assertSame((int)$first['id'], (int)$second['id']);
        self::assertSame(1, WorkTask::where('tenant_id', self::TENANT_ID)
            ->where('type_code', 'procurement')
            ->where('source_type', 'sales_reservation_item')
            ->where('source_id', $itemId)
            ->whereIn('status', ['pending', 'processing'])
            ->count());
    }

    public function test_supplier_is_not_required_for_shortage_procurement_work_task(): void
    {
        $goodsId = $this->createGoods('goods without supplier', 'RSV-C', '0.00');

        $reservation = SalesReservationLogic::submit([
            'customer_id' => 1,
            'customer_name' => 'customer',
            'items' => [['goods_id' => $goodsId, 'num' => 2]],
        ]);

        self::assertNotFalse($reservation);
        $task = $reservation['items'][0]['work_task'];
        self::assertSame('pending', $task['status']);
        self::assertSame('procurement', $task['type_code']);
        self::assertSame('sales_reservation_item', $task['source_type']);
    }

    public function test_inbound_backfill_marks_ready_only_when_full_and_creates_sales_convert_once(): void
    {
        $goodsId = $this->createGoods('inbound goods', 'RSV-E', '0.00');
        $reservation = SalesReservationLogic::submit([
            'customer_id' => 1,
            'customer_name' => 'customer',
            'items' => [['goods_id' => $goodsId, 'num' => 5]],
        ]);
        self::assertNotFalse($reservation);
        $taskId = (int)$reservation['items'][0]['work_task']['id'];

        WorkTaskService::backfillProcurementInbound(1001, [[
            'id' => 2001,
            'goods_id' => $goodsId,
            'number' => '2.0000',
        ]]);
        $partialTask = WorkTask::find($taskId);
        self::assertSame('processing', (string)$partialTask->status);
        self::assertSame('2.0000', (string)$partialTask->progress_num);
        self::assertSame('shortage', (string)SalesReservation::find((int)$reservation['id'])->status);

        WorkTaskService::backfillProcurementInbound(1002, [[
            'id' => 2002,
            'goods_id' => $goodsId,
            'number' => '3.0000',
        ]]);
        WorkTaskService::backfillProcurementInbound(1002, [[
            'id' => 2002,
            'goods_id' => $goodsId,
            'number' => '3.0000',
        ]]);

        $completedTask = WorkTask::find($taskId);
        self::assertSame('completed', (string)$completedTask->status);
        self::assertSame('5.0000', (string)$completedTask->progress_num);
        self::assertSame('ready', (string)SalesReservation::find((int)$reservation['id'])->status);
        self::assertSame(1, WorkTask::where('tenant_id', self::TENANT_ID)
            ->where('type_code', 'sales_convert')
            ->where('reservation_id', (int)$reservation['id'])
            ->whereIn('status', ['pending', 'processing'])
            ->count());
    }

    public function test_procurement_inbound_backfill_requires_matching_reservation_item_dimensions(): void
    {
        $goodsId = $this->createGoods('dimensioned inbound goods', 'RSV-DIM', '0.00');
        $reservation = SalesReservationLogic::submit([
            'customer_id' => 1,
            'customer_name' => 'customer',
            'items' => [[
                'goods_id' => $goodsId,
                'warehouse_id' => 10,
                'sku_id' => 20,
                'spec_id' => 30,
                'num' => 5,
            ]],
        ]);
        self::assertNotFalse($reservation);
        $itemId = (int)$reservation['items'][0]['id'];
        $taskId = (int)$reservation['items'][0]['work_task']['id'];

        WorkTaskService::backfillProcurementInbound(1101, [[
            'id' => 2101,
            'goods_id' => $goodsId,
            'warehouse_id' => 99,
            'sku_id' => 98,
            'spec_id' => 97,
            'number' => '5.0000',
        ]]);

        $unmatchedTask = WorkTask::find($taskId);
        self::assertSame('pending', (string)$unmatchedTask->status);
        self::assertSame('0.0000', (string)$unmatchedTask->progress_num);
        self::assertSame('shortage', (string)SalesReservationItem::find($itemId)->status);
        self::assertSame('shortage', (string)SalesReservation::find((int)$reservation['id'])->status);
        self::assertSame(0, WorkTask::where('tenant_id', self::TENANT_ID)
            ->where('type_code', 'sales_convert')
            ->where('reservation_id', (int)$reservation['id'])
            ->whereIn('status', ['pending', 'processing'])
            ->count());

        WorkTaskService::backfillProcurementInbound(1102, [[
            'id' => 2102,
            'goods_id' => $goodsId,
            'warehouse_id' => 10,
            'sku_id' => 20,
            'spec_id' => 30,
            'number' => '5.0000',
        ]]);
        WorkTaskService::backfillProcurementInbound(1102, [[
            'id' => 2102,
            'goods_id' => $goodsId,
            'warehouse_id' => 10,
            'sku_id' => 20,
            'spec_id' => 30,
            'number' => '5.0000',
        ]]);

        $matchedTask = WorkTask::find($taskId);
        self::assertSame('completed', (string)$matchedTask->status);
        self::assertSame('5.0000', (string)$matchedTask->progress_num);
        self::assertSame('reserved', (string)SalesReservationItem::find($itemId)->status);
        self::assertSame('ready', (string)SalesReservation::find((int)$reservation['id'])->status);
        self::assertSame(1, WorkTask::where('tenant_id', self::TENANT_ID)
            ->where('type_code', 'sales_convert')
            ->where('reservation_id', (int)$reservation['id'])
            ->whereIn('status', ['pending', 'processing'])
            ->count());
    }

    public function test_supply_order_publish_backfills_procurement_inbound_with_order_warehouse_and_spec_dimension(): void
    {
        $warehouseId = $this->createWarehouse('supply publish warehouse');
        $supplierId = $this->createVendor('supply publish supplier');
        $goodsId = $this->createGoods('supply publish goods', 'RSV-SP', '0.00');
        $skuId = $this->createSku($goodsId, 'supply publish sku');
        $this->bindSupplierSku($goodsId, $skuId, $supplierId);

        $reservation = SalesReservationLogic::submit([
            'customer_id' => 1,
            'customer_name' => 'customer',
            'items' => [[
                'goods_id' => $goodsId,
                'warehouse_id' => $warehouseId,
                'sku_id' => $skuId,
                'spec_id' => 41,
                'num' => 4,
            ]],
        ]);
        self::assertNotFalse($reservation);
        $itemId = (int)$reservation['items'][0]['id'];
        $taskId = (int)$reservation['items'][0]['work_task']['id'];

        $mismatch = SupplyOrderLogic::publish([
            'supplier_id' => $supplierId,
            'warehouse_id' => $warehouseId,
            'order_pay_money' => '1.00',
            'goods' => [[
                'goods_id' => $goodsId,
                'sku_id' => $skuId,
                'spec_id' => 42,
                'order_qty' => 1,
                'price' => '1.00',
            ]],
        ]);
        self::assertNotFalse($mismatch);
        self::assertSame('0.0000', (string)WorkTask::find($taskId)->progress_num);
        self::assertSame('shortage', (string)SalesReservationItem::find($itemId)->status);

        $matched = SupplyOrderLogic::publish([
            'supplier_id' => $supplierId,
            'warehouse_id' => $warehouseId,
            'order_pay_money' => '4.00',
            'goods' => [[
                'goods_id' => $goodsId,
                'sku_id' => $skuId,
                'spec_id' => 41,
                'order_qty' => 4,
                'price' => '1.00',
            ]],
        ]);
        self::assertNotFalse($matched);

        $completed = WorkTask::find($taskId);
        self::assertSame('completed', (string)$completed->status);
        self::assertSame('4.0000', (string)$completed->progress_num);
        self::assertSame('reserved', (string)SalesReservationItem::find($itemId)->status);
        self::assertSame('ready', (string)SalesReservation::find((int)$reservation['id'])->status);
        self::assertSame(1, WorkTask::where('tenant_id', self::TENANT_ID)
            ->where('type_code', 'sales_convert')
            ->where('reservation_id', (int)$reservation['id'])
            ->whereIn('status', ['pending', 'processing'])
            ->count());
    }

    public function test_convert_sales_requires_all_items_ready_and_consumes_reservation(): void
    {
        $customerId = $this->createCustomer('convert customer');
        $warehouseId = $this->createWarehouse('convert warehouse');
        $shortageGoodsId = $this->createGoods('convert shortage goods', 'RSV-F1', '0.00');
        $readyGoodsId = $this->createGoods('convert ready goods', 'RSV-F2', '5.00');

        $shortage = SalesReservationLogic::submit([
            'customer_id' => $customerId,
            'customer_name' => 'convert customer',
            'items' => [['goods_id' => $shortageGoodsId, 'warehouse_id' => $warehouseId, 'num' => 2, 'price' => 1]],
        ]);
        self::assertFalse(SalesReservationLogic::convertSales(['id' => (int)$shortage['id']]));
        self::assertSame('JXC_RESERVATION_NOT_READY', SalesReservationLogic::getReturnData()['error_code'] ?? null);

        $ready = SalesReservationLogic::submit([
            'customer_id' => $customerId,
            'customer_name' => 'convert customer',
            'items' => [['goods_id' => $readyGoodsId, 'warehouse_id' => $warehouseId, 'num' => 3, 'price' => 2]],
        ]);
        $converted = SalesReservationLogic::convertSales(['id' => (int)$ready['id']]);

        self::assertNotFalse($converted);
        self::assertGreaterThan(0, (int)$converted['sales_order_id']);
        self::assertSame('converted', (string)SalesReservation::find((int)$ready['id'])->status);
        self::assertSame('consumed', (string)InventoryReservation::where('reservation_id', (int)$ready['id'])->find()->status);
    }

    public function test_cancel_sales_reservation_releases_active_reservation_and_cancels_open_procurement_work_task(): void
    {
        $goodsId = $this->createGoods('cancel goods', 'RSV-G', '10.00');
        $reservation = SalesReservationLogic::submit([
            'customer_id' => 1,
            'customer_name' => 'customer',
            'items' => [['goods_id' => $goodsId, 'num' => 12]],
        ]);

        self::assertSame('0.0000', InventoryReservationService::availableForGoods($goodsId));

        $cancelled = SalesReservationLogic::cancel(['id' => (int)$reservation['id']]);

        self::assertNotFalse($cancelled);
        self::assertSame('cancelled', $cancelled['status']);
        self::assertSame('10.0000', InventoryReservationService::availableForGoods($goodsId));
        self::assertSame('cancelled', (string)WorkTask::where('tenant_id', self::TENANT_ID)
            ->where('type_code', 'procurement')
            ->where('reservation_id', (int)$reservation['id'])
            ->find()->status);
    }

    private function prepareRequestContext(): void
    {
        request()->tenantId = self::TENANT_ID;
        request()->adminId = self::ADMIN_ID;
        request()->userId = self::ADMIN_ID;
        request()->adminInfo = [
            'admin_id' => self::ADMIN_ID,
            'tenant_id' => self::TENANT_ID,
        ];
    }

    private function assertImplementationClassesExist(): void
    {
        foreach ([
            SalesReservationLogic::class,
            WorkTaskService::class,
            InventoryReservationService::class,
            SalesReservation::class,
            SalesReservationItem::class,
            InventoryReservation::class,
            WorkTask::class,
        ] as $class) {
            self::assertTrue(class_exists($class), $class . ' should exist');
        }
    }

    private function ensureTables(): void
    {
        $prefix = env('database.prefix', 'la_');
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `{$prefix}task_employee` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `name` varchar(100) NOT NULL DEFAULT '',
  `user_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `admin_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `mobile` varchar(30) NOT NULL DEFAULT '',
  `is_enabled` tinyint(1) UNSIGNED NOT NULL DEFAULT 1,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `delete_time` int(11) UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_admin` (`tenant_id`, `admin_id`),
  KEY `idx_tenant_user` (`tenant_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{$prefix}task_role` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `code` varchar(50) NOT NULL DEFAULT '',
  `name` varchar(100) NOT NULL DEFAULT '',
  `is_system` tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
  `is_enabled` tinyint(1) UNSIGNED NOT NULL DEFAULT 1,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_code` (`tenant_id`, `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{$prefix}task_employee_role` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `employee_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `role_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `role_code` varchar(50) NOT NULL DEFAULT '',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_employee_role` (`tenant_id`, `employee_id`, `role_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{$prefix}task_type` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `code` varchar(50) NOT NULL DEFAULT '',
  `name` varchar(100) NOT NULL DEFAULT '',
  `is_system` tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
  `is_enabled` tinyint(1) UNSIGNED NOT NULL DEFAULT 1,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_code` (`tenant_id`, `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{$prefix}task_type_role` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `type_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `role_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `type_code` varchar(50) NOT NULL DEFAULT '',
  `role_code` varchar(50) NOT NULL DEFAULT '',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_type_role` (`tenant_id`, `type_code`, `role_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{$prefix}work_task` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `sn` varchar(64) NOT NULL DEFAULT '',
  `type_code` varchar(50) NOT NULL DEFAULT '',
  `type_name` varchar(100) NOT NULL DEFAULT '',
  `source_type` varchar(50) NOT NULL DEFAULT '',
  `source_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `source_sn` varchar(64) NOT NULL DEFAULT '',
  `reservation_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `reservation_sn` varchar(64) NOT NULL DEFAULT '',
  `title` varchar(200) NOT NULL DEFAULT '',
  `content` text NULL,
  `assignee_employee_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `assignee_employee_name` varchar(100) NOT NULL DEFAULT '',
  `status` varchar(32) NOT NULL DEFAULT 'pending',
  `progress_num` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `target_num` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `create_by` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_by` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `delete_time` int(11) UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_source` (`tenant_id`, `source_type`, `source_id`),
  KEY `idx_tenant_status` (`tenant_id`, `status`),
  KEY `idx_tenant_assignee` (`tenant_id`, `assignee_employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{$prefix}work_task_log` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `task_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `action` varchar(50) NOT NULL DEFAULT '',
  `content` varchar(500) NOT NULL DEFAULT '',
  `operator_employee_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `operator_admin_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_task` (`tenant_id`, `task_id`)
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
CREATE TABLE IF NOT EXISTS `{$prefix}goods_sku` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `sku_name` varchar(200) NOT NULL DEFAULT '',
  `sku_code` varchar(100) NOT NULL DEFAULT '',
  `quality_status` varchar(50) NOT NULL DEFAULT '',
  `quality_label` varchar(100) NOT NULL DEFAULT '',
  `base_unit_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `base_unit_name` varchar(50) NOT NULL DEFAULT '',
  `purchase_status` tinyint(1) NOT NULL DEFAULT 1,
  `sale_status` tinyint(1) NOT NULL DEFAULT 1,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `sort` int(11) NOT NULL DEFAULT 0,
  `remark` varchar(500) NOT NULL DEFAULT '',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_goods_sku_code` (`tenant_id`, `goods_id`, `sku_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{$prefix}goods_supplier` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `sku_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `supplier_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `is_preferred` tinyint(1) NOT NULL DEFAULT 0,
  `supplier_product_code` varchar(100) NOT NULL DEFAULT '',
  `supplier_goods_name` varchar(200) NOT NULL DEFAULT '',
  `purchase_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `purchase_unit_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `purchase_unit_name` varchar(50) NOT NULL DEFAULT '',
  `settlement_unit_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `settlement_unit_name` varchar(50) NOT NULL DEFAULT '',
  `min_purchase_qty` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `daily_capacity_qty` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `lead_time_days` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `last_purchase_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `last_purchase_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `remark` varchar(500) NOT NULL DEFAULT '',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_goods_sku_supplier` (`tenant_id`, `goods_id`, `sku_id`, `supplier_id`)
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_supply_order` (`tenant_id`, `supply_order_id`)
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
        $this->ensureSupplyPublishColumns($prefix);
    }

    private function ensureSupplyPublishColumns(string $prefix): void
    {
        foreach ([
            "ALTER TABLE `{$prefix}order_goods` ADD COLUMN `sku_id` int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `goods_id`",
            "ALTER TABLE `{$prefix}order_goods` ADD COLUMN `sku_name` varchar(200) NOT NULL DEFAULT '' AFTER `sku_id`",
            "ALTER TABLE `{$prefix}order_goods` ADD COLUMN `supplier_relation_id` int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `sku_name`",
            "ALTER TABLE `{$prefix}order_goods` ADD COLUMN `order_unit_id` int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `units`",
            "ALTER TABLE `{$prefix}order_goods` ADD COLUMN `order_unit_name` varchar(50) NOT NULL DEFAULT '' AFTER `order_unit_id`",
            "ALTER TABLE `{$prefix}order_goods` ADD COLUMN `order_qty` decimal(12,4) NOT NULL DEFAULT 0.0000 AFTER `order_unit_name`",
            "ALTER TABLE `{$prefix}order_goods` ADD COLUMN `base_unit_id` int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `order_qty`",
            "ALTER TABLE `{$prefix}order_goods` ADD COLUMN `base_unit_name` varchar(50) NOT NULL DEFAULT '' AFTER `base_unit_id`",
            "ALTER TABLE `{$prefix}order_goods` ADD COLUMN `conversion_rate` decimal(18,6) NOT NULL DEFAULT 1.000000 AFTER `base_unit_name`",
            "ALTER TABLE `{$prefix}order_goods` ADD COLUMN `conversion_source_type` varchar(50) NOT NULL DEFAULT '' AFTER `conversion_rate`",
            "ALTER TABLE `{$prefix}order_goods` ADD COLUMN `conversion_effective_date` date NULL DEFAULT NULL AFTER `conversion_source_type`",
            "ALTER TABLE `{$prefix}order_goods` ADD COLUMN `expected_base_qty` decimal(12,4) NOT NULL DEFAULT 0.0000 AFTER `conversion_effective_date`",
            "ALTER TABLE `{$prefix}order_goods` ADD COLUMN `actual_base_qty` decimal(12,4) NOT NULL DEFAULT 0.0000 AFTER `expected_base_qty`",
            "ALTER TABLE `{$prefix}order_goods` ADD COLUMN `loss_base_qty` decimal(12,4) NOT NULL DEFAULT 0.0000 AFTER `actual_base_qty`",
            "ALTER TABLE `{$prefix}order_goods` ADD COLUMN `loss_rate` decimal(12,6) NOT NULL DEFAULT 0.000000 AFTER `loss_base_qty`",
            "ALTER TABLE `{$prefix}order_goods` ADD COLUMN `batch_id` int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `loss_rate`",
            "ALTER TABLE `{$prefix}stock_flow` ADD COLUMN `sku_id` int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `goods_id`",
            "ALTER TABLE `{$prefix}stock_flow` ADD COLUMN `batch_id` int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `sku_id`",
        ] as $statement) {
            try {
                Db::execute($statement);
            } catch (\Throwable) {
            }
        }
    }

    private function cleanTenantData(): void
    {
        foreach ([
            'work_task_log', 'work_task', 'task_type_role', 'task_employee_role', 'task_type', 'task_role', 'task_employee',
            'inventory_reservation', 'sales_reservation_item', 'sales_reservation',
            'goods_loss_record', 'purchase_arrival_detail', 'purchase_arrival', 'goods_batch',
            'order_goods', 'supply_order', 'sales_order', 'stock_flow', 'goods_supplier', 'goods_sku',
            'vendor', 'goods', 'customer', 'warehouse',
        ] as $table) {
            try {
                Db::name($table)->where('tenant_id', self::TENANT_ID)->delete();
            } catch (\Throwable) {
            }
        }
    }

    private function createGoods(string $name, string $code, string $stock): int
    {
        return (int)Db::name('goods')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'name' => $name,
            'product_code' => $code . '-' . uniqid(),
            'units' => 'piece',
            'unit_id' => 0,
            'price' => '1.00',
            'cost' => '0.00',
            'stock' => $stock,
            'category_id' => 0,
            'is_disabled' => 0,
            'create_time' => time(),
            'update_time' => time(),
        ]);
    }

    private function createCustomer(string $name): int
    {
        return (int)Db::name('customer')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'customer_name' => $name,
            'contact' => '',
            'phone' => '',
            'is_disabled' => 0,
            'create_time' => time(),
            'update_time' => time(),
        ]);
    }

    private function createWarehouse(string $name): int
    {
        return (int)Db::name('warehouse')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'name' => $name,
            'is_enabled' => 1,
            'create_time' => time(),
            'update_time' => time(),
        ]);
    }

    private function createVendor(string $name): int
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

    private function createSku(int $goodsId, string $name): int
    {
        return (int)Db::name('goods_sku')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'goods_id' => $goodsId,
            'sku_name' => $name,
            'sku_code' => 'SKU-' . uniqid(),
            'base_unit_id' => 0,
            'base_unit_name' => 'piece',
            'purchase_status' => 1,
            'sale_status' => 1,
            'status' => 1,
            'create_time' => time(),
            'update_time' => time(),
        ]);
    }

    private function bindSupplierSku(int $goodsId, int $skuId, int $supplierId): void
    {
        Db::name('goods_supplier')->insert([
            'tenant_id' => self::TENANT_ID,
            'goods_id' => $goodsId,
            'sku_id' => $skuId,
            'supplier_id' => $supplierId,
            'is_primary' => 1,
            'is_preferred' => 1,
            'supplier_product_code' => '',
            'supplier_goods_name' => '',
            'purchase_price' => '1.00',
            'purchase_unit_id' => 0,
            'purchase_unit_name' => 'piece',
            'settlement_unit_id' => 0,
            'settlement_unit_name' => 'piece',
            'min_purchase_qty' => '0.0000',
            'daily_capacity_qty' => '0.0000',
            'lead_time_days' => 0,
            'status' => 1,
            'create_time' => time(),
            'update_time' => time(),
        ]);
    }
}
