<?php
declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\CustomerReportLogic;
use app\api\jxc\logic\DeliveryInventoryLogic;
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
            'sales' => ['document_version' => $report['sales_document']['version'] ?? 0, 'lines' => [['report_item_id' => $report['items'][0]['id'], 'price_unit_id' => $fixture['unit'], 'settlement_quantity' => '10', 'price' => '20']], 'rounding_amount' => '0']];
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

    public function test_complete_save_persists_quantity_and_issues_sales_document_together(): void
    {
        $fixture = $this->fixture();
        $saved = CustomerReportLogic::saveWorkspace($this->payload($fixture));
        self::assertNotFalse($saved, CustomerReportLogic::getError());
        self::assertSame('complete', $saved['save_result']['state']);
        self::assertSame('200.00', $saved['sales_document']['amount']);
        self::assertSame('pending_delivery', $saved['sales_document']['accounting_status']);
        $fresh = CustomerReportLogic::detail(['id' => $saved['id']]);
        self::assertNotFalse($fresh, CustomerReportLogic::getError());
        self::assertSame('10.0000', $fresh['items'][0]['processing_groups'][0]['inventory_base_quantity']);
        self::assertSame('complete', $fresh['workspace']['state']);
        self::assertSame('100.0000', WarehouseSkuBalanceService::onHand($fixture['warehouse'], $fixture['sku']));
    }

    public function test_missing_price_is_saved_on_server_without_becoming_zero_price(): void
    {
        $fixture = $this->fixture();
        $params = $this->payload($fixture);
        $params['sales']['lines'][0]['price'] = '';
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

    public function test_retry_and_unchanged_save_do_not_create_another_amount_version(): void
    {
        $fixture = $this->fixture();
        $params = $this->payload($fixture);
        $saved = CustomerReportLogic::saveWorkspace($params);
        self::assertNotFalse($saved, CustomerReportLogic::getError());
        $replayed = CustomerReportLogic::saveWorkspace($params);
        self::assertNotFalse($replayed, CustomerReportLogic::getError());
        self::assertTrue($replayed['save_result']['replayed']);
        self::assertSame($saved['sales_document']['id'], $replayed['sales_document']['id']);
        self::assertSame(1, $replayed['sales_document']['version']);
        $params['sales']['lines'][0]['price'] = '21';
        self::assertFalse(CustomerReportLogic::saveWorkspace($params));

        $fixture['report'] = $saved;
        $unchanged = CustomerReportLogic::saveWorkspace($this->payload($fixture, 'workspace-unchanged'));
        self::assertNotFalse($unchanged, CustomerReportLogic::getError());
        self::assertSame('200.00', $unchanged['sales_document']['amount']);
        self::assertSame(1, $unchanged['sales_document']['version']);
        self::assertCount(1, $unchanged['sales_document']['revisions']);
    }

    public function test_all_three_missing_notes_allow_effective_revision_and_posting_then_supplement_without_new_amount_version(): void
    {
        $fixture = $this->fixture();
        $issued = CustomerReportLogic::saveWorkspace($this->payload($fixture));
        self::assertNotFalse($issued, CustomerReportLogic::getError());
        $fixture['report'] = $issued;
        $params = $this->payload($fixture, 'workspace-free-revision');
        unset($params['quantities']);
        $params['sales']['lines'][0]['settlement_quantity'] = '9';
        $params['sales']['lines'][0]['price'] = '0';
        $revised = CustomerReportLogic::saveWorkspace($params);
        self::assertNotFalse($revised, CustomerReportLogic::getError());
        self::assertSame('complete', $revised['workspace']['state']);
        self::assertSame(2, $revised['sales_document']['version']);
        self::assertSame('0.00', $revised['sales_document']['amount']);
        self::assertTrue($revised['sales_document']['can_print']);
        self::assertTrue($revised['sales_document']['can_share']);
        self::assertCount(3, $revised['workspace']['pending_notes']);
        $todos = $this->reportFinanceTodos((int)$revised['id']);
        self::assertCount(1, $todos);
        self::assertSame('sales_notes', $todos[0]['target']['params']['action']);

        $this->deliverAll($revised, 'workspace-free-delivery');
        $posted = CustomerReportLogic::detail(['id' => $revised['id']]);
        self::assertSame('posted', $posted['sales_document']['accounting_status'], $posted['sales_document']['blocked_reason']);
        self::assertSame('90.0000', WarehouseSkuBalanceService::onHand($fixture['warehouse'], $fixture['sku']));
        self::assertCount(1, $this->reportFinanceTodos((int)$revised['id']));
        $history = $posted['sales_document']['revisions'];
        $notes = array_map(static fn($note) => ['revision_id' => $note['revision_id'], 'report_item_id' => $note['report_item_id'], 'type' => $note['type'], 'content' => '客户确认本次赠送及重量约定'], $posted['workspace']['pending_notes']);
        $supplement = ['id' => $posted['id'], 'idempotency_key' => 'workspace-supplement', 'notes' => $notes];
        $saved = CustomerReportLogic::supplementSalesNotes($supplement);
        self::assertNotFalse($saved, CustomerReportLogic::getError());
        self::assertSame([], $saved['workspace']['pending_notes']);
        self::assertSame([], $this->reportFinanceTodos((int)$saved['id']));
        self::assertSame(2, $saved['sales_document']['version']);
        self::assertSame('posted', $saved['sales_document']['accounting_status']);
        self::assertSame('0.00', $saved['sales_document']['amount']);
        self::assertSame(array_column($history, 'revision_reason'), array_column($saved['sales_document']['revisions'], 'revision_reason'));
        $replay = CustomerReportLogic::supplementSalesNotes($supplement);
        self::assertNotFalse($replay, CustomerReportLogic::getError());
        self::assertSame([], $replay['workspace']['pending_notes']);
        $supplement['notes'][0]['content'] = '改变同一请求内容';
        self::assertFalse(CustomerReportLogic::supplementSalesNotes($supplement));
    }

    public function test_incomplete_revision_stops_output_and_posting_but_not_delivery_and_restoring_original_values_does_not_revise(): void
    {
        $fixture = $this->fixture();
        $issued = CustomerReportLogic::saveWorkspace($this->payload($fixture));
        self::assertNotFalse($issued, CustomerReportLogic::getError());
        $fixture['report'] = $issued;
        $params = $this->payload($fixture, 'workspace-incomplete-revision');
        unset($params['quantities']);
        $params['sales']['lines'][0]['price'] = '';
        $incomplete = CustomerReportLogic::saveWorkspace($params);
        self::assertNotFalse($incomplete, CustomerReportLogic::getError());
        self::assertFalse($incomplete['sales_document']['can_print']);
        self::assertFalse($incomplete['sales_document']['can_share']);
        self::assertSame('200.00', $incomplete['sales_document']['amount']);
        self::assertSame(1, $incomplete['sales_document']['version']);
        $this->deliverAll($incomplete, 'workspace-incomplete-delivery');
        \app\api\jxc\logic\CustomerReportSalesDocument::afterReportChange((int)$issued['id']);
        $fresh = CustomerReportLogic::detail(['id' => $issued['id']]);
        self::assertNotSame('posted', $fresh['sales_document']['accounting_status']);
        self::assertSame('incomplete', $fresh['workspace']['state']);
        self::assertSame('90.0000', WarehouseSkuBalanceService::onHand($fixture['warehouse'], $fixture['sku']));
        $todos = $this->reportFinanceTodos((int)$fresh['id']);
        self::assertCount(1, $todos, '未完成修订只应聚合一条销售完善待办');
        self::assertSame('S27', $todos[0]['source']['code']);
        self::assertSame('sales_workspace', $todos[0]['target']['params']['action']);

        $fixture['report'] = $fresh;
        $params = $this->payload($fixture, 'workspace-restore-original');
        unset($params['quantities']);
        $complete = CustomerReportLogic::saveWorkspace($params);
        self::assertNotFalse($complete, CustomerReportLogic::getError());
        self::assertSame('complete', $complete['workspace']['state']);
        self::assertSame(1, $complete['sales_document']['version']);
        self::assertCount(1, $complete['sales_document']['revisions']);
        self::assertTrue($complete['sales_document']['can_print']);
        self::assertSame('posted', $complete['sales_document']['accounting_status'], $complete['sales_document']['blocked_reason']);
        self::assertSame('200.00', $complete['sales_document']['amount']);
    }

    public function test_quantity_only_save_preserves_an_existing_sales_draft_and_cannot_issue_prices(): void
    {
        $fixture = $this->fixture();
        $params = $this->payload($fixture);
        $params['sales']['lines'][0]['price'] = '';
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
        self::assertSame('10', $fresh['workspace']['input']['sales']['lines'][0]['settlement_quantity']);
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
        self::assertSame('200.00', $saved['sales_document']['amount']);
        $fixture['report'] = $saved;
        $params = $this->payload($fixture, 'workspace-invalid-count');
        unset($params['quantities']);
        $params['sales']['lines'][0]['settlement_quantity'] = '9';
        self::assertFalse(CustomerReportLogic::saveWorkspace($params));
        self::assertStringContainsString('数量', CustomerReportLogic::getError());
    }

    public function test_restoring_incomplete_supplement_uses_the_saved_group_instead_of_appending_twice(): void
    {
        $fixture = $this->fixture('瓶');
        $params = $this->payload($fixture);
        unset($params['quantities']['groups'][0]['net_weight_input']);
        $params['quantities']['groups'][0]['quantity_facts'] = [['unit_id' => $fixture['unit'], 'quantity' => '4']];
        $params['sales']['lines'][0]['settlement_quantity'] = '4';
        $issued = CustomerReportLogic::saveWorkspace($params);
        self::assertNotFalse($issued, CustomerReportLogic::getError());
        $this->deliverAll($issued, 'workspace-first-partial');
        $fresh = CustomerReportLogic::detail(['id' => $issued['id']]);
        $fixture['report'] = $fresh;
        $params = $this->payload($fixture, 'workspace-supplement-incomplete');
        $source = $fresh['items'][0]['processing_groups'][0]['id'];
        $params['quantities']['groups'] = [['append_to_group_id' => $source, 'quantity_facts' => [['unit_id' => $fixture['unit'], 'quantity' => '6']]]];
        $params['sales']['lines'][0]['price'] = '';
        $incomplete = CustomerReportLogic::saveWorkspace($params);
        self::assertNotFalse($incomplete, CustomerReportLogic::getError());
        self::assertCount(2, $incomplete['items'][0]['processing_groups']);
        $fresh = CustomerReportLogic::detail(['id' => $issued['id']]);
        $restored = $fresh['workspace']['input'];
        self::assertGreaterThan(0, $restored['quantities']['groups'][0]['id'] ?? 0);
        self::assertArrayNotHasKey('append_to_group_id', $restored['quantities']['groups'][0]);
        $restored['sales']['lines'][0]['price'] = '20';
        $restored['sales']['document_version'] = $fresh['sales_document']['version'];
        $complete = CustomerReportLogic::saveWorkspace($restored + ['id' => $fresh['id'], 'version' => $fresh['version'], 'workspace_version' => $fresh['workspace']['version'], 'idempotency_key' => 'workspace-supplement-finish']);
        self::assertNotFalse($complete, CustomerReportLogic::getError());
        self::assertSame('complete', $complete['workspace']['state']);
        self::assertCount(2, $complete['items'][0]['processing_groups']);
        self::assertSame('200.00', $complete['sales_document']['amount']);
        self::assertSame('10.0000', $complete['sales_document']['lines'][0]['settlement_quantity']);
        self::assertSame('96.0000', WarehouseSkuBalanceService::onHand($fixture['warehouse'], $fixture['sku']));
    }

    public function test_filling_a_note_through_unified_save_keeps_the_amount_version(): void
    {
        $fixture = $this->fixture();
        $params = $this->payload($fixture);
        $params['sales']['lines'][0]['price'] = '0';
        $issued = CustomerReportLogic::saveWorkspace($params);
        self::assertNotFalse($issued, CustomerReportLogic::getError());
        self::assertCount(1, $issued['workspace']['pending_notes']);
        $fixture['report'] = $issued;
        $params = $this->payload($fixture, 'workspace-inline-note');
        unset($params['quantities']);
        $params['sales']['lines'][0]['price'] = '0';
        $params['sales']['lines'][0]['zero_price_reason'] = '客户赠送';
        $saved = CustomerReportLogic::saveWorkspace($params);
        self::assertNotFalse($saved, CustomerReportLogic::getError());
        self::assertSame(1, $saved['sales_document']['version']);
        self::assertCount(1, $saved['sales_document']['revisions']);
        self::assertSame([], $saved['workspace']['pending_notes']);
        self::assertSame('', $saved['sales_document']['revisions'][0]['lines'][0]['zero_price_reason'], '原始历史快照不可被补说明覆盖');
        self::assertSame('客户赠送', $saved['sales_document']['revisions'][0]['supplemental_notes'][0]['content']);
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
        self::assertSame('200.00', $fresh['sales_document']['amount']);
        self::assertSame(1, $fresh['sales_document']['version']);
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
        $params['sales']['lines'][0]['price'] = '';
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
