<?php
declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\CustomerReportOverviewLogic;
use app\api\jxc\logic\CustomerReportLogic;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

require_once __DIR__ . '/CustomerReportTestSupport.php';

final class CustomerReportOverviewTest extends TestCase
{
    use CustomerReportTestSupport;

    protected function setUp(): void
    {
        $this->prepareCustomerReportRequestContext();
        $this->ensureCustomerReportTables();
        $this->cleanCustomerReportData();
    }

    protected function tearDown(): void { $this->cleanCustomerReportData(); }

    public function test_overview_keeps_all_customers_supplements_and_original_units(): void
    {
        $first = $this->report('甲总店', '甲分店', '2.5', '斤');
        $this->report('甲总店', '甲分店', '3', '斤', ['supplement_for_report_id' => $first]);
        $this->report('乙客户', '乙客户', '7', '条');
        $this->report('已取消客户', '已取消客户', '99', '斤', ['status' => 'cancelled']);
        $this->report('明日客户', '明日客户', '88', '斤', ['delivery_date' => '2026-10-11']);

        $result = CustomerReportOverviewLogic::overview(['delivery_date' => '2026-10-10']);
        self::assertNotFalse($result, CustomerReportOverviewLogic::getError());
        self::assertSame(2, $result['summary']['customer_count']);
        self::assertSame(3, $result['summary']['report_count']);
        self::assertCount(2, $result['customers']);
        self::assertCount(2, $result['customers'][0]['reports']);
        self::assertSame($first, $result['customers'][0]['reports'][1]['supplement_for_report_id']);
        self::assertSame(['5.5000', '7.0000'], array_column($result['goods_summary'], 'quantity'));
        self::assertSame(['斤', '条'], array_column($result['goods_summary'], 'unit_name'));
    }

    public function test_cancelled_report_history_preserves_reason_and_does_not_change_effective_overview(): void
    {
        $cancelledTime = strtotime('2026-10-10 09:30:00');
        $id = $this->report('取消客户', '取消客户', '9', '斤', [
            'status' => 'cancelled', 'cancellation_reason' => '客户撤回报货',
            'cancelled_by' => 7, 'cancelled_time' => $cancelledTime,
        ]);
        $this->report('有效客户', '有效客户', '3', '斤');

        $effective = CustomerReportOverviewLogic::overview(['delivery_date' => '2026-10-10']);
        self::assertSame(1, $effective['summary']['report_count']);
        $history = CustomerReportOverviewLogic::overview(['delivery_date' => '2026-10-10', 'status' => 'cancelled']);
        self::assertNotFalse($history, CustomerReportOverviewLogic::getError());
        self::assertSame(1, $history['summary']['report_count']);
        $report = $history['customers'][0]['reports'][0];
        self::assertSame($id, $report['id']);
        self::assertSame('已取消', $report['status_label']);
        self::assertSame('客户撤回报货', $report['cancellation_reason']);
        self::assertSame($cancelledTime, $report['cancelled_time']);
        self::assertSame(7, $report['cancelled_by']);
        self::assertFalse(CustomerReportOverviewLogic::exportWord([
            'delivery_date' => '2026-10-10', 'status' => 'cancelled',
            'expected_tenant_id' => self::TENANT_ID, 'snapshot_hash' => $history['snapshot_hash'],
        ]));
        self::assertStringContainsString('取消记录', CustomerReportOverviewLogic::getError());
    }

