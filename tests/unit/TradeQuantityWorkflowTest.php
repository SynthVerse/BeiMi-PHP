<?php
declare(strict_types=1);
namespace tests\unit;
use app\api\jxc\logic\CustomerReportLogic;
use app\api\jxc\logic\FulfillmentTaskLogic;
use app\api\jxc\logic\DeliveryInventoryLogic;
use app\api\jxc\logic\WarehouseSkuBalanceService;
use app\api\jxc\logic\WorkforceLogic;
use app\api\jxc\logic\StockService;
use app\api\jxc\logic\GoodsPackagingSourceService;
use PHPUnit\Framework\TestCase;
use think\facade\Db;
require_once __DIR__ . '/CustomerReportTestSupport.php';

final class TradeQuantityWorkflowTest extends TestCase
{
    use CustomerReportTestSupport;
    protected function setUp(): void
    {
        $this->prepareCustomerReportRequestContext(); $this->ensureCustomerReportTables(); $this->cleanCustomerReportData();
        WorkforceLogic::saveProcess(['name' => '直接备货', 'trigger_type' => 'report_selection', 'is_enabled' => 1, 'sort' => 10]);
        WorkforceLogic::saveProcess(['name' => '送货', 'trigger_type' => 'all_processing_completed', 'is_enabled' => 1, 'sort' => 900]);
    }
    protected function tearDown(): void { $this->cleanCustomerReportData(); }

    private function fixture(string $baseName = '斤', string $opening = '100', string $method = 'self_delivery'): array
    {
        $base = $this->createCustomerReportUnit($baseName); $count = $baseName === '瓶' ? $base : $this->createCustomerReportUnit('把');
        $goods = $this->createCustomerReportGoods('独立数量商品', 'TRADE-V2', $baseName); $sku = $this->customerReportSkuId($goods);
        Db::name('goods')->where('id', $goods)->update(['unit_id' => $base]);
        Db::name('goods_sku')->where('id', $sku)->update(['base_unit_id' => $base, 'base_unit_name' => $baseName]);
        foreach (array_unique([$base, $count]) as $id) {
            Db::name('goods_units_binding')->insert(['tenant_id' => self::TENANT_ID, 'goods_id' => $goods, 'unit_id' => $id,
                'unit_name' => $id === $base ? $baseName : '把', 'is_base_unit' => $id === $base ? 1 : 0, 'status' => 1]);
        }
        $warehouse = $this->createCustomerReportWarehouse('数量事实仓');
        if (bccomp($opening, '0', 4) > 0) { WarehouseSkuBalanceService::inbound($warehouse, $sku, $opening); }
        $process = (int)Db::name('work_process')->where('tenant_id', self::TENANT_ID)->where('trigger_type', 'report_selection')->where('is_enabled', 1)->value('id');
        $payload = ['quantity_contract_version' => 2, 'main_customer_id' => $this->createCustomer('数量客户'),
            'delivery_date' => date('Y-m-d'), 'delivery_arrangement' => ['delivery_method' => $method], 'idempotency_key' => uniqid('trade-'),
            'items' => [['goods_id' => $goods, 'sku_id' => $sku, 'warehouse_id' => $warehouse,
                'unit_id' => $count, 'unit_name' => $baseName === '瓶' ? '瓶' : '把', 'order_qty' => '5', 'price_status' => 'unpriced',
                'processing_groups' => [['group_key' => 'all', 'name' => '全部数量', 'planned_qty' => '5', 'process_ids' => [$process]]]]]];
        $report = CustomerReportLogic::submit($payload);
        self::assertNotFalse($report, CustomerReportLogic::getError());
        return compact('base', 'count', 'goods', 'sku', 'warehouse', 'report', 'payload', 'process');
    }

    private function deliverySelection(array $report, array $groupIds, string $remainingAction = 'none'): array
    {
        $groups = $report['items'][0]['processing_groups'];
        $selected = array_values(array_filter($groups, static fn(array $group): bool => in_array((int)$group['id'], $groupIds, true)));
        self::assertNotEmpty($selected);
        return [[
            'report_item_id' => (int)$report['items'][0]['id'],
            'delivery_group_ids' => $groupIds,
            'facts_version' => (int)$selected[0]['version'],
            'remaining_action' => $remainingAction,
        ]];
    }

