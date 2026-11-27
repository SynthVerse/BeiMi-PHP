<?php
declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\CustomerReportOverviewLogic;
use app\api\jxc\logic\CustomerReportLogic;
use app\api\jxc\logic\CustomerLogic;
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
        self::assertCount(3, $result['reports']);
        self::assertSame($first, $result['reports'][1]['supplement_for_report_id']);
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
        $report = $history['reports'][0];
        self::assertSame($id, $report['id']);
        self::assertSame('已取消', $report['status_label']);
        self::assertSame('客户撤回报货', $report['cancellation_reason']);
        self::assertSame($cancelledTime, $report['cancelled_time']);
        self::assertSame(7, $report['cancelled_by']);
        self::assertFalse(CustomerReportOverviewLogic::exportWord([
            'delivery_date' => '2026-10-10', 'status' => 'cancelled',
            'expected_tenant_id' => self::TENANT_ID, 'snapshot_hash' => $history['snapshot_hash'],
            'document_type' => 'customers',
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
        self::assertSame([$pending, $delivered, $settled, $partial], array_column($all['reports'], 'id'));
        self::assertSame(4, $all['summary']['report_count']);
        $completed = CustomerReportOverviewLogic::overview($query + ['status' => 'completed']);
        self::assertSame([$delivered, $settled], array_column($completed['reports'], 'id'));
        self::assertSame(['已交付', '已交付'], array_column($completed['reports'], 'status_label'));
        self::assertSame('待补价', $completed['settlement_notices'][$delivered]['pricing']);
        self::assertSame('7.0000', $completed['summary']['unit_totals']['条']);
        $open = CustomerReportOverviewLogic::overview($query + ['status' => 'pending']);
        self::assertSame([$pending], array_column($open['reports'], 'id'));
        self::assertNotFalse(CustomerReportOverviewLogic::exportWord($query + [
            'status' => 'completed', 'expected_tenant_id' => self::TENANT_ID, 'snapshot_hash' => $completed['snapshot_hash'],
            'document_type' => 'customers',
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

    public function test_primary_customer_report_scope_applies_to_pages_counts_cancelled_and_numbers(): void
    {
        $cancelled = $this->report('陈记', '一店', '1', '条', ['status' => 'cancelled', 'sn' => 'CR202611211942555101']);
        $pending = $this->report('陈记', '二店', '2', '条');
        $secondCancelled = $this->report('陈记', '二店', '1', '条', ['status' => 'cancelled']);
        $knownReports = [$cancelled, $pending, $secondCancelled];
        for ($i = 0; $i < 18; $i++) {
            $knownReports[] = $this->report('陈记', '一店', '1', '条', ['status' => 'completed']);
        }
        $mainId = (int)Db::name('customer_report')->where('id', $pending)->value('main_customer_id');
        $otherReport = $this->report('另一主客户', '分店', '1', '条', ['main_customer_name' => '陈记', 'sn' => 'CR202611211942555102']);
        $scope = ['main_customer_id' => (string)$mainId, 'expected_tenant_id' => self::TENANT_ID];

        $firstPage = CustomerReportLogic::lists($scope + ['page_size' => 20]);
        self::assertSame(self::TENANT_ID, $firstPage['tenant_id']);
        self::assertSame($mainId, $firstPage['main_customer_id']);
        self::assertSame(21, $firstPage['count']);
        self::assertSame(array_slice(array_reverse($knownReports), 0, 20), array_map('intval', array_column($firstPage['lists'], 'id')));
        $secondPage = CustomerReportLogic::lists($scope + ['page_no' => 2, 'page_size' => 20]);
        self::assertSame(21, $secondPage['count']);
        self::assertSame([$cancelled], array_map('intval', array_column($secondPage['lists'], 'id')));
        $pendingPage = CustomerReportLogic::lists($scope + ['status_scope' => 'pending']);
        self::assertSame(1, $pendingPage['count']);
        self::assertSame(1, $pendingPage['delivery_date_counts'][0]['count']);
        $cancelledPage = CustomerReportLogic::lists($scope + ['status_scope' => 'cancelled']);
        self::assertSame(2, $cancelledPage['count']);
        self::assertSame([$secondCancelled, $cancelled], array_map('intval', array_column($cancelledPage['lists'], 'id')));
        self::assertSame(1, CustomerReportLogic::lists($scope + ['sn' => 'CR202611211942555101'])['count']);
        self::assertSame(0, CustomerReportLogic::lists($scope + ['sn' => 'CR202611211942555102'])['count']);
        self::assertFalse(CustomerReportLogic::lists($scope + ['sn' => 'CR20261121']));
        self::assertSame($otherReport, (int)CustomerReportLogic::lists(['sn' => 'CR202611211942555102'])['lists'][0]['id']);
        self::assertFalse(CustomerReportLogic::lists(['main_customer_id' => 0]));
        $child = (int)Db::name('customer_report_item')->where('report_id', $pending)->value('delivery_customer_id');
        self::assertFalse(CustomerReportLogic::lists(['main_customer_id' => $child]));
        $this->prepareCustomerReportRequestContext(self::OTHER_TENANT_ID);
        self::assertFalse(CustomerReportLogic::lists($scope));
        self::assertFalse(CustomerReportLogic::lists(['main_customer_id' => $mainId]));
        $this->prepareCustomerReportRequestContext();
        request()->adminInfo = []; request()->userId = 0; request()->adminId = 0;
        self::assertFalse(CustomerReportLogic::lists($scope));
    }

    public function test_customer_sales_history_routes_each_report_and_direct_order_to_its_own_document(): void
    {
        $report = $this->report('销售主客户', '销售收货点', '2', '斤');
        $parties = Db::name('customer_report_item')->where('report_id', $report)->find();
        $issuedId = (int)Db::name('customer_report_sales_document')->insertGetId([
            'tenant_id' => self::TENANT_ID, 'report_id' => $report, 'order_sn' => uniqid('issued-'),
            'snapshot' => '{}', 'approval_actor' => '{}', 'create_time' => time(), 'update_time' => time(),
        ]);
        foreach (['customer_report', 'direct'] as $kind) {
            Db::name('sales_order')->insert([
                'tenant_id' => self::TENANT_ID, 'order_sn' => uniqid('history-'),
                'customer_id' => $kind === 'direct' ? $parties['delivery_customer_id'] : $parties['main_customer_id'],
                'source_type' => $kind, 'source_id' => $report,
            ]);
        }
        $history = CustomerLogic::salesHistory(['customer_id' => $parties['main_customer_id']]);
        self::assertSame(2, $history['total']);
        $byType = array_column($history['data'], null, 'source_type');
        self::assertSame($report, $byType['customer_report']['source_report_id']);
        self::assertSame($issuedId, $byType['customer_report']['issued_sales_document_id']);
        self::assertSame(0, $byType['direct']['source_report_id']);
        self::assertSame(0, $byType['direct']['issued_sales_document_id']);
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
        $id = $this->report('甲<&总店', '甲分店', '5', '斤', [
            'remark' => '', 'delivery_method' => 'counter_pickup', 'status' => 'submitted_shortage',
        ]);
        Db::name('customer_report_item')->where('report_id', $id)->update([
            'goods_name' => '鲈鱼', 'sku_name' => '鲈鱼', 'shortage_base_qty' => '1',
            'processing_requirement' => '', 'line_remark' => '',
        ]);
        $query = ['delivery_date' => '2026-10-10', 'status' => 'pending', 'keyword' => '甲分', 'expected_tenant_id' => self::TENANT_ID];
        $snapshot = CustomerReportOverviewLogic::overview($query);
        $command = $query + ['snapshot_hash' => $snapshot['snapshot_hash'], 'document_type' => 'customers'];
        $file = CustomerReportOverviewLogic::exportWord($command);
        self::assertNotFalse($file, CustomerReportOverviewLogic::getError());
        self::assertSame($snapshot['snapshot_hash'], $file['snapshot_hash']);
        self::assertSame('customers', $file['document_type']);
        self::assertSame('客户报货单_2026-10-10.docx', $file['filename']);
        $doc = $this->wordPart($file, 'document');
        $header = $this->wordPart($file, 'header1');
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        self::assertSame(1, $xpath->query('//w:pgSz[@w:w="11906" and @w:h="16838"]')->length);
        self::assertSame(1, $xpath->query('//w:cols[@w:num="2"]')->length);
        self::assertStringContainsString($snapshot['store_name'], $header->textContent);
        self::assertStringContainsString('客户报货单', $header->textContent);
        self::assertStringContainsString('2026-10-10', $header->textContent);
        self::assertStringContainsString('待交付', $header->textContent);
        self::assertStringContainsString('甲分', $header->textContent);
        $lines = $this->wordLines($doc);
        self::assertContains('客户：1. 甲<&总店 / 甲分店', $lines);
        self::assertContains('送货：客户自提', $lines);
        self::assertContains('□ 商品：① 鲈鱼', $lines);
        self::assertContains('报货数量：5 斤', $lines);
        self::assertContains('缺货数量：1 斤', $lines);
        self::assertSame(1, substr_count($doc->textContent, '鲈鱼'));
        foreach (['商品需求汇总', '备货人', '核对人', '导出时间', '备注', '车牌：', '最早：', $snapshot['reports'][0]['sn']] as $omitted) {
            self::assertStringNotContainsString($omitted, $doc->textContent);
        }
        foreach ([null, ['goods'], 'other'] as $value) {
            self::assertFalse(CustomerReportOverviewLogic::exportWord(array_replace($command, ['document_type' => $value])));
            self::assertStringContainsString('单据类型', CustomerReportOverviewLogic::getError());
        }
        Db::name('customer_report')->where('id', $id)->update(['remark' => '临时改为分箱']);
        self::assertFalse(CustomerReportOverviewLogic::exportWord($command));
        self::assertStringContainsString('刷新', CustomerReportOverviewLogic::getError());
        $this->prepareCustomerReportRequestContext(self::OTHER_TENANT_ID);
        self::assertFalse(CustomerReportOverviewLogic::exportWord($command));
        self::assertStringContainsString('门店', CustomerReportOverviewLogic::getError());
        $this->prepareCustomerReportRequestContext();
    }

    public function test_word_prints_the_vehicle_handoff_together_and_checks_each_report_separately(): void
    {
        $vehicle = [
            'delivery_method' => 'customer_vehicle',
            'delivery_arrangement_snapshot' => json_encode([
                'plate_number' => '测A2794', 'vehicle_location' => '验收装车位',
            ], JSON_UNESCAPED_UNICODE),
        ];
        $late = $this->report('渔港甲', '渔港甲', '1', '件', $vehicle + ['earliest_delivery_time' => '08:00']);
        $middle = $this->report('桥东乙', '桥东乙', '1', '件', $vehicle + ['earliest_delivery_time' => '05:00']);
        $early = $this->report('渔港甲', '渔港甲', '1', '件', $vehicle + ['earliest_delivery_time' => '04:00']);

        $query = ['delivery_date' => '2026-10-10', 'expected_tenant_id' => self::TENANT_ID];
        $snapshot = CustomerReportOverviewLogic::overview($query);
        self::assertSame([$early, $middle, $late], array_column($snapshot['reports'], 'id'));
        $command = $query + ['snapshot_hash' => $snapshot['snapshot_hash']];
        $customers = CustomerReportOverviewLogic::exportWord($command + ['document_type' => 'customers']);
        self::assertNotFalse($customers, CustomerReportOverviewLogic::getError());
        $customerLines = $this->wordLines($this->wordPart($customers, 'document'));
        self::assertSame([
            '客户：1. 渔港甲',
            '报货次序：第 1 单',
            '送货：客户车辆　车牌：测A2794　最早：04:00',
            '客户：2. 桥东乙',
            '送货：客户车辆　车牌：测A2794　最早：05:00',
            '客户：1. 渔港甲',
            '报货次序：第 2 单',
            '送货：客户车辆　车牌：测A2794　最早：08:00',
        ], array_values(preg_grep('/^(客户|报货次序|送货)：/u', $customerLines)));
        self::assertContains('交接地点：验收装车位', $customerLines);
        self::assertContains('□ 商品：① 桂鱼 中号', $customerLines);
        self::assertContains('□ 商品：② 桂鱼 中号', $customerLines);
        self::assertContains('报货数量：1 件', $customerLines);

        $goods = CustomerReportOverviewLogic::exportWord($command + ['document_type' => 'goods']);
        self::assertNotFalse($goods, CustomerReportOverviewLogic::getError());
        $goodsLines = $this->wordLines($this->wordPart($goods, 'document'));
        self::assertSame([
            '总报货量：3 件',
            '客户：1. 渔港甲',
            '客户合计：2 件',
            '□ 报货次序：第 1 单',
            '本单分货量：1 件',
            '最早送货时间：04:00',
            '□ 报货次序：第 2 单',
            '本单分货量：1 件',
            '最早送货时间：08:00',
            '客户：2. 桥东乙',
            '□ 本单分货量：1 件',
            '最早送货时间：05:00',
        ], array_values(preg_grep('/^(?:□ )?(总报货量|客户|客户合计|报货次序|本单分货量|最早送货时间)：/u', $goodsLines)));
        self::assertSame(3, substr_count(implode("\n", $goodsLines), '□'));
    }

    public function test_overview_orders_customer_vehicle_times_before_pending_times_and_pickup(): void
    {
        $pickup = $this->report('自提客户', '自提客户', '1', '条', [
            'delivery_method' => 'counter_pickup', 'submitted_time' => 1,
        ]);
        $vehicle = ['delivery_method' => 'customer_vehicle'];
        $missing = $this->report('缺时客户', '缺时客户', '1', '条', $vehicle + ['submitted_time' => 2]);
        $laterSubmission = $this->report('同时间晚提交', '同时间晚提交', '1', '条', $vehicle + [
            'earliest_delivery_time' => '05:00', 'submitted_time' => 5,
        ]);
        $sameTimeFirst = $this->report('同时间先提交', '同时间先提交', '1', '条', $vehicle + [
            'earliest_delivery_time' => '05:00', 'submitted_time' => 4,
        ]);
        $sameTimeNext = $this->report('同时间同提交', '同时间同提交', '1', '条', $vehicle + [
            'earliest_delivery_time' => '05:00', 'submitted_time' => 4,
        ]);
        $midnight = $this->report('零点客户', '零点客户', '1', '条', $vehicle + [
            'earliest_delivery_time' => '00:00', 'submitted_time' => 7,
        ]);
        $query = ['delivery_date' => '2026-10-10', 'expected_tenant_id' => self::TENANT_ID];
        $snapshot = CustomerReportOverviewLogic::overview($query);
        self::assertSame(
            [$midnight, $sameTimeFirst, $sameTimeNext, $laterSubmission, $missing, $pickup],
            array_column($snapshot['reports'], 'id')
        );
        self::assertSame(0, $snapshot['reports'][0]['delivery_arrangement']['source_vehicle_id']);

        $word = CustomerReportOverviewLogic::exportWord($query + [
            'snapshot_hash' => $snapshot['snapshot_hash'], 'document_type' => 'customers',
        ]);
        self::assertNotFalse($word, CustomerReportOverviewLogic::getError());
        $lines = $this->wordLines($this->wordPart($word, 'document'));
        self::assertContains('送货：客户车辆　车牌：待补　最早：00:00', $lines);
        self::assertContains('送货：客户车辆　车牌：待补　最早：待补', $lines);

        Db::name('customer_report')->where('id', $missing)->update(['earliest_delivery_time' => '04:30']);
        self::assertFalse(CustomerReportOverviewLogic::exportWord($query + [
            'snapshot_hash' => $snapshot['snapshot_hash'], 'document_type' => 'customers',
        ]));
        self::assertStringContainsString('刷新', CustomerReportOverviewLogic::getError());
        self::assertSame(
            [$midnight, $missing, $sameTimeFirst, $sameTimeNext, $laterSubmission, $pickup],
            array_column(CustomerReportOverviewLogic::overview($query)['reports'], 'id')
        );
    }

    public function test_word_separates_customer_handoffs_and_reconciles_goods_by_receiver_specification_and_unit(): void
    {
        $first = $this->report('海味饭店', '海味饭店', '3', '条', [
            'delivery_method' => 'counter_pickup', 'remark' => '交给后厨',
        ]);
        $supplement = $this->report('海味饭店', '海味饭店', '2', '条', [
            'supplement_for_report_id' => $first, 'delivery_method' => 'third_party', 'remark' => '补报单独装',
        ]);
        $vehicle = $this->report('渔家坊', '老街分店', '8', '条', [
            'delivery_method' => 'customer_vehicle', 'earliest_delivery_time' => '05:10', 'remark' => '',
            'delivery_arrangement_snapshot' => json_encode([
                'plate_number' => '辽A12345', 'vehicle_location' => '东门二号位', 'driver_phone' => '19900000000',
            ], JSON_UNESCAPED_UNICODE),
        ]);
        $weight = $this->report('渔家坊', '老街分店', '4.5', '斤');
        $missing = $this->report('待补资料客户', '待补资料客户', '2', '件', [
            'delivery_method' => 'customer_vehicle', 'earliest_delivery_time' => '06:00',
        ]);
        Db::name('customer_report_item')->whereIn('report_id', [$first, $supplement, $vehicle, $weight])->update([
            'goods_name' => '鲈鱼', 'sku_name' => '鲈鱼', 'processing_requirement' => '', 'line_remark' => '',
        ]);
        Db::name('customer_report_item')->where('report_id', $missing)->update([
            'goods_id' => 777, 'sku_id' => 778, 'estimate_status' => 'unknown', 'unit_name' => "箱\x0B装",
        ]);
        foreach ([['7头', 701, '6'], ['8头', 801, '9']] as [$specification, $specId, $quantity]) {
            $id = $this->report('海味饭店', '海味饭店', $quantity, '斤');
            Db::name('customer_report_item')->where('report_id', $id)->update([
                'goods_id' => 888, 'goods_name' => '鲍鱼', 'sku_id' => 889, 'sku_name' => '鲍鱼',
                'spec_id' => $specId, 'specification_snapshot' => $specification,
                'processing_requirement' => '', 'line_remark' => '',
            ]);
        }
        $itemId = (int)Db::name('customer_report_item')->where('report_id', $vehicle)->value('id');
        Db::name('customer_report_item')->where('id', $itemId)->update([
            'piece_weight_confirmed' => 1, 'piece_weight_min' => '1.5', 'piece_weight_max' => '1.7',
            'processing_requirement' => '鱼背留皮；鱼腹留鳞',
            'quality_snapshot' => '活鲜；当天',
            'line_remark' => '分两袋；避重压',
        ]);
        foreach ([['第1组', '5', ['去鳞', '去内脏']], ['留头', '3', ['打包']]] as $index => [$name, $quantity, $processes]) {
            $group = (int)Db::name('customer_report_processing_group')->insertGetId([
                'tenant_id' => self::TENANT_ID, 'report_id' => $vehicle, 'report_item_id' => $itemId,
                'group_key' => 'group-' . $index, 'name' => $name, 'planned_qty' => $quantity, 'sort' => $index,
            ]);
            foreach ($processes as $step => $process) {
                Db::name('customer_report_processing_group_process')->insert([
                    'tenant_id' => self::TENANT_ID, 'processing_group_id' => $group,
                    'process_id' => $step + 1, 'step_no' => $step + 1, 'process_name_snapshot' => $process,
                ]);
            }
        }

        $query = ['delivery_date' => '2026-10-10', 'expected_tenant_id' => self::TENANT_ID];
        $snapshot = CustomerReportOverviewLogic::overview($query);
        $command = $query + ['snapshot_hash' => $snapshot['snapshot_hash']];
        $customers = CustomerReportOverviewLogic::exportWord($command + ['document_type' => 'customers']);
        self::assertNotFalse($customers, CustomerReportOverviewLogic::getError());
        $customerDocument = $this->wordPart($customers, 'document');
        $customerText = $this->paperText($customerDocument);
        foreach (['整单备注：交给后厨', '补报', '送货：第三方配送', '整单备注：补报单独装',
            '送货：客户车辆　车牌：辽A12345　最早：05:10', '交接地点：东门二号位', '司机电话：19900000000',
            '车牌：待补', '交接地点：待补', '待称重', '5 条 去鳞→去内脏', '3 条 打包（留头）',
            '客户：3. 海味饭店', '客户：1. 渔家坊 / 老街分店', '商品：③ 鲈鱼', '商品：④ 鲈鱼',
            '商品：① 鲍鱼 7头', '商品：② 鲍鱼 8头', '报货数量：8 条', '报货数量：4.5 斤'] as $expected) {
            self::assertStringContainsString($expected, $customerText);
        }
        self::assertSame(1, substr_count($customerText, '交给后厨'));
        self::assertSame(1, substr_count($customerText, '补报单独装'));
        self::assertStringContainsString("2 箱\u{FFFD}装", $customerText);

        $goods = CustomerReportOverviewLogic::exportWord($command + ['document_type' => 'goods']);
        self::assertNotFalse($goods, CustomerReportOverviewLogic::getError());
        self::assertSame('goods', $goods['document_type']);
        self::assertSame('商品分货单_2026-10-10.docx', $goods['filename']);
        self::assertSame($customers['snapshot_hash'], $goods['snapshot_hash']);
        $goodsDocument = $this->wordPart($goods, 'document');
        $goodsText = $this->paperText($goodsDocument);
        foreach (['商品：① 鲈鱼', '总报货量：13 条', '客户：3. 海味饭店', '客户合计：5 条',
            '客户：1. 渔家坊 / 老街分店', '本单分货量：8 条', '补报', '本单分货量：2 条',
            '商品：② 鲈鱼', '总报货量：4.5 斤', '本单分货量：4.5 斤', '鲍鱼 7头', '总报货量：6 斤', '鲍鱼 8头', '总报货量：9 斤',
            '5 条 去鳞→去内脏', '3 条 打包（留头）', '单体重量：1.5–1.7 斤', '待称重'] as $expected) {
            self::assertStringContainsString($expected, $goodsText);
        }
        self::assertStringNotContainsString('15 条', $goodsText);
        self::assertStringNotContainsString('19900000000', $goodsText);
        self::assertStringNotContainsString('交给后厨', $goodsText);
        self::assertStringContainsString("2 箱\u{FFFD}装", $goodsText);
        foreach ([$customerDocument, $goodsDocument] as $document) {
            $lines = $this->wordLines($document);
            self::assertContains('加工要求：鱼背留皮；鱼腹留鳞', $lines);
            self::assertContains('品质：活鲜；当天', $lines);
            self::assertContains('商品备注：分两袋；避重压', $lines);
        }
    }

    public function test_word_repeats_the_active_order_or_receiver_when_details_continue_in_the_next_column(): void
    {
        $main = 'ABERDEEN FISH PACKING CORPORATION';
        $receiver = '南门分店';
        $first = $this->report($main, $receiver, '5', '条', ['delivery_method' => 'counter_pickup', 'remark' => '']);
        $this->report($main, $receiver, '8', '条', [
            'delivery_method' => 'third_party', 'supplement_for_report_id' => $first, 'remark' => '',
        ]);
        $item = Db::name('customer_report_item')->where('report_id', $first)->find();
        unset($item['id']);
        $detail = str_repeat('ALASKA ALL NATURAL SOCKEYE SALMON FILLETS，单独装袋。', 4);
        for ($index = 1; $index <= 10; $index++) {
            Db::name('customer_report_item')->insert(array_replace($item, [
                'sort' => $index, 'line_remark' => $detail . '末尾' . $index,
            ]));
        }
        $query = ['delivery_date' => '2026-10-10', 'expected_tenant_id' => self::TENANT_ID];
        $snapshot = CustomerReportOverviewLogic::overview($query);
        $command = $query + ['snapshot_hash' => $snapshot['snapshot_hash']];
        $customers = CustomerReportOverviewLogic::exportWord($command + ['document_type' => 'customers']);
        self::assertNotFalse($customers, CustomerReportOverviewLogic::getError());
        $path = new \DOMXPath($this->wordPart($customers, 'document'));
        $path->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $continued = $path->query('//w:p[w:r/w:br[@w:type="column"]]/following-sibling::w:p[1]');
        $restart = [];
        foreach ($continued as $firstLine) {
            $context = $firstLine->textContent;
            foreach ($path->query('following-sibling::w:p[position() <= 3]', $firstLine) as $line) {
                $context .= $line->textContent;
            }
            $restart[] = $context;
        }
        self::assertNotEmpty(preg_grep('/^客户：1\..*南门分店（续）报货次序：第 1 单/u', $restart), implode("\n", $restart));
        self::assertStringContainsString($receiver, $path->document->textContent);
        self::assertStringContainsString('末尾10', $path->document->textContent);
        self::assertStringContainsString('送货：第三方配送', $path->document->textContent);
        self::assertSame(12, substr_count($path->document->textContent, '□'));

        $goods = CustomerReportOverviewLogic::exportWord($command + ['document_type' => 'goods']);
        self::assertNotFalse($goods, CustomerReportOverviewLogic::getError());
        $path = new \DOMXPath($this->wordPart($goods, 'document'));
        $path->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $continued = $path->query('//w:p[w:r/w:br[@w:type="column"]]/following-sibling::w:p[1]');
        self::assertContains('商品：① 桂鱼 中号（续）', array_map(fn(\DOMNode $node) => trim($this->paperText($node)), iterator_to_array($continued)));
        self::assertStringContainsString($receiver, $path->document->textContent);
        self::assertStringContainsString('总报货量：63 条', $path->document->textContent);
        self::assertStringContainsString('末尾10', $path->document->textContent);
        self::assertSame(2, substr_count($path->document->textContent, '□'));
    }

    public function test_word_keeps_goods_numbers_circled_beyond_fifty_in_both_documents(): void
    {
        $original = $this->report('海味饭店', '海味饭店', '1', '条', ['status' => 'completed']);
        $id = $this->report('海味饭店', '海味饭店', '1', '条', ['supplement_for_report_id' => $original]);
        $item = Db::name('customer_report_item')->where('report_id', $id)->find();
        unset($item['id']);
        $items = [];
        for ($position = 2; $position <= 100; $position++) {
            $name = '样品' . $position;
            $items[] = array_replace($item, [
                'sort' => $position, 'goods_id' => 10000 + $position, 'sku_id' => 20000 + $position,
                'goods_name' => $name, 'sku_name' => $name, 'specification_snapshot' => '',
            ]);
        }
        Db::name('customer_report_item')->insertAll($items);
        $query = ['delivery_date' => '2026-10-10', 'status' => 'pending', 'expected_tenant_id' => self::TENANT_ID];
        $snapshot = CustomerReportOverviewLogic::overview($query);
        foreach (['customers', 'goods'] as $type) {
            $file = CustomerReportOverviewLogic::exportWord($query + ['document_type' => $type, 'snapshot_hash' => $snapshot['snapshot_hash']]);
            self::assertNotFalse($file, CustomerReportOverviewLogic::getError());
            $document = $this->wordPart($file, 'document');
            $text = $this->paperText($document);
            foreach ([11 => '⑪', 20 => '⑳', 21 => '㉑', 50 => '㊿', 51 => '◯51', 100 => '◯100'] as $position => $marker) {
                self::assertStringContainsString('商品：' . $marker . ' 样品' . $position, $text);
            }
            if ($type === 'customers') {
                $path = new \DOMXPath($document);
                $path->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
                $break = '//w:body/w:p[w:r/w:br[@w:type="column"]]';
                self::assertGreaterThan(0, $path->query($break)->length);
                self::assertStringContainsString('补报', $text);
                self::assertSame(100, substr_count($text, '□'));
            }
        }
    }

    private function wordLines(\DOMDocument $document): array
    {
        $path = new \DOMXPath($document);
        $path->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        return array_values(array_filter(array_map(
            fn(\DOMNode $paragraph) => trim($this->paperText($paragraph)),
            iterator_to_array($path->query('//w:p'))
        )));
    }

    private function paperText(\DOMNode $node): string
    {
        if ($node instanceof \DOMElement && $node->localName === 'tab' && $node->parentNode->localName === 'r') {
            return ' ';
        }
        if ($node instanceof \DOMText) {
            return $node->nodeValue;
        }
        $text = '';
        foreach ($node->childNodes as $child) {
            $text .= $this->paperText($child);
        }
        return $text;
    }

    private function wordPart(array $file, string $part): \DOMDocument
    {
        $path = tempnam(sys_get_temp_dir(), 'overview-test-');
        try {
            file_put_contents($path, base64_decode($file['base64'], true));
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($path));
            $xml = $zip->getFromName('word/' . $part . '.xml');
            $zip->close();
            $doc = new \DOMDocument();
            self::assertTrue($doc->loadXML($xml));
            return $doc;
        } finally {
            unlink($path);
        }
    }

    public function test_filters_permissions_and_unknown_weights_remain_explicit(): void
    {
        $id = $this->report('甲总店', '甲分店', '5', '条');
        Db::name('customer_report_item')->where('report_id', $id)->update(['estimate_status' => 'unknown']);
        $this->report('乙总店', '乙分店', '3', '斤', ['status' => 'completed']);
        $query = ['delivery_date' => '2026-10-10', 'keyword' => '甲分'];
        $result = CustomerReportOverviewLogic::overview($query);
        self::assertSame(1, $result['summary']['report_count']);
        self::assertTrue($result['reports'][0]['items'][0]['pending_measurement']);
        self::assertNull($result['reports'][0]['items'][0]['shortage_quantity']);
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
        self::assertSame(10, $result['summary']['customer_count']);
        self::assertSame('50.0000', $result['goods_summary'][0]['quantity']);
        self::assertSame(10, $result['goods_summary'][0]['customer_count']);
        $line = $result['reports'][0]['items'][0];
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
