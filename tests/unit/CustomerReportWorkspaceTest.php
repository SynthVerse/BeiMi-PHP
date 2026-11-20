<?php
declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\CustomerReportLogic;
use app\api\jxc\logic\DeliveryInventoryLogic;
use app\api\jxc\logic\FinanceSalesOutput;
use app\api\jxc\logic\TodoQueryLogic;
use app\api\jxc\logic\WarehouseSkuBalanceService;
use app\api\jxc\logic\WorkforceLogic;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

require_once __DIR__ . '/CustomerReportTestSupport.php';

final class CustomerReportWorkspaceTest extends TestCase
{
    use CustomerReportTestSupport;

    protected function setUp(): void
    {
        $this->prepareCustomerReportRequestContext();
        request()->todoPermissionSnapshot = null;
        $this->ensureCustomerReportTables();
        $this->cleanCustomerReportData();
        foreach (glob(dirname(__DIR__, 2) . '/database/migrations/*finance*.sql') as $file) {
            if (str_ends_with($file, '20261015_000002_finance_draft_continuation.sql') && Db::query("SHOW COLUMNS FROM la_finance_document LIKE 'resume_requested'")) continue;
            $this->runStatements($this->prepareMigration((string)file_get_contents($file)));
        }
        $this->cleanFinance();
        Db::name('finance_preparation')->insert(['tenant_id' => self::TENANT_ID, 'activation_date' => date('Y-m-01')]);
        Db::name('finance_opening_book')->insert(['tenant_id' => self::TENANT_ID, 'status' => 'active', 'reviews' => '{}', 'confirmed_snapshot' => '{}', 'created_by' => '{}', 'last_modified_by' => '{}', 'create_time' => time(), 'update_time' => time()]);
        WorkforceLogic::saveProcess(['name' => '直接备货', 'trigger_type' => 'report_selection', 'is_enabled' => 1, 'sort' => 10]);
        WorkforceLogic::saveProcess(['name' => '送货', 'trigger_type' => 'all_processing_completed', 'is_enabled' => 1, 'sort' => 900]);
    }

    protected function tearDown(): void
    {
        request()->todoPermissionSnapshot = null;
        $this->prepareCustomerReportRequestContext();
        $this->cleanFinance();
        $this->cleanCustomerReportData();
    }

    private function cleanFinance(): void
    {
        foreach (['finance_customer_terms', 'finance_correction', 'finance_sales_pricing_fact', 'finance_sales_group_coverage', 'finance_sales_coverage', 'finance_entry', 'finance_source', 'finance_document', 'finance_command', 'finance_opening_source', 'finance_opening_book', 'finance_preparation'] as $table) {
            Db::name($table)->where('tenant_id', self::TENANT_ID)->delete();
        }
        foreach (['customer_report_sales_note', 'customer_report_workspace_save'] as $table) {
            if (Db::query('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', ['la_' . $table])) Db::name($table)->where('tenant_id', self::TENANT_ID)->delete();
        }
    }

    private function fixture(string $unitName = '斤'): array
    {
        $unit = $this->createCustomerReportUnit($unitName);
        $goods = $this->createCustomerReportGoods('统一保存商品', 'WORKSPACE', $unitName);
        $sku = $this->customerReportSkuId($goods);
        $warehouse = $this->createCustomerReportWarehouse('统一保存仓');
        Db::name('goods')->where('id', $goods)->update(['unit_id' => $unit]);
        Db::name('goods_sku')->where('id', $sku)->update(['base_unit_id' => $unit, 'base_unit_name' => $unitName]);
        Db::name('goods_units_binding')->insert(['tenant_id' => self::TENANT_ID, 'goods_id' => $goods, 'unit_id' => $unit, 'unit_name' => $unitName, 'is_base_unit' => 1, 'status' => 1]);
        WarehouseSkuBalanceService::inbound($warehouse, $sku, '100');
        $customer = $this->createCustomer('统一保存客户');
        $process = (int)Db::name('work_process')->where('tenant_id', self::TENANT_ID)->where('trigger_type', 'report_selection')->value('id');
        $report = CustomerReportLogic::submit(['quantity_contract_version' => 2, 'main_customer_id' => $customer, 'delivery_date' => date('Y-m-d'), 'delivery_arrangement' => ['delivery_method' => 'self_delivery'], 'idempotency_key' => 'workspace-report',
            'items' => [['goods_id' => $goods, 'sku_id' => $sku, 'warehouse_id' => $warehouse, 'unit_id' => $unit, 'unit_name' => $unitName, 'order_qty' => '10', 'price_status' => 'unpriced',
                'processing_groups' => [['group_key' => 'whole', 'planned_qty' => '10', 'process_ids' => [$process]]]]]]);
        self::assertNotFalse($report, CustomerReportLogic::getError());
        return compact('report', 'unit', 'warehouse', 'sku', 'customer');
    }