    public function test_single_operator_can_save_net_weight_without_size_or_processing_confirmation(): void
    {
        $f = $this->fixture(); $report = $f['report']; $item = $report['items'][0];
        Db::name('customer_report_item')->where('id', $item['id'])->update([
            'piece_weight_confirmed' => 1, 'piece_weight_min' => '1', 'piece_weight_max' => '2',
            'size_check_stage' => 'before_processing',
        ]);
        $saved = CustomerReportLogic::saveProcessingWeights([
            'id' => $report['id'], 'version' => $report['version'], 'idempotency_key' => 'single-operator-net',
            'groups' => [['id' => $item['processing_groups'][0]['id'],
                'quantity_facts' => [['unit_id' => $f['count'], 'quantity' => '5']],
                'net_weight_input' => ['value' => '9.5', 'unit' => '斤']]],
        ]);
        self::assertNotFalse($saved, CustomerReportLogic::getError());
        self::assertSame('9.5000', $saved['items'][0]['actual_delivery_weight']);
        self::assertSame('100.0000', WarehouseSkuBalanceService::onHand($f['warehouse'], $f['sku']));
        self::assertArrayNotHasKey('specification_verification', json_decode($saved['items'][0]['processing_groups'][0]['quantity_facts_snapshot'], true));
    }

    public function test_cancel_prepared_report_voids_printed_papers_and_keeps_controls_reachable(): void
    {
        $f = $this->fixture(); $report = $f['report'];
        $saved = CustomerReportLogic::saveProcessingWeights(['id' => $report['id'], 'version' => $report['version'],
            'idempotency_key' => 'prepared-cancel-weight', 'groups' => [['id' => $report['items'][0]['processing_groups'][0]['id'],
                'quantity_facts' => [['unit_id' => $f['count'], 'quantity' => '5']],
                'net_weight_input' => ['value' => '9.5', 'unit' => '斤']]]]);
        self::assertNotFalse($saved, CustomerReportLogic::getError());
        $tasks = $saved['task_group']['tasks'];
        foreach ($tasks as $task) {
            $print = FulfillmentTaskLogic::printData(['id' => $task['id']]);
            self::assertNotFalse($print, FulfillmentTaskLogic::getError());
            self::assertNotFalse(FulfillmentTaskLogic::printResult(['id' => $task['id'], 'print_log_id' => $print['print_log_id'], 'success' => 1]));
        }
        $cancelled = CustomerReportLogic::cancel(['id' => $saved['id'], 'version' => $saved['version'], 'reason' => '已全部备齐，客户取消']);
        self::assertNotFalse($cancelled, CustomerReportLogic::getError());
        self::assertSame('100.0000', WarehouseSkuBalanceService::onHand($f['warehouse'], $f['sku']));
        self::assertSame('0.0000', WarehouseSkuBalanceService::reserved($f['warehouse'], $f['sku']));
        self::assertSame('9.5000', $cancelled['items'][0]['actual_delivery_weight']);
        self::assertCount(count($tasks), $cancelled['cancellation_followup']['paper_controls']);
        $dashboard = FulfillmentTaskLogic::dashboard(['delivery_date' => $report['delivery_date']]);
        $visible = array_merge(...array_column($dashboard['process_groups'], 'tasks'));
        self::assertCount(count($tasks), $visible, '取消任务的旧纸票必须仍可在看板处理');
        foreach ($cancelled['cancellation_followup']['paper_controls'] as $control) {
            self::assertNotFalse(FulfillmentTaskLogic::paperControl(['control_id' => $control['id'], 'resolution' => 'recovered', 'note' => '现场收回作废']));
        }
        self::assertSame('cancelled', CustomerReportLogic::detail(['id' => $report['id']])['status']);
    }

    public function test_quantity_v2_requires_an_explicit_selected_process(): void
    {
        $f = $this->fixture();
        $payload = $f['payload'];
        $payload['idempotency_key'] = 'v2-missing-processing-groups';
        unset($payload['items'][0]['processing_groups']);
        self::assertFalse(CustomerReportLogic::submit($payload));
        self::assertSame('每条报货明细必须至少添加一个分批处理组', CustomerReportLogic::getError());

        $payload['idempotency_key'] = 'v2-missing-selected-process';
        $payload['items'][0]['processing_groups'] = [[
            'group_key' => 'all', 'name' => '全部数量', 'planned_qty' => '5', 'process_ids' => [],
        ]];
        self::assertFalse(CustomerReportLogic::submit($payload));
        self::assertSame('每个分批处理组必须选择至少一个执行工序', CustomerReportLogic::getError());
    }

