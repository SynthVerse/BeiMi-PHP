<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\GoodsLogic;
use app\api\jxc\logic\GoodsSupplierMatrixLogic;
use app\api\jxc\logic\SupplierLogic;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

require_once __DIR__ . '/CustomerReportTestSupport.php';

final class GoodsSupplierRelationContractTest extends TestCase
{
    use CustomerReportTestSupport;

    private array $supplierIds = [];

    protected function setUp(): void
    {
        $this->prepareCustomerReportRequestContext();
        $this->ensureCustomerReportTables();
        $this->cleanCustomerReportData();
    }

    protected function tearDown(): void
    {
        $this->prepareCustomerReportRequestContext();
        $this->cleanCustomerReportData();
        if ($this->supplierIds !== []) {
            Db::name('vendor')->where('tenant_id', self::TENANT_ID)->whereIn('id', $this->supplierIds)->delete();
        }
    }

    public function test_saved_supplier_relation_exposes_identity_units_and_status_without_commercial_defaults(): void
    {
        $goods = $this->createCustomerReportGoods('精简供应关系商品', 'RELATION-CONTRACT', '斤');
        $sku = $this->customerReportSkuId($goods);
        $supplier = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '精简供应关系供方']);
        $this->supplierIds[] = $supplier;

        $saved = GoodsSupplierMatrixLogic::save(['goods_id' => $goods, 'relations' => [[
            'sku_id' => $sku, 'supplier_id' => $supplier, 'supplier_goods_name' => '供方商品名',
            'purchase_unit_name' => '斤', 'settlement_unit_name' => '斤', 'is_preferred' => 1, 'status' => 1,
        ]]]);
        self::assertIsArray($saved, GoodsSupplierMatrixLogic::getError());
        self::assertCount(1, $saved);
        self::assertSame($sku, $saved[0]['sku_id']);
        self::assertSame($supplier, $saved[0]['supplier_id']);
        self::assertSame('供方商品名', $saved[0]['supplier_goods_name']);
        self::assertSame('斤', $saved[0]['purchase_unit_name']);
        self::assertSame('斤', $saved[0]['settlement_unit_name']);
        self::assertSame(1, $saved[0]['is_preferred']);
        $this->assertNoCommercialDefaults($saved[0]);
        $this->assertNoCommercialDefaults(GoodsSupplierMatrixLogic::lists(['goods_id' => $goods])[0]);

        $supplierGoods = SupplierLogic::goods(['supplier_id' => $supplier]);
        self::assertCount(1, $supplierGoods['data']);
        $this->assertNoCommercialDefaults($supplierGoods['data'][0]);
        self::assertSame('1.00', $supplierGoods['data'][0]['cost']);
        $detail = GoodsLogic::detail(['id' => $goods]);
        self::assertIsArray($detail);
        self::assertSame('1.00', $detail['cost']);
        self::assertArrayNotHasKey('purchase_price', $detail);
    }

    private function assertNoCommercialDefaults(array $row): void
    {
        foreach (['purchase_price', 'default_purchase_price', 'supplier_purchase_price', 'daily_capacity_qty', 'min_purchase_qty', 'lead_time_days'] as $field) {
            self::assertArrayNotHasKey($field, $row);
        }
    }

    public function test_fresh_supplier_schema_has_no_commercial_defaults_or_preset_price_history(): void
    {
        $columns = array_column(Db::query('SHOW COLUMNS FROM `la_goods_supplier`'), 'Field');
        foreach (['purchase_price', 'daily_capacity_qty', 'min_purchase_qty', 'lead_time_days'] as $field) {
            self::assertNotContains($field, $columns);
        }
        self::assertContains('purchase_unit_id', $columns);
        self::assertContains('settlement_unit_id', $columns);
        self::assertSame([], Db::query('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?', ['la_goods_supplier_price_history']));
    }
}