    private function payload(array $fixture, string $key = 'workspace-save'): array
    {
        $report = $fixture['report'];
        return ['id' => $report['id'], 'version' => $report['version'], 'workspace_version' => $report['workspace']['version'] ?? 0, 'idempotency_key' => $key,
            'quantities' => ['groups' => [['id' => $report['items'][0]['processing_groups'][0]['id'], 'net_weight_input' => ['value' => '10', 'unit' => '斤'], 'quantity_facts' => []]]],
            'sales' => ['document_version' => $report['sales_document']['version'] ?? 0, 'lines' => [['report_item_id' => $report['items'][0]['id'], 'quantity_mode' => 'actual', 'reference_price_unit_id' => $fixture['unit'], 'reference_price' => '20', 'price_unit_id' => $fixture['unit'], 'settlement_quantity' => '10', 'price' => '20']], 'rounding_amount' => '0']];
    }

    public function test_save_and_deliver_posts_once_and_replays_without_duplicate_stock(): void
    {
        $f = $this->fixture();
        $params = $this->payload($f);
        $params['intent'] = 'save_and_deliver';
        $saved = CustomerReportLogic::saveWorkspace($params);
        self::assertNotFalse($saved, CustomerReportLogic::getError());
        self::assertSame('90.0000', WarehouseSkuBalanceService::onHand($f['warehouse'], $f['sku']));
        self::assertSame('posted', $saved['sales_document']['accounting_status']);
        self::assertNotFalse(CustomerReportLogic::saveWorkspace($params), CustomerReportLogic::getError());
        self::assertSame('90.0000', WarehouseSkuBalanceService::onHand($f['warehouse'], $f['sku']));
    }

    public function test_inline_manual_pricing_round_trips_reference_and_effective_values_without_changing_stock_facts(): void
    {
        $f = $this->fixture();
        $params = $this->payload($f, 'inline-manual-draft');
        $params['quantities']['groups'][0]['net_weight_input']['value'] = '10.6';
        $params['sales']['lines'][0]['quantity_mode'] = 'manual';
        $params['sales']['lines'][0]['price'] = '22';
        $saved = CustomerReportLogic::saveWorkspace($params);
        self::assertNotFalse($saved, CustomerReportLogic::getError());
        self::assertSame('manual', $saved['workspace']['input']['sales']['lines'][0]['quantity_mode']);
        self::assertSame('20', $saved['workspace']['input']['sales']['lines'][0]['reference_price']);
        $f['report'] = $saved;
        $params = $this->payload($f, 'inline-manual-deliver');
        $params['intent'] = 'save_and_deliver';
        $params['quantities']['groups'][0]['net_weight_input']['value'] = '10.8';
        $params['sales'] = $saved['workspace']['input']['sales'];
        $delivered = CustomerReportLogic::saveWorkspace($params);
        self::assertNotFalse($delivered, CustomerReportLogic::getError());
        $line = $delivered['sales_document']['lines'][0];
        self::assertSame('manual', $line['quantity_mode']);
        self::assertSame($f['unit'], $line['reference_price_unit_id']);
        self::assertSame('20.00', $line['reference_price']);
        self::assertSame('10.0000', $line['settlement_quantity']);
        self::assertSame('22.00', $line['price']);
        self::assertSame('220.00', $line['amount']);
        self::assertSame('89.2000', WarehouseSkuBalanceService::onHand($f['warehouse'], $f['sku']));
        $fresh = CustomerReportLogic::detail(['id' => $saved['id']]);
        self::assertSame($line, $fresh['sales_document']['lines'][0]);
    }

    public function test_actual_mode_uses_authoritative_quantity_and_reference_price_instead_of_client_totals(): void
    {
        $f = $this->fixture();
        $params = $this->payload($f, 'inline-actual');
        $params['intent'] = 'save_and_deliver';
        $params['quantities']['groups'][0]['net_weight_input']['value'] = '10.6';
        $params['sales']['lines'][0] = array_merge($params['sales']['lines'][0], ['quantity_mode' => 'actual',
            'reference_price_unit_id' => $f['unit'], 'reference_price' => '20', 'settlement_quantity' => '999', 'price' => '999']);
        $saved = CustomerReportLogic::saveWorkspace($params);
        self::assertNotFalse($saved, CustomerReportLogic::getError());
        $line = $saved['sales_document']['lines'][0];
        self::assertSame('actual', $line['quantity_mode']);
        self::assertSame('10.6000', $line['settlement_quantity']);
        self::assertSame('20.00', $line['price']);
        self::assertSame('212.00', $line['amount']);
    }

