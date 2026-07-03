<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\InventoryReservationService;
use app\api\jxc\logic\ProcurementTaskLogic;
use app\api\jxc\logic\ProcurementTaskService;
use app\api\jxc\logic\SalesReservationLogic;
use app\common\model\jxc\InventoryReservation;
use app\common\model\jxc\ProcurementTask;
use app\common\model\jxc\SalesReservation;
use app\common\model\jxc\SalesReservationItem;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class SalesReservationProcurementTaskTest extends TestCase
{
    private const TENANT_ID = 880001;
    private const ADMIN_ID = 990001;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertImplementationClassesExist();
        $this->prepareRequestContext();
        $this->ensureNewTables();
        $this->cleanTenantData();
    }

    protected function tearDown(): void
    {
        $this->cleanTenantData();
        parent::tearDown();
    }

    public function test_submit_reservation_uses_goods_level_available_stock(): void
    {
        $goodsId = $this->createGoods('可预留商品', 'RSV-A', '10.00');

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
            'customer_name' => '测试客户',
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
    }

    public function test_shortage_creates_one_procurement_task_per_reservation_item(): void
    {
        $goodsId = $this->createGoods('缺口商品', 'RSV-B', '0.00');

        $reservation = SalesReservationLogic::submit([
            'customer_id' => 1,
            'customer_name' => '测试客户',
            'items' => [['goods_id' => $goodsId, 'num' => 5]],
        ]);

        self::assertNotFalse($reservation);
        $itemId = (int)$reservation['items'][0]['id'];
        $first = ProcurementTaskService::createForReservationItem($itemId);
        $second = ProcurementTaskService::createForReservationItem($itemId);

        self::assertSame((int)$first['id'], (int)$second['id']);
        self::assertSame(1, ProcurementTask::where('source_reservation_item_id', $itemId)->count());
    }

    public function test_supplier_not_required_for_procurement_task(): void
    {
        $goodsId = $this->createGoods('无供应商商品', 'RSV-C', '0.00');

        $reservation = SalesReservationLogic::submit([
            'customer_id' => 1,
            'customer_name' => '测试客户',
            'items' => [['goods_id' => $goodsId, 'num' => 2]],
        ]);
        $manual = ProcurementTaskLogic::manualCreate([
            'goods_id' => $goodsId,
            'required_num' => 3,
        ]);

        self::assertNotFalse($reservation);
        self::assertSame('pending', $reservation['items'][0]['procurement_task']['status']);
        self::assertNotFalse($manual);
        self::assertSame($goodsId, (int)$manual['goods_id']);
    }

    public function test_close_procurement_task_marks_gap_closed_not_ready(): void
    {
        $goodsId = $this->createGoods('关闭缺口商品', 'RSV-D', '0.00');
        $reservation = SalesReservationLogic::submit([
            'customer_id' => 1,
            'customer_name' => '测试客户',
            'items' => [['goods_id' => $goodsId, 'num' => 4]],
        ]);
        $taskId = (int)$reservation['items'][0]['procurement_task']['id'];

        $closed = ProcurementTaskLogic::close(['id' => $taskId, 'close_reason' => '不采购']);
        $convert = SalesReservationLogic::convertSales(['id' => (int)$reservation['id']]);

        self::assertNotFalse($closed);
        self::assertSame('closed', $closed['status']);
        self::assertSame('gap_closed', SalesReservation::find((int)$reservation['id'])->status);
        self::assertFalse($convert);
        self::assertSame('JXC_RESERVATION_NOT_READY', SalesReservationLogic::getReturnData()['error_code'] ?? null);
    }

    public function test_close_procurement_task_accepts_reason_alias(): void
    {
        $goodsId = $this->createGoods('关闭原因别名商品', 'RSV-D2', '0.00');
        $reservation = SalesReservationLogic::submit([
            'customer_id' => 1,
            'customer_name' => '测试客户',
            'items' => [['goods_id' => $goodsId, 'num' => 4]],
        ]);
        $taskId = (int)$reservation['items'][0]['procurement_task']['id'];

        $closed = ProcurementTaskLogic::close(['id' => $taskId, 'reason' => '前端原因']);

        self::assertNotFalse($closed);
        self::assertSame('closed', $closed['status']);
        self::assertSame('前端原因', $closed['close_reason']);
        self::assertSame('前端原因', ProcurementTask::find($taskId)->close_reason);
    }

    public function test_supply_inbound_backfills_task_and_marks_ready_only_when_full(): void
    {
        $goodsId = $this->createGoods('到货回填商品', 'RSV-E', '0.00');
        $reservation = SalesReservationLogic::submit([
            'customer_id' => 1,
            'customer_name' => '测试客户',
            'items' => [['goods_id' => $goodsId, 'num' => 5]],
        ]);
        $taskId = (int)$reservation['items'][0]['procurement_task']['id'];

        ProcurementTaskService::backfillSupplyInbound(1001, [[
            'id' => 2001,
            'goods_id' => $goodsId,
            'number' => '2.0000',
        ]]);
        $partialTask = ProcurementTask::find($taskId);
        self::assertSame('partial_arrived', $partialTask->status);
        self::assertSame('shortage', SalesReservation::find((int)$reservation['id'])->status);

        ProcurementTaskService::backfillSupplyInbound(1002, [[
            'id' => 2002,
            'goods_id' => $goodsId,
            'number' => '3.0000',
        ]]);
        $fulfilledTask = ProcurementTask::find($taskId);
        self::assertSame('fulfilled', $fulfilledTask->status);
        self::assertSame('ready', SalesReservation::find((int)$reservation['id'])->status);
    }

    public function test_convert_sales_requires_all_items_ready_and_consumes_reservation(): void
    {
        $customerId = $this->createCustomer('转换客户');
        $warehouseId = $this->createWarehouse('转换仓库');
        $shortageGoodsId = $this->createGoods('转换缺口商品', 'RSV-F1', '0.00');
        $readyGoodsId = $this->createGoods('转换现货商品', 'RSV-F2', '5.00');

        $shortage = SalesReservationLogic::submit([
            'customer_id' => $customerId,
            'customer_name' => '转换客户',
            'items' => [['goods_id' => $shortageGoodsId, 'warehouse_id' => $warehouseId, 'num' => 2, 'price' => 1]],
        ]);
        self::assertFalse(SalesReservationLogic::convertSales(['id' => (int)$shortage['id']]));
        self::assertSame('JXC_RESERVATION_NOT_READY', SalesReservationLogic::getReturnData()['error_code'] ?? null);

        $ready = SalesReservationLogic::submit([
            'customer_id' => $customerId,
            'customer_name' => '转换客户',
            'items' => [['goods_id' => $readyGoodsId, 'warehouse_id' => $warehouseId, 'num' => 3, 'price' => 2]],
        ]);
        $converted = SalesReservationLogic::convertSales(['id' => (int)$ready['id']]);

        self::assertNotFalse($converted);
        self::assertGreaterThan(0, (int)$converted['sales_order_id']);
        self::assertSame('converted', SalesReservation::find((int)$ready['id'])->status);
        self::assertSame('consumed', InventoryReservation::where('reservation_id', (int)$ready['id'])->find()->status);
    }

    public function test_cancel_sales_reservation_releases_active_reservation(): void
    {
        $goodsId = $this->createGoods('取消释放商品', 'RSV-G', '10.00');
        $reservation = SalesReservationLogic::submit([
            'customer_id' => 1,
            'customer_name' => '测试客户',
            'items' => [['goods_id' => $goodsId, 'num' => 12]],
        ]);

        self::assertSame('0.0000', InventoryReservationService::availableForGoods($goodsId));

        $cancelled = SalesReservationLogic::cancel(['id' => (int)$reservation['id']]);

        self::assertNotFalse($cancelled);
        self::assertSame('cancelled', $cancelled['status']);
        self::assertSame('10.0000', InventoryReservationService::availableForGoods($goodsId));
        self::assertSame('cancelled', ProcurementTask::where('source_reservation_id', (int)$reservation['id'])->find()->status);
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
            ProcurementTaskLogic::class,
            ProcurementTaskService::class,
            InventoryReservationService::class,
            SalesReservation::class,
            SalesReservationItem::class,
            InventoryReservation::class,
            ProcurementTask::class,
        ] as $class) {
            self::assertTrue(class_exists($class), $class . ' should exist');
        }
    }

    private function ensureNewTables(): void
    {
        $prefix = env('database.prefix', 'la_');
        $sql = <<<SQL
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
  `procurement_task_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
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
CREATE TABLE IF NOT EXISTS `{$prefix}procurement_task` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `sn` varchar(64) NOT NULL DEFAULT '',
  `source_type` varchar(32) NOT NULL DEFAULT '',
  `source_key` varchar(128) NOT NULL DEFAULT '',
  `source_reservation_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `source_reservation_item_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `goods_name` varchar(200) NOT NULL DEFAULT '',
  `goods_code` varchar(100) NOT NULL DEFAULT '',
  `warehouse_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `sku_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `spec_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `required_num` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `arrived_num` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `status` varchar(32) NOT NULL DEFAULT 'pending',
  `close_reason` varchar(500) NOT NULL DEFAULT '',
  `start_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `finish_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `close_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `create_by` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_by` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `delete_time` int(11) UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_source` (`tenant_id`, `source_type`, `source_key`),
  KEY `idx_tenant_goods_status` (`tenant_id`, `goods_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{$prefix}procurement_task_inbound` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `goods_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `supply_order_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `supply_order_item_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `inbound_num` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_task` (`task_id`),
  KEY `idx_supply_item` (`supply_order_id`, `supply_order_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            if ($statement !== '') {
                Db::execute($statement);
            }
        }
    }

    private function cleanTenantData(): void
    {
        try {
            $taskIds = Db::name('procurement_task')->where('tenant_id', self::TENANT_ID)->column('id');
            if (!empty($taskIds)) {
                Db::name('procurement_task_inbound')->whereIn('task_id', $taskIds)->delete();
            }
        } catch (\Throwable) {
        }

        foreach (['procurement_task', 'inventory_reservation', 'sales_reservation_item', 'sales_reservation'] as $table) {
            try {
                Db::name($table)->where('tenant_id', self::TENANT_ID)->delete();
            } catch (\Throwable) {
            }
        }
        foreach (['order_goods', 'sales_order', 'stock_flow', 'goods', 'customer', 'warehouse'] as $table) {
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
            'units' => '件',
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
}