    /** @dataProvider sizeConfirmationCases */
    public function test_size_confirmation_without_measured_extrema(array $verification, bool $weightOrder, ?string $error): void
    {
        $f = $this->fixture(); $report = $f['report']; $item = $report['items'][0];
        $groupId = (int)$item['processing_groups'][0]['id'];
        Db::name('customer_report_item')->where('id', $item['id'])->update([
            'piece_weight_confirmed' => 1, 'piece_weight_min' => '1', 'piece_weight_max' => '2',
            'size_check_stage' => 'before_processing',
            'unit_id' => $weightOrder ? $f['base'] : $f['count'],
        ]);
        Db::name('customer_report_processing_group_process')->where('processing_group_id', $groupId)->delete();
        $save = ['id' => $report['id'], 'version' => $report['version'], 'idempotency_key' => 'manual-size',
            'specification_verifications' => [$item['id'] => $verification],
            'groups' => [['id' => $groupId, 'net_weight_input' => ['value' => '5', 'unit' => '斤'],
                'quantity_facts' => $weightOrder ? [] : [['unit_id' => $f['count'], 'quantity' => '5']]]]];
        $result = CustomerReportLogic::saveProcessingWeights($save);
        if ($error !== null) {
            self::assertFalse($result);
            self::assertStringContainsString($error, CustomerReportLogic::getError());
            self::assertSame((int)$report['version'], (int)Db::name('customer_report')->where('id', $report['id'])->value('version'));
            return;
        }
        self::assertNotFalse($result, CustomerReportLogic::getError());
        $facts = json_decode(Db::name('customer_report_processing_group')->where('id', $groupId)->value('quantity_facts_snapshot'), true);
        $saved = $facts['specification_verification'];
        if (($verification['mode'] ?? '') === 'manual_confirmation') {
            self::assertArrayNotHasKey('min', $saved); self::assertArrayNotHasKey('max', $saved);
            self::assertSame('manual_confirmation', $saved['mode']);
            self::assertEquals(1, $saved['requirement']['min']);
            self::assertEquals(2, $saved['requirement']['max']);
        } else {
            self::assertSame('1', $saved['min']); self::assertSame('2', $saved['max']);
        }
        self::assertSame((int)$result['version'], (int)CustomerReportLogic::saveProcessingWeights($save)['version']);
    }

    public static function sizeConfirmationCases(): array
    {
        $manual = ['mode' => 'manual_confirmation', 'confirmed' => true, 'stage' => 'before_processing'];
        $legacy = ['confirmed' => true, 'stage' => 'before_processing', 'min' => '1', 'max' => '2', 'count' => '5'];
        return [
            'counted confirmation' => [$manual + ['count' => '5'], false, null],
            'weight confirmation without invented count' => [$manual, true, null],
            'manual ignores claimed extrema' => [$manual + ['min' => '8', 'max' => '9'], true, null],
            'invalid explicit count' => [$manual + ['count' => 'abc'], true, '数量必须'],
            'negative explicit count' => [$manual + ['count' => '-1'], true, '数量必须'],
            'unchecked' => [array_replace($manual, ['confirmed' => false]), true, '核对大小'],
            'wrong stage' => [array_replace($manual, ['stage' => 'delivery']), true, '核验阶段'],
            'unknown mode' => [array_replace($manual, ['mode' => 'unknown']), true, '核验方式'],
            'wrong count' => [$manual + ['count' => '4'], false, '实际清点数量一致'],
            'missing count' => [$manual, false, '数量必须'],
            'legacy accepted' => [$legacy, false, null],
            'legacy out of range' => [array_replace($legacy, ['max' => '3']), false, '核验范围'],
            'legacy missing extrema' => [array_diff_key($legacy, ['min' => 1, 'max' => 1]), false, '数量必须'],
        ];
    }

    public function test_quantity_v2_processing_groups_sum_with_four_decimal_precision(): void
    {
        $f = $this->fixture();
        $payload = $f['payload'];
        $payload['idempotency_key'] = 'v2-four-decimal-processing-groups';
        $payload['items'][0]['processing_groups'] = [
            ['group_key' => 'first', 'name' => '第一批', 'planned_qty' => '0.0050', 'process_ids' => [$f['process']]],
            ['group_key' => 'second', 'name' => '第二批', 'planned_qty' => '4.9950', 'process_ids' => [$f['process']]],
        ];

        self::assertNotFalse(CustomerReportLogic::submit($payload), CustomerReportLogic::getError());
    }