    public function test_restore_actual_pricing_discards_manual_override_and_uses_current_facts(): void
    {
        $f = $this->fixture();
        $params = $this->payload($f, 'inline-manual-before-restore');
        $params['sales']['lines'][0]['quantity_mode'] = 'manual';
        $params['sales']['lines'][0]['price'] = '22';
        $saved = CustomerReportLogic::saveWorkspace($params);
        self::assertNotFalse($saved, CustomerReportLogic::getError());
        self::assertSame('manual', $saved['workspace']['input']['sales']['lines'][0]['quantity_mode'], '相等数量不能丢失人工模式');
        $f['report'] = $saved;
        $params = $this->payload($f, 'inline-restore-actual');
        $params['intent'] = 'save_and_deliver';
        $params['quantities']['groups'][0]['net_weight_input']['value'] = '10.8';
        $params['sales'] = $saved['workspace']['input']['sales'];
        $params['sales']['lines'][0]['quantity_mode'] = 'actual';
        $saved = CustomerReportLogic::saveWorkspace($params);
        self::assertNotFalse($saved, CustomerReportLogic::getError());
        self::assertSame('actual', $saved['sales_document']['lines'][0]['quantity_mode']);
        self::assertSame('10.8000', $saved['sales_document']['lines'][0]['settlement_quantity']);
        self::assertSame('20.00', $saved['sales_document']['lines'][0]['price']);
        self::assertSame('216.00', $saved['sales_document']['amount']);
    }

    public function test_manual_mode_even_with_equal_quantity_requires_weight_adjustment_permission(): void
    {
        $f = $this->fixture();
        request()->adminInfo = array_merge(request()->adminInfo, ['root' => 0]);
        request()->userId = 0;
        request()->todoPermissionSnapshot = ['tenant_id' => self::TENANT_ID, 'operator_id' => self::ADMIN_ID, 'identity' => 'tenant_admin',
            'owner' => false, 'permissions' => ['settlement.bill', 'settlement.weight', 'settlement.view']];
        $params = $this->payload($f, 'inline-manual-owner-only');
        $params['sales']['lines'][0]['quantity_mode'] = 'manual';
        self::assertFalse(CustomerReportLogic::saveWorkspace($params));
        self::assertSame('100.0000', WarehouseSkuBalanceService::onHand($f['warehouse'], $f['sku']));
    }

    public function test_manual_reference_count_unit_requires_real_count_before_delivery(): void
    {
        $f = $this->fixture();
        $count = $this->createCustomerReportUnit('条');
        Db::name('goods_units_binding')->insert(['tenant_id' => self::TENANT_ID, 'goods_id' => $f['report']['items'][0]['goods_id'],
            'unit_id' => $count, 'unit_name' => '条', 'is_base_unit' => 0, 'status' => 1]);
        $params = $this->payload($f, 'inline-missing-reference-count');
        $params['sales']['lines'][0]['quantity_mode'] = 'manual';
        $params['sales']['lines'][0]['reference_price_unit_id'] = $count;
        $missing = CustomerReportLogic::saveWorkspace($params);
        self::assertNotFalse($missing, CustomerReportLogic::getError());
        self::assertSame('incomplete', $missing['workspace']['state']);
        self::assertSame('100.0000', WarehouseSkuBalanceService::onHand($f['warehouse'], $f['sku']));
        $params['idempotency_key'] = 'inline-reference-count-filled';
        $params['workspace_version'] = $missing['workspace']['version'];
        $params['version'] = $missing['version'];
        $params['quantities']['groups'][0]['quantity_facts'] = [['unit_id' => $count, 'quantity' => '3']];
        $params['intent'] = 'save_and_deliver';
        $saved = CustomerReportLogic::saveWorkspace($params);
        self::assertNotFalse($saved, CustomerReportLogic::getError());
        self::assertSame('manual', $saved['sales_document']['lines'][0]['quantity_mode']);
        self::assertSame($count, $saved['sales_document']['lines'][0]['reference_price_unit_id']);
        self::assertSame('200.00', $saved['sales_document']['amount']);
    }

