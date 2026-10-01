<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\GoodsDimensionLogic;
use app\api\jxc\logic\GoodsLogic;
use app\api\jxc\logic\GoodsSkuLogic;
use app\api\jxc\logic\GoodsSpecificationLogic;
use app\api\jxc\logic\CustomerReportLineService;
use app\api\jxc\logic\SalesOrderLogic;
use app\api\jxc\logic\WarehouseSkuBalanceService;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

require_once __DIR__ . '/CustomerReportTestSupport.php';

final class GoodsDimensionLogicTest extends TestCase
{
    use CustomerReportTestSupport;

    private static bool $dimensionSchemaReady = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareCustomerReportRequestContext();
        $this->ensureCustomerReportTables();
        $this->ensureDimensionSchema();
        $this->cleanCustomerReportData();
        Db::name('goods_spec')->where('tenant_id', self::OTHER_TENANT_ID)->delete();
    }

    protected function tearDown(): void
    {
        $this->prepareCustomerReportRequestContext();
        $this->cleanCustomerReportData();
        Db::name('goods_spec')->where('tenant_id', self::OTHER_TENANT_ID)->delete();
        parent::tearDown();
    }

    public function test_dimension_definitions_are_tenant_scoped_and_referenced_dimensions_cannot_be_deleted(): void
    {
        $origin = GoodsDimensionLogic::saveDefinition([
            'name' => '产地',
            'code' => 'origin',
            'dimension_type' => 'sku',
            'sort' => 9,
        ]);
        self::assertIsArray($origin);
        self::assertSame('origin', $origin['code']);
        self::assertSame('sku', $origin['dimension_type']);

        $this->prepareCustomerReportRequestContext(self::OTHER_TENANT_ID);
        self::assertSame([], GoodsDimensionLogic::definitions());
        self::assertFalse(GoodsDimensionLogic::saveDefinition([
            'id' => $origin['id'],
            'name' => '越权修改',
            'code' => 'origin',
            'dimension_type' => 'sku',
        ]));

        $this->prepareCustomerReportRequestContext();
        $goodsId = $this->createCustomerReportGoods('测试鲍鱼', 'ABALONE');
        self::assertIsArray(GoodsDimensionLogic::saveProductDimensions([
            'goods_id' => $goodsId,
            'dimensions' => [[
                'dimension_id' => $origin['id'],
                'values' => [['name' => '大连', 'code' => 'dalian']],
            ]],
        ]));

        self::assertFalse(GoodsDimensionLogic::deleteDefinition(['id' => $origin['id']]));
        self::assertStringContainsString('停用', GoodsDimensionLogic::getError());

        $disabled = GoodsDimensionLogic::saveDefinition([
            'id' => $origin['id'],
            'name' => '产地',
            'code' => 'origin',
            'dimension_type' => 'sku',
            'status' => 0,
        ]);
        self::assertSame(0, $disabled['status']);
        self::assertSame(9, $disabled['sort']);
    }

    public function test_each_product_can_choose_whether_the_same_dimension_participates_in_sku(): void
    {
        $origin = GoodsDimensionLogic::saveDefinition([
            'name' => 'Origin',
            'code' => 'origin',
            'dimension_type' => 'sku',
        ]);
        self::assertIsArray($origin);

        $skuGoodsId = $this->createCustomerReportGoods('Role SKU Goods', 'ROLE-SKU');
        $skuConfig = GoodsDimensionLogic::saveProductDimensions([
            'goods_id' => $skuGoodsId,
            'dimensions' => [[
                'dimension_id' => $origin['id'],
                'usage_mode' => 'sku',
                'values' => [
                    ['name' => 'Dalian', 'code' => 'dalian'],
                    ['name' => 'Shandong', 'code' => 'shandong'],
                ],
            ]],
        ]);
        self::assertIsArray($skuConfig, GoodsDimensionLogic::getError());
        self::assertSame('sku', $skuConfig['dimensions'][0]['usage_mode']);
        self::assertCount(2, $skuConfig['skus']);

        $descriptiveGoodsId = $this->createCustomerReportGoods('Role Description Goods', 'ROLE-DESCRIPTION');
        $descriptiveConfig = GoodsDimensionLogic::saveProductDimensions([
            'goods_id' => $descriptiveGoodsId,
            'dimensions' => [[
                'dimension_id' => $origin['id'],
                'usage_mode' => 'descriptive',
                'values' => [['name' => 'Dalian', 'code' => 'dalian']],
            ]],
        ]);
        self::assertIsArray($descriptiveConfig, GoodsDimensionLogic::getError());
        self::assertSame('descriptive', $descriptiveConfig['dimensions'][0]['usage_mode']);
        self::assertCount(1, $descriptiveConfig['skus']);
        self::assertSame('Role Description Goods', $descriptiveConfig['skus'][0]['sku_name']);
        self::assertSame([], $descriptiveConfig['skus'][0]['dimensions']);
    }

    public function test_goods_without_dimensions_are_created_with_one_base_sku(): void
    {
        $unitId = $this->createCustomerReportUnit('kg');

        $created = GoodsLogic::add([
            'name' => 'Single SKU Goods',
            'category_id' => 0,
            'units' => 'kg',
            'units_id' => $unitId,
            'dimensions' => [],
        ]);

        self::assertIsArray($created, GoodsLogic::getError());
        $config = GoodsDimensionLogic::productDimensions(['goods_id' => (int)$created['id']]);
        self::assertSame([], $config['dimensions']);
        self::assertCount(1, $config['skus']);
        self::assertSame('Single SKU Goods', $config['skus'][0]['sku_name']);
        self::assertSame('SKU-' . (int)$created['id'] . '-BASE', $config['skus'][0]['sku_code']);
        self::assertSame(1, $config['skus'][0]['is_auto_generated']);
    }

    public function test_reordering_product_dimensions_does_not_change_sku_identity(): void
    {
        $origin = GoodsDimensionLogic::saveDefinition([
            'name' => 'Stable Origin', 'code' => 'stable_origin', 'dimension_type' => 'sku', 'sort' => 0,
        ]);
        $quality = GoodsDimensionLogic::saveDefinition([
            'name' => 'Stable Quality', 'code' => 'stable_quality', 'dimension_type' => 'sku', 'sort' => 1,
        ]);
        $goodsId = $this->createCustomerReportGoods('Stable Identity Goods', 'STABLE-IDENTITY', 'kg');
        $first = GoodsDimensionLogic::saveProductDimensions([
            'goods_id' => $goodsId,
            'dimensions' => [
                ['dimension_id' => $origin['id'], 'sort' => 0, 'values' => [['name' => 'Dalian', 'code' => 'dalian']]],
                ['dimension_id' => $quality['id'], 'sort' => 1, 'values' => [['name' => 'Live', 'code' => 'live']]],
            ],
        ]);
        self::assertIsArray($first, GoodsDimensionLogic::getError());
        $skuId = (int)$first['skus'][0]['id'];

        $second = GoodsDimensionLogic::saveProductDimensions([
            'goods_id' => $goodsId,
            'dimensions' => [
                ['dimension_id' => $quality['id'], 'sort' => 0, 'values' => [['id' => $first['dimensions'][1]['values'][0]['id'], 'name' => 'Live', 'code' => 'live']]],
                ['dimension_id' => $origin['id'], 'sort' => 1, 'values' => [['id' => $first['dimensions'][0]['values'][0]['id'], 'name' => 'Dalian', 'code' => 'dalian']]],
            ],
        ]);
        self::assertIsArray($second, GoodsDimensionLogic::getError());
        self::assertCount(1, $second['skus']);
        self::assertSame($skuId, (int)$second['skus'][0]['id']);
        self::assertSame('stable_quality', $second['dimensions'][0]['code']);
        self::assertSame('stable_origin', $second['dimensions'][1]['code']);
    }

    public function test_referenced_base_sku_is_restored_when_product_returns_to_single_sku(): void
    {
        $goodsId = $this->createCustomerReportGoods('Restored Base SKU Goods', 'RESTORE-BASE', 'kg');
        $baseSkuId = $this->customerReportSkuId($goodsId);
        $warehouseId = $this->createCustomerReportWarehouse('Restore Base SKU Warehouse');
        self::assertNotFalse(WarehouseSkuBalanceService::inbound($warehouseId, $baseSkuId, '2.0000'));

        $origin = GoodsDimensionLogic::saveDefinition([
            'name' => 'Restore Origin',
            'code' => 'restore_origin',
            'dimension_type' => 'sku',
        ]);
        self::assertIsArray($origin);
        self::assertIsArray(GoodsDimensionLogic::saveProductDimensions([
            'goods_id' => $goodsId,
            'dimensions' => [[
                'dimension_id' => $origin['id'],
                'usage_mode' => 'sku',
                'values' => [['name' => 'Dalian', 'code' => 'dalian']],
            ]],
        ]));
        self::assertSame(0, (int)Db::name('goods_sku')->where('id', $baseSkuId)->value('status'));
        self::assertGreaterThanOrEqual(8, (int)Db::name('goods_sku')->where('id', $baseSkuId)->value('dimension_disabled_snapshot'));

        $single = GoodsDimensionLogic::saveProductDimensions([
            'goods_id' => $goodsId,
            'dimensions' => [],
        ]);
        self::assertIsArray($single, GoodsDimensionLogic::getError());
        self::assertCount(1, $single['skus']);
        self::assertSame($baseSkuId, (int)$single['skus'][0]['id']);
        self::assertSame(1, (int)$single['skus'][0]['status']);
        self::assertSame(0, (int)$single['skus'][0]['dimension_disabled_snapshot']);
        self::assertSame('2.0000', WarehouseSkuBalanceService::onHand($warehouseId, $baseSkuId));
    }

    public function test_only_enabled_sku_combinations_create_skus_and_descriptive_dimensions_do_not_expand_them(): void
    {
        $origin = GoodsDimensionLogic::saveDefinition(['name' => '产地', 'code' => 'origin', 'dimension_type' => 'sku']);
        $size = GoodsDimensionLogic::saveDefinition(['name' => '规格', 'code' => 'weight_grade', 'dimension_type' => 'sku']);
        $quality = GoodsDimensionLogic::saveDefinition(['name' => '品质', 'code' => 'quality_status', 'dimension_type' => 'sku']);
        $appearance = GoodsDimensionLogic::saveDefinition(['name' => '外观说明', 'code' => 'appearance', 'dimension_type' => 'descriptive']);
        $goodsId = $this->createCustomerReportGoods('组合鲍鱼', 'ABALONE-COMBO', '斤');

        $result = GoodsDimensionLogic::saveProductDimensions([
            'goods_id' => $goodsId,
            'dimensions' => [
                ['dimension_id' => $origin['id'], 'values' => [
                    ['name' => '山东', 'code' => 'shandong'],
                    ['name' => '大连', 'code' => 'dalian'],
                ]],
                ['dimension_id' => $size['id'], 'values' => [
                    ['name' => '5头', 'code' => '5_head'],
                    ['name' => '10头', 'code' => '10_head'],
                ]],
                ['dimension_id' => $quality['id'], 'values' => [
                    ['name' => '活鲍鱼', 'code' => 'live'],
                    ['name' => '冻鲍鱼', 'code' => 'frozen'],
                ]],
                ['dimension_id' => $appearance['id'], 'values' => [
                    ['name' => '壳色偏深', 'code' => 'dark_shell'],
                ]],
            ],
            'combinations' => [
                ['values' => ['origin' => 'dalian', 'weight_grade' => '5_head', 'quality_status' => 'live']],
                ['values' => ['origin' => 'shandong', 'weight_grade' => '10_head', 'quality_status' => 'frozen']],
            ],
        ]);

        self::assertIsArray($result);
        self::assertCount(4, $result['dimensions']);
        self::assertCount(2, $result['skus']);
        self::assertSame(2, Db::name('goods_sku')->where('tenant_id', self::TENANT_ID)->where('goods_id', $goodsId)->count());
        self::assertSame(6, Db::name('goods_sku_spec_value')->where('tenant_id', self::TENANT_ID)->where('goods_id', $goodsId)->count());
        self::assertSame(0, Db::name('goods_sku_spec_value')->where('tenant_id', self::TENANT_ID)->where('goods_id', $goodsId)->where('spec_id', $appearance['id'])->count());

        $skuNames = array_column($result['skus'], 'sku_name');
        self::assertContains('组合鲍鱼-大连-5头-活鲍鱼', $skuNames);
        self::assertContains('组合鲍鱼-山东-10头-冻鲍鱼', $skuNames);
    }

    public function test_disabled_referenced_dimension_can_be_preserved_but_not_assigned_to_another_goods(): void
    {
        $origin = GoodsDimensionLogic::saveDefinition(['name' => '产地', 'code' => 'origin', 'dimension_type' => 'sku']);
        $goodsId = $this->createCustomerReportGoods('停用维度原商品', 'DISABLED-DIMENSION-EXISTING', '斤');
        $config = GoodsDimensionLogic::saveProductDimensions([
            'goods_id' => $goodsId,
            'dimensions' => [[
                'dimension_id' => $origin['id'],
                'values' => [['name' => '大连', 'code' => 'dalian']],
            ]],
        ]);
        self::assertIsArray($config);
        self::assertIsArray(GoodsDimensionLogic::saveDefinition([
            'id' => $origin['id'],
            'name' => '产地',
            'code' => 'origin',
            'dimension_type' => 'sku',
            'status' => 0,
        ]));

        self::assertIsArray(GoodsDimensionLogic::saveProductDimensions([
            'goods_id' => $goodsId,
            'dimensions' => $config['dimensions'],
        ]), GoodsDimensionLogic::getError());

        $tamperedDimensions = $config['dimensions'];
        $tamperedDimensions[0]['values'][0]['name'] = '擅自改名';
        self::assertFalse(GoodsDimensionLogic::saveProductDimensions([
            'goods_id' => $goodsId,
            'dimensions' => $tamperedDimensions,
        ]));
        self::assertStringContainsString('只能保留原商品配置', GoodsDimensionLogic::getError());

        $otherGoodsId = $this->createCustomerReportGoods('停用维度新商品', 'DISABLED-DIMENSION-NEW', '斤');
        self::assertFalse(GoodsDimensionLogic::saveProductDimensions([
            'goods_id' => $otherGoodsId,
            'dimensions' => [[
                'dimension_id' => $origin['id'],
                'values' => [['name' => '大连', 'code' => 'dalian']],
            ]],
        ]));
        self::assertStringContainsString('已停用', GoodsDimensionLogic::getError());
    }

    public function test_dimension_writes_require_goods_maintenance_permission(): void
    {
        $origin = GoodsDimensionLogic::saveDefinition(['name' => '产地', 'code' => 'origin', 'dimension_type' => 'sku']);
        $goodsId = $this->createCustomerReportGoods('无权维度商品', 'DIMENSION-PERMISSION', '斤');
        request()->adminInfo = [
            'admin_id' => self::ADMIN_ID,
            'tenant_id' => self::TENANT_ID,
            'root' => 0,
        ];

        self::assertFalse(GoodsDimensionLogic::saveDefinition([
            'id' => $origin['id'],
            'name' => '越权改名',
        ]));
        self::assertSame('当前账号没有商品维护权限', GoodsDimensionLogic::getError());
        self::assertFalse(GoodsDimensionLogic::deleteDefinition(['id' => $origin['id']]));
        self::assertFalse(GoodsDimensionLogic::saveProductDimensions([
            'goods_id' => $goodsId,
            'dimensions' => [[
                'dimension_id' => $origin['id'],
                'values' => [['name' => '大连', 'code' => 'dalian']],
            ]],
        ]));
        self::assertSame(0, Db::name('goods_spec_value')->where('goods_id', $goodsId)->count());
    }

    public function test_selected_sku_dimension_requires_values_and_preserves_the_base_sku_on_failure(): void
    {
        $description = GoodsDimensionLogic::saveDefinition([
            'name' => '捕捞说明',
            'code' => 'catch_note',
            'dimension_type' => 'sku',
        ]);
        $goodsId = $this->createCustomerReportGoods('无SKU维度商品', 'NO-SKU');
        $before = GoodsDimensionLogic::productDimensions(['goods_id' => $goodsId]);

        self::assertFalse(GoodsDimensionLogic::saveProductDimensions([
            'goods_id' => $goodsId,
            'dimensions' => [[
                'dimension_id' => $description['id'],
                'values' => [],
            ]],
        ]));
        self::assertStringContainsString('SKU维度', GoodsDimensionLogic::getError());
        self::assertSame(0, Db::name('goods_spec_value')->where('goods_id', $goodsId)->count());
        self::assertSame($before, GoodsDimensionLogic::productDimensions(['goods_id' => $goodsId]));
    }

    public function test_legacy_quality_and_specification_endpoints_read_generic_dimension_values(): void
    {
        $quality = GoodsDimensionLogic::saveDefinition(['name' => '品质', 'code' => 'quality_status', 'dimension_type' => 'sku']);
        $size = GoodsDimensionLogic::saveDefinition(['name' => '规格', 'code' => 'weight_grade', 'dimension_type' => 'sku']);
        $goodsId = $this->createCustomerReportGoods('兼容商品', 'LEGACY');

        self::assertIsArray(GoodsDimensionLogic::saveProductDimensions([
            'goods_id' => $goodsId,
            'dimensions' => [
                ['dimension_id' => $quality['id'], 'values' => [['name' => '鲜活', 'code' => 'live']]],
                ['dimension_id' => $size['id'], 'values' => [['name' => '大', 'code' => 'large']]],
            ],
        ]));

        self::assertSame('鲜活', GoodsSpecificationLogic::qualityList(['goods_id' => $goodsId])[0]['name']);
        self::assertSame('大', GoodsSpecificationLogic::specificationList(['goods_id' => $goodsId])[0]['name']);
    }

    public function test_goods_creation_persists_required_dimensions_and_skus_atomically(): void
    {
        $quality = GoodsDimensionLogic::saveDefinition(['name' => '品质', 'code' => 'quality_status', 'dimension_type' => 'sku']);
        $unitId = $this->createCustomerReportUnit('斤');

        $result = GoodsLogic::add([
            'name' => '原子创建鲍鱼',
            'category_id' => 0,
            'units' => '斤',
            'units_id' => $unitId,
            'dimensions' => [[
                'dimension_id' => $quality['id'],
                'values' => [['name' => '活鲍鱼', 'code' => 'live']],
            ]],
            'combinations' => [['values' => ['quality_status' => 'live']]],
        ]);

        self::assertIsArray($result);
        $goodsId = (int)$result['id'];
        self::assertSame(1, Db::name('goods_sku')->where('tenant_id', self::TENANT_ID)->where('goods_id', $goodsId)->count());
        self::assertSame(1, Db::name('goods_spec_value')->where('tenant_id', self::TENANT_ID)->where('goods_id', $goodsId)->count());
    }

    public function test_goods_creation_with_only_descriptive_dimensions_keeps_one_base_sku(): void
    {
        $description = GoodsDimensionLogic::saveDefinition(['name' => '说明', 'code' => 'note', 'dimension_type' => 'descriptive']);
        $unitId = $this->createCustomerReportUnit('斤');

        $result = GoodsLogic::add([
            'name' => '应回滚商品',
            'category_id' => 0,
            'units' => '斤',
            'units_id' => $unitId,
            'dimensions' => [[
                'dimension_id' => $description['id'],
                'values' => [['name' => '仅描述', 'code' => 'description_only']],
            ]],
            'combinations' => [],
        ]);
        self::assertIsArray($result, GoodsLogic::getError());
        $goodsId = (int)$result['id'];
        $config = GoodsDimensionLogic::productDimensions(['goods_id' => $goodsId]);
        self::assertCount(1, $config['skus']);
        self::assertSame('SKU-' . $goodsId . '-BASE', $config['skus'][0]['sku_code']);
        self::assertSame('descriptive', $config['dimensions'][0]['dimension_type']);
        self::assertSame('仅描述', $config['dimensions'][0]['values'][0]['name']);
        self::assertSame(0, Db::name('goods_sku_spec_value')->where('goods_id', $goodsId)->count());
    }

    public function test_goods_creation_without_dimensions_creates_the_canonical_base_sku(): void
    {
        $unitId = $this->createCustomerReportUnit('斤');

        $result = GoodsLogic::add([
            'name' => '缺少维度商品',
            'category_id' => 0,
            'units' => '斤',
            'units_id' => $unitId,
        ]);
        self::assertIsArray($result, GoodsLogic::getError());
        $goodsId = (int)$result['id'];
        $config = GoodsDimensionLogic::productDimensions(['goods_id' => $goodsId]);
        self::assertSame([], $config['dimensions']);
        self::assertCount(1, $config['skus']);
        self::assertSame('SKU-' . $goodsId . '-BASE', $config['skus'][0]['sku_code']);
        self::assertSame('缺少维度商品', $config['skus'][0]['sku_name']);
        self::assertSame($unitId, (int)$config['skus'][0]['base_unit_id']);
    }

    public function test_reordering_dimensions_keeps_identity_and_preserves_operationally_disabled_sku(): void
    {
        $origin = GoodsDimensionLogic::saveDefinition(['name' => '产地', 'code' => 'origin', 'dimension_type' => 'sku']);
        $quality = GoodsDimensionLogic::saveDefinition(['name' => '品质', 'code' => 'quality_status', 'dimension_type' => 'sku']);
        $goodsId = $this->createCustomerReportGoods('稳定组合鲍鱼', 'STABLE-COMBO', '斤');

        $first = GoodsDimensionLogic::saveProductDimensions([
            'goods_id' => $goodsId,
            'dimensions' => [
                ['dimension_id' => $origin['id'], 'values' => [['name' => '大连', 'code' => 'dalian']]],
                ['dimension_id' => $quality['id'], 'values' => [['name' => '活鲍鱼', 'code' => 'live']]],
            ],
            'combinations' => [['values' => ['origin' => 'dalian', 'quality_status' => 'live']]],
        ]);
        self::assertIsArray($first);
        $firstSkuId = (int)$first['skus'][0]['id'];
        $valuesByDimension = [];
        foreach ($first['dimensions'] as $dimension) {
            $valuesByDimension[$dimension['code']] = $dimension['values'][0];
        }
        Db::name('goods_sku')->where('id', $firstSkuId)->update([
            'status' => 0,
            'purchase_status' => 0,
            'sale_status' => 0,
            'remark' => '运营停用保留备注',
        ]);

        $second = GoodsDimensionLogic::saveProductDimensions([
            'goods_id' => $goodsId,
            'dimensions' => [
                ['dimension_id' => $quality['id'], 'values' => [$valuesByDimension['quality_status']]],
                ['dimension_id' => $origin['id'], 'values' => [$valuesByDimension['origin']]],
            ],
            'combinations' => [['values' => ['quality_status' => 'live', 'origin' => 'dalian']]],
        ]);

        self::assertIsArray($second);
        $preservedSku = Db::name('goods_sku')->where('id', $firstSkuId)->find();
        self::assertSame($firstSkuId, (int)$preservedSku['id']);
        self::assertSame(0, (int)$preservedSku['status']);
        self::assertSame(0, (int)$preservedSku['purchase_status']);
        self::assertSame(0, (int)$preservedSku['sale_status']);
        self::assertSame(0, (int)$preservedSku['dimension_disabled_snapshot']);
        self::assertSame('运营停用保留备注', $preservedSku['remark']);
        self::assertSame(1, Db::name('goods_sku')->where('tenant_id', self::TENANT_ID)->where('goods_id', $goodsId)->count());
    }

    public function test_removed_dimension_values_are_not_returned_as_active_product_attributes(): void
    {
        $origin = GoodsDimensionLogic::saveDefinition(['name' => '产地', 'code' => 'origin', 'dimension_type' => 'sku']);
        $goodsId = $this->createCustomerReportGoods('选项移除鲍鱼', 'REMOVED-VALUE', '斤');
        $first = GoodsDimensionLogic::saveProductDimensions([
            'goods_id' => $goodsId,
            'dimensions' => [[
                'dimension_id' => $origin['id'],
                'values' => [
                    ['name' => '大连', 'code' => 'dalian'],
                    ['name' => '山东', 'code' => 'shandong'],
                ],
            ]],
        ]);
        $dalian = array_values(array_filter(
            $first['dimensions'][0]['values'],
            static fn(array $value): bool => $value['code'] === 'dalian'
        ))[0];

        self::assertIsArray(GoodsDimensionLogic::saveProductDimensions([
            'goods_id' => $goodsId,
            'dimensions' => [[
                'dimension_id' => $origin['id'],
                'values' => [$dalian],
            ]],
        ]));

        $current = GoodsDimensionLogic::productDimensions(['goods_id' => $goodsId]);
        self::assertSame(['dalian'], array_column($current['dimensions'][0]['values'], 'code'));
        self::assertSame(0, (int)Db::name('goods_spec_value')
            ->where('tenant_id', self::TENANT_ID)
            ->where('goods_id', $goodsId)
            ->where('code', 'shandong')
            ->value('status'));
    }

    public function test_removing_an_unused_generated_sku_also_removes_its_supplier_relation(): void
    {
        $origin = GoodsDimensionLogic::saveDefinition(['name' => '产地', 'code' => 'origin', 'dimension_type' => 'sku']);
        $goodsId = $this->createCustomerReportGoods('清理组合鲍鱼', 'CLEAN-COMBO', '斤');
        $first = GoodsDimensionLogic::saveProductDimensions([
            'goods_id' => $goodsId,
            'dimensions' => [[
                'dimension_id' => $origin['id'],
                'values' => [
                    ['name' => '大连', 'code' => 'dalian'],
                    ['name' => '山东', 'code' => 'shandong'],
                ],
            ]],
        ]);
        $staleSku = array_values(array_filter(
            $first['skus'],
            static fn(array $sku): bool => str_contains($sku['sku_name'], '山东')
        ))[0];
        Db::name('goods_supplier')->insert([
            'tenant_id' => self::TENANT_ID,
            'goods_id' => $goodsId,
            'sku_id' => (int)$staleSku['id'],
            'supplier_id' => 900001,
            'create_time' => time(),
            'update_time' => time(),
        ]);
        $dalian = array_values(array_filter(
            $first['dimensions'][0]['values'],
            static fn(array $value): bool => $value['code'] === 'dalian'
        ))[0];

        self::assertIsArray(GoodsDimensionLogic::saveProductDimensions([
            'goods_id' => $goodsId,
            'dimensions' => [[
                'dimension_id' => $origin['id'],
                'values' => [$dalian],
            ]],
        ]));
        self::assertSame(0, Db::name('goods_supplier')->where('tenant_id', self::TENANT_ID)->where('sku_id', (int)$staleSku['id'])->count());
        self::assertSame(0, Db::name('goods_sku')->where('tenant_id', self::TENANT_ID)->where('id', (int)$staleSku['id'])->count());
    }

    public function test_product_dimension_combination_limit_rejects_exponential_expansion(): void
    {
        $origin = GoodsDimensionLogic::saveDefinition(['name' => '产地', 'code' => 'origin', 'dimension_type' => 'sku']);
        $size = GoodsDimensionLogic::saveDefinition(['name' => '规格', 'code' => 'weight_grade', 'dimension_type' => 'sku']);
        $goodsId = $this->createCustomerReportGoods('超限组合商品', 'TOO-MANY-COMBOS', '斤');
        $values = static function (string $prefix): array {
            $rows = [];
            for ($index = 1; $index <= 23; $index++) {
                $rows[] = ['name' => $prefix . $index, 'code' => strtolower($prefix) . '_' . $index];
            }
            return $rows;
        };

        self::assertFalse(GoodsDimensionLogic::saveProductDimensions([
            'goods_id' => $goodsId,
            'dimensions' => [
                ['dimension_id' => $origin['id'], 'values' => $values('O')],
                ['dimension_id' => $size['id'], 'values' => $values('S')],
            ],
        ]));
        self::assertStringContainsString('500', GoodsDimensionLogic::getError());
        self::assertSame(0, Db::name('goods_spec_value')->where('tenant_id', self::TENANT_ID)->where('goods_id', $goodsId)->count());
    }

    public function test_requested_combination_payload_limit_rejects_duplicate_input_before_expansion(): void
    {
        $origin = GoodsDimensionLogic::saveDefinition(['name' => '产地', 'code' => 'origin', 'dimension_type' => 'sku']);
        $goodsId = $this->createCustomerReportGoods('重复组合鲍鱼', 'DUPLICATE-COMBOS', '斤');
        $requested = array_fill(0, GoodsDimensionLogic::MAX_SKU_COMBINATIONS + 1, [
            'values' => ['origin' => 'dalian'],
        ]);

        self::assertFalse(GoodsDimensionLogic::saveProductDimensions([
            'goods_id' => $goodsId,
            'dimensions' => [[
                'dimension_id' => $origin['id'],
                'values' => [['name' => '大连', 'code' => 'dalian']],
            ]],
            'combinations' => $requested,
        ]));
        self::assertStringContainsString('500', GoodsDimensionLogic::getError());
        self::assertSame(0, Db::name('goods_spec_value')->where('tenant_id', self::TENANT_ID)->where('goods_id', $goodsId)->count());
    }

    public function test_generated_sku_name_limit_returns_a_business_error_and_rolls_back(): void
    {
        $goodsId = $this->createCustomerReportGoods(str_repeat('品', 20), 'LONG-SKU-NAME', '斤');
        $before = GoodsDimensionLogic::productDimensions(['goods_id' => $goodsId]);
        $dimensions = [];
        for ($index = 1; $index <= GoodsDimensionLogic::MAX_PRODUCT_DIMENSIONS; $index++) {
            $definition = GoodsDimensionLogic::saveDefinition([
                'name' => '维度' . $index,
                'code' => 'dimension_' . $index,
                'dimension_type' => 'sku',
            ]);
            $dimensions[] = [
                'dimension_id' => $definition['id'],
                'values' => [['name' => str_repeat('项', 20), 'code' => 'value_' . $index]],
            ];
        }

        self::assertFalse(GoodsDimensionLogic::saveProductDimensions([
            'goods_id' => $goodsId,
            'dimensions' => $dimensions,
        ]));
        self::assertStringContainsString('SKU名称不能超过200个字符', GoodsDimensionLogic::getError());
        self::assertSame(0, Db::name('goods_spec_value')->where('tenant_id', self::TENANT_ID)->where('goods_id', $goodsId)->count());
        self::assertSame($before, GoodsDimensionLogic::productDimensions(['goods_id' => $goodsId]));
    }

    public function test_order_goods_direct_sku_reference_keeps_removed_generated_sku_disabled(): void
    {
        $origin = GoodsDimensionLogic::saveDefinition(['name' => '产地', 'code' => 'origin', 'dimension_type' => 'sku']);
        $goodsId = $this->createCustomerReportGoods('订单引用组合鲍鱼', 'ORDER-SKU-REFERENCE', '斤');
        $config = GoodsDimensionLogic::saveProductDimensions([
            'goods_id' => $goodsId,
            'dimensions' => [[
                'dimension_id' => $origin['id'],
                'values' => [
                    ['name' => '大连', 'code' => 'dalian'],
                    ['name' => '山东', 'code' => 'shandong'],
                ],
            ]],
        ]);
        $staleSku = array_values(array_filter(
            $config['skus'],
            static fn(array $sku): bool => str_contains($sku['sku_name'], '山东')
        ))[0];
        Db::name('order_goods')->insert([
            'tenant_id' => self::TENANT_ID,
            'goods_id' => $goodsId,
            'sku_id' => (int)$staleSku['id'],
        ]);
        $dalian = array_values(array_filter(
            $config['dimensions'][0]['values'],
            static fn(array $value): bool => $value['code'] === 'dalian'
        ))[0];

        self::assertIsArray(GoodsDimensionLogic::saveProductDimensions([
            'goods_id' => $goodsId,
            'dimensions' => [['dimension_id' => $origin['id'], 'values' => [$dalian]]],
        ]));
        $preserved = Db::name('goods_sku')->where('id', (int)$staleSku['id'])->find();
        self::assertNotEmpty($preserved);
        self::assertSame(0, (int)$preserved['status']);
        self::assertSame(0, (int)$preserved['sale_status']);
        self::assertSame(15, (int)$preserved['dimension_disabled_snapshot']);

        self::assertFalse(GoodsSkuLogic::status([
            'id' => (int)$staleSku['id'],
            'status' => 1,
            'purchase_status' => 1,
            'sale_status' => 1,
        ]));
        self::assertStringContainsString('恢复组合', GoodsSkuLogic::getError());
        self::assertFalse(GoodsSkuLogic::save([
            'goods_id' => $goodsId,
            'skus' => [$staleSku],
        ]));
        self::assertStringContainsString('商品维度维护', GoodsSkuLogic::getError());
        self::assertFalse(GoodsSkuLogic::save(['goods_id' => $goodsId, 'skus' => []]));
        self::assertStringContainsString('通用维度', GoodsSkuLogic::getError());
        self::assertSame(1, Db::name('goods_sku')->where('id', (int)$staleSku['id'])->count());

        $customerId = $this->createCustomer('已移除组合报货客户');
        $warehouseId = $this->createCustomerReportWarehouse('已移除组合报货仓');
        $goods = Db::name('goods')->where('id', $goodsId)->find();
        Db::name('goods_sku')->where('id', (int)$staleSku['id'])->update([
            'status' => 1,
            'purchase_status' => 1,
            'sale_status' => 1,
        ]);
        self::assertFalse(CustomerReportLineService::normalizeItems([[
            'goods_id' => $goodsId,
            'sku_id' => (int)$staleSku['id'],
            'warehouse_id' => $warehouseId,
            'delivery_customer_id' => $customerId,
            'unit_id' => (int)$goods['unit_id'],
            'unit_name' => (string)$goods['units'],
            'order_qty' => '1',
            'piece_weight_min' => '1',
            'piece_weight_max' => '1',
            'piece_weight_confirmed' => 1,
            'price_status' => 'unpriced',
        ]], $customerId));
        self::assertStringContainsString('不可销售', CustomerReportLineService::getError());

        self::assertIsArray(GoodsDimensionLogic::saveProductDimensions([
            'goods_id' => $goodsId,
            'dimensions' => [['dimension_id' => $origin['id'], 'values' => [$dalian]]],
        ]), GoodsDimensionLogic::getError());
        $preservedAfterRepeatedSave = Db::name('goods_sku')->where('id', (int)$staleSku['id'])->find();
        self::assertSame(15, (int)$preservedAfterRepeatedSave['dimension_disabled_snapshot']);

        self::assertIsArray(GoodsDimensionLogic::saveProductDimensions([
            'goods_id' => $goodsId,
            'dimensions' => [['dimension_id' => $origin['id'], 'values' => $config['dimensions'][0]['values']]],
        ]), GoodsDimensionLogic::getError());
        $reenabled = Db::name('goods_sku')->where('id', (int)$staleSku['id'])->find();
        self::assertSame(1, (int)$reenabled['status']);
        self::assertSame(1, (int)$reenabled['purchase_status']);
        self::assertSame(1, (int)$reenabled['sale_status']);
        self::assertSame(0, (int)$reenabled['dimension_disabled_snapshot']);
    }

    public function test_order_goods_supplier_relation_reference_keeps_relation_and_removed_sku_disabled(): void
    {
        $origin = GoodsDimensionLogic::saveDefinition(['name' => '产地', 'code' => 'origin', 'dimension_type' => 'sku']);
        $goodsId = $this->createCustomerReportGoods('供应商历史组合鲍鱼', 'SUPPLIER-SKU-REFERENCE', '斤');
        $config = GoodsDimensionLogic::saveProductDimensions([
            'goods_id' => $goodsId,
            'dimensions' => [[
                'dimension_id' => $origin['id'],
                'values' => [
                    ['name' => '大连', 'code' => 'dalian'],
                    ['name' => '山东', 'code' => 'shandong'],
                ],
            ]],
        ]);
        $staleSku = array_values(array_filter(
            $config['skus'],
            static fn(array $sku): bool => str_contains($sku['sku_name'], '山东')
        ))[0];
        $relationId = (int)Db::name('goods_supplier')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'goods_id' => $goodsId,
            'sku_id' => (int)$staleSku['id'],
            'supplier_id' => 900002,
            'create_time' => time(),
            'update_time' => time(),
        ]);
        Db::name('order_goods')->insert([
            'tenant_id' => self::TENANT_ID,
            'goods_id' => $goodsId,
            'supplier_relation_id' => $relationId,
        ]);
        $dalian = array_values(array_filter(
            $config['dimensions'][0]['values'],
            static fn(array $value): bool => $value['code'] === 'dalian'
        ))[0];

        self::assertIsArray(GoodsDimensionLogic::saveProductDimensions([
            'goods_id' => $goodsId,
            'dimensions' => [['dimension_id' => $origin['id'], 'values' => [$dalian]]],
        ]));
        self::assertSame(1, Db::name('goods_supplier')->where('id', $relationId)->count());
        self::assertSame(0, (int)Db::name('goods_sku')->where('id', (int)$staleSku['id'])->value('status'));
    }

    public function test_persistent_business_references_keep_removed_generated_skus_and_supplier_history(): void
    {
        $origin = GoodsDimensionLogic::saveDefinition(['name' => '产地', 'code' => 'origin', 'dimension_type' => 'sku']);
        $goodsId = $this->createCustomerReportGoods('历史引用鲍鱼', 'BUSINESS-REFERENCE', '斤');
        $config = GoodsDimensionLogic::saveProductDimensions([
            'goods_id' => $goodsId,
            'dimensions' => [[
                'dimension_id' => $origin['id'],
                'values' => [
                    ['name' => '大连', 'code' => 'dalian'],
                    ['name' => '山东', 'code' => 'shandong'],
                    ['name' => '南方', 'code' => 'south'],
                    ['name' => '福建', 'code' => 'fujian'],
                ],
            ]],
        ]);
        $skusByOrigin = [];
        foreach ($config['skus'] as $sku) {
            $skusByOrigin[$sku['dimensions'][0]['value_name']] = $sku;
        }
        $relationId = (int)Db::name('goods_supplier')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'goods_id' => $goodsId,
            'sku_id' => (int)$skusByOrigin['山东']['id'],
            'supplier_id' => 900003,
            'create_time' => time(),
            'update_time' => time(),
        ]);
        Db::name('goods_supplier_price_history')->insert([
            'tenant_id' => self::TENANT_ID,
            'goods_supplier_id' => $relationId,
            'goods_id' => $goodsId,
            'sku_id' => (int)$skusByOrigin['山东']['id'],
            'supplier_id' => 900003,
            'purchase_price' => '10.00',
            'create_time' => time(),
        ]);
        Db::name('customer_report_item')->insert([
            'tenant_id' => self::TENANT_ID,
            'goods_id' => $goodsId,
            'sku_id' => (int)$skusByOrigin['南方']['id'],
            'goods_name' => '历史引用鲍鱼',
            'sku_name' => (string)$skusByOrigin['南方']['sku_name'],
        ]);
        Db::name('stock_flow')->insert([
            'tenant_id' => self::TENANT_ID,
            'goods_id' => $goodsId,
            'sku_id' => (int)$skusByOrigin['福建']['id'],
        ]);
        $dalian = array_values(array_filter(
            $config['dimensions'][0]['values'],
            static fn(array $value): bool => $value['code'] === 'dalian'
        ))[0];

        self::assertIsArray(GoodsDimensionLogic::saveProductDimensions([
            'goods_id' => $goodsId,
            'dimensions' => [['dimension_id' => $origin['id'], 'values' => [$dalian]]],
        ]), GoodsDimensionLogic::getError());
        foreach (['山东', '南方', '福建'] as $originName) {
            self::assertSame(0, (int)Db::name('goods_sku')->where('id', (int)$skusByOrigin[$originName]['id'])->value('status'));
        }
        self::assertSame(1, Db::name('goods_supplier')->where('id', $relationId)->count());
        self::assertSame(1, Db::name('goods_supplier_price_history')->where('goods_supplier_id', $relationId)->count());
    }

    public function test_customer_report_line_keeps_the_selected_generic_sku_snapshot(): void
    {
        $origin = GoodsDimensionLogic::saveDefinition(['name' => '产地', 'code' => 'origin', 'dimension_type' => 'sku']);
        $quality = GoodsDimensionLogic::saveDefinition(['name' => '品质', 'code' => 'quality_status', 'dimension_type' => 'sku']);
        $goodsId = $this->createCustomerReportGoods('报货鲍鱼', 'REPORT-ABALONE', '斤');
        $customerId = $this->createCustomer('报货测试客户');
        $warehouseId = $this->createCustomerReportWarehouse('报货测试仓');
        $config = GoodsDimensionLogic::saveProductDimensions([
            'goods_id' => $goodsId,
            'dimensions' => [
                ['dimension_id' => $origin['id'], 'values' => [['name' => '山东', 'code' => 'shandong']]],
                ['dimension_id' => $quality['id'], 'values' => [['name' => '活鲍鱼', 'code' => 'live']]],
            ],
        ]);
        $goods = Db::name('goods')->where('id', $goodsId)->find();
        $sku = $config['skus'][0];
        $groups = $this->fulfillmentPayload($customerId, $goodsId, $warehouseId, 'dimension-snapshot', '1', '测试加工')['items'][0]['processing_groups'];

        $rows = CustomerReportLineService::normalizeItems([[
            'goods_id' => $goodsId,
            'sku_id' => $sku['id'],
            'warehouse_id' => $warehouseId,
            'delivery_customer_id' => $customerId,
            'unit_id' => (int)$goods['unit_id'],
            'unit_name' => (string)$goods['units'],
            'order_qty' => '1',
            'piece_weight_min' => '1',
            'piece_weight_max' => '1',
            'piece_weight_confirmed' => 1,
            'price_status' => 'unpriced',
            'processing_groups' => $groups,
        ]], $customerId);

        self::assertIsArray($rows, CustomerReportLineService::getError());
        self::assertSame((int)$sku['id'], $rows[0]['sku_id']);
        self::assertSame('报货鲍鱼-山东-活鲍鱼', $rows[0]['sku_name']);
        self::assertSame('活鲍鱼', $rows[0]['quality_snapshot']);

        self::assertIsArray(GoodsDimensionLogic::saveDefinition([
            'id' => $quality['id'],
            'name' => '品质',
            'code' => 'quality_status',
            'dimension_type' => 'sku',
            'status' => 0,
        ]));
        self::assertIsArray(CustomerReportLineService::normalizeItems([[
            'goods_id' => $goodsId,
            'sku_id' => $sku['id'],
            'warehouse_id' => $warehouseId,
            'delivery_customer_id' => $customerId,
            'unit_id' => (int)$goods['unit_id'],
            'unit_name' => (string)$goods['units'],
            'order_qty' => '1',
            'piece_weight_min' => '1',
            'piece_weight_max' => '1',
            'piece_weight_confirmed' => 1,
            'price_status' => 'unpriced',
            'processing_groups' => $groups,
        ]], $customerId), CustomerReportLineService::getError());

        $method = new \ReflectionMethod(SalesOrderLogic::class, 'buildGoodsRows');
        $method->setAccessible(true);
        $salesRows = $method->invoke(null, [[
            'goods_id' => $goodsId,
            'sku_id' => $rows[0]['sku_id'],
            'sku_name' => $rows[0]['sku_name'],
            'number' => '1',
            'base_quantity' => '1',
            'price' => '10.00',
        ]]);
        self::assertIsArray($salesRows, SalesOrderLogic::getError());
        self::assertSame((int)$sku['id'], $salesRows[0]['sku_id']);
        self::assertSame((string)$sku['sku_name'], $salesRows[0]['sku_name']);
        $baseGoodsRows = $method->invoke(null, [[
            'goods_id' => $goodsId,
            'sku_id' => 0,
            'sku_name' => '伪造SKU快照',
            'number' => '1',
            'base_quantity' => '1',
            'price' => '10.00',
        ]]);
        self::assertSame((int)$sku['id'], (int)$baseGoodsRows[0]['sku_id']);
        self::assertSame((string)$sku['sku_name'], $baseGoodsRows[0]['sku_name']);

        Db::name('goods_sku')->where('id', (int)$sku['id'])->update(['sale_status' => 0]);
        self::assertFalse(CustomerReportLineService::normalizeItems([[
            'goods_id' => $goodsId,
            'sku_id' => $sku['id'],
            'warehouse_id' => $warehouseId,
            'delivery_customer_id' => $customerId,
            'unit_id' => (int)$goods['unit_id'],
            'unit_name' => (string)$goods['units'],
            'order_qty' => '1',
            'piece_weight_min' => '1',
            'piece_weight_max' => '1',
            'piece_weight_confirmed' => 1,
            'price_status' => 'unpriced',
            'processing_groups' => $groups,
        ]], $customerId));
        self::assertStringContainsString('不可销售', CustomerReportLineService::getError());

        self::assertFalse(CustomerReportLineService::normalizeItems([[
            'goods_id' => $goodsId,
            'sku_id' => 0,
            'warehouse_id' => $warehouseId,
            'delivery_customer_id' => $customerId,
            'unit_id' => (int)$goods['unit_id'],
            'unit_name' => (string)$goods['units'],
            'order_qty' => '1',
            'piece_weight_min' => '1',
            'piece_weight_max' => '1',
            'piece_weight_confirmed' => 1,
            'price_status' => 'unpriced',
            'processing_groups' => $groups,
        ]], $customerId));
        self::assertStringContainsString('商品尚未配置可销售SKU', CustomerReportLineService::getError());
    }

    public function test_legacy_sku_save_keeps_quality_values_visible_for_the_same_goods(): void
    {
        $genericQuality = GoodsDimensionLogic::saveDefinition([
            'name' => '品质',
            'code' => 'quality_status',
            'dimension_type' => 'sku',
        ]);
        $goodsId = $this->createCustomerReportGoods('旧接口商品', 'LEGACY-SKU', '斤');

        self::assertIsArray(GoodsSkuLogic::save([
            'goods_id' => $goodsId,
            'skus' => [[
                'sku_name' => '旧接口商品-鲜活',
                'quality_status' => 'live',
                'quality_label' => '鲜活',
                'base_unit_name' => '斤',
            ]],
        ]));

        $qualities = GoodsSpecificationLogic::qualityList(['goods_id' => $goodsId]);
        self::assertCount(1, $qualities);
        self::assertSame('鲜活', $qualities[0]['name']);
        self::assertSame(1, Db::name('goods_spec')->where('tenant_id', self::TENANT_ID)->where('code', 'quality_status')->count());
        self::assertSame((int)$genericQuality['id'], (int)Db::name('goods_sku_spec_value')->where('tenant_id', self::TENANT_ID)->where('goods_id', $goodsId)->value('spec_id'));
    }

    public function test_legacy_sku_save_cannot_delete_a_sku_referenced_by_stock_history(): void
    {
        $goodsId = $this->createCustomerReportGoods('旧接口库存历史商品', 'LEGACY-STOCK-HISTORY', '斤');
        $saved = GoodsSkuLogic::save([
            'goods_id' => $goodsId,
            'skus' => [[
                'sku_code' => 'LEGACY-HISTORY-MANUAL',
                'sku_name' => '旧接口库存历史商品-鲜活',
                'quality_status' => 'live',
                'quality_label' => '鲜活',
                'base_unit_name' => '斤',
            ]],
        ]);
        self::assertIsArray($saved, GoodsSkuLogic::getError());
        $manualSku = current(array_filter($saved, static fn(array $row): bool => $row['sku_code'] === 'LEGACY-HISTORY-MANUAL'));
        self::assertIsArray($manualSku);
        $skuId = (int)$manualSku['id'];
        Db::name('stock_flow')->insert([
            'tenant_id' => self::TENANT_ID,
            'goods_id' => $goodsId,
            'sku_id' => $skuId,
        ]);

        self::assertFalse(GoodsSkuLogic::save(['goods_id' => $goodsId, 'skus' => []]));
        self::assertStringContainsString('业务单据', GoodsSkuLogic::getError());
        self::assertSame(1, Db::name('goods_sku')->where('id', $skuId)->count());
    }

    public function test_legacy_cartesian_generator_rejects_generic_products_and_foreign_values(): void
    {
        $quality = GoodsDimensionLogic::saveDefinition([
            'name' => '品质', 'code' => 'quality_status', 'dimension_type' => 'sku',
        ]);
        $specification = GoodsDimensionLogic::saveDefinition([
            'name' => '规格', 'code' => 'weight_grade', 'dimension_type' => 'sku',
        ]);
        $genericGoodsId = $this->createCustomerReportGoods('通用维度生成保护商品', 'GENERIC-GENERATE-GUARD', '斤');
        $config = GoodsDimensionLogic::saveProductDimensions([
            'goods_id' => $genericGoodsId,
            'dimensions' => [
                ['dimension_id' => $quality['id'], 'values' => [['name' => '活品', 'code' => 'live']]],
                ['dimension_id' => $specification['id'], 'values' => [['name' => '大号', 'code' => 'large']]],
            ],
        ]);
        self::assertIsArray($config, GoodsDimensionLogic::getError());
        $valueIds = [];
        foreach ($config['dimensions'] as $dimension) {
            $valueIds[$dimension['code']] = (int)$dimension['values'][0]['id'];
        }

        self::assertFalse(GoodsSkuLogic::generateFromCartesian([
            'goods_id' => $genericGoodsId,
            'quality_ids' => [$valueIds['quality_status']],
            'specification_ids' => [$valueIds['weight_grade']],
        ]));
        self::assertStringContainsString('通用维度', GoodsSkuLogic::getError());
        self::assertFalse(GoodsSpecificationLogic::saveQualities([
            'goods_id' => $genericGoodsId,
            'qualities' => [['id' => $valueIds['quality_status'], 'name' => '绕过改名', 'code' => 'bypass']],
        ]));
        self::assertStringContainsString('商品维度', GoodsSpecificationLogic::getError());
        self::assertFalse(GoodsSpecificationLogic::saveSpecifications([
            'goods_id' => $genericGoodsId,
            'specifications' => [['id' => $valueIds['weight_grade'], 'name' => '绕过规格', 'code' => 'bypass']],
        ]));
        self::assertStringContainsString('商品维度', GoodsSpecificationLogic::getError());

        $legacyGoodsId = $this->createCustomerReportGoods('旧生成归属保护商品', 'LEGACY-GENERATE-OWNER', '斤');
        self::assertFalse(GoodsSkuLogic::generateFromCartesian([
            'goods_id' => $legacyGoodsId,
            'quality_ids' => [$valueIds['quality_status']],
            'specification_ids' => [$valueIds['weight_grade']],
        ]));
        self::assertStringContainsString('不属于当前商品', GoodsSkuLogic::getError());
    }

    public function test_switching_from_legacy_to_generic_retires_manual_skus_and_blocks_legacy_save(): void
    {
        $goodsId = $this->createCustomerReportGoods('旧转通用维度商品', 'LEGACY-TO-GENERIC', '斤');
        $legacySkus = GoodsSkuLogic::save([
            'goods_id' => $goodsId,
            'skus' => [
                ['sku_name' => '旧转通用维度商品-保留历史', 'sku_code' => 'LEGACY-KEEP', 'quality_label' => '保留历史'],
                ['sku_name' => '旧转通用维度商品-无历史', 'sku_code' => 'LEGACY-DROP', 'quality_label' => '无历史'],
            ],
        ]);
        self::assertIsArray($legacySkus, GoodsSkuLogic::getError());
        $keptLegacyId = (int)$legacySkus[0]['id'];
        $droppedLegacyId = (int)$legacySkus[1]['id'];
        Db::name('stock_flow')->insert([
            'tenant_id' => self::TENANT_ID,
            'goods_id' => $goodsId,
            'sku_id' => $keptLegacyId,
        ]);
        $origin = GoodsDimensionLogic::saveDefinition([
            'name' => '产地', 'code' => 'origin', 'dimension_type' => 'sku',
        ]);

        self::assertIsArray(GoodsDimensionLogic::saveProductDimensions([
            'goods_id' => $goodsId,
            'dimensions' => [[
                'dimension_id' => $origin['id'],
                'values' => [['name' => '大连', 'code' => 'dalian']],
            ]],
        ]), GoodsDimensionLogic::getError());
        self::assertSame('generic', (string)Db::name('goods')->where('id', $goodsId)->value('dimension_mode'));
        $retained = Db::name('goods_sku')->where('id', $keptLegacyId)->find();
        self::assertNotEmpty($retained);
        self::assertSame(0, (int)$retained['status']);
        self::assertSame(15, (int)$retained['dimension_disabled_snapshot']);
        self::assertSame(0, Db::name('goods_sku')->where('id', $droppedLegacyId)->count());
        self::assertSame(1, Db::name('goods_sku')
            ->where('tenant_id', self::TENANT_ID)
            ->where('goods_id', $goodsId)
            ->where('is_auto_generated', 1)
            ->where('status', 1)
            ->count());

        self::assertFalse(GoodsSkuLogic::save([
            'goods_id' => $goodsId,
            'skus' => [['sku_name' => '绕过组合的手工SKU', 'sku_code' => 'GENERIC-BYPASS']],
        ]));
        self::assertStringContainsString('通用维度', GoodsSkuLogic::getError());
    }

    public function test_legacy_cartesian_generator_cannot_delete_referenced_auto_sku(): void
    {
        $quality = GoodsDimensionLogic::saveDefinition([
            'name' => '品质', 'code' => 'quality_status', 'dimension_type' => 'sku',
        ]);
        $specification = GoodsDimensionLogic::saveDefinition([
            'name' => '规格', 'code' => 'weight_grade', 'dimension_type' => 'sku',
        ]);
        $goodsId = $this->createCustomerReportGoods('旧生成历史保护商品', 'LEGACY-GENERATE-HISTORY', '斤');
        $now = time();
        $qualityValueId = (int)Db::name('goods_spec_value')->insertGetId([
            'tenant_id' => self::TENANT_ID, 'goods_id' => $goodsId, 'spec_id' => (int)$quality['id'],
            'name' => '活品', 'code' => 'live', 'status' => 1, 'sort' => 0,
            'create_time' => $now, 'update_time' => $now,
        ]);
        $smallValueId = (int)Db::name('goods_spec_value')->insertGetId([
            'tenant_id' => self::TENANT_ID, 'goods_id' => $goodsId, 'spec_id' => (int)$specification['id'],
            'name' => '小号', 'code' => 'small', 'status' => 1, 'sort' => 0,
            'create_time' => $now, 'update_time' => $now,
        ]);
        $largeValueId = (int)Db::name('goods_spec_value')->insertGetId([
            'tenant_id' => self::TENANT_ID, 'goods_id' => $goodsId, 'spec_id' => (int)$specification['id'],
            'name' => '大号', 'code' => 'large', 'status' => 1, 'sort' => 1,
            'create_time' => $now, 'update_time' => $now,
        ]);
        $generated = GoodsSkuLogic::generateFromCartesian([
            'goods_id' => $goodsId,
            'quality_ids' => [$qualityValueId],
            'specification_ids' => [$smallValueId, $largeValueId],
        ]);
        self::assertIsArray($generated, GoodsSkuLogic::getError());
        $largeSku = array_values(array_filter(
            $generated,
            static fn(array $sku): bool => $sku['specification_status'] === 'large'
        ))[0];
        Db::name('stock_flow')->insert([
            'tenant_id' => self::TENANT_ID,
            'goods_id' => $goodsId,
            'sku_id' => (int)$largeSku['id'],
        ]);

        self::assertFalse(GoodsSkuLogic::generateFromCartesian([
            'goods_id' => $goodsId,
            'quality_ids' => [$qualityValueId],
            'specification_ids' => [$smallValueId],
        ]));
        self::assertStringContainsString('业务单据', GoodsSkuLogic::getError());
        self::assertSame(2, Db::name('goods_sku')->where('tenant_id', self::TENANT_ID)->where('goods_id', $goodsId)->count());
    }

    public function test_dimension_value_and_goods_renames_refresh_current_sku_display_snapshots(): void
    {
        $origin = GoodsDimensionLogic::saveDefinition(['name' => '产地', 'code' => 'origin', 'dimension_type' => 'sku']);
        $unitId = $this->createCustomerReportUnit('斤');
        $created = GoodsLogic::add([
            'name' => '改名前鲍鱼',
            'category_id' => 0,
            'units' => '斤',
            'units_id' => $unitId,
            'dimensions' => [[
                'dimension_id' => $origin['id'],
                'values' => [['name' => '大连', 'code' => 'dalian']],
            ]],
            'combinations' => [['values' => ['origin' => 'dalian']]],
        ]);
        $goodsId = (int)$created['id'];
        $config = GoodsDimensionLogic::productDimensions(['goods_id' => $goodsId]);
        $valueId = (int)$config['dimensions'][0]['values'][0]['id'];

        $otherGoodsId = $this->createCustomerReportGoods('跨商品维度鲍鱼', 'CROSS-GOODS-DIMENSION', '斤');
        self::assertIsArray(GoodsDimensionLogic::saveProductDimensions([
            'goods_id' => $otherGoodsId,
            'dimensions' => [[
                'dimension_id' => $origin['id'],
                'values' => [['name' => '山东', 'code' => 'shandong']],
            ]],
        ]));
        self::assertIsArray(GoodsDimensionLogic::saveDefinition([
            'id' => $origin['id'],
            'name' => '来源产地',
            'code' => 'origin',
            'dimension_type' => 'sku',
        ]));
        self::assertSame(
            '来源产地',
            GoodsDimensionLogic::productDimensions(['goods_id' => $otherGoodsId])['skus'][0]['dimensions'][0]['dimension_name']
        );
        self::assertIsArray(GoodsDimensionLogic::saveProductDimensions([
            'goods_id' => $goodsId,
            'dimensions' => [[
                'dimension_id' => $origin['id'],
                'values' => [['id' => $valueId, 'name' => '辽宁大连', 'code' => 'dalian']],
            ]],
            'combinations' => [['values' => ['origin' => 'dalian']]],
        ]));
        self::assertTrue(GoodsLogic::edit(['id' => $goodsId, 'name' => '改名后鲍鱼']));

        $updated = GoodsDimensionLogic::productDimensions(['goods_id' => $goodsId]);
        self::assertSame('来源产地', $updated['skus'][0]['dimensions'][0]['dimension_name']);
        self::assertSame('辽宁大连', $updated['skus'][0]['dimensions'][0]['value_name']);
        self::assertSame('改名后鲍鱼-辽宁大连', $updated['skus'][0]['sku_name']);
    }

    private function ensureDimensionSchema(): void
    {
        if (self::$dimensionSchemaReady) {
            return;
        }
        $columns = array_column(Db::query('SHOW COLUMNS FROM `la_goods_sku`'), 'Field');
        if (!in_array('specification_status', $columns, true)) {
            Db::execute("ALTER TABLE `la_goods_sku` ADD COLUMN `specification_status` varchar(50) NOT NULL DEFAULT '' AFTER `quality_label`");
            Db::execute("ALTER TABLE `la_goods_sku` ADD COLUMN `specification_label` varchar(100) NOT NULL DEFAULT '' AFTER `specification_status`");
            Db::execute('ALTER TABLE `la_goods_sku` ADD COLUMN `is_auto_generated` tinyint(1) NOT NULL DEFAULT 0 AFTER `remark`');
        }
        $dimensionColumns = array_column(Db::query('SHOW COLUMNS FROM `la_goods_spec`'), 'Field');
        if (!in_array('dimension_type', $dimensionColumns, true)) {
            Db::execute("ALTER TABLE `la_goods_spec` ADD COLUMN `dimension_type` varchar(20) NOT NULL DEFAULT 'sku' AFTER `code`");
        }
        self::$dimensionSchemaReady = true;
    }
}