    public function test_physical_delivery_is_visible_before_and_after_pricing(): void
    {
        $pending = $this->report('陈记饭店', '陈记饭店', '2', '条');
        $delivered = $this->report('陈记饭店', '陈记饭店', '3', '条', ['status' => 'delivered_pending_settlement']);
        $settled = $this->report('陈记饭店', '陈记饭店', '4', '条', ['status' => 'settled']);
        $partial = $this->report('陈记饭店', '陈记饭店', '5', '条', ['status' => 'partial_pending_settlement']);
        $this->report('陈记饭店', '陈记饭店', '99', '条', ['status' => 'cancelled']);
        $query = ['delivery_date' => '2026-10-10', 'keyword' => '陈记'];

        $all = CustomerReportOverviewLogic::overview($query);
        self::assertSame([$pending, $delivered, $settled, $partial], array_column($all['customers'][0]['reports'], 'id'));
        self::assertSame(4, $all['summary']['report_count']);
        $completed = CustomerReportOverviewLogic::overview($query + ['status' => 'completed']);
        self::assertSame([$delivered, $settled], array_column($completed['customers'][0]['reports'], 'id'));
        self::assertSame(['已交付', '已交付'], array_column($completed['customers'][0]['reports'], 'status_label'));
        self::assertSame('待补价', $completed['settlement_notices'][$delivered]['pricing']);
        self::assertSame('7.0000', $completed['summary']['unit_totals']['条']);
        $open = CustomerReportOverviewLogic::overview($query + ['status' => 'pending']);
        self::assertSame([$pending], array_column($open['customers'][0]['reports'], 'id'));
        self::assertNotFalse(CustomerReportOverviewLogic::exportWord($query + [
            'status' => 'completed', 'expected_tenant_id' => self::TENANT_ID, 'snapshot_hash' => $completed['snapshot_hash'],
        ]), CustomerReportOverviewLogic::getError());

        $document = Db::name('customer_report_sales_document')->insertGetId([
            'tenant_id' => self::TENANT_ID, 'report_id' => $delivered, 'order_sn' => 'QA-OVERVIEW-PRICE',
            'accounting_status' => 'pending_amount', 'snapshot' => '{}', 'approval_actor' => '{}',
            'create_time' => time(), 'update_time' => time(),
        ]);
        foreach (['pending_amount' => '待补价', 'review_required' => '金额待复核', 'pending_posting' => '待入账'] as $accounting => $label) {
            Db::name('customer_report_sales_document')->where('id', $document)->update(['accounting_status' => $accounting]);
            $result = CustomerReportOverviewLogic::overview($query + ['status' => 'completed']);
            self::assertSame($label, $result['settlement_notices'][$delivered]['pricing']);
            self::assertSame($completed['snapshot_hash'], $result['snapshot_hash']);
        }
    }

    public function test_full_report_number_finds_a_cancelled_original_across_dates_only_in_its_store(): void
    {
        $sn = 'CR202611211942555007';
        $report = $this->report('陈记饭店', '陈记饭店', '3', '条', [
            'sn' => $sn, 'status' => 'cancelled', 'delivery_date' => '2026-11-21',
        ]);
        $this->report('另一客户', '另一客户', '5', '条', ['sn' => 'CR202611211942555008']);
        $params = ['sn' => $sn, 'expected_tenant_id' => self::TENANT_ID];
        $result = CustomerReportLogic::lists($params);
        self::assertNotFalse($result, CustomerReportLogic::getError());
        self::assertSame(1, $result['count']);
        self::assertSame(self::TENANT_ID, $result['tenant_id']);
        self::assertSame($report, (int)$result['lists'][0]['id']);
        self::assertSame('cancelled', $result['lists'][0]['status']);
        self::assertSame('2026-11-21', $result['lists'][0]['delivery_date']);
        self::assertFalse(CustomerReportLogic::lists(['sn' => 'CR20261121%']));
        $this->prepareCustomerReportRequestContext(self::OTHER_TENANT_ID);
        self::assertFalse(CustomerReportLogic::lists($params));
        self::assertSame(0, CustomerReportLogic::lists(['sn' => $sn])['count']);
        $this->prepareCustomerReportRequestContext();
        request()->adminInfo = []; request()->userId = 0; request()->adminId = 0;
        self::assertFalse(CustomerReportLogic::lists($params));
    }