    public function test_manual_reference_price_can_be_unknown_but_invalid_reference_input_is_rejected(): void
    {
        $f = $this->fixture();
        $params = $this->payload($f, 'inline-unknown-reference-price');
        $params['sales']['lines'][0]['quantity_mode'] = 'manual';
        $params['sales']['lines'][0]['reference_price'] = '';
        $params['sales']['lines'][0]['price'] = '22';
        $saved = CustomerReportLogic::saveWorkspace($params);
        self::assertNotFalse($saved, CustomerReportLogic::getError());
        self::assertSame('complete', $saved['workspace']['state']);
        self::assertSame('', $saved['workspace']['input']['sales']['lines'][0]['reference_price']);
        $params['idempotency_key'] = 'inline-invalid-reference-price';
        $params['workspace_version'] = $saved['workspace']['version'];
        $params['version'] = $saved['version'];
        $params['sales']['lines'][0]['reference_price'] = '-1';
        self::assertFalse(CustomerReportLogic::saveWorkspace($params));
        self::assertSame('sales.lines.0.reference_price', CustomerReportLogic::getReturnData()['field_errors'][0]['field']);
    }

    public function test_combined_delivery_without_price_then_price_save_does_not_ship_twice(): void
    {
        $f = $this->fixture(); $params = $this->payload($f);
        $params['intent'] = 'save_and_deliver'; $params['sales']['lines'][0]['price'] = ''; $params['sales']['lines'][0]['reference_price'] = '';
        $saved = CustomerReportLogic::saveWorkspace($params);
        self::assertNotFalse($saved, CustomerReportLogic::getError());
        self::assertNull($saved['sales_document']);
        self::assertSame('90.0000', WarehouseSkuBalanceService::onHand($f['warehouse'], $f['sku']));
        $pricing = ['id' => $saved['id'], 'version' => $saved['version'], 'workspace_version' => $saved['workspace']['version'],
            'idempotency_key' => 'finish-price', 'intent' => 'save', 'sales' => $params['sales']];
        $pricing['sales']['lines'][0]['price'] = '20'; $pricing['sales']['lines'][0]['reference_price'] = '20';
        $priced = CustomerReportLogic::saveWorkspace($pricing);
        self::assertNotFalse($priced, CustomerReportLogic::getError());
        self::assertSame('posted', $priced['sales_document']['accounting_status']);
        self::assertSame('90.0000', WarehouseSkuBalanceService::onHand($f['warehouse'], $f['sku']));
    }

    public function test_plain_save_restores_pricing_without_issuing_before_delivery(): void
    {
        $f = $this->fixture(); $saved = CustomerReportLogic::saveWorkspace($this->payload($f));
        self::assertNotFalse($saved, CustomerReportLogic::getError());
        self::assertNull($saved['sales_document']);
        self::assertSame('20', $saved['workspace']['input']['sales']['lines'][0]['price']);
        self::assertSame('100.0000', WarehouseSkuBalanceService::onHand($f['warehouse'], $f['sku']));
    }

    public function test_ticket_goods_name_survives_incomplete_save_and_posts_into_the_customer_sales_output(): void
    {
        $fixture = $this->fixture();
        $original = $fixture['report']['items'][0];
        $params = $this->payload($fixture);
        $params['sales']['lines'][0]['price'] = '';
        $params['sales']['lines'][0]['reference_price'] = '';
        $params['sales']['lines'][0]['ticket_goods_name'] = '　鲜活鳜鱼 ';
        $saved = CustomerReportLogic::saveWorkspace($params);
        self::assertNotFalse($saved, CustomerReportLogic::getError());
        self::assertSame('incomplete', $saved['workspace']['state']);

        $fresh = CustomerReportLogic::detail(['id' => $saved['id']]);
        self::assertSame('鲜活鳜鱼', $fresh['workspace']['ticket_goods_names'][$original['id']] ?? null);
        self::assertSame($original['goods_name'], $fresh['items'][0]['goods_name']);
        self::assertSame($original['goods_id'], $fresh['items'][0]['goods_id']);
        self::assertSame($original['sku_id'], $fresh['items'][0]['sku_id']);
        self::assertSame('100.0000', WarehouseSkuBalanceService::onHand($fixture['warehouse'], $fixture['sku']));

        $fixture['report'] = $fresh;
        $delivery = $this->payload($fixture, 'workspace-deliver-ticket-name');
        unset($delivery['quantities']);
        $delivery['intent'] = 'save_and_deliver';
        $invalid = $delivery;
        $invalid['sales']['lines'][0]['ticket_goods_name'] = "鳜鱼\x1b@";
        self::assertFalse(CustomerReportLogic::saveWorkspace($invalid));
        self::assertStringContainsString('控制字符', CustomerReportLogic::getError());
        self::assertSame('100.0000', WarehouseSkuBalanceService::onHand($fixture['warehouse'], $fixture['sku']));
        $issued = CustomerReportLogic::saveWorkspace($delivery);
        self::assertNotFalse($issued, CustomerReportLogic::getError());
        $document = $issued['sales_document'];
        self::assertSame('posted', $document['accounting_status'], $document['blocked_reason']);
        self::assertSame('200.00', $document['amount']);
        self::assertSame('鲜活鳜鱼', $document['lines'][0]['ticket_goods_name'] ?? null);
        self::assertSame($original['goods_name'], $document['lines'][0]['goods_name']);
        self::assertSame('90.0000', WarehouseSkuBalanceService::onHand($fixture['warehouse'], $fixture['sku']));
        $output = FinanceSalesOutput::document(['id' => $document['finance_document_id']]);
        self::assertSame($document['order_sn'], $output['order_sn']);
        self::assertSame('鲜活鳜鱼', $output['lines'][0]['name']);
        self::assertSame('200.00', $output['order_money']);
    }