    public function test_unknown_estimate_saves_demand_then_measured_delivery_uses_real_stock_and_replays(): void
    {
        $f = $this->fixture(); $report = $f['report'];
        self::assertSame('submitted_pending_measurement', $report['status']); self::assertNull($report['items'][0]['expected_base_qty']);
        self::assertSame('0.0000', WarehouseSkuBalanceService::reserved($f['warehouse'], $f['sku']));
        $save = ['id' => $report['id'], 'version' => $report['version'], 'idempotency_key' => 'weight-v2', 'groups' => [[
            'id' => $report['items'][0]['processing_groups'][0]['id'], 'quantity_facts' => [['unit_id' => $f['count'], 'quantity' => '5']],
            'net_weight_input' => ['value' => '4.35', 'unit' => '公斤']]]];
        $weighted = CustomerReportLogic::saveProcessingWeights($save); self::assertNotFalse($weighted, CustomerReportLogic::getError());
        self::assertSame('8.7000', $weighted['items'][0]['actual_delivery_weight']);
        self::assertSame((int)$weighted['version'], (int)CustomerReportLogic::saveProcessingWeights($save)['version']);
        $stale = $save; $stale['idempotency_key'] = 'other-weight';
        self::assertFalse(CustomerReportLogic::saveProcessingWeights($stale));
        $task = (int)Db::name('fulfillment_task')->where('report_id', $report['id'])->where('source_key', 'report:' . $report['id'] . ':delivery')->value('id');
        $delivery = ['task_id' => $task, 'event_type' => 'customer_handoff', 'facts_version' => $weighted['version'], 'idempotency_key' => 'delivery-v2'];
        self::assertFalse(DeliveryInventoryLogic::confirmSelfDelivery($delivery));
        self::assertStringContainsString('明确选择', DeliveryInventoryLogic::getError());
        self::assertSame('100.0000', WarehouseSkuBalanceService::onHand($f['warehouse'], $f['sku']));
        $delivery['items'] = $this->deliverySelection($weighted, [(int)$weighted['items'][0]['processing_groups'][0]['id']]);
        $event = DeliveryInventoryLogic::confirmSelfDelivery($delivery); self::assertNotFalse($event, DeliveryInventoryLogic::getError());
        self::assertSame('91.3000', WarehouseSkuBalanceService::onHand($f['warehouse'], $f['sku']));
        self::assertSame('8.7000', $event['items'][0]['inventory_base_quantity']);
        self::assertSame((int)$event['id'], (int)DeliveryInventoryLogic::confirmSelfDelivery($delivery)['id']);
        $delivery['idempotency_key'] = 'second-entry'; self::assertFalse(DeliveryInventoryLogic::confirmSelfDelivery($delivery));
        self::assertSame('91.3000', WarehouseSkuBalanceService::onHand($f['warehouse'], $f['sku']));
    }

    public function test_bottles_deliver_without_inventing_weight(): void
    {
        $f = $this->fixture('瓶'); $report = $f['report'];
        $weighted = CustomerReportLogic::saveProcessingWeights(['id' => $report['id'], 'version' => $report['version'], 'idempotency_key' => 'bottle-weight', 'groups' => [[
            'id' => $report['items'][0]['processing_groups'][0]['id'], 'quantity_facts' => [['unit_id' => $f['count'], 'quantity' => '3']]]]]);
        self::assertNotFalse($weighted, CustomerReportLogic::getError()); self::assertNull($weighted['items'][0]['actual_delivery_weight']);
        $task = (int)Db::name('fulfillment_task')->where('report_id', $report['id'])->where('source_key', 'report:' . $report['id'] . ':delivery')->value('id');
        $event = DeliveryInventoryLogic::confirmSelfDelivery(['task_id' => $task, 'event_type' => 'customer_handoff', 'facts_version' => $weighted['version'], 'idempotency_key' => 'bottle-delivery',
            'items' => $this->deliverySelection($weighted, [(int)$weighted['items'][0]['processing_groups'][0]['id']])]);
        self::assertNotFalse($event, DeliveryInventoryLogic::getError()); self::assertNull($event['items'][0]['actual_delivery_weight']);
        self::assertSame('97.0000', WarehouseSkuBalanceService::onHand($f['warehouse'], $f['sku']));
        self::assertSame('partially_delivered_pending', Db::name('customer_report_item')->where('id', $report['items'][0]['id'])->value('fulfillment_status'));
    }

