<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\CustomerReportCandidateLogic;
use app\api\jxc\logic\CustomerReportLogic;
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

    public function test_customer_sections_assign_goods_to_each_receiver_without_creating_goods_for_titles(): void
    {
        $main = $this->createCustomer('主客户1');
        $first = $this->createCustomer('子客户1', $main);
        $second = $this->createCustomer('子客户2', $main);
        $this->createCustomerReportGoods('桂鱼', 'SECTIONS-MANDARIN', '条');
        $this->createCustomerReportGoods('鲈鱼', 'SECTIONS-BASS', '条');

        $report = CustomerReportCandidateLogic::recognize("主客户1\n桂鱼100条\n子客户1\n鲈鱼100条\n客户：子客户2 桂鱼3条\n鲈鱼4条\n子客户2\n桂鱼20条");
        self::assertSame(['桂鱼100条', '鲈鱼100条', '客户：子客户2 桂鱼3条', '鲈鱼4条', '桂鱼20条'], array_column($report['lines'], 'source_text'));
        self::assertSame([2, 6], array_column($report['segments'], 'id'));
        self::assertSame(['customer', 'customer'], array_column($report['segments'], 'kind'));
        self::assertSame([1, 3, 4, 5, 7], array_column($report['lines'], 'source_index'));
        self::assertSame([$main, $first, $second, $first, $second], array_map(static fn(array $row) => $row['customer']['selected']['id'], $report['lines']));
        self::assertSame([null, 2, null, 2, 6], array_map(static fn(array $row) => $row['segment_id'] ?? null, $report['lines']));
    }

    public function test_unknown_labels_and_customer_goods_collisions_preserve_both_interpretations(): void
    {
        $main = $this->createCustomer('总店');
        $first = $this->createCustomer('南门', $main);
        $other = $this->createCustomer('桂鱼', $main);
        $goods = $this->createCustomerReportGoods('桂鱼', 'OVERLAP', '条');
        $this->createCustomerReportGoods('鲈鱼', 'BASS', '条');
        $report = CustomerReportCandidateLogic::recognize("总店\n南门\n桂鱼2条\n桂鱼\n鲈鱼5条\n未建名称\n鲈鱼7条");
        self::assertSame(['桂鱼2条', '鲈鱼5条', '鲈鱼7条'], array_column($report['lines'], 'source_text'));
        [$known, $collision, $unknown] = $report['segments'];
        self::assertSame(['customer', 'undecided', 'undecided'], array_column($report['segments'], 'kind'));
        self::assertSame([$other], array_column($collision['customer']['candidates'], 'id'));
        self::assertSame($goods, $collision['product_line']['goods']['selected']['id']);
        self::assertSame($first, $collision['product_line']['customer']['selected']['id']);
        self::assertSame($known['id'], $collision['product_line']['segment_id']);
        self::assertSame('未建名称', $unknown['product_line']['source_text']);
        self::assertSame('none', $unknown['product_line']['goods']['status']);
        self::assertSame($collision['id'], $unknown['product_line']['segment_id']);
        self::assertSame([1, 3, 5], array_column($report['lines'], 'segment_id'));
        self::assertNull($report['lines'][1]['customer']['selected']);
        self::assertNull($report['lines'][2]['customer']['selected']);
    }

    public function test_recognized_main_and_child_receivers_submit_as_three_reports(): void
    {
        $main = $this->createCustomer('主客户1');
        $east = $this->createCustomer('东门', $main);
        $west = $this->createCustomer('西门', $main);
        $warehouse = $this->createCustomerReportWarehouse('分组测试仓');
        $unit = (int)Db::name('goods_unit')->where('tenant_id', self::TENANT_ID)->where('name', '条')->value('id');
        foreach (['桂鱼', '鲈鱼', '鲫鱼'] as $index => $name) {
            $goods = $this->createCustomerReportGoods($name, 'SECTION-SUBMIT-' . $index, '条');
            Db::name('goods')->where('id', $goods)->update(['unit_id' => $unit]);
            Db::name('goods_sku')->where('id', $this->customerReportSkuId($goods))->update(['base_unit_id' => $unit]);
            Db::name('goods_units_binding')->insert([
                'tenant_id' => self::TENANT_ID, 'goods_id' => $goods, 'unit_id' => $unit,
                'unit_name' => '条', 'is_base_unit' => 1, 'status' => 1,
            ]);
        }
        $recognition = CustomerReportCandidateLogic::recognize("主客户1\n桂鱼100条\n东门\n鲈鱼100条\n西门\n鲫鱼50条");
        self::assertCount(3, $recognition['lines']);

        $groups = [];
        foreach ($recognition['lines'] as $index => $line) {
            $goods = (int)$line['goods']['selected']['id'];
            $group = $this->fulfillmentPayload($main, $goods, $warehouse, 'section-submit-' . $index, $line['quantity']['value'], '');
            $group['quantity_contract_version'] = 2;
            $group['items'][0]['sku_id'] = $this->customerReportSkuId($goods);
            $group['items'][0]['delivery_customer_id'] = $line['customer']['selected']['id'];
            $group['items'][0]['unit_id'] = $unit;
            $group['items'][0]['unit_name'] = '条';
            $groups[] = $group;
        }
        $submitted = CustomerReportLogic::submitGrouped(['idempotency_key' => 'section-submit', 'groups' => $groups]);
        self::assertNotFalse($submitted, CustomerReportLogic::getError());
        self::assertSame([$main, $east, $west], array_map(
            static fn(array $report): int => (int)$report['items'][0]['delivery_customer_id'],
            $submitted['reports']
        ));
        self::assertSame(['桂鱼', '鲈鱼', '鲫鱼'], array_map(
            static fn(array $report): string => $report['items'][0]['goods_name'],
            $submitted['reports']
        ));
    }

    public function test_customer_section_scope_excludes_unrelated_and_disabled_receivers_and_keeps_ambiguous_names_pending(): void
    {
        $main = $this->createCustomer('主客户1');
        $first = $this->createCustomer('子客户1', $main);
        $second = $this->createCustomer('子客户2', $main);
        $disabled = $this->createCustomer('子客户3', $main);
        Db::name('customer')->where('id', $disabled)->update(['is_disabled' => 1]);
        $unrelated = $this->createCustomer('另一个主客户');
        $this->createCustomer('子客户1', $unrelated);
        $this->createCustomer('子客户4', $first);
        $this->createCustomerReportGoods('桂鱼', 'SCOPED-MANDARIN', '条');
        $this->createCustomerReportGoods('鲈鱼', 'SCOPED-BASS', '条');

        $report = CustomerReportCandidateLogic::recognize("主客户1\n桂鱼100条\n子客户\n鲈鱼100条\n子客户1\n鲈鱼\n主客户1\n桂鱼3条");
        [$ambiguous, $exact, $backToMain] = $report['segments'];
        self::assertSame('customer', $ambiguous['kind']);
        self::assertSame('ambiguous', $ambiguous['customer']['status']);
        self::assertSame([$first, $second], array_column($ambiguous['customer']['candidates'], 'id'));
        self::assertNull($report['lines'][1]['customer']['selected']);
        self::assertSame($first, $exact['customer']['selected']['id']);
        self::assertSame($main, $backToMain['customer']['selected']['id']);
        self::assertSame('鲈鱼', $report['lines'][2]['goods']['selected']['name']);
        self::assertSame('missing', $report['lines'][2]['quantity']['status']);
        $outOfScope = CustomerReportCandidateLogic::recognize("主客户1\n桂鱼100条\n另一个主客户\n鲈鱼100条\n子客户3\n桂鱼1条\n子客户4\n鲈鱼1条");
        self::assertSame(['none', 'none', 'none'], array_map(static fn(array $segment) => $segment['customer']['status'], $outOfScope['segments']));
        self::assertSame([$main, null, null, null], array_map(static fn(array $line) => $line['customer']['selected']['id'] ?? null, $outOfScope['lines']));
    }

    public function test_section_before_a_main_is_identified_only_suggests_valid_receivers(): void
    {
        $main = $this->createCustomer('总店');
        $child = $this->createCustomer('一级', $main);
        $this->createCustomer('三级外送点', $child);
        $this->createCustomerReportGoods('鲈鱼', 'NO-HEADER', '条');
        $report = CustomerReportCandidateLogic::recognize("鲈鱼2条\n三级外送点\n鲈鱼3条\n一级\n鲈鱼4条");
        self::assertNull($report['header']);
        self::assertSame('none', $report['segments'][0]['customer']['status']);
        self::assertNull($report['lines'][1]['customer']['selected']);
        self::assertSame($child, $report['lines'][2]['customer']['selected']['id']);
    }

    public function test_unknown_goods_with_a_decimal_quantity_stay_in_the_current_customer_section(): void
    {
        $main = $this->createCustomer('总店');
        $receiver = $this->createCustomer('北门', $main);
        $this->createCustomerReportGoods('鲈鱼', 'DECIMAL', '斤');
        $report = CustomerReportCandidateLogic::recognize("总店\n北门\n未知货0.1234斤\n鲈鱼10斤");
        self::assertSame(['北门'], array_column($report['segments'], 'source_text'));
        self::assertSame('未知货0.1234斤', $report['lines'][0]['source_text']);
        self::assertSame('0.1234', $report['lines'][0]['quantity']['value']);
        self::assertSame($receiver, $report['lines'][0]['customer']['selected']['id']);
    }

    public function test_standalone_exact_customer_name_with_spaces_and_a_customer_marker_remains_a_section(): void
    {
        $main = $this->createCustomer('总店');
        $receiver = $this->createCustomer('客户 广场 分店', $main);
        $this->createCustomerReportGoods('鲈鱼', 'SPACES', '条');
        $report = CustomerReportCandidateLogic::recognize("总店\n鲈鱼2条\n客户 广场 分店\n鲈鱼3条\n客户：不存在 鲈鱼5条");
        self::assertSame(['客户 广场 分店'], array_column($report['segments'], 'source_text'));
        self::assertSame($receiver, $report['lines'][1]['customer']['selected']['id']);
        self::assertNull($report['lines'][2]['customer']['selected']);
        self::assertArrayNotHasKey('segment_id', $report['lines'][2]);
    }

    public function test_punctuation_in_customer_section_names_matches_its_literal_characters(): void
    {
        $main = $this->createCustomer('总店');
        $this->createCustomer('江南A店', $main);
        $literal = $this->createCustomer('东区!_50%门店', $main);
        $this->createCustomerReportGoods('鲈鱼', 'LITERAL', '条');
        $report = CustomerReportCandidateLogic::recognize("总店\n鲈鱼1条\n江南_店\n鲈鱼2条\n江南%店\n鲈鱼3条\n!_50%\n鲈鱼4条");
        self::assertSame([null, null, $literal], array_map(static fn(array $segment) => $segment['customer']['selected']['id'] ?? null, $report['segments']));
        self::assertSame([$main, null, null, $literal], array_map(static fn(array $line) => $line['customer']['selected']['id'] ?? null, $report['lines']));
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

    public function test_customer_section_considers_exact_names_before_limiting_visible_candidates(): void
    {
        $main = $this->createCustomer('总店');
        for ($index = 1; $index <= 20; $index++) {
            $this->createCustomer('分店' . $index, $main);
        }
        $exact = $this->createCustomer('分店', $main);
        $this->createCustomerReportGoods('鲈鱼', 'MANY-CHILDREN', '条');
        $source = "总店\n鲈鱼2条\n分店\n鲈鱼10条";
        $report = CustomerReportCandidateLogic::recognize($source);
        self::assertSame($exact, $report['segments'][0]['customer']['selected']['id'] ?? null);
        self::assertSame($exact, $report['lines'][1]['customer']['selected']['id']);

        $anotherExact = $this->createCustomer('分店', $main);
        $report = CustomerReportCandidateLogic::recognize($source);
        $candidates = $report['segments'][0]['customer'];
        self::assertSame('ambiguous', $candidates['status']);
        self::assertNull($report['lines'][1]['customer']['selected']);
        self::assertContains($exact, array_column($candidates['candidates'], 'id'));
        self::assertContains($anotherExact, array_column($candidates['candidates'], 'id'));
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
        $shop = $this->createCustomer('活力商行', $header);
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

    public function test_inline_customer_uses_the_declared_main_and_cannot_override_the_following_receiver(): void
    {
        $main = $this->createCustomer('总店');
        $north = $this->createCustomer('北门', $main);
        $south = $this->createCustomer('南门', $main);
        $other = $this->createCustomer('别家总店');
        $this->createCustomer('北门', $other);
        $this->createCustomer('外地分店', $other);
        $this->createCustomerReportGoods('桂鱼', 'INLINE-SCOPE', '条');
        $report = CustomerReportCandidateLogic::recognize("总店\n南门\n客户：北门 桂鱼1条\n客户：外地分店 桂鱼2条\n桂鱼3条");
        self::assertSame([$north, null, $south], array_map(static fn(array $line) => $line['customer']['selected']['id'] ?? null, $report['lines']));
        self::assertSame('none', $report['lines'][1]['customer']['status']);
        self::assertSame([null, null, 1], array_map(static fn(array $line) => $line['segment_id'] ?? null, $report['lines']));
        $noMain = CustomerReportCandidateLogic::recognize('客户：北门 桂鱼1条')['lines'][0];
        self::assertSame('ambiguous', $noMain['customer']['status']);
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
