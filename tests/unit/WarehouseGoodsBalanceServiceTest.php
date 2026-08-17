<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\StockService;
use app\api\jxc\logic\WarehouseGoodsBalanceService;
use app\api\jxc\logic\WarehouseSkuBalanceService;
use app\api\jxc\logic\GoodsLogic;
use app\common\model\jxc\Goods;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

require_once __DIR__ . '/CustomerReportTestSupport.php';

final class WarehouseGoodsBalanceServiceTest extends TestCase
{
    use CustomerReportTestSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareCustomerReportRequestContext();
        $this->ensureCustomerReportTables();
        $this->resetWarehouseGoodsBalanceSchema();
        $this->cleanWarehouseGoodsBalanceTenantData();
    }

    protected function tearDown(): void
    {
        $this->cleanWarehouseGoodsBalanceTenantData();
        $this->cleanWarehouseGoodsBalanceTenantData(self::OTHER_TENANT_ID);
        parent::tearDown();
    }

    public function test_inbound_stock_is_isolated_by_warehouse_and_updates_the_total_stock_display(): void
    {
        $goodsId = $this->createCustomerReportGoods('仓库隔离商品', 'WH-BALANCE');
        $warehouseA = $this->createCustomerReportWarehouse('A仓');
        $warehouseB = $this->createCustomerReportWarehouse('B仓');

        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseA, $goodsId, '10.0000'));
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseB, $goodsId, '5.0000'));

        self::assertSame('10.0000', WarehouseGoodsBalanceService::available($warehouseA, $goodsId));
        self::assertSame('5.0000', WarehouseGoodsBalanceService::available($warehouseB, $goodsId));
        self::assertSame('15.0000', (string)Goods::where('id', $goodsId)->value('stock'));
        self::assertSame(1, (int)Db::name('warehouse_goods_balance')->where('tenant_id', self::TENANT_ID)->where('warehouse_id', $warehouseA)->where('goods_id', $goodsId)->value('version'));
        self::assertSame(0, (int)Db::name('warehouse_goods_balance')->where('tenant_id', self::TENANT_ID)->where('warehouse_id', $warehouseA)->where('goods_id', $goodsId)->value('base_unit_id'));
        self::assertSame('件', (string)Db::name('warehouse_goods_balance')->where('tenant_id', self::TENANT_ID)->where('warehouse_id', $warehouseA)->where('goods_id', $goodsId)->value('base_unit_name'));
    }

    public function test_outbound_and_reservation_cannot_make_a_warehouse_balance_negative(): void
    {
        $goodsId = $this->createCustomerReportGoods('负库存保护商品', 'WH-NEGATIVE');
        $warehouseId = $this->createCustomerReportWarehouse('负库存保护仓');

        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, '10.0000'));
        self::assertNotFalse(WarehouseGoodsBalanceService::reserve($warehouseId, $goodsId, '6.0000'));
        self::assertFalse(WarehouseGoodsBalanceService::reserve($warehouseId, $goodsId, '5.0000'));
        self::assertFalse(WarehouseGoodsBalanceService::outbound($warehouseId, $goodsId, '5.0000'));
        self::assertFalse(WarehouseGoodsBalanceService::release($warehouseId, $goodsId, '7.0000'));

        self::assertSame('10.0000', WarehouseGoodsBalanceService::onHand($warehouseId, $goodsId));
        self::assertSame('6.0000', WarehouseGoodsBalanceService::reserved($warehouseId, $goodsId));
        self::assertSame('4.0000', WarehouseGoodsBalanceService::available($warehouseId, $goodsId));
    }

    public function test_invalid_first_movement_does_not_create_a_zero_balance_row(): void
    {
        $goodsId = $this->createCustomerReportGoods('首笔库存商品', 'WH-FIRST-MOVEMENT');
        $warehouseId = $this->createCustomerReportWarehouse('首笔库存仓');

        self::assertFalse(WarehouseGoodsBalanceService::outbound($warehouseId, $goodsId, '1.0000'));
        self::assertFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, '-1.0000'));
        self::assertFalse(WarehouseGoodsBalanceService::reserve($warehouseId, $goodsId, '-1.0000'));
        self::assertSame(0, Db::name('warehouse_goods_balance')->where('tenant_id', self::TENANT_ID)->where('warehouse_id', $warehouseId)->where('goods_id', $goodsId)->count());
    }

    public function test_transfer_preserves_total_stock_and_moves_only_the_specified_warehouse_balance(): void
    {
        $goodsId = $this->createCustomerReportGoods('调拨守恒商品', 'WH-TRANSFER');
        $warehouseA = $this->createCustomerReportWarehouse('调出仓');
        $warehouseB = $this->createCustomerReportWarehouse('调入仓');

        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseA, $goodsId, '10.0000'));
        self::assertNotFalse(WarehouseGoodsBalanceService::transfer($warehouseA, $warehouseB, $goodsId, '4.0000'));

        self::assertSame('6.0000', WarehouseGoodsBalanceService::onHand($warehouseA, $goodsId));
        self::assertSame('4.0000', WarehouseGoodsBalanceService::onHand($warehouseB, $goodsId));
        self::assertSame('10.0000', (string)Goods::where('id', $goodsId)->value('stock'));
    }

    public function test_stock_service_uses_the_warehouse_balance_primitive(): void
    {
        $goodsId = $this->createCustomerReportGoods('统一入口商品', 'WH-STOCK-SERVICE');
        $warehouseId = $this->createCustomerReportWarehouse('统一入口仓');
        $skuId = $this->customerReportSkuId($goodsId);

        self::assertTrue(StockService::inbound($warehouseId, $goodsId, '3.0000', 1001, 'supply', 'SUP1001', '', $skuId));
        self::assertTrue(StockService::outbound($warehouseId, $goodsId, '1.0000', 1002, 'sales', 'SAL1002', '', $skuId));
        self::assertFalse(StockService::outbound($warehouseId, $goodsId, '3.0000', 1003, 'sales', 'SAL1003', '', $skuId));

        self::assertSame('2.0000', WarehouseSkuBalanceService::available($warehouseId, $skuId));
        self::assertSame('2.0000', (string)Goods::where('id', $goodsId)->value('stock'));
        self::assertSame(2, Db::name('stock_flow')->where('tenant_id', self::TENANT_ID)->where('goods_id', $goodsId)->count());
    }

    public function test_stock_balance_and_flow_roll_back_together_when_flow_write_fails(): void
    {
        $goodsId = $this->createCustomerReportGoods('流水原子性商品', 'WH-FLOW-ATOMIC');
        $warehouseId = $this->createCustomerReportWarehouse('流水原子性仓');
        $skuId = $this->customerReportSkuId($goodsId);
        Db::execute('ALTER TABLE `la_stock_flow` ADD UNIQUE KEY `uk_test_stock_flow_order` (`tenant_id`, `order_sn`)');

        try {
            self::assertTrue(StockService::inbound($warehouseId, $goodsId, '3.0000', 2001, 'supply', 'FLOW-ATOMIC', '', $skuId));
            self::assertFalse(StockService::inbound($warehouseId, $goodsId, '1.0000', 2002, 'supply', 'FLOW-ATOMIC', '', $skuId));
            self::assertSame('3.0000', WarehouseSkuBalanceService::onHand($warehouseId, $skuId));
            self::assertSame('3.0000', (string)Goods::where('id', $goodsId)->value('stock'));
        } finally {
            Db::execute('ALTER TABLE `la_stock_flow` DROP INDEX `uk_test_stock_flow_order`');
        }
    }

    public function test_balance_is_isolated_by_tenant(): void
    {
        $goodsId = $this->createCustomerReportGoods('租户一商品', 'WH-TENANT-A');
        $warehouseId = $this->createCustomerReportWarehouse('租户一仓');
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, '3.0000'));

        $this->prepareCustomerReportRequestContext(self::OTHER_TENANT_ID);
        $otherGoodsId = $this->createGoodsForTenant(self::OTHER_TENANT_ID, '租户二商品', 'WH-TENANT-B');
        $otherWarehouseId = $this->createWarehouseForTenant(self::OTHER_TENANT_ID, '租户二仓');
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($otherWarehouseId, $otherGoodsId, '8.0000'));
        self::assertSame('8.0000', WarehouseGoodsBalanceService::available($otherWarehouseId, $otherGoodsId));

        $this->prepareCustomerReportRequestContext();
        self::assertSame('3.0000', WarehouseGoodsBalanceService::available($warehouseId, $goodsId));
    }

    public function test_migration_can_be_run_repeatedly_without_losing_the_balance_table(): void
    {
        $this->runWarehouseGoodsBalanceMigration();
        $this->runWarehouseGoodsBalanceMigration();

        self::assertSame(1, (int)Db::query("SELECT COUNT(*) AS count FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'la_warehouse_goods_balance'")[0]['count']);
        $columns = Db::query("SELECT table_name, column_name, numeric_precision AS precision_digits, numeric_scale AS scale_digits FROM information_schema.columns WHERE table_schema = DATABASE() AND ((table_name = 'la_goods' AND column_name = 'stock') OR (table_name = 'la_stock_flow' AND column_name IN ('quantity', 'before_stock', 'after_stock'))) ORDER BY table_name, column_name");
        self::assertCount(4, $columns);
        foreach ($columns as $column) {
            self::assertSame(18, (int)$column['precision_digits']);
            self::assertSame(4, (int)$column['scale_digits']);
        }
        $balanceColumns = Db::query('SHOW COLUMNS FROM `la_warehouse_goods_balance`');
        self::assertContains('base_unit_id', array_column($balanceColumns, 'Field'));
        self::assertContains('base_unit_name', array_column($balanceColumns, 'Field'));
    }

    public function test_goods_master_write_paths_cannot_set_authoritative_stock(): void
    {
        $method = new \ReflectionMethod(GoodsLogic::class, 'buildSaveData');
        $method->setAccessible(true);

        $newGoods = $method->invoke(null, ['name' => '主数据商品', 'stock' => '9.0000']);
        $existingGoods = $method->invoke(null, ['name' => '主数据商品', 'stock' => '99.0000'], [
            'tenant_id' => self::TENANT_ID,
            'name' => '主数据商品',
            'stock' => '3.0000',
        ]);

        self::assertSame('0.00', $newGoods['stock']);
        self::assertSame('3.00', $existingGoods['stock']);
        self::assertStringContainsString("'stock' => '0.0000'", (string)file_get_contents(dirname(__DIR__, 2) . '/app/common/service/cloud/CloudGoodsService.php'));
    }

    public function test_goods_with_a_warehouse_balance_cannot_change_its_base_unit(): void
    {
        $goodsId = $this->createCustomerReportGoods('基础单位锁定商品', 'WH-UNIT-LOCK');
        $warehouseId = $this->createCustomerReportWarehouse('基础单位锁定仓');
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, '1.0000'));
        self::assertSame(1, Db::name('warehouse_goods_balance')->where('tenant_id', self::TENANT_ID)->where('goods_id', $goodsId)->count());

        $goods = Goods::where('id', $goodsId)->findOrEmpty()->toArray();
        $goods['id'] = $goodsId;
        $goods['units'] = '条';

        self::assertFalse(GoodsLogic::edit($goods));
        self::assertSame('件', (string)Goods::where('id', $goodsId)->value('units'));
    }

    private function runWarehouseGoodsBalanceMigration(): void
    {
        $migration = dirname(__DIR__, 2) . '/database/migrations/20260729_000001_create_warehouse_goods_balance.sql';
        $sql = $this->prepareMigration((string)file_get_contents($migration));
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            Db::execute($statement);
        }
    }

    private function resetWarehouseGoodsBalanceSchema(): void
    {
        Db::execute('DROP TABLE IF EXISTS `la_warehouse_goods_balance`');
        $this->runWarehouseGoodsBalanceMigration();
    }

    private function cleanWarehouseGoodsBalanceTenantData(int $tenantId = self::TENANT_ID): void
    {
        Db::name('warehouse_goods_balance')->where('tenant_id', $tenantId)->delete();
        Db::name('warehouse_sku_balance')->where('tenant_id', $tenantId)->delete();
    }

    private function createGoodsForTenant(int $tenantId, string $name, string $code): int
    {
        return (int)Db::name('goods')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => $name,
            'product_code' => $code . '-' . uniqid(),
            'units' => '件',
            'unit_id' => 0,
            'price' => '1.00',
            'cost' => '1.00',
            'stock' => '0.0000',
            'category_id' => 0,
            'is_disabled' => 0,
            'create_time' => time(),
            'update_time' => time(),
        ]);
    }

    private function createWarehouseForTenant(int $tenantId, string $name): int
    {
        return (int)Db::name('warehouse')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => $name,
            'is_enabled' => 1,
            'create_time' => time(),
            'update_time' => time(),
        ]);
    }
}