    public function test_processing_records_loss_immediately_and_delivery_does_not_consume_raw_twice(): void
    {
        $f = $this->fixture(); $report = $f['report'];
        $save = ['id' => $report['id'], 'version' => $report['version'], 'idempotency_key' => 'processing-facts', 'groups' => [[
            'id' => $report['items'][0]['processing_groups'][0]['id'], 'quantity_facts' => [['unit_id' => $f['count'], 'quantity' => '5']],
            'net_weight_input' => ['value' => '18', 'unit' => '斤'],
            'processing' => ['mode' => 'direct_loss', 'raw_input_weight' => '20', 'loss_weight' => '2', 'disposition' => 'discarded', 'reason' => '修整废弃']]]];
        $weighted = CustomerReportLogic::saveProcessingWeights($save); self::assertNotFalse($weighted, CustomerReportLogic::getError());
        self::assertSame('98.0000', WarehouseSkuBalanceService::onHand($f['warehouse'], $f['sku']));
        self::assertSame('18.0000', WarehouseSkuBalanceService::reserved($f['warehouse'], $f['sku']));
        self::assertNotFalse(CustomerReportLogic::saveProcessingWeights($save));
        self::assertSame(1, Db::name('stock_flow')->where('tenant_id', self::TENANT_ID)->where('order_type', 'fulfillment_internal_loss')->count());
        $task = (int)Db::name('fulfillment_task')->where('report_id', $report['id'])->where('source_key', 'report:' . $report['id'] . ':delivery')->value('id');
        $event = DeliveryInventoryLogic::confirmSelfDelivery(['task_id' => $task, 'event_type' => 'customer_handoff', 'facts_version' => $weighted['version'], 'idempotency_key' => 'processed-delivery',
            'items' => $this->deliverySelection($weighted, [(int)$weighted['items'][0]['processing_groups'][0]['id']])]);
        self::assertNotFalse($event, DeliveryInventoryLogic::getError()); self::assertSame('80.0000', WarehouseSkuBalanceService::onHand($f['warehouse'], $f['sku']));
    }

    public function test_verified_packaging_uses_receipt_snapshot_and_consumes_source_once(): void
    {
        $this->prepareLegacyOperatingStore();
        $f = $this->fixture('斤', '0'); $report = $f['report'];
        $rule = (int)Db::name('goods_unit_conversion_rule')->insertGetId(['tenant_id' => self::TENANT_ID, 'goods_id' => $f['goods'], 'sku_id' => $f['sku'],
            'supplier_id' => 0, 'from_unit_id' => $f['count'], 'to_unit_id' => $f['base'], 'from_unit_name' => '把', 'to_unit_name' => '斤', 'ratio' => '1', 'status' => 1]);
        self::assertTrue(StockService::inbound($f['warehouse'], $f['goods'], '5', 1, 'supply', 'package-receipt', '', $f['sku']));
        $flow = (int)Db::name('stock_flow')->where('tenant_id', self::TENANT_ID)->where('sku_id', $f['sku'])->max('id');
        $source = Db::transaction(fn() => GoodsPackagingSourceService::recordInbound($flow, $rule, '5', $f['count'], true));
        Db::name('goods_unit_conversion_rule')->where('id', $rule)->update(['ratio' => '2']);
        $weighted = CustomerReportLogic::saveProcessingWeights(['id' => $report['id'], 'version' => $report['version'], 'idempotency_key' => 'packaging-facts', 'groups' => [[
            'id' => $report['items'][0]['processing_groups'][0]['id'], 'packaging' => ['source_id' => $source, 'unit_id' => $f['count'], 'quantity' => '5', 'intact' => true]]]]);
        self::assertNotFalse($weighted, CustomerReportLogic::getError()); self::assertSame('5.0000', $weighted['items'][0]['actual_delivery_weight']);
        self::assertSame('verified_packaging_rule', $weighted['items'][0]['processing_groups'][0]['weight_source']);
        $task = (int)Db::name('fulfillment_task')->where('report_id', $report['id'])->where('source_key', 'report:' . $report['id'] . ':delivery')->value('id');
        $event = DeliveryInventoryLogic::confirmSelfDelivery(['task_id' => $task, 'event_type' => 'customer_handoff', 'facts_version' => $weighted['version'], 'idempotency_key' => 'package-delivery',
            'items' => $this->deliverySelection($weighted, [(int)$weighted['items'][0]['processing_groups'][0]['id']])]);
        self::assertNotFalse($event, DeliveryInventoryLogic::getError()); self::assertSame('0.0000', WarehouseSkuBalanceService::onHand($f['warehouse'], $f['sku']));
        self::assertSame('0.0000', Db::name('goods_packaging_source')->where('id', $source)->value('remaining_package_quantity'));
    }

