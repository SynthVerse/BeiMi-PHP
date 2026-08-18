<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\GoodsDimensionLogic;
use app\api\jxc\logic\StockService;
use app\api\jxc\logic\WarehouseSkuBalanceService;
use app\common\model\jxc\StockFlow;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

require_once __DIR__ . '/CustomerReportTestSupport.php';

final class WarehouseSkuBalanceServiceTest extends TestCase
{
    use CustomerReportTestSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareCustomerReportRequestContext();
        $this->ensureCustomerReportTables();
        $this->cleanCustomerReportData();
    }

    protected function tearDown(): void
    {
        $this->prepareCustomerReportRequestContext();
        $this->cleanCustomerReportData();
        parent::tearDown();
    }

    public function test_same_goods_skus_have_independent_balances_in_the_same_warehouse(): void
    {
        $dimension = GoodsDimensionLogic::saveDefinition([
            'name' => 'Origin',
            'code' => 'origin',
            'dimension_type' => 'sku',
        ]);
        self::assertIsArray($dimension);
        $goodsId = $this->createCustomerReportGoods('Independent SKU Goods', 'INDEPENDENT-SKU', 'kg');
        $config = GoodsDimensionLogic::saveProductDimensions([
            'goods_id' => $goodsId,
            'dimensions' => [[
                'dimension_id' => $dimension['id'],
                'usage_mode' => 'sku',
                'values' => [
                    ['name' => 'A', 'code' => 'a'],
                    ['name' => 'B', 'code' => 'b'],
                ],
            ]],
        ]);
        self::assertIsArray($config, GoodsDimensionLogic::getError());
        $skuA = (int)$config['skus'][0]['id'];
        $skuB = (int)$config['skus'][1]['id'];
        $warehouseId = $this->createCustomerReportWarehouse('SKU Warehouse');

        self::assertNotFalse(WarehouseSkuBalanceService::inbound($warehouseId, $skuA, '10.0000'));
        self::assertNotFalse(WarehouseSkuBalanceService::inbound($warehouseId, $skuB, '3.0000'));
        self::assertNotFalse(WarehouseSkuBalanceService::reserve($warehouseId, $skuA, '4.0000'));

        self::assertSame('6.0000', WarehouseSkuBalanceService::available($warehouseId, $skuA));
        self::assertSame('3.0000', WarehouseSkuBalanceService::available($warehouseId, $skuB));
        self::assertSame('4.0000', WarehouseSkuBalanceService::reserved($warehouseId, $skuA));
        self::assertSame('0.0000', WarehouseSkuBalanceService::reserved($warehouseId, $skuB));
        self::assertSame(2, Db::name('warehouse_sku_balance')
            ->where('tenant_id', self::TENANT_ID)
            ->where('warehouse_id', $warehouseId)
            ->where('goods_id', $goodsId)
            ->count());
    }

    public function test_balance_rejects_a_sku_from_another_tenant(): void
    {
        $goodsId = $this->createCustomerReportGoods('Tenant SKU Goods', 'TENANT-SKU', 'kg');
        self::assertIsArray(GoodsDimensionLogic::saveProductDimensions([
            'goods_id' => $goodsId,
            'dimensions' => [],
        ]));
        $skuId = (int)Db::name('goods_sku')
            ->where('tenant_id', self::TENANT_ID)
            ->where('goods_id', $goodsId)
            ->value('id');
        $warehouseId = $this->createCustomerReportWarehouse('Tenant Warehouse');

        $this->prepareCustomerReportRequestContext(self::OTHER_TENANT_ID);
        self::assertFalse(WarehouseSkuBalanceService::inbound($warehouseId, $skuId, '1.0000'));
        self::assertSame(0, Db::name('warehouse_sku_balance')->where('sku_id', $skuId)->count());
    }

    public function test_sku_transfer_writes_auditable_source_and_target_flows(): void
    {
        $goodsId = $this->createCustomerReportGoods('Transfer SKU Goods', 'TRANSFER-SKU', 'kg');
        $skuId = $this->customerReportSkuId($goodsId);
        $warehouseA = $this->createCustomerReportWarehouse('Transfer Source');
        $warehouseB = $this->createCustomerReportWarehouse('Transfer Target');
        self::assertNotFalse(WarehouseSkuBalanceService::inbound($warehouseA, $skuId, '5.0000'));

        self::assertTrue(StockService::transfer(
            $warehouseA,
            $warehouseB,
            $goodsId,
            '2.0000',
            9001,
            'warehouse-transfer',
            'WT-9001',
            '测试调拨',
            $skuId
        ));

        self::assertSame('3.0000', WarehouseSkuBalanceService::onHand($warehouseA, $skuId));
        self::assertSame('2.0000', WarehouseSkuBalanceService::onHand($warehouseB, $skuId));
        $flows = Db::name('stock_flow')
            ->where('tenant_id', self::TENANT_ID)
            ->where('order_type', 'warehouse-transfer')
            ->where('order_id', 9001)
            ->order('id', 'asc')
            ->select()
            ->toArray();
        self::assertCount(2, $flows);
        self::assertSame([$warehouseA, $warehouseB], array_map('intval', array_column($flows, 'warehouse_id')));
        self::assertSame([$skuId, $skuId], array_map('intval', array_column($flows, 'sku_id')));
        self::assertSame([StockFlow::FLOW_OUT, StockFlow::FLOW_IN], array_map('intval', array_column($flows, 'flow_type')));
        self::assertSame(['WT-9001', 'WT-9001'], array_column($flows, 'order_sn'));
    }

    public function test_regular_outbound_cannot_deepen_negative_available_stock_reserved_by_other_orders(): void
    {
        $goodsId = $this->createCustomerReportGoods('Reserved SKU Goods', 'RESERVED-SKU', 'kg');
        $skuId = $this->customerReportSkuId($goodsId);
        $warehouseId = $this->createCustomerReportWarehouse('Reserved Warehouse');

        self::assertNotFalse(WarehouseSkuBalanceService::inbound($warehouseId, $skuId, '10.0000'));
        self::assertNotFalse(WarehouseSkuBalanceService::reserve($warehouseId, $skuId, '10.0000'));
        self::assertFalse(WarehouseSkuBalanceService::outbound($warehouseId, $skuId, '1.0000'));
        self::assertSame('10.0000', WarehouseSkuBalanceService::onHand($warehouseId, $skuId));
        self::assertSame('10.0000', WarehouseSkuBalanceService::reserved($warehouseId, $skuId));
        self::assertSame('0.0000', WarehouseSkuBalanceService::available($warehouseId, $skuId));
    }
}