    public function test_ticket_goods_name_alone_creates_a_revision_and_posting_locks_it(): void
    {
        $fixture = $this->fixture();
        $delivery = $this->payload($fixture);
        $delivery['intent'] = 'save_and_deliver';
        $delivery['sales']['lines'][0]['price'] = '';
        $delivery['sales']['lines'][0]['reference_price'] = '';
        $delivered = CustomerReportLogic::saveWorkspace($delivery);
        self::assertNotFalse($delivered, CustomerReportLogic::getError());
        self::assertNull($delivered['sales_document']);

        $fixture['report'] = $delivered;
        $issue = $this->payload($fixture, 'issue-ticket-name');
        unset($issue['quantities']);
        $issue['sales']['lines'][0]['ticket_goods_name'] = '001';
        Db::name('finance_preparation')->where('tenant_id', self::TENANT_ID)->update(['activation_date' => date('Y-m-d', strtotime('+1 day'))]);
        $issued = CustomerReportLogic::saveWorkspace($issue);
        self::assertNotFalse($issued, CustomerReportLogic::getError());
        self::assertSame('review_required', $issued['sales_document']['accounting_status']);
        $original = $issued['sales_document'];

        // A saved version can still be awaiting a posting retry.
        Db::name('customer_report_sales_document')->where('tenant_id', self::TENANT_ID)->where('id', $original['id'])
            ->update(['accounting_status' => 'pending_posting']);
        Db::name('finance_preparation')->where('tenant_id', self::TENANT_ID)->update(['activation_date' => date('Y-m-01')]);
        $fixture['report'] = $issued;
        $revision = $this->payload($fixture, 'revise-ticket-name');
        unset($revision['quantities']);
        $revision['sales']['lines'][0]['ticket_goods_name'] = '1';
        $saved = CustomerReportLogic::saveWorkspace($revision);
        self::assertNotFalse($saved, CustomerReportLogic::getError());
        $document = $saved['sales_document'];
        self::assertSame($original['order_sn'], $document['order_sn']);
        self::assertSame($original['version'] + 1, $document['version']);
        self::assertSame($original['amount'], $document['amount']);
        self::assertSame('001', $document['revisions'][1]['lines'][0]['ticket_goods_name']);
        self::assertSame('1', $document['lines'][0]['ticket_goods_name']);
        self::assertSame('posted', $document['accounting_status'], $document['blocked_reason']);
        self::assertFalse($saved['workspace']['can_save_sales']);

        $revision['idempotency_key'] = 'stale-ticket-name';
        $revision['sales']['lines'][0]['ticket_goods_name'] = '鳜鱼';
        self::assertFalse(CustomerReportLogic::saveWorkspace($revision));
        self::assertStringContainsString('版本', CustomerReportLogic::getError());
        $revision['version'] = $saved['version'];
        $revision['workspace_version'] = $saved['workspace']['version'];
        $revision['sales']['document_version'] = $document['version'];
        self::assertFalse(CustomerReportLogic::saveWorkspace($revision));
        self::assertStringContainsString('已入账', CustomerReportLogic::getError());
        self::assertSame('1', FinanceSalesOutput::document(['id' => $document['finance_document_id']])['lines'][0]['name']);
    }