    public function test_counter_pickup_records_real_delivery_without_fabricating_vehicle(): void
    {
        $f = $this->fixture('瓶', '100', 'counter_pickup'); $report = $f['report'];
        self::assertSame('ready', $report['delivery_arrangement']['status']);
        self::assertSame('', $report['delivery_arrangement']['plate_number']);
        $weighted = CustomerReportLogic::saveProcessingWeights(['id' => $report['id'], 'version' => $report['version'], 'idempotency_key' => 'counter-facts',
            'groups' => [['id' => $report['items'][0]['processing_groups'][0]['id'], 'quantity_facts' => [['unit_id' => $f['count'], 'quantity' => '5']]]]]);
        self::assertNotFalse($weighted, CustomerReportLogic::getError());
        $task = (int)Db::name('fulfillment_task')->where('report_id', $report['id'])->where('source_key', 'report:' . $report['id'] . ':delivery')->value('id');
        $event = DeliveryInventoryLogic::confirmCounterPickup(['task_id' => $task, 'facts_version' => $weighted['version'], 'idempotency_key' => 'counter-delivery',
            'items' => $this->deliverySelection($weighted, [(int)$weighted['items'][0]['processing_groups'][0]['id']])]);
        self::assertNotFalse($event, DeliveryInventoryLogic::getError()); self::assertSame('counter_pickup', $event['delivery_method']);
        self::assertSame('95.0000', WarehouseSkuBalanceService::onHand($f['warehouse'], $f['sku']));
    }

    public function test_two_then_three_use_new_physical_group_and_keep_original_delivery_immutable(): void
    {
        $f = $this->fixture(); $report = $f['report']; $group = $report['items'][0]['processing_groups'][0]['id'];
        $first = CustomerReportLogic::saveProcessingWeights(['id' => $report['id'], 'version' => $report['version'], 'idempotency_key' => 'first-two',
            'groups' => [['id' => $group, 'quantity_facts' => [['unit_id' => $f['count'], 'quantity' => '2']], 'net_weight_input' => ['value' => '3.2', 'unit' => '斤']]]]);
        self::assertNotFalse($first, CustomerReportLogic::getError());
        $task = (int)Db::name('fulfillment_task')->where('report_id', $report['id'])->where('source_key', 'report:' . $report['id'] . ':delivery')->value('id');
        $event = DeliveryInventoryLogic::confirmSelfDelivery(['task_id' => $task, 'event_type' => 'customer_handoff', 'facts_version' => $first['version'], 'idempotency_key' => 'give-two',
            'items' => $this->deliverySelection($first, [(int)$group])]);
        self::assertNotFalse($event, DeliveryInventoryLogic::getError());
        self::assertSame('96.8000', WarehouseSkuBalanceService::onHand($f['warehouse'], $f['sku']));
        self::assertSame('0.0000', WarehouseSkuBalanceService::reserved($f['warehouse'], $f['sku']));
        $original = Db::name('customer_report_processing_group')->where('id', $group)->value('quantity_facts_snapshot');
        $save = ['id' => $report['id'], 'version' => $first['version'], 'idempotency_key' => 'next-three',
            'groups' => [['append_to_group_id' => $group, 'quantity_facts' => [['unit_id' => $f['count'], 'quantity' => '3']], 'net_weight_input' => ['value' => '5.5', 'unit' => '斤']]]];
        $second = CustomerReportLogic::saveProcessingWeights($save);
        self::assertNotFalse($second, CustomerReportLogic::getError());
        self::assertCount(2, $second['items'][0]['processing_groups']);
        self::assertSame($original, Db::name('customer_report_processing_group')->where('id', $group)->value('quantity_facts_snapshot'));
        self::assertSame('5.5000', WarehouseSkuBalanceService::reserved($f['warehouse'], $f['sku']));
        self::assertSame('8.7000', $second['items'][0]['actual_delivery_weight']);
        self::assertSame('0.0000', $second['items'][0]['remaining_order_quantity']);
        self::assertNotFalse(CustomerReportLogic::saveProcessingWeights($save));
        self::assertSame(2, Db::name('customer_report_processing_group')->where('report_id', $report['id'])->count());
        $secondEvent = DeliveryInventoryLogic::confirmSelfDelivery(['task_id' => $task, 'event_type' => 'customer_handoff', 'facts_version' => $second['version'], 'idempotency_key' => 'give-three',
            'items' => $this->deliverySelection($second, [(int)$second['items'][0]['processing_groups'][1]['id']])]);
        self::assertNotFalse($secondEvent, DeliveryInventoryLogic::getError());
        self::assertSame('5.5000', $secondEvent['items'][0]['inventory_base_quantity']);
        self::assertNotSame($event['items'][0]['delivery_group_key'], $secondEvent['items'][0]['delivery_group_key']);
        self::assertSame('91.3000', WarehouseSkuBalanceService::onHand($f['warehouse'], $f['sku']));
        self::assertSame('0.0000', WarehouseSkuBalanceService::reserved($f['warehouse'], $f['sku']));
        self::assertSame('partial_pending_settlement', Db::name('customer_report')->where('id', $report['id'])->value('status'));

        $next = CustomerReportLogic::submit(['quantity_contract_version' => 2, 'main_customer_id' => $report['main_customer_id'],
            'delivery_date' => date('Y-m-d'), 'delivery_arrangement' => ['delivery_method' => 'self_delivery'], 'idempotency_key' => 'reference-report',
            'items' => [['goods_id' => $f['goods'], 'sku_id' => $f['sku'], 'warehouse_id' => $f['warehouse'], 'unit_id' => $f['count'],
                'unit_name' => '把', 'order_qty' => '5', 'price_status' => 'unpriced', 'processing_groups' => [[
                    'group_key' => 'all', 'name' => '全部数量', 'planned_qty' => '5', 'process_ids' => [$f['process']]]]]]]);
        self::assertNotFalse($next, CustomerReportLogic::getError());
        self::assertSame('completed_delivery_reference', $next['items'][0]['estimate_source']);
        self::assertSame('9.1666', $next['items'][0]['expected_base_qty']);
        self::assertSame(0, (int)$next['items'][0]['piece_weight_confirmed']);
        self::assertNull($next['items'][0]['final_actual_weight']);
        $reference = json_decode($next['items'][0]['estimate_reference_snapshot'], true);
        self::assertSame((int)$secondEvent['items'][0]['id'], $reference['source_delivery_item_id']);
    }

