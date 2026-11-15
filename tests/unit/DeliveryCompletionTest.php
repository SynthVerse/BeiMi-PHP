<?php
declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\CustomerReportLogic;
use app\api\jxc\logic\DeliveryCompletionService;
use app\api\jxc\logic\DeliveryInventoryLogic;
use app\api\jxc\logic\WarehouseSkuBalanceService;
use app\api\jxc\logic\WorkforceLogic;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

require_once __DIR__ . '/CustomerReportTestSupport.php';

final class DeliveryCompletionTest extends TestCase
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
    }

    private function fixture(bool $deliver = true): array
    {
        $unit = $this->createCustomerReportUnit('斤');
        $goods = $this->createCustomerReportGoods('重量履约结案商品', 'DELIVERY-COMPLETE', '斤');
        $sku = $this->customerReportSkuId($goods);
        $warehouse = $this->createCustomerReportWarehouse('重量履约结案仓');
        Db::name('goods')->where('id', $goods)->update(['unit_id' => $unit]);
        Db::name('goods_sku')->where('id', $sku)->update(['base_unit_id' => $unit, 'base_unit_name' => '斤']);
        Db::name('goods_units_binding')->insert(['tenant_id' => self::TENANT_ID, 'goods_id' => $goods, 'unit_id' => $unit, 'unit_name' => '斤', 'is_base_unit' => 1, 'status' => 1]);
        WarehouseSkuBalanceService::inbound($warehouse, $sku, '100');
        $customer = $this->createCustomer('重量履约结案客户');
        $process = (int)Db::name('work_process')->where('tenant_id', self::TENANT_ID)->where('trigger_type', 'report_selection')->value('id');
        $report = CustomerReportLogic::submit(['quantity_contract_version' => 2, 'main_customer_id' => $customer, 'delivery_date' => date('Y-m-d'), 'delivery_arrangement' => ['delivery_method' => 'self_delivery'], 'idempotency_key' => 'completion-report',
            'items' => [['goods_id' => $goods, 'sku_id' => $sku, 'warehouse_id' => $warehouse, 'unit_id' => $unit, 'unit_name' => '斤', 'order_qty' => '10', 'price_status' => 'unpriced',
                'processing_groups' => [['group_key' => 'whole', 'planned_qty' => '10', 'process_ids' => [$process]]]]]]);
        self::assertNotFalse($report, CustomerReportLogic::getError());
        $count = $this->createCustomerReportUnit('条');
        Db::name('goods_units_binding')->insert(['tenant_id' => self::TENANT_ID, 'goods_id' => $goods, 'unit_id' => $count, 'unit_name' => '条', 'is_base_unit' => 0, 'status' => 1]);
        Db::name('customer_report_item')->where('id', $report['items'][0]['id'])->update(['unit_id' => $count, 'unit_name' => '条', 'pricing_unit_id' => $unit, 'pricing_unit_name' => '斤']);
        $report = CustomerReportLogic::detail(['id' => $report['id']]);
        $report = CustomerReportLogic::saveWorkspace(['id' => $report['id'], 'version' => $report['version'], 'workspace_version' => $report['workspace']['version'], 'idempotency_key' => 'completion-measure',
            'quantities' => ['groups' => [['id' => $report['items'][0]['processing_groups'][0]['id'], 'net_weight_input' => ['value' => '6', 'unit' => '斤'], 'quantity_facts' => []]]],
            'sales' => ['document_version' => 0, 'lines' => [['report_item_id' => $report['items'][0]['id'], 'price_unit_id' => $unit, 'settlement_quantity' => '6', 'price' => '20']], 'rounding_amount' => '0']]);
        self::assertNotFalse($report, CustomerReportLogic::getError());
        if ($deliver) {
            $task = array_values(array_filter($report['task_group']['tasks'], static fn($task) => $task['source_key'] === 'report:' . $report['id'] . ':delivery'))[0];
            $group = $report['items'][0]['processing_groups'][0];
            $event = DeliveryInventoryLogic::confirmSelfDelivery(['task_id' => $task['id'], 'idempotency_key' => 'completion-deliver-six', 'event_type' => 'customer_handoff',
                'items' => [['report_item_id' => $report['items'][0]['id'], 'delivery_group_ids' => [$group['id']], 'facts_version' => $group['version'], 'remaining_action' => 'pending']]]);
            self::assertNotFalse($event, DeliveryInventoryLogic::getError());
            $report = CustomerReportLogic::detail(['id' => $report['id']]);
            self::assertSame('partially_delivered_pending', $report['status']);
        }
        return compact('report', 'unit', 'sku', 'warehouse');
    }

    private function command(array $report, string $key = 'completion-end'): array
    {
        return ['report_id' => $report['id'], 'version' => $report['version'], 'item_ids' => [$report['items'][0]['id']], 'idempotency_key' => $key];
    }

    private function closureEvents(int $reportId): array
    {
        return Db::name('fulfillment_delivery_event')->where('tenant_id', self::TENANT_ID)->where('report_id', $reportId)->where('event_type', 'fulfillment_completed')->order('id')->select()->toArray();
    }

    public function test_confirming_finished_after_six_jin_changes_no_stock_or_delivery_and_posts_once(): void
    {
        $f = $this->fixture();
        $command = $this->command($f['report']);
        $beforeItems = Db::name('fulfillment_delivery_item')->where('tenant_id', self::TENANT_ID)->where('report_id', $f['report']['id'])->select()->toArray();
        $done = DeliveryCompletionService::complete($command);
        self::assertNotFalse($done, DeliveryCompletionService::getError());
        self::assertSame($f['report']['id'], $done['id']);
        self::assertSame('6.0000', $done['items'][0]['fulfilled_base_qty']);
        self::assertSame('posted', $done['sales_document']['accounting_status'], $done['sales_document']['blocked_reason']);
        self::assertSame('120.00', $done['sales_document']['amount']);
        self::assertGreaterThan(0, $done['sales_document']['finance_document_id']);
        self::assertSame('94.0000', WarehouseSkuBalanceService::onHand($f['warehouse'], $f['sku']));
        self::assertSame($beforeItems, Db::name('fulfillment_delivery_item')->where('tenant_id', self::TENANT_ID)->where('report_id', $done['id'])->select()->toArray());
        $events = $this->closureEvents((int)$done['id']);
        self::assertCount(1, $events);
        self::assertSame(0, (int)$events[0]['delivered_time']);

        $again = DeliveryCompletionService::complete($command);
        self::assertNotFalse($again, DeliveryCompletionService::getError());
        self::assertSame($done['sales_document']['finance_document_id'], $again['sales_document']['finance_document_id']);
        self::assertSame($events, $this->closureEvents((int)$done['id']));
        $fresh = CustomerReportLogic::detail(['id' => $done['id']]);
        self::assertNotFalse($fresh, CustomerReportLogic::getError());
        $second = DeliveryCompletionService::complete($this->command($fresh, 'completion-different-key'));
        if ($second !== false) self::assertSame($done['sales_document']['finance_document_id'], $second['sales_document']['finance_document_id']);
        self::assertSame($events, $this->closureEvents((int)$done['id']));
        self::assertSame('94.0000', WarehouseSkuBalanceService::onHand($f['warehouse'], $f['sku']));
    }

    public function test_no_actual_delivery_cannot_be_declared_finished(): void
    {
        $f = $this->fixture(false);
        self::assertFalse(DeliveryCompletionService::complete($this->command($f['report'])));
        self::assertSame([], $this->closureEvents((int)$f['report']['id']));
        self::assertSame('100.0000', WarehouseSkuBalanceService::onHand($f['warehouse'], $f['sku']));
    }

    public function test_completion_requires_the_agreed_total_weight_minimum(): void
    {
        $f = $this->fixture();
        Db::name('customer_report_item')->where('tenant_id', self::TENANT_ID)->where('id', $f['report']['items'][0]['id'])
            ->update(['acceptable_base_qty_min' => '10.0000', 'acceptable_base_qty_max' => '12.0000']);
        self::assertFalse(DeliveryCompletionService::complete($this->command($f['report'])));
        self::assertSame([], $this->closureEvents((int)$f['report']['id']));
        self::assertSame('partially_delivered_pending', CustomerReportLogic::detail(['id' => $f['report']['id']])['status']);
        self::assertSame('94.0000', WarehouseSkuBalanceService::onHand($f['warehouse'], $f['sku']));
    }

    public function test_saved_but_undelivered_next_group_must_be_handled_before_completion(): void
    {
        $f = $this->fixture();
        $report = $f['report'];
        $next = CustomerReportLogic::saveWorkspace(['id' => $report['id'], 'version' => $report['version'], 'workspace_version' => $report['workspace']['version'], 'idempotency_key' => 'completion-next-group',
            'quantities' => ['groups' => [['append_to_group_id' => $report['items'][0]['processing_groups'][0]['id'], 'net_weight_input' => ['value' => '4', 'unit' => '斤'], 'quantity_facts' => []]]]]);
        self::assertNotFalse($next, CustomerReportLogic::getError());
        self::assertFalse(DeliveryCompletionService::complete($this->command($next)));
        self::assertSame([], $this->closureEvents((int)$report['id']));
        self::assertSame('94.0000', WarehouseSkuBalanceService::onHand($f['warehouse'], $f['sku']));
    }

    public function test_stale_foreign_and_revoked_commands_do_not_close_the_report(): void
    {
        $f = $this->fixture();
        $command = $this->command($f['report']);
        $stale = $command; $stale['version'] = (int)$command['version'] - 1;
        self::assertFalse(DeliveryCompletionService::complete($stale));
        $this->prepareCustomerReportRequestContext(self::OTHER_TENANT_ID);
        self::assertFalse(DeliveryCompletionService::complete($command));
        $this->prepareCustomerReportRequestContext();
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID];
        request()->todoPermissionSnapshot = ['tenant_id' => self::TENANT_ID, 'operator_id' => self::ADMIN_ID, 'identity' => 'tenant_admin', 'owner' => false, 'permissions' => []];
        self::assertFalse(DeliveryCompletionService::complete($command));
        $this->prepareCustomerReportRequestContext();
        request()->todoPermissionSnapshot = null;
        self::assertSame([], $this->closureEvents((int)$f['report']['id']));
        self::assertSame('partially_delivered_pending', CustomerReportLogic::detail(['id' => $f['report']['id']])['status']);
    }

    public function test_same_completion_key_rejects_different_content_and_checks_revoked_permission(): void
    {
        $f = $this->fixture();
        $command = $this->command($f['report']);
        $done = DeliveryCompletionService::complete($command);
        self::assertNotFalse($done, DeliveryCompletionService::getError());
        $changed = $command; $changed['item_ids'][] = (int)$command['item_ids'][0] + 999;
        self::assertFalse(DeliveryCompletionService::complete($changed));
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID];
        request()->todoPermissionSnapshot = ['tenant_id' => self::TENANT_ID, 'operator_id' => self::ADMIN_ID, 'identity' => 'tenant_admin', 'owner' => false, 'permissions' => []];
        self::assertFalse(DeliveryCompletionService::complete($command));
        $this->prepareCustomerReportRequestContext();
        request()->todoPermissionSnapshot = null;
        self::assertCount(1, $this->closureEvents((int)$done['id']));
        self::assertSame($done['sales_document']['finance_document_id'], CustomerReportLogic::detail(['id' => $done['id']])['sales_document']['finance_document_id']);
    }
}