    public function test_delivery_rejection_rolls_back_workspace_and_quantity(): void
    {
        $f = $this->fixture(); $params = $this->payload($f); $params['intent'] = 'save_and_deliver';
        Db::name('fulfillment_task')->where('report_id', $f['report']['id'])->update(['status' => 'cancelled']);
        self::assertFalse(CustomerReportLogic::saveWorkspace($params));
        self::assertSame(0, Db::name('customer_report_workspace_save')->where('report_id', $f['report']['id'])->count());
        self::assertSame('100.0000', WarehouseSkuBalanceService::onHand($f['warehouse'], $f['sku']));
    }

    private function deliverAll(array $report, string $key, string $remaining = 'none'): void
    {
        $task = array_values(array_filter($report['task_group']['tasks'], static fn($task) => $task['source_key'] === 'report:' . $report['id'] . ':delivery'))[0];
        $group = $report['items'][0]['processing_groups'][0];
        $result = DeliveryInventoryLogic::confirmSelfDelivery(['task_id' => (int)$task['id'], 'idempotency_key' => $key, 'event_type' => 'customer_handoff',
            'items' => [['report_item_id' => (int)$report['items'][0]['id'], 'delivery_group_ids' => [(int)$group['id']], 'facts_version' => (int)$group['version'], 'remaining_action' => $remaining]]]);
        self::assertNotFalse($result, DeliveryInventoryLogic::getError());
    }

    private function reportFinanceTodos(int $reportId): array
    {
        $list = TodoQueryLogic::lists(['branch' => 'finance', 'page_size' => 100]);
        self::assertNotFalse($list, TodoQueryLogic::getError());
        return array_values(array_filter($list['items'], static fn($item) => ($item['target']['type'] ?? '') === 'report' && (int)($item['target']['params']['id'] ?? 0) === $reportId));
    }

    private function weightDemandFixture(): array
    {
        $f = $this->fixture();
        $count = $this->createCustomerReportUnit('条');
        $item = $f['report']['items'][0];
        Db::name('goods_units_binding')->insert(['tenant_id' => self::TENANT_ID, 'goods_id' => $item['goods_id'], 'unit_id' => $count, 'unit_name' => '条', 'is_base_unit' => 0, 'status' => 1]);
        Db::name('customer_report_item')->where('id', $item['id'])->update(['unit_id' => $count, 'unit_name' => '条', 'pricing_unit_id' => $f['unit'], 'pricing_unit_name' => '斤']);
        $f['report'] = CustomerReportLogic::detail(['id' => $f['report']['id']]);
        return $f;
    }

    public static function weightTargets(): array { return ['unknown remaining weight' => [false], 'explicit ten jin target' => [true]]; }



    public function test_missing_price_is_saved_on_server_without_becoming_zero_price(): void
    {
        $fixture = $this->fixture();
        $params = $this->payload($fixture);
        $params['sales']['lines'][0]['price'] = ''; $params['sales']['lines'][0]['reference_price'] = '';
        $saved = CustomerReportLogic::saveWorkspace($params);
        self::assertNotFalse($saved, CustomerReportLogic::getError());
        self::assertSame('incomplete', $saved['save_result']['state']);
        self::assertNull($saved['sales_document']);
        $fresh = CustomerReportLogic::detail(['id' => $saved['id']]);
        self::assertSame('incomplete', $fresh['workspace']['state']);
        self::assertSame('', $fresh['workspace']['input']['sales']['lines'][0]['price']);
        self::assertNotEmpty($fresh['workspace']['missing_fields']);
        self::assertSame('10.0000', $fresh['items'][0]['processing_groups'][0]['inventory_base_quantity']);
        self::assertSame('100.0000', WarehouseSkuBalanceService::onHand($fixture['warehouse'], $fixture['sku']));
    }

    public function test_invalid_price_rolls_back_the_quantity_in_the_same_save(): void
    {
        $fixture = $this->fixture();
        $params = $this->payload($fixture);
        $params['sales']['lines'][0]['price'] = '-2';
        self::assertFalse(CustomerReportLogic::saveWorkspace($params));
        $fresh = CustomerReportLogic::detail(['id' => $fixture['report']['id']]);
        self::assertNotFalse($fresh, CustomerReportLogic::getError());
        self::assertSame($fixture['report']['version'], $fresh['version']);
        self::assertNull($fresh['items'][0]['processing_groups'][0]['inventory_base_quantity'] ?? null);
        self::assertNull($fresh['sales_document']);
        self::assertSame(0, $fresh['workspace']['version']);
        self::assertSame('100.0000', WarehouseSkuBalanceService::onHand($fixture['warehouse'], $fixture['sku']));
    }




