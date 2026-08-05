<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\CustomerReportCandidateLogic;
use app\api\jxc\logic\CustomerReportLogic;
use app\api\jxc\logic\SalesOrderLogic;
use app\api\jxc\logic\WarehouseGoodsBalanceService;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

require_once __DIR__ . '/CustomerReportTestSupport.php';

final class CustomerReportWorkflowTest extends TestCase
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
        $this->cleanCustomerReportData();
        parent::tearDown();
    }

    public function test_submit_recomputes_count_weight_partially_reserves_and_is_idempotent(): void
    {
        $customerId = $this->createCustomer('客户甲');
        $goodsId = $this->createCustomerReportGoods('桂鱼', 'CR-GUIYU');
        $warehouseId = $this->createCustomerReportWarehouse('客户报货仓');
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, '2.0000'));

        $payload = $this->submitPayload($customerId, $goodsId, $warehouseId, 'idem-guiyu', '2', '1.00', '1.50');
        $first = CustomerReportLogic::submit($payload);
        self::assertNotFalse($first, CustomerReportLogic::getError());
        self::assertSame('submitted_shortage', $first['status']);
        self::assertSame('3.00', (string)$first['total_base_qty']);
        self::assertSame('2.00', (string)$first['reserved_base_qty']);
        self::assertSame('1.00', (string)$first['shortage_base_qty']);

        $again = CustomerReportLogic::submit($payload);
        self::assertNotFalse($again, CustomerReportLogic::getError());
        self::assertSame((int)$first['id'], (int)$again['id']);
        self::assertSame(1, Db::name('customer_report')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame('2.0000', WarehouseGoodsBalanceService::reserved($warehouseId, $goodsId));
        self::assertSame('2.0000', WarehouseGoodsBalanceService::onHand($warehouseId, $goodsId));
        self::assertSame(1, CustomerReportLogic::lists([])['count']);
        self::assertSame('0.0000', CustomerReportLogic::availability(['warehouse_id' => $warehouseId, 'goods_id' => $goodsId])['available_base_qty']);

        $different = $payload; $different['items'][0]['piece_weight_max'] = '1.60';
        self::assertFalse(CustomerReportLogic::submit($different));
        self::assertSame('幂等键已用于不同的报货内容', CustomerReportLogic::getError());
    }

    public function test_candidate_only_returns_choices_and_never_creates_a_report_or_reservation(): void
    {
        $customerId = $this->createCustomer('客户甲');
        $childId = $this->createCustomer('客户甲门店', $customerId);
        $goodsId = $this->createCustomerReportGoods('桂鱼', 'CR-CANDIDATE');
        $candidate = CustomerReportCandidateLogic::recognize('客户甲门店 桂鱼 2斤 8头 去鳞');

        self::assertSame('ready', $candidate['lines'][0]['status']);
        self::assertSame($childId, (int)$candidate['lines'][0]['customer']['selected']['id']);
        self::assertSame($customerId, (int)$candidate['lines'][0]['customer']['selected']['main_customer']['id']);
        self::assertSame('客户甲', $candidate['lines'][0]['customer']['selected']['main_customer']['name']);
        self::assertSame($childId, (int)$candidate['lines'][0]['customer']['selected']['delivery_customer']['id']);
        self::assertSame($goodsId, (int)$candidate['lines'][0]['goods']['selected']['id']);
        self::assertSame('8头', $candidate['lines'][0]['attributes']['specification']);
        self::assertSame(0, Db::name('customer_report')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame(0, Db::name('customer_report_reservation')->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_candidate_uses_an_exact_first_line_customer_as_the_report_header(): void
    {
        $customerId = $this->createCustomer('客户1');
        $goodsId = $this->createCustomerReportGoods('桂鱼', 'CR-FIRST-LINE-CUSTOMER');

        $candidate = CustomerReportCandidateLogic::recognize("客户1\n桂鱼20斤2斤1条");

        self::assertCount(1, $candidate['lines']);
        self::assertSame('桂鱼20斤2斤1条', $candidate['lines'][0]['source_text']);
        self::assertSame('ready', $candidate['lines'][0]['status']);
        self::assertSame($customerId, (int)$candidate['lines'][0]['customer']['selected']['id']);
        self::assertSame($goodsId, (int)$candidate['lines'][0]['goods']['selected']['id']);
        self::assertSame('20.00', $candidate['lines'][0]['quantity']['value']);
        self::assertSame('斤', $candidate['lines'][0]['quantity']['unit']);
    }

    public function test_candidate_does_not_override_an_explicit_ambiguous_customer_with_the_header_customer(): void
    {
        $this->createCustomer('客户1');
        $this->createCustomer('客户甲');
        $this->createCustomer('客户乙');
        $this->createCustomerReportGoods('桂鱼', 'CR-AMBIGUOUS-LINE-CUSTOMER');

        $candidate = CustomerReportCandidateLogic::recognize("客户1\n客户：客户 桂鱼20斤");

        self::assertCount(1, $candidate['lines']);
        self::assertSame('ambiguous', $candidate['lines'][0]['customer']['status']);
        self::assertNull($candidate['lines'][0]['customer']['selected']);
    }

    public function test_only_direct_or_first_level_child_can_receive_the_report(): void
    {
        $main = $this->createCustomer('主客户');
        $other = $this->createCustomer('其他主客户');
        $invalidChild = $this->createCustomer('错误子客户', $other);
        $goodsId = $this->createCustomerReportGoods('8头鲍鱼', 'CR-ABALONE');
        $warehouseId = $this->createCustomerReportWarehouse('层级校验仓');
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, '10.0000'));
        $payload = $this->submitPayload($main, $goodsId, $warehouseId, 'hierarchy-reject', '2', '1.00', '1.00');
        $payload['items'][0]['delivery_customer_id'] = $invalidChild;

        self::assertFalse(CustomerReportLogic::submit($payload));
        self::assertSame('配送客户必须是该主客户或其一级子客户', CustomerReportLogic::getError());
        self::assertSame('0.0000', WarehouseGoodsBalanceService::reserved($warehouseId, $goodsId));
    }

    public function test_direct_weight_uses_the_direct_quantity_and_priced_line_requires_an_explicit_pricing_unit(): void
    {
        $customerId = $this->createCustomer('按斤客户');
        $goodsId = $this->createCustomerReportGoods('基围虾', 'CR-DIRECT');
        Db::name('goods')->where('tenant_id', self::TENANT_ID)->where('id', $goodsId)->update(['units' => '斤']);
        $warehouseId = $this->createCustomerReportWarehouse('按斤报货仓');
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, '10.0000'));
        $payload = $this->submitPayload($customerId, $goodsId, $warehouseId, 'direct-weight', '2', '99.00', '99.00');
        $payload['items'][0]['unit_name'] = '斤';
        $payload['items'][0]['piece_weight_confirmed'] = 0;
        $report = CustomerReportLogic::submit($payload);
        self::assertNotFalse($report, CustomerReportLogic::getError());
        self::assertSame('2.00', (string)$report['total_base_qty']);

        $invalid = $payload; $invalid['idempotency_key'] = 'direct-priced'; $invalid['items'][0]['price_status'] = 'priced'; $invalid['items'][0]['price'] = '12.00';
        self::assertFalse(CustomerReportLogic::submit($invalid));
        self::assertSame('确认单价时必须同时填写计价单位', CustomerReportLogic::getError());

        $unconfirmedAttribute = $payload; $unconfirmedAttribute['idempotency_key'] = 'direct-attribute'; $unconfirmedAttribute['items'][0]['specification'] = '8头';
        self::assertFalse(CustomerReportLogic::submit($unconfirmedAttribute));
        self::assertSame('规格必须从当前商品已维护的属性中选择', CustomerReportLogic::getError());
    }

    public function test_attribute_value_must_match_its_quality_or_specification_dimension(): void
    {
        $customerId = $this->createCustomer('属性客户');
        $goodsId = $this->createCustomerReportGoods('属性桂鱼', 'CR-ATTRIBUTE');
        $warehouseId = $this->createCustomerReportWarehouse('属性仓');
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, '2.0000'));
        $templateId = (int)Db::name('goods_spec_template')->insertGetId(['tenant_id' => self::TENANT_ID, 'code' => 'aquatic_quality', 'name' => '水产属性', 'status' => 1, 'create_time' => time(), 'update_time' => time()]);
        $qualityDimension = (int)Db::name('goods_spec')->insertGetId(['tenant_id' => self::TENANT_ID, 'template_id' => $templateId, 'code' => 'quality_status', 'name' => '品质', 'status' => 1, 'create_time' => time(), 'update_time' => time()]);
        $specificationDimension = (int)Db::name('goods_spec')->insertGetId(['tenant_id' => self::TENANT_ID, 'template_id' => $templateId, 'code' => 'weight_grade', 'name' => '规格', 'status' => 1, 'create_time' => time(), 'update_time' => time()]);
        $qualityValue = (int)Db::name('goods_spec_value')->insertGetId(['tenant_id' => self::TENANT_ID, 'goods_id' => $goodsId, 'spec_id' => $qualityDimension, 'name' => '活鲜', 'code' => 'fresh', 'status' => 1, 'create_time' => time(), 'update_time' => time()]);
        $specificationValue = (int)Db::name('goods_spec_value')->insertGetId(['tenant_id' => self::TENANT_ID, 'goods_id' => $goodsId, 'spec_id' => $specificationDimension, 'name' => '8头', 'code' => 'eight', 'status' => 1, 'create_time' => time(), 'update_time' => time()]);
        $payload = $this->submitPayload($customerId, $goodsId, $warehouseId, 'attribute-type', '1', '1.00', '1.00');
        $payload['items'][0]['quality_id'] = $specificationValue;
        self::assertFalse(CustomerReportLogic::submit($payload));
        self::assertSame('品质不属于当前商品或属性类型不匹配', CustomerReportLogic::getError());

        $payload['idempotency_key'] = 'attribute-type-ready';
        $payload['items'][0]['quality_id'] = $qualityValue;
        $payload['items'][0]['spec_id'] = $specificationValue;
        $report = CustomerReportLogic::submit($payload);
        self::assertNotFalse($report, CustomerReportLogic::getError());
        self::assertSame('活鲜', $report['items'][0]['quality_snapshot']);
        self::assertSame('8头', $report['items'][0]['specification_snapshot']);
    }

    public function test_retry_reserves_new_stock_then_cancel_releases_it(): void
    {
        $customerId = $this->createCustomer('重试客户');
        $goodsId = $this->createCustomerReportGoods('黄花鱼', 'CR-RETRY');
        $warehouseId = $this->createCustomerReportWarehouse('补预留仓');
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, '2.0000'));
        $report = CustomerReportLogic::submit($this->submitPayload($customerId, $goodsId, $warehouseId, 'retry-cancel', '2', '1.00', '1.50'));
        self::assertNotFalse($report, CustomerReportLogic::getError());
        self::assertSame('submitted_shortage', $report['status']);
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, '1.0000'));

        $retried = CustomerReportLogic::retry(['id' => $report['id'], 'version' => $report['version']]);
        self::assertNotFalse($retried, CustomerReportLogic::getError());
        self::assertSame('submitted_ready', $retried['status']);
        self::assertSame('3.0000', WarehouseGoodsBalanceService::reserved($warehouseId, $goodsId));

        $cancelled = CustomerReportLogic::cancel(['id' => $retried['id'], 'version' => $retried['version']]);
        self::assertNotFalse($cancelled, CustomerReportLogic::getError());
        self::assertSame('cancelled', $cancelled['status']);
        self::assertSame('0.0000', WarehouseGoodsBalanceService::reserved($warehouseId, $goodsId));
    }

    public function test_unpriced_report_cannot_convert_to_a_sales_order(): void
    {
        $customerId = $this->createCustomer('转销售客户');
        $goodsId = $this->createCustomerReportGoods('转销售桂鱼', 'CR-CONVERT');
        $warehouseId = $this->createCustomerReportWarehouse('转销售仓');
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, '2.0000'));
        $report = CustomerReportLogic::submit($this->submitPayload($customerId, $goodsId, $warehouseId, 'convert-ready', '1', '1.00', '1.00'));
        self::assertNotFalse($report, CustomerReportLogic::getError());
        self::assertSame('submitted_ready', $report['status']);

        self::assertFalse(CustomerReportLogic::convert(['id' => $report['id'], 'version' => $report['version']]));
        self::assertSame('报货单存在未定价明细，不能转销售', CustomerReportLogic::getError());
        self::assertSame(0, Db::name('sales_order')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame('1.0000', WarehouseGoodsBalanceService::reserved($warehouseId, $goodsId));
    }

    public function test_ready_priced_report_converts_to_one_canonical_sales_order_exactly_once(): void
    {
        $customerId = $this->createCustomer('标准销售客户');
        $goodsId = $this->createCustomerReportGoods('标准销售桂鱼', 'CR-CANONICAL');
        Db::name('goods_units_binding')->insert([
            'tenant_id' => self::TENANT_ID, 'goods_id' => $goodsId, 'unit_id' => 1,
            'unit_name' => '件', 'is_base_unit' => 1, 'status' => 1,
            'create_time' => time(), 'update_time' => time(),
        ]);
        $warehouseId = $this->createCustomerReportWarehouse('标准销售仓');
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, '2.0000'));
        $payload = $this->submitPayload($customerId, $goodsId, $warehouseId, 'canonical-sale', '1', '1.00', '1.00');
        $payload['items'][0]['unit_id'] = 1;
        $payload['items'][0]['price_status'] = 'priced';
        $payload['items'][0]['price'] = '12.50';
        $payload['items'][0]['pricing_unit_id'] = 1;
        $payload['items'][0]['pricing_unit_name'] = '件';
        $report = CustomerReportLogic::submit($payload);
        self::assertNotFalse($report, CustomerReportLogic::getError());

        $converted = CustomerReportLogic::convert(['id' => $report['id'], 'version' => $report['version']]);
        self::assertNotFalse($converted, CustomerReportLogic::getError());
        self::assertSame('completed', $converted['status']);
        self::assertCount(1, $converted['sales_orders']);
        $salesOrder = $converted['sales_orders'][0];
        self::assertSame('customer_report', (string)$salesOrder['source_type']);
        self::assertSame((int)$report['id'], (int)$salesOrder['source_id']);
        self::assertSame($warehouseId, (int)$salesOrder['warehouse_id']);
        self::assertSame('12.50', (string)$salesOrder['order_money']);
        self::assertSame(1, Db::name('sales_order')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame(1, Db::name('order_goods')->where('tenant_id', self::TENANT_ID)->where('order_type', 'sales')->count());
        self::assertSame('1.0000', (string)Db::name('order_goods')->where('order_id', (int)$salesOrder['id'])->value('base_quantity'));
        self::assertSame(1, Db::name('stock_flow')->where('tenant_id', self::TENANT_ID)->where('order_type', 'sales')->count());
        self::assertSame(1, Db::name('receivable_flow')->where('tenant_id', self::TENANT_ID)->where('order_type', 'sales')->count());
        self::assertSame(1, Db::name('audit_log')->where('tenant_id', self::TENANT_ID)
            ->where('module', 'sales_order')->where('action', 'create')
            ->where('target_id', (int)$salesOrder['id'])->count());
        self::assertSame('0.0000', WarehouseGoodsBalanceService::reserved($warehouseId, $goodsId));
        self::assertSame('1.0000', WarehouseGoodsBalanceService::onHand($warehouseId, $goodsId));

        $again = CustomerReportLogic::convert(['id' => $report['id'], 'version' => $report['version']]);
        self::assertNotFalse($again, CustomerReportLogic::getError());
        self::assertCount(1, $again['sales_orders']);
        self::assertSame((int)$salesOrder['id'], (int)$again['sales_orders'][0]['id']);
        self::assertSame(1, Db::name('sales_order')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame(1, Db::name('stock_flow')->where('tenant_id', self::TENANT_ID)->where('order_type', 'sales')->count());
        self::assertSame(1, Db::name('receivable_flow')->where('tenant_id', self::TENANT_ID)->where('order_type', 'sales')->count());
        self::assertSame(1, Db::name('audit_log')->where('tenant_id', self::TENANT_ID)
            ->where('module', 'sales_order')->where('action', 'create')
            ->where('target_id', (int)$salesOrder['id'])->count());

        self::assertFalse(SalesOrderLogic::edit(['id' => (int)$salesOrder['id']]));
        self::assertSame('客户报货生成的销售单不可直接编辑，请通过销售退货处理', SalesOrderLogic::getError());
        self::assertFalse(SalesOrderLogic::remove(['id' => (int)$salesOrder['id']]));
        self::assertSame('客户报货生成的销售单不可直接删除，请通过销售退货处理', SalesOrderLogic::getError());
        self::assertSame(1, Db::name('sales_order')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame('completed', (string)Db::name('customer_report')->where('id', (int)$report['id'])->value('status'));
        self::assertSame('0.0000', WarehouseGoodsBalanceService::reserved($warehouseId, $goodsId));
        self::assertSame('1.0000', WarehouseGoodsBalanceService::onHand($warehouseId, $goodsId));
    }

    public function test_priced_report_spanning_two_warehouses_creates_two_sales_orders(): void
    {
        $customerId = $this->createCustomer('多仓销售客户');
        $goodsA = $this->createCustomerReportGoods('多仓商品甲', 'CR-MULTI-A');
        $goodsB = $this->createCustomerReportGoods('多仓商品乙', 'CR-MULTI-B');
        foreach ([$goodsA, $goodsB] as $goodsId) {
            Db::name('goods_units_binding')->insert([
                'tenant_id' => self::TENANT_ID, 'goods_id' => $goodsId, 'unit_id' => 1,
                'unit_name' => '件', 'is_base_unit' => 1, 'status' => 1,
                'create_time' => time(), 'update_time' => time(),
            ]);
        }
        $warehouseA = $this->createCustomerReportWarehouse('多仓甲');
        $warehouseB = $this->createCustomerReportWarehouse('多仓乙');
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseA, $goodsA, '2.0000'));
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseB, $goodsB, '3.0000'));
        $priced = static fn(int $goodsId, int $warehouseId, string $price): array => [
            'goods_id' => $goodsId, 'warehouse_id' => $warehouseId,
            'unit_id' => 1, 'unit_name' => '件', 'order_qty' => '1',
            'piece_weight_confirmed' => 1, 'piece_weight_min' => '1.00', 'piece_weight_max' => '1.00',
            'price_status' => 'priced', 'price' => $price,
            'pricing_unit_id' => 1, 'pricing_unit_name' => '件',
        ];
        $report = CustomerReportLogic::submit([
            'main_customer_id' => $customerId,
            'idempotency_key' => 'multi-warehouse-sale',
            'items' => [
                $priced($goodsA, $warehouseA, '10.00'),
                $priced($goodsB, $warehouseB, '20.00'),
            ],
        ]);
        self::assertNotFalse($report, CustomerReportLogic::getError());

        $converted = CustomerReportLogic::convert(['id' => $report['id'], 'version' => $report['version']]);
        self::assertNotFalse($converted, CustomerReportLogic::getError());
        self::assertCount(2, $converted['sales_orders']);
        self::assertSame([$warehouseA, $warehouseB], array_map(
            static fn(array $order): int => (int)$order['warehouse_id'],
            $converted['sales_orders']
        ));
        self::assertSame(2, Db::name('sales_order')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame(2, Db::name('stock_flow')->where('tenant_id', self::TENANT_ID)->where('order_type', 'sales')->count());
        self::assertSame(2, Db::name('receivable_flow')->where('tenant_id', self::TENANT_ID)->where('order_type', 'sales')->count());
        self::assertSame(2, Db::name('audit_log')->where('tenant_id', self::TENANT_ID)
            ->where('module', 'sales_order')->where('action', 'create')->count());
        self::assertSame('30.00', (string)Db::name('customer')->where('id', $customerId)->value('order_receivable'));
    }

    public function test_pending_list_count_is_filtered_on_the_server_before_pagination(): void
    {
        $customerId = $this->createCustomer('待处理计数客户');
        $goodsId = $this->createCustomerReportGoods('待处理计数商品', 'CR-PENDING-COUNT');
        $warehouseId = $this->createCustomerReportWarehouse('待处理计数仓');
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, '2.0000'));

        foreach (['pending-count-a', 'pending-count-b'] as $key) {
            self::assertNotFalse(CustomerReportLogic::submit(
                $this->submitPayload($customerId, $goodsId, $warehouseId, $key, '1', '1.00', '1.00')
            ), CustomerReportLogic::getError());
        }

        $pending = CustomerReportLogic::lists([
            'status_scope' => 'pending',
            'page_no' => 1,
            'page_size' => 1,
        ]);

        self::assertSame(2, $pending['count']);
        self::assertCount(1, $pending['lists']);
        self::assertContains($pending['lists'][0]['status'], ['submitted_ready', 'submitted_shortage']);
    }

    public function test_second_warehouse_pricing_failure_rolls_back_every_sales_side_effect(): void
    {
        $customerId = $this->createCustomer('多仓回滚客户');
        $goodsA = $this->createCustomerReportGoods('多仓回滚商品甲', 'CR-ROLLBACK-A');
        $goodsB = $this->createCustomerReportGoods('多仓回滚商品乙', 'CR-ROLLBACK-B');
        foreach ([$goodsA, $goodsB] as $goodsId) {
            Db::name('goods_units_binding')->insert([
                'tenant_id' => self::TENANT_ID, 'goods_id' => $goodsId, 'unit_id' => 1,
                'unit_name' => '件', 'is_base_unit' => 1, 'status' => 1,
                'create_time' => time(), 'update_time' => time(),
            ]);
        }
        $warehouseA = $this->createCustomerReportWarehouse('多仓回滚甲');
        $warehouseB = $this->createCustomerReportWarehouse('多仓回滚乙');
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseA, $goodsA, '2.0000'));
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseB, $goodsB, '2.0000'));
        $priced = static fn(int $goodsId, int $warehouseId): array => [
            'goods_id' => $goodsId, 'warehouse_id' => $warehouseId,
            'unit_id' => 1, 'unit_name' => '件', 'order_qty' => '1',
            'piece_weight_confirmed' => 1, 'piece_weight_min' => '1.00', 'piece_weight_max' => '1.00',
            'price_status' => 'priced', 'price' => '10.00',
            'pricing_unit_id' => 1, 'pricing_unit_name' => '件',
        ];
        $report = CustomerReportLogic::submit([
            'main_customer_id' => $customerId,
            'idempotency_key' => 'multi-warehouse-rollback',
            'items' => [
                $priced($goodsA, $warehouseA),
                $priced($goodsB, $warehouseB),
            ],
        ]);
        self::assertNotFalse($report, CustomerReportLogic::getError());

        Db::name('customer_report_item')
            ->where('tenant_id', self::TENANT_ID)
            ->where('report_id', (int)$report['id'])
            ->where('warehouse_id', $warehouseB)
            ->update(['pricing_unit_id' => 999, 'pricing_unit_name' => '箱']);

        self::assertFalse(CustomerReportLogic::convert([
            'id' => $report['id'],
            'version' => $report['version'],
        ]));
        self::assertSame('计价单位暂不支持转换为标准销售单', CustomerReportLogic::getError());
        self::assertSame(0, Db::name('sales_order')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame(0, Db::name('stock_flow')->where('tenant_id', self::TENANT_ID)->where('order_type', 'sales')->count());
        self::assertSame(0, Db::name('receivable_flow')->where('tenant_id', self::TENANT_ID)->where('order_type', 'sales')->count());
        self::assertSame('1.0000', WarehouseGoodsBalanceService::reserved($warehouseA, $goodsA));
        self::assertSame('1.0000', WarehouseGoodsBalanceService::reserved($warehouseB, $goodsB));
        self::assertSame('0.00', (string)Db::name('customer')->where('id', $customerId)->value('order_receivable'));
        self::assertSame('submitted_ready', (string)Db::name('customer_report')->where('id', (int)$report['id'])->value('status'));
    }

    public function test_edit_uses_new_version_and_releases_the_reservation_delta(): void
    {
        $customerId = $this->createCustomer('编辑客户');
        $goodsId = $this->createCustomerReportGoods('海鲈鱼', 'CR-EDIT-DELTA');
        $warehouseId = $this->createCustomerReportWarehouse('编辑仓');
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, '10.0000'));
        $report = CustomerReportLogic::submit($this->submitPayload($customerId, $goodsId, $warehouseId, 'edit-delta', '2', '2.00', '2.00'));
        self::assertNotFalse($report, CustomerReportLogic::getError());
        self::assertSame('4.0000', WarehouseGoodsBalanceService::reserved($warehouseId, $goodsId));

        $item = $report['items'][0];
        $edited = CustomerReportLogic::edit([
            'id' => $report['id'], 'version' => $report['version'], 'main_customer_id' => $customerId, 'remark' => '',
            'items' => [[
                'id' => $item['id'], 'goods_id' => $goodsId, 'warehouse_id' => $warehouseId, 'unit_id' => 0, 'unit_name' => '件',
                'order_qty' => '2', 'piece_weight_confirmed' => 1, 'piece_weight_min' => '1.00', 'piece_weight_max' => '1.00', 'price_status' => 'unpriced',
            ]],
        ]);
        self::assertNotFalse($edited, CustomerReportLogic::getError());
        self::assertSame('2.0000', WarehouseGoodsBalanceService::reserved($warehouseId, $goodsId));
        self::assertSame('10.0000', WarehouseGoodsBalanceService::onHand($warehouseId, $goodsId));
    }

    public function test_edit_can_move_a_line_to_another_warehouse_and_remove_a_line(): void
    {
        $customerId = $this->createCustomer('编辑换仓客户');
        $goodsA = $this->createCustomerReportGoods('编辑商品甲', 'CR-EDIT-A');
        $goodsB = $this->createCustomerReportGoods('编辑商品乙', 'CR-EDIT-B');
        $warehouseA = $this->createCustomerReportWarehouse('编辑原仓');
        $warehouseB = $this->createCustomerReportWarehouse('编辑新仓');
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseA, $goodsA, '2.0000'));
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseA, $goodsB, '2.0000'));
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseB, $goodsA, '2.0000'));
        $report = CustomerReportLogic::submit([
            'main_customer_id' => $customerId, 'idempotency_key' => 'edit-move-delete', 'items' => [
                ['goods_id' => $goodsA, 'warehouse_id' => $warehouseA, 'unit_id' => 0, 'unit_name' => '件', 'order_qty' => '1', 'piece_weight_confirmed' => 1, 'piece_weight_min' => '1.00', 'piece_weight_max' => '1.00', 'price_status' => 'unpriced'],
                ['goods_id' => $goodsB, 'warehouse_id' => $warehouseA, 'unit_id' => 0, 'unit_name' => '件', 'order_qty' => '1', 'piece_weight_confirmed' => 1, 'piece_weight_min' => '1.00', 'piece_weight_max' => '1.00', 'price_status' => 'unpriced'],
            ],
        ]);
        self::assertNotFalse($report, CustomerReportLogic::getError());
        $itemA = array_values(array_filter($report['items'], static fn(array $item): bool => (int)$item['goods_id'] === $goodsA))[0];
        $edited = CustomerReportLogic::edit([
            'id' => $report['id'], 'version' => $report['version'], 'main_customer_id' => $customerId, 'remark' => '',
            'items' => [[
                'id' => $itemA['id'], 'goods_id' => $goodsA, 'warehouse_id' => $warehouseB, 'unit_id' => 0, 'unit_name' => '件',
                'order_qty' => '1', 'piece_weight_confirmed' => 1, 'piece_weight_min' => '1.00', 'piece_weight_max' => '1.00', 'price_status' => 'unpriced',
            ]],
        ]);
        self::assertNotFalse($edited, CustomerReportLogic::getError());
        self::assertCount(1, $edited['items']);
        self::assertSame($warehouseB, (int)$edited['items'][0]['warehouse_id']);
        self::assertSame('0.0000', WarehouseGoodsBalanceService::reserved($warehouseA, $goodsA));
        self::assertSame('0.0000', WarehouseGoodsBalanceService::reserved($warehouseA, $goodsB));
        self::assertSame('1.0000', WarehouseGoodsBalanceService::reserved($warehouseB, $goodsA));
    }

    public function test_fresh_customer_report_migration_uses_two_decimal_business_fields(): void
    {
        $expected = [
            'la_customer_report' => ['total_base_qty', 'reserved_base_qty', 'shortage_base_qty'],
            'la_customer_report_item' => ['expected_base_qty', 'reserved_base_qty', 'shortage_base_qty', 'fulfilled_base_qty', 'piece_weight_min', 'piece_weight_max', 'price'],
            'la_customer_report_reservation' => ['reserved_base_qty', 'consumed_base_qty', 'released_base_qty'],
        ];
        foreach ($expected as $table => $fields) {
            $types = array_column(Db::query("SHOW COLUMNS FROM `{$table}`"), 'Type', 'Field');
            foreach ($fields as $field) {
                self::assertSame('decimal(18,2)', strtolower((string)$types[$field]), $table . '.' . $field);
            }
        }
    }

    public function test_two_concurrent_submissions_cannot_over_reserve_one_warehouse_balance(): void
    {
        $customerId = $this->createCustomer('并发客户');
        $goodsId = $this->createCustomerReportGoods('并发桂鱼', 'CR-CONCURRENT');
        $warehouseId = $this->createCustomerReportWarehouse('并发仓');
        self::assertNotFalse(WarehouseGoodsBalanceService::inbound($warehouseId, $goodsId, '1.0000'));
        $paths = [];
        $startPath = tempnam(sys_get_temp_dir(), 'customer-report-start-');
        unlink($startPath);
        try {
            $processes = [];
            foreach (['concurrent-a', 'concurrent-b'] as $key) {
                $inputPath = tempnam(sys_get_temp_dir(), 'customer-report-input-');
                $outputPath = tempnam(sys_get_temp_dir(), 'customer-report-output-');
                $paths[] = $inputPath; $paths[] = $outputPath;
                file_put_contents($inputPath, json_encode([
                    'tenant_id' => self::TENANT_ID, 'admin_id' => self::ADMIN_ID,
                    'report' => $this->submitPayload($customerId, $goodsId, $warehouseId, $key, '1', '1.00', '1.00'),
                ], JSON_UNESCAPED_UNICODE));
                $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/fixtures/customer_report_submit_worker.php') . ' ' . escapeshellarg($inputPath) . ' ' . escapeshellarg($outputPath) . ' ' . escapeshellarg($startPath);
                $processes[] = [proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes), $pipes, $outputPath];
            }
            usleep(100_000);
            touch($startPath);
            foreach ($processes as [$process, $pipes]) {
                self::assertIsResource($process);
                stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                self::assertSame(0, proc_close($process));
            }
            $statuses = array_map(static function (array $process): string {
                $response = json_decode((string)file_get_contents($process[2]), true) ?: [];
                return (string)($response['result']['status'] ?? '');
            }, $processes);
            self::assertContains('submitted_ready', $statuses);
            self::assertContains('submitted_shortage', $statuses, json_encode(array_map(static fn(array $process): array => json_decode((string)file_get_contents($process[2]), true) ?: [], $processes), JSON_UNESCAPED_UNICODE));
            self::assertSame('1.0000', WarehouseGoodsBalanceService::reserved($warehouseId, $goodsId));
            self::assertSame('0.0000', WarehouseGoodsBalanceService::available($warehouseId, $goodsId));
            self::assertSame(2, CustomerReportLogic::lists([])['count']);
        } finally {
            if (is_file($startPath)) { unlink($startPath); }
            foreach ($paths as $path) { if (is_file($path)) { unlink($path); } }
        }
    }

    private function submitPayload(int $customerId, int $goodsId, int $warehouseId, string $key, string $quantity, string $minimum, string $maximum): array
    {
        return ['main_customer_id' => $customerId, 'idempotency_key' => $key, 'remark' => '', 'items' => [[
            'goods_id' => $goodsId, 'warehouse_id' => $warehouseId, 'unit_id' => 0, 'unit_name' => '件', 'order_qty' => $quantity,
            'piece_weight_confirmed' => 1, 'piece_weight_min' => $minimum, 'piece_weight_max' => $maximum,
            'price_status' => 'unpriced',
        ]]];
    }

}