    public function test_receipt_badge_follows_actual_balance_without_changing_word_snapshot(): void
    {
        $migrations = dirname(__DIR__, 2) . '/database/migrations/';
        foreach (['20260907_000004_finance_business.sql', '20260907_000005_finance_sales.sql', '20260907_000012_finance_sales_coverage.sql'] as $migration) {
            $this->runStatements($this->prepareMigration((string)file_get_contents($migrations . $migration)));
        }
        $report = $this->report('陈记饭店', '陈记饭店', '3', '条', ['status' => 'delivered_pending_settlement']);
        $customer = (int)Db::name('customer_report')->where('tenant_id', self::TENANT_ID)->where('id', $report)->value('main_customer_id');
        $order = (int)Db::name('sales_order')->insertGetId([
            'tenant_id' => self::TENANT_ID, 'order_sn' => uniqid('overview-sales-'), 'customer_id' => $customer,
            'source_type' => 'customer_report', 'source_id' => $report, 'settlement_status' => 'formal',
        ]);
        $document = 6400000000 + $order;
        Db::name('finance_sales_coverage')->insert([
            'tenant_id' => self::TENANT_ID, 'document_id' => $document, 'delivery_item_id' => $order, 'order_id' => $order,
            'covered_delta' => '5.6000', 'create_time' => time(),
        ]);
        $source = (int)Db::name('finance_source')->insertGetId([
            'tenant_id' => self::TENANT_ID, 'document_id' => $document, 'category' => 'receivable', 'subject_id' => $customer,
            'amount' => '112.00', 'snapshot' => json_encode(['order_id' => $order]), 'business_date' => '2026-10-10', 'create_time' => time(),
        ]);
        $reference = 'n:' . $source;
        try {
            $query = ['delivery_date' => '2026-10-10', 'status' => 'completed'];
            $initial = CustomerReportOverviewLogic::overview($query);
            self::assertSame(['receipt' => '仍欠款'], $initial['settlement_notices'][$report]);
            foreach (['-80.00', '-32.00'] as $allocation) {
                Db::name('finance_entry')->insert([
                    'tenant_id' => self::TENANT_ID, 'document_id' => $document, 'source_ref' => $reference,
                    'metric' => 'balance', 'purpose' => 'allocation', 'subject_id' => $customer, 'amount' => $allocation,
                    'business_date' => '2026-10-10', 'posting_month' => '2026-10', 'details' => '{}', 'create_time' => time(),
                ]);
                $afterward = CustomerReportOverviewLogic::overview($query);
                self::assertSame($initial['snapshot_hash'], $afterward['snapshot_hash']);
                self::assertSame(1, $afterward['summary']['report_count']);
                self::assertSame($allocation === '-80.00', isset($afterward['settlement_notices'][$report]['receipt']));
            }
        } finally {
            Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('source_ref', $reference)->delete();
            Db::name('finance_source')->where('tenant_id', self::TENANT_ID)->where('id', $source)->delete();
            Db::name('finance_sales_coverage')->where('tenant_id', self::TENANT_ID)->where('document_id', $document)->delete();
        }
    }

    public function test_cancelled_history_lists_all_delivery_dates_in_cancellation_order(): void
    {
        $olderId = $this->report('旧单客户', '旧单客户', '2', '斤', [
            'delivery_date' => '2026-08-01', 'status' => 'cancelled',
            'cancellation_reason' => '改期', 'cancelled_time' => strtotime('2026-10-10 11:00:00'),
        ]);
        $newerId = $this->report('新单客户', '新单客户', '1', '斤', [
            'status' => 'cancelled', 'cancellation_reason' => '撤回',
            'cancelled_time' => strtotime('2026-10-10 09:00:00'),
        ]);
        $this->report('有效客户', '有效客户', '3', '斤');

        $first = CustomerReportLogic::lists(['status_scope' => 'cancelled', 'page_no' => 1, 'page_size' => 1]);
        self::assertNotFalse($first, CustomerReportLogic::getError());
        self::assertSame(2, $first['count']);
        self::assertSame($olderId, (int)$first['lists'][0]['id']);
        self::assertSame('改期', $first['lists'][0]['cancellation_reason']);
        self::assertSame('2026-08-01', $first['lists'][0]['delivery_date']);
        $second = CustomerReportLogic::lists(['status_scope' => 'cancelled', 'page_no' => 2, 'page_size' => 1]);
        self::assertSame($newerId, (int)$second['lists'][0]['id']);
    }