    public function test_quantity_only_save_preserves_an_existing_sales_draft_and_cannot_issue_prices(): void
    {
        $fixture = $this->fixture();
        $params = $this->payload($fixture);
        $params['sales']['lines'][0]['price'] = ''; $params['sales']['lines'][0]['reference_price'] = '';
        $draft = CustomerReportLogic::saveWorkspace($params);
        self::assertNotFalse($draft, CustomerReportLogic::getError());
        $fixture['report'] = $draft;
        $params = $this->payload($fixture, 'workspace-weight-only');
        unset($params['sales']);
        $params['quantities']['groups'][0]['net_weight_input']['value'] = '9';
        request()->todoPermissionSnapshot = ['tenant_id' => self::TENANT_ID, 'operator_id' => self::ADMIN_ID, 'identity' => 'tenant_admin', 'owner' => false, 'permissions' => ['settlement.weight', 'settlement.view']];
        $saved = CustomerReportLogic::saveWorkspace($params);
        self::assertNotFalse($saved, CustomerReportLogic::getError());
        self::assertNull($saved['sales_document']);
        self::assertArrayNotHasKey('sales', $saved['workspace']['input']);
        self::assertSame('9.0000', $saved['items'][0]['processing_groups'][0]['inventory_base_quantity']);
        $fixture['report'] = $saved;
        self::assertFalse(CustomerReportLogic::saveWorkspace($this->payload($fixture, 'workspace-weight-cannot-bill')));
        request()->todoPermissionSnapshot = null;
        $fresh = CustomerReportLogic::detail(['id' => $draft['id']]);
        self::assertSame('incomplete', $fresh['workspace']['state']);
        self::assertSame('', $fresh['workspace']['input']['sales']['lines'][0]['price']);
        self::assertSame('10.0000', $fresh['workspace']['input']['sales']['lines'][0]['settlement_quantity']);
    }

    public function test_counted_base_units_save_without_weight_and_pricing_count_cannot_differ(): void
    {
        $fixture = $this->fixture('瓶');
        $params = $this->payload($fixture);
        unset($params['quantities']['groups'][0]['net_weight_input']);
        $params['quantities']['groups'][0]['quantity_facts'] = [['unit_id' => $fixture['unit'], 'quantity' => '10']];
        $saved = CustomerReportLogic::saveWorkspace($params);
        self::assertNotFalse($saved, CustomerReportLogic::getError());
        self::assertSame('complete', $saved['workspace']['state']);
        self::assertSame('10.0000', $saved['items'][0]['processing_groups'][0]['inventory_base_quantity']);
        self::assertNull($saved['items'][0]['processing_groups'][0]['actual_delivery_weight']);
        self::assertNull($saved['sales_document']);
        $fixture['report'] = $saved;
        $params = $this->payload($fixture, 'workspace-invalid-count');
        unset($params['quantities']);
        $params['sales']['lines'][0]['settlement_quantity'] = '9'; $params['sales']['lines'][0]['quantity_mode'] = 'manual';
        self::assertFalse(CustomerReportLogic::saveWorkspace($params));
        self::assertStringContainsString('数量', CustomerReportLogic::getError());
    }



    public function test_stale_or_other_tenant_workspace_cannot_overwrite_saved_sales(): void
    {
        $fixture = $this->fixture();
        $params = $this->payload($fixture);
        $saved = CustomerReportLogic::saveWorkspace($params);
        self::assertNotFalse($saved, CustomerReportLogic::getError());
        $params['idempotency_key'] = 'workspace-stale-version';
        $params['sales']['lines'][0]['price'] = '21';
        self::assertFalse(CustomerReportLogic::saveWorkspace($params));
        $this->prepareCustomerReportRequestContext(self::OTHER_TENANT_ID);
        $params['idempotency_key'] = 'workspace-other-tenant';
        self::assertFalse(CustomerReportLogic::saveWorkspace($params));
        $this->prepareCustomerReportRequestContext();
        $fresh = CustomerReportLogic::detail(['id' => $saved['id']]);
        self::assertNull($fresh['sales_document']);
        self::assertSame('20', $fresh['workspace']['input']['sales']['lines'][0]['price']);
        self::assertSame(1, $fresh['workspace']['version']);
    }

