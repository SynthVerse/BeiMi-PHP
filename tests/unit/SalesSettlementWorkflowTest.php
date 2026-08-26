<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\SalesSettlementLogic;
use app\api\jxc\logic\WorkforceLogic;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

require_once __DIR__ . '/CustomerReportTestSupport.php';

final class SalesSettlementWorkflowTest extends TestCase
{
    use CustomerReportTestSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareCustomerReportRequestContext();
        $this->ensureCustomerReportTables();
        $this->cleanCustomerReportData();
        $this->createCustomerReportUnit('斤');
    }

    protected function tearDown(): void
    {
        $this->prepareCustomerReportRequestContext();
        $this->cleanCustomerReportData();
        parent::tearDown();
    }

    public function test_sales_settlement_migration_is_safe_to_replay(): void
    {
        $migration = $this->prepareMigration((string)file_get_contents(
            dirname(__DIR__, 2) . '/database/migrations/20260819_000003_sales_settlement_versions.sql'
        ));
        $this->runStatements($migration);
        $this->runStatements($migration);

        foreach (['la_sales_order_version', 'la_sales_settlement_action', 'la_sales_weight_difference_todo', 'la_customer_sales_preference', 'la_sales_settlement_setting', 'la_sales_delivery_correction'] as $table) {
            self::assertNotEmpty(Db::query("SHOW TABLES LIKE '{$table}'"));
        }
        foreach (['settlement_version', 'goods_amount', 'rounding_amount', 'debt_after_order', 'show_cumulative_debt'] as $column) {
            self::assertNotEmpty(Db::query("SHOW COLUMNS FROM `la_sales_order` LIKE '{$column}'"));
        }
        foreach (['customer_settlement_quantity', 'billing_weight_difference', 'pricing_unit_name', 'price_status', 'zero_price_reason'] as $column) {
            self::assertNotEmpty(Db::query("SHOW COLUMNS FROM `la_order_goods` LIKE '{$column}'"));
        }

        $printMigration = $this->prepareMigration((string)file_get_contents(
            dirname(__DIR__, 2) . '/database/migrations/20260819_000004_customer_sales_print_receipts.sql'
        ));
        $this->runStatements($printMigration);
        $this->runStatements($printMigration);
        self::assertNotEmpty(Db::query("SHOW TABLES LIKE 'la_customer_sales_print_log'"));
    }

    public function test_customer_identity_uses_first_valid_report_item_identically_for_detail_and_list(): void
    {
        $fixture = $this->pendingOrderFixture('2.0000');
        $mainName = (string)Db::name('customer')->where('id', $fixture['customer_id'])->value('customer_name');
        $firstChildName = '结算子客户-首项-' . uniqid();
        $firstChildId = $this->createCustomer($firstChildName, $fixture['customer_id']);
        $secondChildName = '结算子客户-后项-' . uniqid();
        $secondChildId = $this->createCustomer($secondChildName, $fixture['customer_id']);
        $reportId = (int)Db::name('sales_order')->where('id', $fixture['order_id'])->value('source_id');
        $now = time();
        $firstSourceLineId = $fixture['source_line_id'];
        $secondSourceLineId = $firstSourceLineId + 1;
        $reportItem = [
            'tenant_id' => self::TENANT_ID,
            'report_id' => $reportId,
            'main_customer_id' => $fixture['customer_id'],
            'main_customer_name' => $mainName,
            'warehouse_id' => $fixture['warehouse_id'],
            'goods_id' => $fixture['goods_id'],
            'goods_name' => '结算商品',
            'sku_id' => $fixture['sku_id'],
            'unit_id' => $fixture['unit_id'],
            'unit_name' => '斤',
            'base_unit_id' => $fixture['unit_id'],
            'base_unit_name' => '斤',
            'order_qty' => '2.00',
            'expected_base_qty' => '2.00',
            'fulfilled_base_qty' => '2.00',
            'create_time' => $now,
            'update_time' => $now,
        ];
        Db::name('customer_report_item')->insert(array_merge($reportItem, [
            'id' => $firstSourceLineId,
            'delivery_customer_id' => $firstChildId,
            'delivery_customer_name' => $firstChildName,
        ]));
        Db::name('customer_report_item')->insert(array_merge($reportItem, [
            'id' => $secondSourceLineId,
            'delivery_customer_id' => $secondChildId,
            'delivery_customer_name' => $secondChildName,
        ]));
        Db::name('order_goods')->where('id', $fixture['order_goods_id'])->update([
            'source_line_id' => $secondSourceLineId,
            'update_time' => $now,
        ]);
        $secondOrderGoods = Db::name('order_goods')->where('id', $fixture['order_goods_id'])->find();
        unset($secondOrderGoods['id']);
        $secondOrderGoods['source_line_id'] = $firstSourceLineId;
        $secondOrderGoods['sort'] = 2;
        $secondOrderGoods['create_time'] = $now;
        $secondOrderGoods['update_time'] = $now;
        Db::name('order_goods')->insert($secondOrderGoods);

        $detail = SalesSettlementLogic::detail(['id' => $fixture['order_id']]);

        self::assertNotFalse($detail, SalesSettlementLogic::getError());
        self::assertSame($fixture['customer_id'], $detail['main_customer_id']);
        self::assertSame($mainName, $detail['main_customer_name']);
        self::assertSame($firstChildId, $detail['delivery_customer_id']);
        self::assertSame($firstChildName, $detail['delivery_customer_name']);
        self::assertSame($firstChildName, $detail['sub_customer_name']);
        self::assertSame($mainName, $detail['customer_name']);

        $list = SalesSettlementLogic::lists(['status' => 'all']);
        self::assertNotFalse($list, SalesSettlementLogic::getError());
        $listedOrder = array_values(array_filter(
            $list['lists'],
            static fn(array $row): bool => (int)$row['order_id'] === $fixture['order_id'],
        ))[0] ?? null;
        self::assertNotNull($listedOrder);
        self::assertSame($mainName, $listedOrder['main_customer_name']);
        self::assertSame($firstChildName, $listedOrder['sub_customer_name']);

        Db::name('customer_report_item')->where('id', $firstSourceLineId)->update([
            'delete_time' => $now,
            'update_time' => $now,
        ]);
        $detailAfterSoftDelete = SalesSettlementLogic::detail(['id' => $fixture['order_id']]);
        self::assertNotFalse($detailAfterSoftDelete, SalesSettlementLogic::getError());
        self::assertSame($secondChildName, $detailAfterSoftDelete['sub_customer_name']);
        $listAfterSoftDelete = SalesSettlementLogic::lists(['status' => 'all']);
        self::assertNotFalse($listAfterSoftDelete, SalesSettlementLogic::getError());
        $listedOrderAfterSoftDelete = array_values(array_filter(
            $listAfterSoftDelete['lists'],
            static fn(array $row): bool => (int)$row['order_id'] === $fixture['order_id'],
        ))[0] ?? null;
        self::assertNotNull($listedOrderAfterSoftDelete);
        self::assertSame($secondChildName, $listedOrderAfterSoftDelete['sub_customer_name']);
    }

    public function test_print_receipts_accumulate_for_one_sales_order_across_versions_and_devices(): void
    {
        $fixture = $this->pendingOrderFixture('2.0000');
        $v1 = SalesSettlementLogic::submit($this->settlementPayload(
            $fixture,
            'settlement-print-receipt-v1',
            '2.0000',
            '10.00'
        ));
        self::assertNotFalse($v1, SalesSettlementLogic::getError());

        $first = SalesSettlementLogic::preparePrint([
            'id' => $fixture['order_id'],
            'expected_version' => 1,
            'idempotency_key' => 'customer-sales-print-v1-first',
        ]);
        self::assertNotFalse($first, SalesSettlementLogic::getError());
        self::assertSame(1, $first['copy_no']);
        self::assertFalse($first['is_reprint']);
        self::assertSame(0, $first['successful_print_count']);
        self::assertSame($first, SalesSettlementLogic::preparePrint([
            'id' => $fixture['order_id'],
            'expected_version' => 1,
            'idempotency_key' => 'customer-sales-print-v1-first',
        ]));
        self::assertSame(1, Db::name('customer_sales_print_log')->where('order_id', $fixture['order_id'])->count());
        self::assertFalse(SalesSettlementLogic::preparePrint([
            'id' => $fixture['order_id'],
            'expected_version' => 1,
            'idempotency_key' => 'customer-sales-print-v1-concurrent',
        ]));
        self::assertSame('上一张销售单的打印回执尚未确认', SalesSettlementLogic::getError());

        $firstResult = SalesSettlementLogic::printResult([
            'id' => $fixture['order_id'],
            'print_log_id' => $first['print_log_id'],
            'success' => 1,
        ]);
        self::assertNotFalse($firstResult, SalesSettlementLogic::getError());
        self::assertSame(1, $firstResult['successful_print_count']);
        self::assertSame($firstResult, SalesSettlementLogic::printResult([
            'id' => $fixture['order_id'],
            'print_log_id' => $first['print_log_id'],
            'success' => 1,
        ]));

        $v2Payload = $this->settlementPayload($fixture, 'settlement-print-receipt-v2', '2.0000', '11.00');
        $v2Payload['expected_version'] = 1;
        $v2Payload['edit_reason'] = '录入更正：打印后修正最终实价';
        $v2 = SalesSettlementLogic::submit($v2Payload);
        self::assertNotFalse($v2, SalesSettlementLogic::getError());

        $v2Detail = SalesSettlementLogic::detail(['id' => $fixture['order_id']]);
        self::assertNotFalse($v2Detail, SalesSettlementLogic::getError());
        self::assertSame(2, $v2Detail['version']);
        self::assertSame(1, $v2Detail['successful_print_count']);
        $recoveredV1 = SalesSettlementLogic::preparePrint([
            'id' => $fixture['order_id'],
            'expected_version' => 1,
            'idempotency_key' => 'customer-sales-print-v1-first',
        ]);
        self::assertNotFalse($recoveredV1, SalesSettlementLogic::getError());
        self::assertSame($first['print_log_id'], $recoveredV1['print_log_id']);
        self::assertSame(1, $recoveredV1['version']);
        self::assertSame('success', $recoveredV1['status']);
        self::assertFalse(SalesSettlementLogic::preparePrint([
            'id' => $fixture['order_id'],
            'expected_version' => 1,
            'idempotency_key' => 'customer-sales-print-stale-v1',
        ]));
        self::assertSame('销售单版本已变化，请重新加载后再打印', SalesSettlementLogic::getError());

        $second = SalesSettlementLogic::preparePrint([
            'id' => $fixture['order_id'],
            'expected_version' => 2,
            'idempotency_key' => 'customer-sales-print-v2-second',
        ]);
        self::assertNotFalse($second, SalesSettlementLogic::getError());
        self::assertSame(2, $second['copy_no']);
        self::assertTrue($second['is_reprint']);
        self::assertSame(1, $second['reprint_count']);
        self::assertSame(2, $second['version']);

        $failedResult = SalesSettlementLogic::printResult([
            'id' => $fixture['order_id'],
            'print_log_id' => $second['print_log_id'],
            'success' => 0,
            'error_message' => '测试打印机断开',
        ]);
        self::assertNotFalse($failedResult, SalesSettlementLogic::getError());
        self::assertSame(1, $failedResult['successful_print_count']);
        self::assertSame(1, Db::name('customer_sales_print_log')->where('order_id', $fixture['order_id'])
            ->where('status', 'success')->count());
    }

    public function test_lost_v1_print_prepare_can_be_replayed_and_closed_after_v2_is_saved(): void
    {
        $fixture = $this->pendingOrderFixture('2.0000');
        self::assertNotFalse(SalesSettlementLogic::submit($this->settlementPayload(
            $fixture,
            'settlement-lost-print-v1',
            '2.0000',
            '10.00'
        )), SalesSettlementLogic::getError());
        $lost = SalesSettlementLogic::preparePrint([
            'id' => $fixture['order_id'],
            'expected_version' => 1,
            'idempotency_key' => 'customer-sales-lost-prepare-v1',
        ]);
        self::assertNotFalse($lost, SalesSettlementLogic::getError());

        $v2Payload = $this->settlementPayload($fixture, 'settlement-after-lost-print-v2', '2.0000', '11.00');
        $v2Payload['expected_version'] = 1;
        $v2Payload['edit_reason'] = '录入更正：打印准备响应丢失后修改价格';
        self::assertNotFalse(SalesSettlementLogic::submit($v2Payload), SalesSettlementLogic::getError());

        $replayed = SalesSettlementLogic::preparePrint([
            'id' => $fixture['order_id'],
            'expected_version' => 1,
            'idempotency_key' => 'customer-sales-lost-prepare-v1',
        ]);
        self::assertNotFalse($replayed, SalesSettlementLogic::getError());
        self::assertSame($lost['print_log_id'], $replayed['print_log_id']);
        self::assertSame('pending', $replayed['status']);
        self::assertNotFalse(SalesSettlementLogic::printResult([
            'id' => $fixture['order_id'],
            'print_log_id' => $replayed['print_log_id'],
            'success' => 0,
            'error_message' => '打印准备响应中断，客户端未启动打印',
        ]), SalesSettlementLogic::getError());

        $v2Print = SalesSettlementLogic::preparePrint([
            'id' => $fixture['order_id'],
            'expected_version' => 2,
            'idempotency_key' => 'customer-sales-print-after-recovery-v2',
        ]);
        self::assertNotFalse($v2Print, SalesSettlementLogic::getError());
        self::assertSame(2, $v2Print['version']);
        self::assertSame(1, $v2Print['copy_no']);
    }

    public function test_late_success_receipt_remains_replayable_after_another_device_reserves_the_next_copy(): void
    {
        $fixture = $this->pendingOrderFixture('2.0000');
        self::assertNotFalse(SalesSettlementLogic::submit($this->settlementPayload(
            $fixture,
            'settlement-late-print-receipt',
            '2.0000',
            '10.00'
        )), SalesSettlementLogic::getError());

        $first = SalesSettlementLogic::preparePrint([
            'id' => $fixture['order_id'],
            'expected_version' => 1,
            'idempotency_key' => 'customer-sales-print-device-a',
        ]);
        self::assertNotFalse($first, SalesSettlementLogic::getError());
        Db::name('customer_sales_print_log')->where('id', $first['print_log_id'])->update([
            'create_time' => time() - 601,
            'update_time' => time() - 601,
        ]);

        $second = SalesSettlementLogic::preparePrint([
            'id' => $fixture['order_id'],
            'expected_version' => 1,
            'idempotency_key' => 'customer-sales-print-device-b',
        ]);
        self::assertNotFalse($second, SalesSettlementLogic::getError());
        self::assertSame(2, $second['copy_no']);
        self::assertSame('pending', Db::name('customer_sales_print_log')
            ->where('id', $first['print_log_id'])->value('status'));

        $lateFirstResult = SalesSettlementLogic::printResult([
            'id' => $fixture['order_id'],
            'print_log_id' => $first['print_log_id'],
            'success' => 1,
        ]);
        self::assertNotFalse($lateFirstResult, SalesSettlementLogic::getError());
        self::assertSame(1, $lateFirstResult['successful_print_count']);

        $secondResult = SalesSettlementLogic::printResult([
            'id' => $fixture['order_id'],
            'print_log_id' => $second['print_log_id'],
            'success' => 1,
        ]);
        self::assertNotFalse($secondResult, SalesSettlementLogic::getError());
        self::assertSame(2, $secondResult['successful_print_count']);
        self::assertSame(2, Db::name('customer_sales_print_log')
            ->where('order_id', $fixture['order_id'])->where('status', 'success')->count());
    }

    public function test_migration_backfills_existing_formal_customer_report_order_as_v1_without_readding_receivable(): void
    {
        $fixture = $this->pendingOrderFixture('2.0000');
        Db::name('order_goods')->where('id', $fixture['order_goods_id'])->update([
            'number' => '2.0000', 'price' => '10.00', 'amount' => '20.00',
            'pricing_unit_id' => $fixture['unit_id'], 'units' => '斤',
            'customer_settlement_quantity' => '0.0000', 'pricing_unit_name' => '', 'price_status' => 'unpriced',
        ]);
        Db::name('sales_order')->where('id', $fixture['order_id'])->update([
            'settlement_status' => 'formal', 'settlement_version' => 0,
            'order_money' => '20.00', 'goods_amount' => '0.00', 'debt_after_order' => '0.00',
        ]);
        Db::name('customer')->where('id', $fixture['customer_id'])->update([
            'order_receivable' => '20.00', 'order_money' => '20.00',
        ]);
        $migration = $this->prepareMigration((string)file_get_contents(
            dirname(__DIR__, 2) . '/database/migrations/20260819_000003_sales_settlement_versions.sql'
        ));
        $this->runStatements($migration);

        self::assertSame(1, (int)Db::name('sales_order')->where('id', $fixture['order_id'])->value('settlement_version'));
        self::assertSame(1, Db::name('sales_order_version')->where('order_id', $fixture['order_id'])->where('version', 1)->count());
        self::assertSame('2.0000', (string)Db::name('order_goods')->where('id', $fixture['order_goods_id'])->value('customer_settlement_quantity'));
        self::assertSame('斤', (string)Db::name('order_goods')->where('id', $fixture['order_goods_id'])->value('pricing_unit_name'));

        $v2 = $this->settlementPayload($fixture, 'settlement-migrated-formal-v2', '2.0000', '11.00');
        $v2['expected_version'] = 1;
        $v2['edit_reason'] = '迁移后录入更正：最终实价应为11元';
        $result = SalesSettlementLogic::submit($v2);
        self::assertNotFalse($result, SalesSettlementLogic::getError());
        self::assertSame(2, $result['version']);
        self::assertSame('22.00', $result['debt_after_order']);
        self::assertSame('22.00', (string)Db::name('customer')->where('id', $fixture['customer_id'])->value('order_receivable'));
        self::assertSame(1, Db::name('receivable_flow')->where('order_id', $fixture['order_id'])->count());
        self::assertSame('2.00', (string)Db::name('receivable_flow')->where('order_id', $fixture['order_id'])->value('amount'));
    }

    public function test_migration_preserves_a_formal_zero_price_line_as_explicit_zero_with_an_audit_reason(): void
    {
        $fixture = $this->pendingOrderFixture('1.0000');
        Db::name('order_goods')->where('id', $fixture['order_goods_id'])->update([
            'number' => '1.0000', 'price' => '0.00', 'amount' => '0.00',
            'pricing_unit_id' => $fixture['unit_id'], 'units' => '斤',
            'customer_settlement_quantity' => '0.0000', 'pricing_unit_name' => '',
            'price_status' => 'unpriced', 'zero_price_reason' => '',
        ]);
        Db::name('sales_order')->where('id', $fixture['order_id'])->update([
            'settlement_status' => 'formal', 'settlement_version' => 0,
            'order_money' => '0.00', 'goods_amount' => '0.00', 'debt_after_order' => '0.00',
        ]);

        $migration = $this->prepareMigration((string)file_get_contents(
            dirname(__DIR__, 2) . '/database/migrations/20260819_000003_sales_settlement_versions.sql'
        ));
        $this->runStatements($migration);

        $line = Db::name('order_goods')->where('id', $fixture['order_goods_id'])->find();
        self::assertSame('zero', (string)$line['price_status']);
        self::assertSame('迁移确认：存量正式销售单零价', (string)$line['zero_price_reason']);
        self::assertSame(1, (int)Db::name('sales_order')->where('id', $fixture['order_id'])->value('settlement_version'));
        self::assertSame(1, Db::name('sales_order_version')->where('order_id', $fixture['order_id'])->where('version', 1)->count());
    }

    public function test_equal_weight_confirmation_creates_one_formal_v1_receivable_and_no_stock_flow(): void
    {
        $fixture = $this->pendingOrderFixture('2.0000', '5.00');
        $payload = $this->settlementPayload($fixture, 'settlement-v1-equal', '2.0000', '10.00', '1.00');

        $result = SalesSettlementLogic::submit($payload);
        self::assertNotFalse($result, SalesSettlementLogic::getError());
        self::assertSame('formal', $result['settlement_status']);
        self::assertSame(1, $result['version']);
        self::assertSame('19.00', $result['order_money']);
        self::assertSame('24.00', $result['debt_after_order']);
        self::assertSame(0, Db::name('stock_flow')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame(1, Db::name('receivable_flow')->where('order_id', $fixture['order_id'])->count());
        self::assertSame('19.00', (string)Db::name('receivable_flow')->where('order_id', $fixture['order_id'])->value('amount'));
        self::assertSame('2.0000', (string)Db::name('order_goods')->where('id', $fixture['order_goods_id'])->value('base_quantity'));
        self::assertSame('2.0000', (string)Db::name('order_goods')->where('id', $fixture['order_goods_id'])->value('customer_settlement_quantity'));

        $replay = SalesSettlementLogic::submit($payload);
        self::assertSame($result, $replay);
        self::assertSame(1, Db::name('sales_order_version')->where('order_id', $fixture['order_id'])->count());
        self::assertSame(1, Db::name('receivable_flow')->where('order_id', $fixture['order_id'])->count());
    }

    public function test_nonzero_billing_weight_difference_stays_pending_until_highest_authority_resolves_it(): void
    {
        $fixture = $this->pendingOrderFixture('2.0000');
        $pending = SalesSettlementLogic::submit(
            $this->settlementPayload($fixture, 'settlement-weight-review', '2.1000', '10.00')
        );

        self::assertNotFalse($pending, SalesSettlementLogic::getError());
        self::assertSame('pending_weight_review', $pending['settlement_status']);
        self::assertSame(0, $pending['version']);
        self::assertFalse($pending['can_print']);
        self::assertSame(0, Db::name('sales_order_version')->where('order_id', $fixture['order_id'])->count());
        self::assertSame(0, Db::name('receivable_flow')->where('order_id', $fixture['order_id'])->count());
        $todo = Db::name('sales_weight_difference_todo')->where('order_id', $fixture['order_id'])->find();
        self::assertNotEmpty($todo);
        self::assertSame('highest_privilege', $todo['assignee_scope']);
        self::assertSame('open', $todo['status']);
        $todos = SalesSettlementLogic::weightDifferenceTodos(['status' => 'open']);
        self::assertNotFalse($todos, SalesSettlementLogic::getError());
        self::assertCount(1, $todos['lists']);
        self::assertSame('0.1000', $todos['lists'][0]['differences'][0]['billing_weight_difference']);
        $detail = SalesSettlementLogic::detail(['id' => $fixture['order_id']]);
        self::assertNotFalse($detail, SalesSettlementLogic::getError());
        self::assertFalse($detail['can_print']);

        $resolved = SalesSettlementLogic::resolveWeightDifference([
            'id' => (int)$todo['id'],
            'decision' => 'approve',
            'reason' => '核对纸票与客户计费约定，确认按2.1斤计费',
            'idempotency_key' => 'settlement-weight-review-resolve',
        ]);
        self::assertNotFalse($resolved, SalesSettlementLogic::getError());
        self::assertSame('formal', $resolved['settlement_status']);
        self::assertSame(1, $resolved['version']);
        self::assertSame('21.00', $resolved['order_money']);
        self::assertSame('closed', (string)Db::name('sales_weight_difference_todo')->where('id', (int)$todo['id'])->value('status'));
        self::assertSame(1, Db::name('sales_order_version')->where('order_id', $fixture['order_id'])->count());
        self::assertSame(1, Db::name('receivable_flow')->where('order_id', $fixture['order_id'])->count());
        self::assertSame(0, Db::name('stock_flow')->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_unpriced_and_invalid_rounding_are_rejected_but_explicit_zero_price_is_distinct(): void
    {
        $unpriced = $this->pendingOrderFixture('1.0000');
        $payload = $this->settlementPayload($unpriced, 'settlement-unpriced', '1.0000', null);
        self::assertFalse(SalesSettlementLogic::submit($payload));
        self::assertSame('存在未定价商品，不能生成正式客户销售单', SalesSettlementLogic::getError());

        $zero = $this->pendingOrderFixture('1.0000');
        $zeroPayload = $this->settlementPayload($zero, 'settlement-zero', '1.0000', '0');
        self::assertFalse(SalesSettlementLogic::submit($zeroPayload));
        self::assertSame('零价商品必须填写零价原因', SalesSettlementLogic::getError());
        $zeroPayload['lines'][0]['zero_price_reason'] = '开业赠送';
        $zeroResult = SalesSettlementLogic::submit($zeroPayload);
        self::assertNotFalse($zeroResult, SalesSettlementLogic::getError());
        self::assertSame('0.00', $zeroResult['order_money']);
        self::assertSame('zero', (string)Db::name('order_goods')->where('id', $zero['order_goods_id'])->value('price_status'));
        self::assertSame(0, Db::name('receivable_flow')->where('order_id', $zero['order_id'])->count());

        $rounded = $this->pendingOrderFixture('1.0000');
        $invalidRounding = $this->settlementPayload($rounded, 'settlement-round-to-zero', '1.0000', '10.00', '10.00');
        self::assertFalse(SalesSettlementLogic::submit($invalidRounding));
        self::assertSame('抹零金额必须小于商品合计且不能把正常销售单减至零元', SalesSettlementLogic::getError());
    }

    public function test_rounding_threshold_requires_reason_and_second_confirmation(): void
    {
        Db::name('sales_settlement_setting')->insert([
            'tenant_id' => self::TENANT_ID,
            'rounding_confirm_threshold' => '5.00',
            'create_time' => time(),
            'update_time' => time(),
        ]);
        $fixture = $this->pendingOrderFixture('2.0000');
        $payload = $this->settlementPayload($fixture, 'settlement-rounding-threshold', '2.0000', '10.00', '6.00');
        self::assertFalse(SalesSettlementLogic::submit($payload));
        self::assertSame('抹零超过阈值，必须填写原因并二次确认', SalesSettlementLogic::getError());

        $payload['rounding_reason'] = '客户长期合作，本单经店长确认抹零';
        $payload['second_confirmed'] = 1;
        $result = SalesSettlementLogic::submit($payload);
        self::assertNotFalse($result, SalesSettlementLogic::getError());
        self::assertSame('14.00', $result['order_money']);
        self::assertSame('6.00', (string)Db::name('sales_order')->where('id', $fixture['order_id'])->value('rounding_amount'));
    }

    public function test_rounding_requires_highest_authority_even_when_employee_can_bill(): void
    {
        $fixture = $this->pendingOrderFixture('2.0000');
        $employee = WorkforceLogic::saveEmployee([
            'name' => '普通结算员', 'mobile' => '13800009921', 'bind_user_id' => 996921, 'is_enabled' => 1,
            'process_ids' => [], 'permission_keys' => ['settlement.bill'],
        ]);
        self::assertNotFalse($employee, WorkforceLogic::getError());
        Db::execute('CREATE TABLE IF NOT EXISTS `la_user_session` ('
            . '`id` int unsigned NOT NULL AUTO_INCREMENT, `user_id` int unsigned NOT NULL DEFAULT 0, '
            . '`token` varchar(128) NOT NULL DEFAULT \'\', `expire_time` int unsigned NOT NULL DEFAULT 0, '
            . '`update_time` int unsigned NOT NULL DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID];
        request()->adminId = 0;
        request()->userId = 996921;

        $payload = $this->settlementPayload($fixture, 'settlement-rounding-highest-only', '2.0000', '10.00', '1.00');
        self::assertFalse(SalesSettlementLogic::submit($payload));
        self::assertSame('只有最高权限人员可以执行销售单抹零', SalesSettlementLogic::getError());
        self::assertSame(0, Db::name('sales_settlement_action')->where('order_id', $fixture['order_id'])->count());
        $this->prepareCustomerReportRequestContext();
    }

    public function test_saved_order_uses_optimistic_v2_and_preserves_debt_snapshots(): void
    {
        $fixture = $this->pendingOrderFixture('2.0000');
        $v1 = SalesSettlementLogic::submit($this->settlementPayload($fixture, 'settlement-version-v1', '2.0000', '10.00'));
        self::assertNotFalse($v1, SalesSettlementLogic::getError());

        $v2Payload = $this->settlementPayload($fixture, 'settlement-version-v2', '2.0000', '11.00');
        $v2Payload['expected_version'] = 1;
        $v2Payload['edit_reason'] = '录入更正：最终实价应为11元';
        $v2Payload['show_cumulative_debt'] = 1;
        $v2 = SalesSettlementLogic::submit($v2Payload);
        self::assertNotFalse($v2, SalesSettlementLogic::getError());
        self::assertSame(2, $v2['version']);
        self::assertSame('22.00', $v2['order_money']);
        self::assertSame(0, Db::name('stock_flow')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame(2, Db::name('receivable_flow')->where('order_id', $fixture['order_id'])->count());

        $versions = Db::name('sales_order_version')->where('order_id', $fixture['order_id'])->order('version', 'asc')->select()->toArray();
        self::assertCount(2, $versions);
        self::assertSame('20.00', (string)$versions[0]['order_money']);
        self::assertSame('20.00', (string)$versions[0]['debt_after_order']);
        self::assertSame(0, (int)$versions[0]['show_cumulative_debt']);
        self::assertSame('22.00', (string)$versions[1]['order_money']);
        self::assertSame('22.00', (string)$versions[1]['debt_after_order']);
        self::assertSame(1, (int)$versions[1]['show_cumulative_debt']);

        $stale = $v2Payload;
        $stale['idempotency_key'] = 'settlement-version-stale';
        self::assertFalse(SalesSettlementLogic::submit($stale));
        self::assertSame('销售单版本已变化，请重新加载后再保存', SalesSettlementLogic::getError());
    }

    public function test_detail_and_submit_reject_cross_tenant_id_guessing(): void
    {
        $fixture = $this->pendingOrderFixture('1.0000');
        $payload = $this->settlementPayload($fixture, 'settlement-cross-tenant', '1.0000', '8.00');
        $this->prepareCustomerReportRequestContext(self::OTHER_TENANT_ID);

        self::assertFalse(SalesSettlementLogic::detail(['id' => $fixture['order_id']]));
        self::assertSame('销售单不存在', SalesSettlementLogic::getError());
        self::assertFalse(SalesSettlementLogic::submit($payload));
        self::assertSame('销售单不存在', SalesSettlementLogic::getError());
        self::assertSame(0, Db::name('sales_order_version')->where('tenant_id', self::OTHER_TENANT_ID)->count());
        self::assertSame(0, Db::name('receivable_flow')->where('tenant_id', self::OTHER_TENANT_ID)->count());
    }

    public function test_audit_failure_rolls_back_version_receivable_and_draft_changes(): void
    {
        $fixture = $this->pendingOrderFixture('2.0000', '3.00');
        Db::execute('RENAME TABLE `la_audit_log` TO `la_audit_log_sales_settlement_failure`');
        try {
            self::assertFalse(SalesSettlementLogic::submit(
                $this->settlementPayload($fixture, 'settlement-audit-rollback', '2.0000', '9.00')
            ));
        } finally {
            Db::execute('RENAME TABLE `la_audit_log_sales_settlement_failure` TO `la_audit_log`');
        }
        $order = Db::name('sales_order')->where('id', $fixture['order_id'])->find();
        self::assertSame('pending', (string)$order['settlement_status']);
        self::assertSame(0, (int)$order['settlement_version']);
        self::assertSame('0.00', (string)$order['order_money']);
        self::assertSame('3.00', (string)Db::name('customer')->where('id', $fixture['customer_id'])->value('order_receivable'));
        self::assertSame(0, Db::name('sales_settlement_action')->where('order_id', $fixture['order_id'])->count());
        self::assertSame(0, Db::name('sales_order_version')->where('order_id', $fixture['order_id'])->count());
        self::assertSame(0, Db::name('receivable_flow')->where('order_id', $fixture['order_id'])->count());
    }

    public function test_two_processes_with_one_key_return_one_version_and_one_receivable_fact(): void
    {
        $fixture = $this->pendingOrderFixture('2.0000');
        $settlement = $this->settlementPayload($fixture, 'settlement-concurrent-one-key', '2.0000', '10.00');
        $startPath = tempnam(sys_get_temp_dir(), 'sales-settlement-start-');
        unlink($startPath);
        $paths = [];
        try {
            $processes = [];
            for ($worker = 0; $worker < 2; $worker++) {
                $inputPath = tempnam(sys_get_temp_dir(), 'sales-settlement-input-');
                $outputPath = tempnam(sys_get_temp_dir(), 'sales-settlement-output-');
                $paths[] = $inputPath;
                $paths[] = $outputPath;
                file_put_contents($inputPath, json_encode([
                    'tenant_id' => self::TENANT_ID,
                    'admin_id' => self::ADMIN_ID,
                    'settlement' => $settlement,
                ], JSON_UNESCAPED_UNICODE));
                $command = escapeshellarg(PHP_BINARY) . ' '
                    . escapeshellarg(dirname(__DIR__) . '/fixtures/sales_settlement_worker.php') . ' '
                    . escapeshellarg($inputPath) . ' ' . escapeshellarg($outputPath) . ' '
                    . escapeshellarg($startPath);
                $processes[] = [proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes), $pipes, $outputPath];
            }
            usleep(100_000);
            touch($startPath);
            $responses = [];
            foreach ($processes as [$process, $pipes, $outputPath]) {
                self::assertIsResource($process);
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exit = proc_close($process);
                self::assertSame(0, $exit, $stdout . $stderr);
                $responses[] = json_decode((string)file_get_contents($outputPath), true);
            }
            self::assertSame($responses[0]['result'], $responses[1]['result']);
            self::assertSame(1, $responses[0]['result']['version']);
            self::assertSame(1, Db::name('sales_settlement_action')->where('idempotency_key', 'settlement-concurrent-one-key')->count());
            self::assertSame(1, Db::name('sales_order_version')->where('order_id', $fixture['order_id'])->count());
            self::assertSame(1, Db::name('receivable_flow')->where('order_id', $fixture['order_id'])->count());
            self::assertSame(0, Db::name('stock_flow')->where('tenant_id', self::TENANT_ID)->count());
        } finally {
            if (is_file($startPath)) {
                unlink($startPath);
            }
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function test_pricing_unit_conversion_and_cost_pending_profit_are_explicit(): void
    {
        $kilogram = $this->pendingOrderFixture('2.0000');
        $kilogramUnitId = $this->bindSalesPricingUnit($kilogram, '公斤');
        Db::name('sales_order')->where('id', $kilogram['order_id'])->update([
            'cost_status' => 'pending',
            'profit_status' => 'cost_pending',
        ]);
        $payload = $this->settlementPayload($kilogram, 'settlement-kilogram', '2.0000', '10.00');
        $payload['lines'][0]['pricing_unit_id'] = $kilogramUnitId;
        $payload['lines'][0]['pricing_unit_name'] = '公斤';
        $result = SalesSettlementLogic::submit($payload);
        self::assertNotFalse($result, SalesSettlementLogic::getError());
        self::assertSame('10.00', $result['order_money']);
        self::assertSame('cost_pending', $result['profit_status']);
        self::assertSame('1.0000', (string)Db::name('order_goods')->where('id', $kilogram['order_goods_id'])->value('number'));
        self::assertSame('cost_pending', (string)Db::name('sales_order')->where('id', $kilogram['order_id'])->value('profit_status'));

        $counted = $this->pendingOrderFixture('2.0000');
        $countUnitId = $this->bindSalesPricingUnit($counted, '件');
        $countPayload = $this->settlementPayload($counted, 'settlement-counted', '2.0000', '12.00');
        $countPayload['lines'][0]['pricing_unit_id'] = $countUnitId;
        $countPayload['lines'][0]['pricing_unit_name'] = '件';
        self::assertFalse(SalesSettlementLogic::submit($countPayload));
        self::assertSame('计件与计重换算必须提供已确认的单件重量', SalesSettlementLogic::getError());
        $countPayload['lines'][0]['pricing_quantity'] = '1';
        $countPayload['lines'][0]['per_unit_weight'] = '2';
        $countResult = SalesSettlementLogic::submit($countPayload);
        self::assertNotFalse($countResult, SalesSettlementLogic::getError());
        self::assertSame('12.00', $countResult['order_money']);
    }

    public function test_pricing_unit_is_tenant_goods_bound_and_uses_server_name(): void
    {
        $fixture = $this->pendingOrderFixture('2.0000');
        $payload = $this->settlementPayload($fixture, 'settlement-unit-server-name', '2.0000', '10.00');
        $payload['lines'][0]['pricing_unit_name'] = '公斤';
        $result = SalesSettlementLogic::submit($payload);
        self::assertNotFalse($result, SalesSettlementLogic::getError());
        self::assertSame('20.00', $result['order_money']);
        self::assertSame('斤', (string)Db::name('order_goods')->where('id', $fixture['order_goods_id'])->value('pricing_unit_name'));

        $other = $this->pendingOrderFixture('1.0000');
        $foreignUnitId = (int)Db::name('goods_unit')->insertGetId([
            'tenant_id' => self::OTHER_TENANT_ID, 'name' => '公斤', 'status' => 1, 'sort' => 0,
            'create_time' => time(), 'update_time' => time(),
        ]);
        $foreign = $this->settlementPayload($other, 'settlement-unit-cross-tenant', '1.0000', '10.00');
        $foreign['lines'][0]['pricing_unit_id'] = $foreignUnitId;
        $foreign['lines'][0]['pricing_unit_name'] = '公斤';
        self::assertFalse(SalesSettlementLogic::submit($foreign));
        self::assertSame('计价单位未绑定到当前商品或已经停用', SalesSettlementLogic::getError());
        self::assertSame(0, Db::name('sales_settlement_action')->where('order_id', $other['order_id'])->count());

        $zeroId = $this->pendingOrderFixture('1.0000');
        $zero = $this->settlementPayload($zeroId, 'settlement-unit-zero-id', '1.0000', '10.00');
        $zero['lines'][0]['pricing_unit_id'] = 0;
        self::assertFalse(SalesSettlementLogic::submit($zero));
        self::assertSame('销售结算商品明细参数不正确', SalesSettlementLogic::getError());
    }

    public function test_same_idempotency_key_cannot_overwrite_a_different_settlement_fact(): void
    {
        $fixture = $this->pendingOrderFixture('1.0000');
        $payload = $this->settlementPayload($fixture, 'settlement-key-fingerprint', '1.0000', '8.00');
        self::assertNotFalse(SalesSettlementLogic::submit($payload), SalesSettlementLogic::getError());
        $payload['lines'][0]['price'] = '9.00';
        self::assertFalse(SalesSettlementLogic::submit($payload));
        self::assertSame('同一幂等键不能提交不同的销售结算事实', SalesSettlementLogic::getError());
        self::assertSame(1, Db::name('sales_order_version')->where('order_id', $fixture['order_id'])->count());
        self::assertSame('8.00', (string)Db::name('sales_order')->where('id', $fixture['order_id'])->value('order_money'));
    }

    public function test_pending_v2_weight_proposal_never_overwrites_authoritative_v1_and_reject_restores_it(): void
    {
        $fixture = $this->pendingOrderFixture('2.0000');
        $v1 = SalesSettlementLogic::submit($this->settlementPayload(
            $fixture, 'settlement-pending-v2-v1', '2.0000', '10.00'
        ));
        self::assertNotFalse($v1, SalesSettlementLogic::getError());

        $proposal = $this->settlementPayload($fixture, 'settlement-pending-v2-proposal', '2.1000', '11.00');
        $proposal['expected_version'] = 1;
        $proposal['edit_reason'] = '客户结算重量更正为2.1斤';
        $pending = SalesSettlementLogic::submit($proposal);
        self::assertNotFalse($pending, SalesSettlementLogic::getError());
        self::assertSame('pending_weight_review', $pending['settlement_status']);
        $order = Db::name('sales_order')->where('id', $fixture['order_id'])->find();
        self::assertSame(1, (int)$order['settlement_version']);
        self::assertSame('20.00', (string)$order['order_money']);
        self::assertSame('formal', (string)$order['settlement_status']);
        self::assertSame('2.0000', (string)Db::name('order_goods')->where('id', $fixture['order_goods_id'])->value('customer_settlement_quantity'));
        self::assertSame('10.00', (string)Db::name('order_goods')->where('id', $fixture['order_goods_id'])->value('price'));
        self::assertSame('20.00', (string)Db::name('customer')->where('id', $fixture['customer_id'])->value('order_receivable'));
        $detail = SalesSettlementLogic::detail(['id' => $fixture['order_id']]);
        self::assertNotFalse($detail, SalesSettlementLogic::getError());
        self::assertSame('2.1000', $detail['pending_proposal']['lines'][0]['customer_settlement_weight']);
        self::assertSame('formal', $detail['settlement_status']);
        self::assertTrue($detail['can_print']);
        $authoritativeUpdateTime = 123456789;
        Db::name('sales_order')->where('id', $fixture['order_id'])->update(['update_time' => $authoritativeUpdateTime]);

        $todoId = (int)Db::name('sales_weight_difference_todo')->where('order_id', $fixture['order_id'])
            ->where('status', 'open')->value('id');
        $rejected = SalesSettlementLogic::resolveWeightDifference([
            'id' => $todoId,
            'decision' => 'reject',
            'reason' => '复核后仍应按原V1重量与价格结算',
            'idempotency_key' => 'settlement-pending-v2-reject',
        ]);
        self::assertNotFalse($rejected, SalesSettlementLogic::getError());
        self::assertSame('formal', $rejected['settlement_status']);
        $restored = SalesSettlementLogic::detail(['id' => $fixture['order_id']]);
        self::assertNotFalse($restored, SalesSettlementLogic::getError());
        self::assertTrue($restored['can_print']);
        self::assertSame(1, $restored['version']);
        self::assertSame('20.00', $restored['order_money']);
        self::assertNull($restored['pending_proposal']);
        self::assertSame($authoritativeUpdateTime, (int)Db::name('sales_order')->where('id', $fixture['order_id'])->value('update_time'));
    }

    public function test_v2_actual_delivery_decrease_returns_only_the_delta_to_inventory_and_reduces_receivable(): void
    {
        $fixture = $this->pendingOrderFixture('2.0000');
        self::assertNotFalse(SalesSettlementLogic::submit($this->settlementPayload(
            $fixture, 'settlement-actual-decrease-v1', '2.0000', '10.00'
        )), SalesSettlementLogic::getError());

        $v2 = $this->settlementPayload($fixture, 'settlement-actual-decrease-v2', '1.5000', '10.00');
        $v2['expected_version'] = 1;
        $v2['edit_reason'] = '录入更正：纸票实际交付为1.5斤';
        $v2['lines'][0]['actual_delivery_weight'] = '1.5000';
        $result = SalesSettlementLogic::submit($v2);
        self::assertNotFalse($result, SalesSettlementLogic::getError());
        self::assertSame(2, $result['version']);
        self::assertSame('15.00', $result['order_money']);
        self::assertSame('1.5000', (string)Db::name('order_goods')->where('id', $fixture['order_goods_id'])->value('base_quantity'));
        self::assertSame('0.5000', (string)Db::name('warehouse_sku_balance')
            ->where('tenant_id', self::TENANT_ID)->where('warehouse_id', $fixture['warehouse_id'])
            ->where('sku_id', $fixture['sku_id'])->value('on_hand_qty'));
        self::assertSame(1, Db::name('stock_flow')->where('order_id', $fixture['order_id'])
            ->where('order_type', 'sales_delivery_correction')->where('flow_type', 1)->count());
        self::assertSame('inbound', (string)Db::name('sales_delivery_correction')->where('order_id', $fixture['order_id'])->value('direction'));
        self::assertSame('15.00', (string)Db::name('customer')->where('id', $fixture['customer_id'])->value('order_receivable'));
        self::assertSame(2, Db::name('receivable_flow')->where('order_id', $fixture['order_id'])->count());
    }

    public function test_v2_actual_delivery_increase_uses_negative_inventory_threshold_and_rolls_back_before_confirmation(): void
    {
        $fixture = $this->pendingOrderFixture('2.0000');
        self::assertNotFalse(SalesSettlementLogic::submit($this->settlementPayload(
            $fixture, 'settlement-actual-increase-v1', '2.0000', '10.00'
        )), SalesSettlementLogic::getError());
        Db::name('negative_inventory_setting')->where('tenant_id', self::TENANT_ID)->delete();
        Db::name('negative_inventory_setting')->insert([
            'tenant_id' => self::TENANT_ID,
            'quantity_threshold' => '0.1000',
            'amount_threshold' => '999999.00',
            'create_time' => time(),
            'update_time' => time(),
        ]);
        $v2 = $this->settlementPayload($fixture, 'settlement-actual-increase-v2', '2.5000', '10.00');
        $v2['expected_version'] = 1;
        $v2['edit_reason'] = '录入更正：纸票实际交付为2.5斤';
        $v2['lines'][0]['actual_delivery_weight'] = '2.5000';
        self::assertFalse(SalesSettlementLogic::submit($v2));
        self::assertSame('实际交付重量更正造成的负库存超过阈值，必须填写说明并二次确认', SalesSettlementLogic::getError());
        self::assertSame(1, (int)Db::name('sales_order')->where('id', $fixture['order_id'])->value('settlement_version'));
        self::assertSame(0, Db::name('sales_delivery_correction')->where('order_id', $fixture['order_id'])->count());
        self::assertSame(0, Db::name('negative_inventory_attribution')->where('sales_order_id', $fixture['order_id'])->count());
        self::assertSame(0, Db::name('stock_flow')->where('order_id', $fixture['order_id'])->count());

        $v2['inventory_exception_reason'] = '复核纸票和装车记录，确认原实重少录0.5斤';
        $v2['inventory_second_confirmed'] = 1;
        $result = SalesSettlementLogic::submit($v2);
        self::assertNotFalse($result, SalesSettlementLogic::getError());
        self::assertSame(2, $result['version']);
        self::assertSame('25.00', $result['order_money']);
        self::assertSame('-0.5000', (string)Db::name('warehouse_sku_balance')
            ->where('tenant_id', self::TENANT_ID)->where('warehouse_id', $fixture['warehouse_id'])
            ->where('sku_id', $fixture['sku_id'])->value('on_hand_qty'));
        self::assertSame('0.5000', (string)Db::name('negative_inventory_attribution')
            ->where('sales_order_id', $fixture['order_id'])->value('negative_qty'));
        self::assertSame(1, Db::name('negative_inventory_todo')->alias('t')
            ->join('negative_inventory_attribution a', 'a.id=t.attribution_id')
            ->where('a.sales_order_id', $fixture['order_id'])->where('t.status', 'open')->count());
        self::assertSame('outbound', (string)Db::name('sales_delivery_correction')->where('order_id', $fixture['order_id'])->value('direction'));
    }

    public function test_actual_decrease_offsets_the_corrected_order_source_before_other_fifo_sources(): void
    {
        $fixture = $this->pendingOrderFixture('2.0000');
        self::assertNotFalse(SalesSettlementLogic::submit($this->settlementPayload(
            $fixture, 'settlement-directed-offset-v1', '2.0000', '10.00'
        )), SalesSettlementLogic::getError());
        Db::name('warehouse_sku_balance')->insert([
            'tenant_id' => self::TENANT_ID, 'warehouse_id' => $fixture['warehouse_id'],
            'goods_id' => $fixture['goods_id'], 'sku_id' => $fixture['sku_id'], 'base_unit_id' => $fixture['unit_id'],
            'on_hand_qty' => '-0.5000', 'reserved_qty' => '0.0000', 'available_qty' => '-0.5000',
            'version' => 1, 'create_time' => time(), 'update_time' => time(),
        ]);
        $otherSource = $this->insertNegativeSource($fixture, 700001, 700101, time() - 10);
        $ownSource = $this->insertNegativeSource($fixture, $fixture['order_id'], $fixture['source_line_id'], time());

        $v2 = $this->settlementPayload($fixture, 'settlement-directed-offset-v2', '1.5000', '10.00');
        $v2['expected_version'] = 1;
        $v2['edit_reason'] = '录入更正：本单实际交付少录为2斤，应为1.5斤';
        $v2['lines'][0]['actual_delivery_weight'] = '1.5000';
        $result = SalesSettlementLogic::submit($v2);
        self::assertNotFalse($result, SalesSettlementLogic::getError());
        self::assertSame('0.0000', (string)Db::name('negative_inventory_attribution')->where('id', $ownSource)->value('remaining_qty'));
        self::assertSame('resolved', (string)Db::name('negative_inventory_attribution')->where('id', $ownSource)->value('resolution_status'));
        self::assertSame('0.5000', (string)Db::name('negative_inventory_attribution')->where('id', $otherSource)->value('remaining_qty'));
        self::assertSame('open', (string)Db::name('negative_inventory_todo')->where('attribution_id', $otherSource)->value('status'));
    }

    public function test_repeated_decrease_increase_decrease_uses_each_settlement_action_as_a_distinct_offset_fact(): void
    {
        $fixture = $this->pendingOrderFixture('2.0000');
        self::assertNotFalse(SalesSettlementLogic::submit($this->settlementPayload(
            $fixture, 'settlement-offset-roundtrip-v1', '2.0000', '10.00'
        )), SalesSettlementLogic::getError());
        Db::name('warehouse_sku_balance')->insert([
            'tenant_id' => self::TENANT_ID, 'warehouse_id' => $fixture['warehouse_id'],
            'goods_id' => $fixture['goods_id'], 'sku_id' => $fixture['sku_id'], 'base_unit_id' => $fixture['unit_id'],
            'on_hand_qty' => '-1.0000', 'reserved_qty' => '0.0000', 'available_qty' => '-1.0000',
            'version' => 1, 'create_time' => time(), 'update_time' => time(),
        ]);
        $originalSource = $this->insertNegativeSource(
            $fixture, $fixture['order_id'], $fixture['source_line_id'], time() - 100, '1.0000'
        );
        Db::name('negative_inventory_setting')->where('tenant_id', self::TENANT_ID)->delete();
        Db::name('negative_inventory_setting')->insert([
            'tenant_id' => self::TENANT_ID, 'quantity_threshold' => '99.0000',
            'amount_threshold' => '999999.00', 'create_time' => time(), 'update_time' => time(),
        ]);

        $decreaseV2 = $this->settlementPayload($fixture, 'settlement-offset-roundtrip-v2', '1.5000', '10.00');
        $decreaseV2['expected_version'] = 1;
        $decreaseV2['edit_reason'] = '录入更正：第一次减少实际交付重量';
        $decreaseV2['lines'][0]['actual_delivery_weight'] = '1.5000';
        self::assertNotFalse(SalesSettlementLogic::submit($decreaseV2), SalesSettlementLogic::getError());

        $increaseV3 = $this->settlementPayload($fixture, 'settlement-offset-roundtrip-v3', '2.0000', '10.00');
        $increaseV3['expected_version'] = 2;
        $increaseV3['edit_reason'] = '录入更正：重新加回实际交付重量';
        $increaseV3['lines'][0]['actual_delivery_weight'] = '2.0000';
        self::assertNotFalse(SalesSettlementLogic::submit($increaseV3), SalesSettlementLogic::getError());

        $decreaseV4 = $this->settlementPayload($fixture, 'settlement-offset-roundtrip-v4', '1.5000', '10.00');
        $decreaseV4['expected_version'] = 3;
        $decreaseV4['edit_reason'] = '录入更正：再次减少到相同实际交付重量';
        $decreaseV4['lines'][0]['actual_delivery_weight'] = '1.5000';
        $result = SalesSettlementLogic::submit($decreaseV4);

        self::assertNotFalse($result, SalesSettlementLogic::getError());
        self::assertSame(4, $result['version']);
        self::assertSame('0.0000', (string)Db::name('negative_inventory_attribution')
            ->where('id', $originalSource)->value('remaining_qty'));
        self::assertSame(2, Db::name('negative_inventory_action')->where('attribution_id', $originalSource)
            ->where('action_type', 'sales_delivery_correction_offset')->count());
        self::assertSame(1, Db::name('negative_inventory_attribution')->where('sales_order_id', $fixture['order_id'])
            ->where('resolution_status', 'open')->count());
    }

    /** @return array{customer_id:int,warehouse_id:int,goods_id:int,sku_id:int,unit_id:int,order_id:int,order_goods_id:int,source_line_id:int} */
    private function pendingOrderFixture(string $actualQuantity, string $startingDebt = '0.00'): array
    {
        $customerId = $this->createCustomer('结算客户-' . uniqid());
        Db::name('customer')->where('id', $customerId)->update([
            'order_receivable' => $startingDebt,
            'order_money' => $startingDebt,
        ]);
        $warehouseId = $this->createCustomerReportWarehouse('结算仓-' . uniqid());
        $goodsId = $this->createCustomerReportGoods('结算商品-' . uniqid(), 'SETTLEMENT', '斤');
        $skuId = $this->customerReportSkuId($goodsId);
        $unitId = (int)Db::name('goods_unit')->where('tenant_id', self::TENANT_ID)->where('name', '斤')->value('id');
        Db::name('goods')->where('tenant_id', self::TENANT_ID)->where('id', $goodsId)->update(['unit_id' => $unitId]);
        Db::name('goods_sku')->where('tenant_id', self::TENANT_ID)->where('id', $skuId)->update([
            'base_unit_id' => $unitId, 'base_unit_name' => '斤',
        ]);
        Db::name('goods_units_binding')->insert([
            'tenant_id' => self::TENANT_ID, 'goods_id' => $goodsId, 'unit_id' => $unitId,
            'unit_name' => '斤', 'is_base_unit' => 1, 'sort' => 0, 'status' => 1,
            'create_time' => time(), 'update_time' => time(),
        ]);
        $now = time();
        $sourceLineId = random_int(100000, 900000);
        $orderId = (int)Db::name('sales_order')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'order_sn' => 'XSD-SETTLEMENT-' . uniqid(),
            'customer_id' => $customerId,
            'customer_name' => (string)Db::name('customer')->where('id', $customerId)->value('customer_name'),
            'warehouse_id' => $warehouseId,
            'order_money' => '0.00',
            'order_pay_money' => '0.00',
            'order_arrears_money' => '0.00',
            'datetimesingle' => $now,
            'source_type' => 'customer_report',
            'source_id' => random_int(100000, 900000),
            'source_version' => 1,
            'settlement_status' => 'pending',
            'cost_status' => 'confirmed',
            'profit_status' => 'pending_settlement',
            'status' => 1,
            'purpose_type' => 'sales',
            'remarks' => '',
            'admin_id' => self::ADMIN_ID,
            'idempotent_key' => '',
            'create_time' => $now,
            'update_time' => $now,
        ]);
        $orderGoodsId = (int)Db::name('order_goods')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'order_id' => $orderId,
            'order_type' => 'sales',
            'goods_id' => $goodsId,
            'sku_id' => $skuId,
            'sku_name' => '默认规格',
            'supplier_relation_id' => 0,
            'name' => (string)Db::name('goods')->where('id', $goodsId)->value('name'),
            'units' => '斤',
            'number' => $actualQuantity,
            'base_quantity' => $actualQuantity,
            'price' => '0.00',
            'amount' => '0.00',
            'pricing_unit_id' => 0,
            'source_line_type' => 'customer_report_item',
            'source_line_id' => $sourceLineId,
            'remark' => '',
            'sort' => 1,
            'create_time' => $now,
            'update_time' => $now,
        ]);
        return compact('customerId', 'warehouseId', 'goodsId', 'skuId', 'unitId', 'orderId', 'orderGoodsId', 'sourceLineId') + [
            'customer_id' => $customerId,
            'warehouse_id' => $warehouseId,
            'goods_id' => $goodsId,
            'sku_id' => $skuId,
            'unit_id' => $unitId,
            'order_id' => $orderId,
            'order_goods_id' => $orderGoodsId,
            'source_line_id' => $sourceLineId,
        ];
    }

    /** @return array<string,mixed> */
    private function settlementPayload(array $fixture, string $idempotencyKey, string $customerWeight, ?string $price, string $rounding = '0.00'): array
    {
        return [
            'order_id' => $fixture['order_id'],
            'expected_version' => 0,
            'idempotency_key' => $idempotencyKey,
            'rounding_amount' => $rounding,
            'rounding_reason' => '',
            'second_confirmed' => 0,
            'show_cumulative_debt' => 0,
            'edit_reason' => '',
            'lines' => [[
                'order_goods_id' => $fixture['order_goods_id'],
                'customer_settlement_weight' => $customerWeight,
                'pricing_unit_id' => $fixture['unit_id'],
                'pricing_unit_name' => '斤',
                'price' => $price,
                'zero_price_reason' => '',
            ]],
        ];
    }

    private function bindSalesPricingUnit(array $fixture, string $name): int
    {
        $unitId = (int)Db::name('goods_unit')->insertGetId([
            'tenant_id' => self::TENANT_ID, 'name' => $name, 'status' => 1, 'sort' => 0,
            'create_time' => time(), 'update_time' => time(),
        ]);
        Db::name('goods_units_binding')->insert([
            'tenant_id' => self::TENANT_ID, 'goods_id' => $fixture['goods_id'], 'unit_id' => $unitId,
            'unit_name' => $name, 'is_base_unit' => 0, 'sort' => 1, 'status' => 1,
            'create_time' => time(), 'update_time' => time(),
        ]);
        return $unitId;
    }

    private function insertNegativeSource(
        array $fixture,
        int $salesOrderId,
        int $sourceLineId,
        int $occurredTime,
        string $quantity = '0.5000'
    ): int
    {
        $attributionId = (int)Db::name('negative_inventory_attribution')->insertGetId([
            'tenant_id' => self::TENANT_ID, 'delivery_event_id' => 0, 'delivery_item_id' => $sourceLineId,
            'sales_order_id' => $salesOrderId, 'report_id' => 0, 'report_item_id' => $sourceLineId,
            'warehouse_id' => $fixture['warehouse_id'], 'goods_id' => $fixture['goods_id'], 'sku_id' => $fixture['sku_id'],
            'negative_qty' => $quantity, 'negative_amount' => $quantity, 'remaining_qty' => $quantity,
            'reason' => '测试负库存来源', 'threshold_explanation' => '', 'threshold_confirmed' => 0,
            'resolution_status' => 'waiting_inbound', 'cost_status' => 'confirmed', 'operator_id' => self::ADMIN_ID,
            'occurred_time' => $occurredTime, 'resolved_time' => 0, 'update_time' => $occurredTime,
        ]);
        Db::name('negative_inventory_todo')->insert([
            'tenant_id' => self::TENANT_ID, 'attribution_id' => $attributionId, 'status' => 'open',
            'severity' => 'red', 'assignee_scope' => 'highest_privilege',
            'create_time' => $occurredTime, 'update_time' => $occurredTime,
        ]);
        return $attributionId;
    }
}
