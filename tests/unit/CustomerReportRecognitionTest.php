<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\CustomerReportCandidateLogic;
use app\api\jxc\logic\GoodsDimensionLogic;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

require_once __DIR__ . '/CustomerReportTestSupport.php';

final class CustomerReportRecognitionTest extends TestCase
{
    use CustomerReportTestSupport;

    protected function setUp(): void
    {
        $this->prepareCustomerReportRequestContext();
        $this->ensureCustomerReportTables();
        $this->cleanCustomerReportData();
        $this->createCustomerReportUnit('斤');
        $this->createCustomerReportUnit('条');
    }

    protected function tearDown(): void
    {
        $this->prepareCustomerReportRequestContext();
        $this->cleanCustomerReportData();
    }

    public function test_unique_chinese_prefix_selects_the_existing_goods_without_creating_an_alias(): void
    {
        $goodsId = $this->createCustomerReportGoods('老虎斑', 'TIGER', '斤');
        $line = CustomerReportCandidateLogic::recognize('老虎10条')['lines'][0];

        self::assertSame('unique', $line['goods']['status']);
        self::assertSame($goodsId, $line['goods']['selected']['id']);
        self::assertSame('prefix', $line['goods']['match_type']);
        self::assertSame('10', $line['quantity']['value']);
        self::assertSame('条', $line['quantity']['unit']);
        self::assertSame('unverified', $line['quantity']['status']);
        self::assertSame(0, Db::name('goods_alias')->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_single_character_prefix_requires_uniqueness_across_containing_goods_names(): void
    {
        $customer = $this->createCustomer('鲈');
        $tiger = $this->createCustomerReportGoods('老虎斑', 'TIGER', '条');
        $this->createCustomerReportGoods('炮弹', 'TRIGGERFISH', '斤');
        $bass = $this->createCustomerReportGoods('鲈鱼', 'BASS', '斤');
        $this->createCustomerReportGoods('桂鱼', 'MANDARIN', '条');

        $report = CustomerReportCandidateLogic::recognize("鲈\n鲈110斤\n老虎10条");
        self::assertSame($customer, $report['header']['customer']['selected']['id']);
        [$bassLine, $tigerLine] = $report['lines'];
        self::assertSame($bass, $bassLine['goods']['selected']['id']);
        self::assertSame('prefix', $bassLine['goods']['match_type']);
        self::assertSame('鲈', $bassLine['goods_needle']);
        self::assertSame('110', $bassLine['quantity']['value']);
        self::assertSame('斤', $bassLine['quantity']['unit']);
        self::assertSame($tiger, $tigerLine['goods']['selected']['id']);

        $seaBass = $this->createCustomerReportGoods('海鲈鱼', 'SEA-BASS', '斤');
        $ambiguous = CustomerReportCandidateLogic::recognize('鲈110斤')['lines'][0];
        self::assertSame('ambiguous', $ambiguous['goods']['status']);
        self::assertNull($ambiguous['goods']['selected']);
        self::assertSame([$bass, $seaBass], array_column($ambiguous['goods']['candidates'], 'id'));
        self::assertSame('110', $ambiguous['quantity']['value']);
        self::assertSame('斤', $ambiguous['quantity']['unit']);
    }

    public function test_single_character_recognition_prefers_goods_names_and_aliases_to_an_exact_product_code(): void
    {
        $otherGoods = $this->createCustomerReportGoods('桂鱼', 'MANDARIN', '斤');
        Db::name('goods')->where('tenant_id', self::TENANT_ID)->where('id', $otherGoods)->update(['product_code' => '鲈']);
        $codeOnly = CustomerReportCandidateLogic::recognize('鲈110斤')['lines'][0];
        self::assertSame($otherGoods, $codeOnly['goods']['selected']['id']);

        $seaBass = $this->createCustomerReportGoods('海鲈鱼', 'SEA-BASS', '斤');
        $manual = CustomerReportCandidateLogic::recognize('鲈110斤')['lines'][0];
        self::assertNull($manual['goods']['selected']);
        self::assertSame([$seaBass], array_column($manual['goods']['candidates'], 'id'));

        $this->createCustomerReportAlias($seaBass, '鲈鱼');
        $prefix = CustomerReportCandidateLogic::recognize('鲈110斤')['lines'][0];
        self::assertSame($seaBass, $prefix['goods']['selected']['id']);
        self::assertSame('prefix', $prefix['goods']['match_type']);

        $literalName = $this->createCustomerReportGoods('鲈', 'LITERAL-BASS', '斤');
        $canonical = CustomerReportCandidateLogic::recognize('鲈110斤')['lines'][0];
        self::assertSame($literalName, $canonical['goods']['selected']['id']);
        self::assertSame('canonical', $canonical['goods']['match_type']);
        Db::name('goods')->where('tenant_id', self::TENANT_ID)->where('id', $literalName)->update(['is_disabled' => 1]);

        $this->createCustomerReportAlias($seaBass, '鲈');
        $exact = CustomerReportCandidateLogic::recognize('鲈110斤')['lines'][0];
        self::assertSame($seaBass, $exact['goods']['selected']['id']);
        self::assertSame('alias', $exact['goods']['match_type']);
    }

    public function test_specification_words_inherit_header_customer_and_choose_the_real_sale_sku(): void
    {
        $customerId = $this->createCustomer('独立客户1');
        $goodsId = $this->createCustomerReportGoods('鲈鱼', 'BASS', '斤');
        $config = $this->configureDimensions($goodsId, ['保鲜状态' => ['鲜活', '冰鲜', '冷冻']]);
        $line = CustomerReportCandidateLogic::recognize("独立客户\n活鲈鱼100斤")['lines'][0];

        self::assertSame($customerId, $line['customer']['selected']['id']);
        self::assertSame('unique', $line['attributes']['specification']['status']);
        self::assertSame(['活'], $line['attributes']['specification']['matched_terms']);
        $live = array_values(array_filter($config['skus'], static fn(array $sku): bool => ($sku['dimensions'][0]['value_name'] ?? '') === '鲜活'))[0];
        self::assertSame($live['id'], $line['sku_id']);
        self::assertSame('100', $line['quantity']['value']);
        self::assertSame([], $line['attributes']['processing']);
    }

    public function test_customer_names_containing_live_word_and_explicit_unknown_customers_are_not_rewritten(): void
    {
        $header = $this->createCustomer('独立客户');
        $shop = $this->createCustomer('活力商行');
        $goods = $this->createCustomerReportGoods('鲈鱼', 'CUSTOMER-LIVE', '斤');
        $this->configureDimensions($goods, ['保鲜状态' => ['鲜活', '冰鲜']]);
        $line = CustomerReportCandidateLogic::recognize("独立客户\n活鲈鱼10斤")['lines'][0];
        self::assertSame($header, $line['customer']['selected']['id']);
        $shopLine = CustomerReportCandidateLogic::recognize("独立客户\n活力商行 鲈鱼10斤")['lines'][0];
        self::assertSame($shop, $shopLine['customer']['selected']['id']);
        self::assertSame('missing', $shopLine['attributes']['specification']['status']);
        $unknown = CustomerReportCandidateLogic::recognize("独立客户\n客户：不存在 活鲈鱼10斤")['lines'][0];
        self::assertSame('none', $unknown['customer']['status']);
        self::assertNull($unknown['customer']['selected']);
    }

    public function test_partial_specification_has_candidates_and_unavailable_requirement_never_chooses_another_sku(): void
    {
        $goods = $this->createCustomerReportGoods('鲈鱼', 'MULTI-SKU', '斤');
        $config = $this->configureDimensions($goods, ['保鲜状态' => ['鲜活', '冰鲜'], '大小' => ['大号', '小号']]);
        $line = CustomerReportCandidateLogic::recognize('活鲈鱼10斤')['lines'][0];
        self::assertSame('ambiguous', $line['attributes']['specification']['status']);
        self::assertCount(2, $line['attributes']['specification']['candidates']);
        self::assertSame(0, $line['sku_id']);
        $complete = CustomerReportCandidateLogic::recognize('大号鲜活鲈鱼10斤')['lines'][0];
        self::assertSame('unique', $complete['attributes']['specification']['status']);
        Db::name('goods_sku')->where('tenant_id', self::TENANT_ID)->where('goods_id', $goods)
            ->whereLike('sku_name', '%冰鲜%')->update(['sale_status' => 0]);
        $unavailable = CustomerReportCandidateLogic::recognize('冰鲜鲈鱼10斤')['lines'][0];
        self::assertSame('none', $unavailable['attributes']['specification']['status']);
        self::assertSame(['冰鲜'], $unavailable['attributes']['specification']['matched_terms']);
        self::assertSame(0, $unavailable['sku_id']);
    }

    public function test_specification_after_goods_is_recognized(): void
    {
        $goods = $this->createCustomerReportGoods('桂鱼', 'AFTER-SKU', '斤');
        $this->configureDimensions($goods, ['保鲜状态' => ['鲜活', '冰鲜']]);
        $line = CustomerReportCandidateLogic::recognize('桂鱼冰鲜10斤')['lines'][0];
        self::assertSame($goods, $line['goods']['selected']['id']);
        self::assertSame('unique', $line['attributes']['specification']['status']);
        self::assertSame(['冰鲜'], $line['attributes']['specification']['matched_terms']);
    }

    public function test_unconfigured_or_disabled_specification_cannot_fall_back_to_the_only_live_sku(): void
    {
        $goods = $this->createCustomerReportGoods('鲈鱼', 'ONLY-LIVE', '斤');
        $config = $this->configureDimensions($goods, ['保鲜状态' => ['鲜活']]);
        $unconfigured = CustomerReportCandidateLogic::recognize('冰鲜鲈鱼10斤')['lines'][0];
        self::assertSame('none', $unconfigured['attributes']['specification']['status']);
        self::assertSame(['冰鲜'], $unconfigured['attributes']['specification']['matched_terms']);
        self::assertSame(0, $unconfigured['sku_id']);
        $definitionId = $config['dimensions'][0]['id'];
        GoodsDimensionLogic::saveDefinition(['id' => $definitionId, 'name' => '保鲜状态', 'status' => 0]);
        $disabled = CustomerReportCandidateLogic::recognize('活鲈鱼10斤')['lines'][0];
        self::assertSame('none', $disabled['attributes']['specification']['status']);
        self::assertSame(0, $disabled['sku_id']);
    }

    public function test_descriptive_value_is_not_used_as_a_stock_specification(): void
    {
        $goods = $this->createCustomerReportGoods('鲈鱼', 'DESCRIPTION', '斤');
        $definition = GoodsDimensionLogic::saveDefinition(['name' => '来源说明', 'dimension_type' => 'descriptive']);
        $config = GoodsDimensionLogic::saveProductDimensions(['goods_id' => $goods, 'dimensions' => [[
            'dimension_id' => $definition['id'], 'usage_mode' => 'descriptive', 'values' => [['name' => '精选']]]]]);
        self::assertIsArray($config, GoodsDimensionLogic::getError());
        $line = CustomerReportCandidateLogic::recognize('精选鲈鱼10斤')['lines'][0];
        self::assertSame('missing', $line['attributes']['specification']['status']);
        self::assertSame(0, $line['sku_id']);
    }

    public function test_prefix_ambiguity_exact_alias_precedence_and_single_character_candidates(): void
    {
        $first = $this->createCustomerReportGoods('老虎斑', 'TIGER1', '斤');
        $second = $this->createCustomerReportGoods('老虎鱼', 'TIGER2', '斤');
        $line = CustomerReportCandidateLogic::recognize('老虎10条')['lines'][0];
        self::assertSame('ambiguous', $line['goods']['status']);
        self::assertNull($line['goods']['selected']);
        self::assertCount(2, $line['goods']['candidates']);
        self::assertSame('needs_confirmation', $line['status']);
        Db::name('goods_alias')->insert(['tenant_id' => self::TENANT_ID, 'goods_id' => $first,
            'alias' => '老虎', 'normalized_alias' => '老虎', 'source' => 'tenant', 'create_time' => time(), 'update_time' => time()]);
        $exact = CustomerReportCandidateLogic::recognize('老虎10条')['lines'][0];
        self::assertSame($first, $exact['goods']['selected']['id']);
        self::assertSame('alias', $exact['goods']['match_type']);
        $single = CustomerReportCandidateLogic::recognize('虎10条')['lines'][0];
        self::assertSame('ambiguous', $single['goods']['status']);
        self::assertNull($single['goods']['selected']);
    }

    public function test_explicit_quantity_survives_unknown_goods_but_does_not_invent_a_bound_unit(): void
    {
        $unknown = CustomerReportCandidateLogic::recognize('神秘鱼10条')['lines'][0];
        self::assertSame('unverified', $unknown['quantity']['status']);
        self::assertSame('10', $unknown['quantity']['value']);
        $goods = $this->createCustomerReportGoods('老虎斑', 'BOUND-TIGER', '斤');
        $unitId = (int)Db::name('goods_unit')->where('tenant_id', self::TENANT_ID)->where('name', '条')->value('id');
        Db::name('goods_units_binding')->insert(['tenant_id' => self::TENANT_ID, 'goods_id' => $goods,
            'unit_id' => $unitId, 'unit_name' => '条', 'status' => 1]);
        $bound = CustomerReportCandidateLogic::recognize('老虎10条')['lines'][0];
        self::assertSame('unique', $bound['quantity']['status']);
        self::assertSame($unitId, $bound['quantity']['unit_id']);
        foreach (['老虎约10条', '老虎10至12条', '老虎每条2斤10条'] as $text) {
            self::assertSame('missing', CustomerReportCandidateLogic::recognize($text)['lines'][0]['quantity']['status']);
        }
    }

    private function configureDimensions(int $goodsId, array $values): array
    {
        $dimensions = [];
        foreach ($values as $name => $options) {
            $definition = GoodsDimensionLogic::saveDefinition(['name' => $name, 'dimension_type' => 'sku']);
            self::assertIsArray($definition, GoodsDimensionLogic::getError());
            $dimensions[] = ['dimension_id' => $definition['id'], 'usage_mode' => 'sku',
                'values' => array_map(static fn(string $value): array => ['name' => $value], $options)];
        }
        $config = GoodsDimensionLogic::saveProductDimensions(['goods_id' => $goodsId, 'dimensions' => $dimensions]);
        self::assertIsArray($config, GoodsDimensionLogic::getError());
        return GoodsDimensionLogic::productDimensions(['goods_id' => $goodsId]);
    }
}