    public function test_legacy_goods_and_sku_base_units_cannot_change_quantity_meaning(): void
    {
        $f = $this->fixture();
        Db::name('goods_units_binding')->where('goods_id', $f['goods'])->delete();
        self::assertFalse(\app\api\jxc\logic\GoodsUnitLogic::edit(['id' => $f['base'], 'measure_type' => 'count', 'quantity_precision' => 0]));
        Db::name('goods')->where('id', $f['goods'])->update(['unit_id' => 0]);
        self::assertFalse(\app\api\jxc\logic\GoodsUnitLogic::edit(['id' => $f['base'], 'measure_type' => 'count', 'quantity_precision' => 0]));
    }

    public function test_weight_order_keeps_unmeasured_second_group_open_after_first_delivery(): void
    {
        $f = $this->fixture();
        $report = CustomerReportLogic::submit(['quantity_contract_version' => 2, 'main_customer_id' => $f['report']['main_customer_id'],
            'delivery_date' => date('Y-m-d'), 'delivery_arrangement' => ['delivery_method' => 'self_delivery'], 'idempotency_key' => 'two-weight-groups',
            'items' => [['goods_id' => $f['goods'], 'sku_id' => $f['sku'], 'warehouse_id' => $f['warehouse'], 'unit_id' => $f['base'],
                'unit_name' => '斤', 'order_qty' => '5', 'price_status' => 'unpriced', 'processing_groups' => [
                    ['group_key' => 'first', 'name' => '第一批', 'planned_qty' => '2', 'process_ids' => [$f['process']]],
                    ['group_key' => 'second', 'name' => '第二批', 'planned_qty' => '3', 'process_ids' => [$f['process']]]]]]]);
        self::assertNotFalse($report, CustomerReportLogic::getError());
        $groups = $report['items'][0]['processing_groups'];
        $first = CustomerReportLogic::saveProcessingWeights(['id' => $report['id'], 'version' => $report['version'], 'idempotency_key' => 'measure-first-weight',
            'groups' => [['id' => $groups[0]['id'], 'net_weight_input' => ['value' => '2', 'unit' => '斤']]]]);
        self::assertNotFalse($first, CustomerReportLogic::getError());
        self::assertTrue($first['items'][0]['has_unmeasured_groups']);
        self::assertNull($first['items'][0]['remaining_order_quantity']);
        $task = (int)Db::name('fulfillment_task')->where('report_id', $report['id'])->where('source_key', 'report:' . $report['id'] . ':delivery')->value('id');
        $event = DeliveryInventoryLogic::confirmSelfDelivery(['task_id' => $task, 'event_type' => 'customer_handoff', 'facts_version' => $first['version'], 'idempotency_key' => 'give-first-weight',
            'items' => $this->deliverySelection($first, [(int)$groups[0]['id']])]);
        self::assertNotFalse($event, DeliveryInventoryLogic::getError());
        self::assertSame('partial', $event['delivery_outcome']);
        self::assertSame('partially_delivered_pending', Db::name('customer_report')->where('id', $report['id'])->value('status'));
        self::assertSame('partially_delivered_pending', Db::name('customer_report_item')->where('id', $report['items'][0]['id'])->value('fulfillment_status'));
        self::assertSame('printable', Db::name('fulfillment_task')->where('id', $task)->value('status'));
        $original = Db::name('customer_report_processing_group')->where('id', $groups[0]['id'])->value('quantity_facts_snapshot');
        $second = CustomerReportLogic::saveProcessingWeights(['id' => $report['id'], 'version' => $first['version'], 'idempotency_key' => 'measure-second-weight',
            'groups' => [['id' => $groups[1]['id'], 'net_weight_input' => ['value' => '3', 'unit' => '斤']]]]);
        self::assertNotFalse($second, CustomerReportLogic::getError());
        self::assertFalse($second['items'][0]['has_unmeasured_groups']);
        self::assertSame($original, Db::name('customer_report_processing_group')->where('id', $groups[0]['id'])->value('quantity_facts_snapshot'));
        self::assertSame('3.0000', WarehouseSkuBalanceService::reserved($f['warehouse'], $f['sku']));
        $completed = DeliveryInventoryLogic::confirmSelfDelivery(['task_id' => $task, 'event_type' => 'customer_handoff', 'facts_version' => $second['version'], 'idempotency_key' => 'give-second-weight',
            'items' => $this->deliverySelection($second, [(int)$groups[1]['id']])]);
        self::assertNotFalse($completed, DeliveryInventoryLogic::getError());
        self::assertSame('partial_completed', $completed['delivery_outcome']);
        self::assertSame('partial_pending_settlement', Db::name('customer_report')->where('id', $report['id'])->value('status'));
        self::assertSame('recovered', Db::name('fulfillment_task')->where('id', $task)->value('status'));
        self::assertSame('95.0000', WarehouseSkuBalanceService::onHand($f['warehouse'], $f['sku']));
        self::assertSame('0.0000', WarehouseSkuBalanceService::reserved($f['warehouse'], $f['sku']));
    }