    public function test_authoritative_delivered_facts_can_complete_a_draft_with_a_cleared_weight_without_rewriting_delivery(): void
    {
        $fixture = $this->fixture();
        $issued = CustomerReportLogic::saveWorkspace($this->payload($fixture));
        self::assertNotFalse($issued, CustomerReportLogic::getError());
        $originalFact = $issued['items'][0]['processing_groups'][0]['quantity_facts_snapshot'];
        $fixture['report'] = $issued;
        $params = $this->payload($fixture, 'workspace-cleared-weight');
        $params['quantities']['groups'][0]['net_weight_input']['value'] = '';
        $incomplete = CustomerReportLogic::saveWorkspace($params);
        self::assertNotFalse($incomplete, CustomerReportLogic::getError());
        self::assertSame('incomplete', $incomplete['workspace']['state']);
        self::assertSame('10.0000', $incomplete['items'][0]['processing_groups'][0]['actual_delivery_weight']);

        $fixture['report'] = $incomplete;
        $salesOnly = $this->payload($fixture, 'workspace-still-unresolved-weight');
        unset($salesOnly['quantities']);
        $stillIncomplete = CustomerReportLogic::saveWorkspace($salesOnly);
        self::assertNotFalse($stillIncomplete, CustomerReportLogic::getError());
        self::assertSame('incomplete', $stillIncomplete['workspace']['state'], '未交组的空白输入不得因只保存计价而被自动清除');
        self::assertNotEmpty($stillIncomplete['workspace']['missing_fields']);

        $this->deliverAll($stillIncomplete, 'workspace-deliver-authoritative-weight');
        $delivered = CustomerReportLogic::detail(['id' => $issued['id']]);
        self::assertFalse($delivered['workspace']['can_save_quantities']);
        self::assertSame('incomplete', $delivered['workspace']['state']);
        $fixture['report'] = $delivered;
        $salesOnly = $this->payload($fixture, 'workspace-confirm-authoritative-weight');
        unset($salesOnly['quantities']);
        $completed = CustomerReportLogic::saveWorkspace($salesOnly);
        self::assertNotFalse($completed, CustomerReportLogic::getError());
        self::assertSame('complete', $completed['workspace']['state']);
        self::assertSame([], $completed['workspace']['missing_fields']);
        self::assertSame(1, $completed['sales_document']['version']);
        self::assertSame('posted', $completed['sales_document']['accounting_status'], $completed['sales_document']['blocked_reason']);
        self::assertSame($originalFact, $completed['items'][0]['processing_groups'][0]['quantity_facts_snapshot']);
        self::assertSame('90.0000', WarehouseSkuBalanceService::onHand($fixture['warehouse'], $fixture['sku']));
    }

    public function test_unpriced_workspace_after_whole_delivery_has_one_continue_todo_instead_of_an_extra_settlement_todo(): void
    {
        $fixture = $this->fixture();
        $params = $this->payload($fixture, 'workspace-measure-before-delivery');
        unset($params['sales']);
        $measured = CustomerReportLogic::saveWorkspace($params);
        self::assertNotFalse($measured, CustomerReportLogic::getError());
        $this->deliverAll($measured, 'workspace-deliver-before-pricing');
        $delivered = CustomerReportLogic::detail(['id' => $measured['id']]);
        self::assertNull($delivered['sales_document']);
        $fixture['report'] = $delivered;
        $params = $this->payload($fixture, 'workspace-unpriced-after-delivery');
        unset($params['quantities']);
        $params['sales']['lines'][0]['price'] = ''; $params['sales']['lines'][0]['reference_price'] = '';
        $incomplete = CustomerReportLogic::saveWorkspace($params);
        self::assertNotFalse($incomplete, CustomerReportLogic::getError());
        self::assertSame('incomplete', $incomplete['workspace']['state']);
        $list = TodoQueryLogic::lists(['branch' => 'finance', 'page_size' => 100]);
        self::assertNotFalse($list, TodoQueryLogic::getError());
        $pricingTodos = array_values(array_filter($list['items'], static fn($item) => in_array($item['source']['code'] ?? '', ['S06', 'S27'], true)));
        self::assertCount(1, $pricingTodos, '一张报货的未完成计价只应提供一个继续填写入口');
        self::assertSame('S27', $pricingTodos[0]['source']['code']);
        self::assertSame('sales_workspace', $pricingTodos[0]['target']['params']['action']);
        self::assertSame((int)$incomplete['id'], $pricingTodos[0]['target']['params']['id']);
    }
}