    public function test_word_exports_the_displayed_snapshot_and_refuses_stale_or_cross_store_requests(): void
    {
        $id = $this->report('甲<&总店', '甲分店', '3', '条');
        $query = ['delivery_date' => '2026-10-10', 'expected_tenant_id' => self::TENANT_ID];
        $snapshot = CustomerReportOverviewLogic::overview($query);
        $command = $query + ['snapshot_hash' => $snapshot['snapshot_hash']];
        $file = CustomerReportOverviewLogic::exportWord($command);
        self::assertNotFalse($file, CustomerReportOverviewLogic::getError());
        self::assertSame($snapshot['snapshot_hash'], $file['snapshot_hash']);
        self::assertSame('报货汇总_2026-10-10.docx', $file['filename']);
        $path = tempnam(sys_get_temp_dir(), 'overview-test-');
        try {
            file_put_contents($path, base64_decode($file['base64'], true));
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($path));
            $xml = $zip->getFromName('word/document.xml');
            $doc = new \DOMDocument();
            self::assertTrue($doc->loadXML($xml));
            $xpath = new \DOMXPath($doc);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            self::assertSame('报货汇总', $xpath->query('//w:p[w:pPr/w:pStyle[@w:val="Title"]]')->item(0)->textContent);
            $header = new \DOMDocument();
            self::assertTrue($header->loadXML($zip->getFromName('word/header1.xml')));
            self::assertSame($snapshot['store_name'] . ' · 报货汇总 · 2026-10-10', $header->textContent);
            self::assertStringContainsString('甲<&总店', $doc->textContent);
            self::assertStringContainsString('甲分店', $doc->textContent);
            self::assertStringContainsString('商品需求汇总', $doc->textContent);
            self::assertStringContainsString('w:w="11906" w:h="16838"', $xml);
            self::assertStringNotContainsString('设计说明', $doc->textContent);
            $zip->close();
        } finally { unlink($path); }
        Db::name('customer_report')->where('id', $id)->update(['remark' => '临时改为分箱']);
        self::assertFalse(CustomerReportOverviewLogic::exportWord($command));
        self::assertStringContainsString('刷新', CustomerReportOverviewLogic::getError());
        $this->prepareCustomerReportRequestContext(self::OTHER_TENANT_ID);
        self::assertFalse(CustomerReportOverviewLogic::exportWord($command));
        self::assertStringContainsString('门店', CustomerReportOverviewLogic::getError());
        $this->prepareCustomerReportRequestContext();
    }

    public function test_filters_permissions_and_unknown_weights_remain_explicit(): void
    {
        $id = $this->report('甲总店', '甲分店', '5', '条');
        Db::name('customer_report_item')->where('report_id', $id)->update(['estimate_status' => 'unknown']);
        $this->report('乙总店', '乙分店', '3', '斤', ['status' => 'completed']);
        $query = ['delivery_date' => '2026-10-10', 'keyword' => '甲分'];
        $result = CustomerReportOverviewLogic::overview($query);
        self::assertSame(1, $result['summary']['report_count']);
        self::assertTrue($result['customers'][0]['reports'][0]['items'][0]['pending_measurement']);
        self::assertNull($result['customers'][0]['reports'][0]['items'][0]['shortage_quantity']);
        self::assertSame(1, CustomerReportOverviewLogic::overview(['delivery_date' => '2026-10-10', 'status' => 'completed'])['summary']['report_count']);
        self::assertFalse(CustomerReportOverviewLogic::overview(['delivery_date' => '2026-02-30']));
        self::assertFalse(CustomerReportOverviewLogic::overview(['keyword' => ['invalid']]));
        $this->prepareCustomerReportRequestContext(self::OTHER_TENANT_ID);
        self::assertSame(0, CustomerReportOverviewLogic::overview($query)['summary']['report_count']);
        $this->prepareCustomerReportRequestContext();
        request()->adminInfo = []; request()->userId = 0; request()->adminId = 0;
        self::assertFalse(CustomerReportOverviewLogic::overview($query));
        self::assertStringContainsString('权限', CustomerReportOverviewLogic::getError());
    }

    public function test_ten_customers_and_multiple_processing_steps_do_not_inflate_totals(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $id = $this->report('客户' . $i, '客户' . $i, '5', '条');
            $itemId = (int)Db::name('customer_report_item')->where('report_id', $id)->value('id');
            Db::name('customer_report_item')->where('id', $itemId)->update(['acceptable_base_qty_min' => '8', 'acceptable_base_qty_max' => '10']);
            foreach (['a' => '2', 'b' => '3'] as $key => $quantity) {
                $group = (int)Db::name('customer_report_processing_group')->insertGetId([
                    'tenant_id' => self::TENANT_ID, 'report_id' => $id, 'report_item_id' => $itemId,
                    'group_key' => $key, 'name' => '分组' . $key, 'planned_qty' => $quantity,
                ]);
                foreach (['去鳞', '去内脏'] as $step => $process) {
                    Db::name('customer_report_processing_group_process')->insert(['tenant_id' => self::TENANT_ID,
                        'processing_group_id' => $group, 'process_id' => $step + 1, 'step_no' => $step + 1, 'process_name_snapshot' => $process]);
                }
            }
        }
        $result = CustomerReportOverviewLogic::overview(['delivery_date' => '2026-10-10']);
        self::assertCount(10, $result['customers']);
        self::assertSame('50.0000', $result['goods_summary'][0]['quantity']);
        self::assertSame(10, $result['goods_summary'][0]['customer_count']);
        $line = $result['customers'][0]['reports'][0]['items'][0];
        self::assertCount(2, $line['processing_groups']);
        self::assertSame(['去鳞', '去内脏'], $line['processing_groups'][0]['processes']);
        self::assertStringContainsString('总重范围', $line['requirements']);
        self::assertSame('50.0000', $result['goods_summary'][0]['processing_summary'][0]['quantity']);
        self::assertSame(['去鳞', '去内脏'], $result['goods_summary'][0]['processing_summary'][0]['processes']);
    }

    private function report(string $main, string $receiver, string $quantity, string $unit, array $overrides = []): int
    {
        $mainId = (int)Db::name('customer')->where('tenant_id', self::TENANT_ID)->where('customer_name', $main)->value('id');
        if (!$mainId) { $mainId = $this->createCustomer($main); }
        $receiverId = (int)Db::name('customer')->where('tenant_id', self::TENANT_ID)->where('customer_name', $receiver)->value('id');
        if (!$receiverId) { $receiverId = $this->createCustomer($receiver, $mainId); }
        $key = uniqid('overview-');
        $id = (int)Db::name('customer_report')->insertGetId(array_replace([
            'tenant_id' => self::TENANT_ID, 'sn' => $key, 'idempotency_key' => $key,
            'main_customer_id' => $mainId, 'main_customer_name' => $main,
            'delivery_date' => '2026-10-10', 'status' => 'submitted_ready',
            'remark' => '请分袋', 'submitted_time' => time(),
        ], $overrides));
        Db::name('customer_report_item')->insert([
            'tenant_id' => self::TENANT_ID, 'report_id' => $id,
            'main_customer_id' => $mainId, 'main_customer_name' => $main,
            'delivery_customer_id' => $receiverId, 'delivery_customer_name' => $receiver,
            'goods_id' => 101, 'goods_name' => '桂鱼', 'sku_id' => 201, 'sku_name' => '中号',
            'order_qty' => $quantity, 'unit_name' => $unit, 'base_unit_name' => '斤',
            'line_remark' => '不要破胆', 'processing_requirement' => '去鳞',
        ]);
        return $id;
    }
}