    public function test_packaging_source_does_not_round_indivisible_single_package(): void
    {
        $this->prepareLegacyOperatingStore();
        $f = $this->fixture('斤', '0');
        $rule = (int)Db::name('goods_unit_conversion_rule')->insertGetId(['tenant_id' => self::TENANT_ID, 'goods_id' => $f['goods'], 'sku_id' => $f['sku'],
            'supplier_id' => 0, 'from_unit_id' => $f['count'], 'to_unit_id' => $f['base'], 'from_unit_name' => '把', 'to_unit_name' => '斤', 'ratio' => '0.333330', 'status' => 1]);
        self::assertTrue(StockService::inbound($f['warehouse'], $f['goods'], '3.3333', 1, 'supply', 'package-precision', '', $f['sku']));
        $flow = (int)Db::name('stock_flow')->where('tenant_id', self::TENANT_ID)->where('sku_id', $f['sku'])->max('id');
        $source = Db::transaction(fn() => GoodsPackagingSourceService::recordInbound($flow, $rule, '10', $f['count'], true));
        $this->expectException(\DomainException::class);
        GoodsPackagingSourceService::verify(['source_id' => $source, 'unit_id' => $f['count'], 'quantity' => '1', 'intact' => true], $f['report']['items'][0]);
    }

    public function test_pricing_weight_conversion_rejects_precision_loss(): void
    {
        $kg = $this->createCustomerReportUnit('公斤');
        self::assertSame('0.3330', \app\api\jxc\logic\TradeQuantityService::weightFromJin('0.6660', $kg));
        $this->expectException(\DomainException::class);
        \app\api\jxc\logic\TradeQuantityService::weightFromJin('0.6661', $kg);
    }
}
