<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\FinanceBusinessLogic;
use app\api\jxc\logic\FinanceLedger;
use app\api\jxc\logic\FinanceSetupLogic;
use app\api\jxc\logic\WorkforceLogic;
use app\api\jxc\logic\SalesSettlementLogic;
use PHPUnit\Framework\TestCase;
use think\facade\Config;
use think\facade\Db;

require_once __DIR__ . '/CustomerReportTestSupport.php';

final class FinanceBusinessWorkflowTest extends TestCase
{
    use CustomerReportTestSupport;
    private int $sequence = 0;
    private int $accountId;
    private int $customerId;
    private string $receivable;

    protected function setUp(): void
    {
        $this->prepareCustomerReportRequestContext(); $this->ensureCustomerReportTables();
        foreach (['20260907_000001_finance_preparation.sql', '20260907_000002_finance_opening.sql', '20260907_000003_finance_opening_details.sql', '20260907_000004_finance_business.sql', '20260907_000005_finance_sales.sql', '20260907_000006_finance_receivables.sql', '20260907_000007_finance_advance_revisions.sql'] as $file) {
            $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/' . $file)));
        }
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260907_000008_finance_statements.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260907_000009_finance_overdue.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260907_000011_finance_sales_precision.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260907_000012_finance_sales_coverage.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260907_000013_finance_sales_output.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260907_000014_finance_cost.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260908_000015_finance_purchase_arrival.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260908_000016_finance_purchase_settlement.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260908_000017_finance_purchase_rules.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260908_000018_finance_purchase_cost_change.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260908_000019_finance_purchase_cost_revision.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260908_000020_finance_supplier_statements.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260908_000021_finance_purchase_return.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260908_000022_finance_purchase_return_resolution.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260908_000023_finance_purchase_difference_review.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260908_000024_finance_purchase_arrival_loss.sql')));
        $this->runStatements($this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260908_000025_finance_inventory_loss.sql')));
        $this->clean();
        Config::set(['activation_tenant_ids' => [self::TENANT_ID]], 'finance');
    }

    protected function tearDown(): void
    {
        $this->prepareCustomerReportRequestContext(); $this->clean();
        Config::set(['activation_tenant_ids' => []], 'finance');
    }

    public function test_cost_events_survive_reload_replay_once_and_roll_back_with_business_transaction(): void
    {
        $this->activate();
        $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        $event = ['reference' => 'test-arrival:1', 'type' => 'receive', 'sku_id' => 20, 'warehouse_id' => 10,
            'business_date' => date('Y-m-d'), 'origin' => 'test-arrival:1', 'quantity' => '100', 'amount' => '1000.00', 'snapshot' => ['basis' => '双方约定']];
        $first = Db::transaction(fn() => $cost->recordWithinTransaction($event));
        self::assertSame($first, Db::transaction(fn() => $cost->recordWithinTransaction($event)));
        self::assertSame('1000.000000', $cost->balance(10, 20)['value']);
        $sale = ['reference' => 'test-delivery:1', 'type' => 'issue', 'sku_id' => 20, 'warehouse_id' => 10,
            'business_date' => date('Y-m-d'), 'quantity' => '25', 'bucket' => 'sale', 'target_reference' => 'test-delivery:1'];
        Db::transaction(fn() => $cost->recordWithinTransaction($sale));
        $reloaded = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        self::assertSame('750.000000', $reloaded->balance(10, 20)['value']);
        $adjustment = ['reference' => 'test-cost-confirm:1', 'type' => 'adjust', 'sku_id' => 20, 'warehouse_id' => 10,
            'business_date' => date('Y-m-d'), 'origin' => 'test-arrival:1', 'amount' => '1200.00'];
        $adjusted = Db::transaction(fn() => $reloaded->recordWithinTransaction($adjustment));
        self::assertSame('50.000000', $adjusted['changes']['sale']);
        self::assertSame('900.000000', $cost->balance(10, 20)['value']);
        self::assertSame('300.000000', $cost->destination(10, 20, 'sale', 'test-delivery:1')['cost']);
        self::assertSame(3, count($cost->events(20)));
        try { Db::transaction(function () use ($cost, $sale): void { $sale['reference'] = 'rollback-sale'; $cost->recordWithinTransaction($sale); throw new \DomainException('后续业务失败'); }); }
        catch (\DomainException $e) { self::assertSame('后续业务失败', $e->getMessage()); }
        self::assertSame('900.000000', $cost->balance(10, 20)['value']); self::assertCount(3, $cost->events(20));
        $event['amount'] = '1001.00';
        $this->expectException(\DomainException::class);
        Db::transaction(fn() => $cost->recordWithinTransaction($event));
    }

    public static function bootstrapCorrections(): array { return [['none'], ['correction'], ['return'], ['transport'], ['old_transport']]; }

    /** @dataProvider bootstrapCorrections */
    public function test_activation_carries_cutoff_stock_then_replays_intervening_sales_cost_without_moving_stock_again(string $mode): void
    {
        $corrected = in_array($mode, ['correction', 'return'], true);
        $date = $mode === 'transport' ? date('Y-m-01', strtotime('first day of last month')) : date('Y-m-d');
        $bucket = 'sale'; $destination = 'sales_order:9827';
        $warehouse = $this->createCustomerReportWarehouse('启用衔接仓');
        $goods = $this->createCustomerReportGoods('启用衔接商品', 'FIN-BOOTSTRAP'); $sku = $this->customerReportSkuId($goods);
        $stockId = (int)Db::name('warehouse_sku_balance')->insertGetId(['tenant_id' => self::TENANT_ID,
            'warehouse_id' => $warehouse, 'goods_id' => $goods, 'sku_id' => $sku, 'on_hand_qty' => '100.0000', 'available_qty' => '100.0000']);
        self::assertNotFalse(FinanceSetupLogic::savePreparation($this->command(0) + ['activation_date' => $date,
            'inventory_cost_reviewed' => 1, 'legacy_settlement_reviewed' => 1, 'excluded_business_reviewed' => 1]));
        if (in_array($mode, ['transport', 'old_transport'], true)) {
            $delivery = (int)Db::name('fulfillment_delivery_event')->insertGetId(['tenant_id' => self::TENANT_ID,
                'idempotency_key' => 'BOOTSTRAP-TRANSPORT', 'actual_handoff_time' => $mode === 'old_transport' ? strtotime('yesterday') : strtotime($date . ' +10 days'), 'create_time' => time()]);
            self::assertNotFalse(Db::transaction(fn() => \app\api\jxc\logic\StockService::outboundTransportLossWithinTransaction($warehouse, $goods, $sku, '10', '0', $delivery)));
            $bucket = 'loss'; $destination = 'delivery_loss:' . $delivery;
        } else { self::assertTrue(\app\api\jxc\logic\StockService::outbound($warehouse, $goods, '10', 9827, 'sales', 'BEFORE-ACTIVATION', '', $sku)); }
        if ($mode === 'correction') { self::assertTrue(\app\api\jxc\logic\StockService::inbound($warehouse, $goods, '4', 9827, 'sales_delivery_correction', 'BEFORE-CORRECTION', '', $sku)); }
        if ($mode === 'return') {
            Db::name('sales_order')->insert(['id' => 9827, 'tenant_id' => self::TENANT_ID, 'warehouse_id' => $warehouse, 'order_sn' => 'BOOTSTRAP-SALE']);
            $return = (int)Db::name('sales_return_order')->insertGetId(['tenant_id' => self::TENANT_ID, 'warehouse_id' => $warehouse, 'original_sales_order_id' => 9827, 'order_sn' => 'BOOTSTRAP-RETURN']);
            self::assertTrue(\app\api\jxc\logic\StockService::inbound($warehouse, $goods, '4', $return, 'sales-return', 'BOOTSTRAP-RETURN', '', $sku));
        }
        $this->opening('item', ['category' => 'inventory', 'subject_id' => $stockId, 'amount' => $mode === 'old_transport' ? '180.00' : '200.00', 'historical_date' => null, 'due_date' => null,
            'source_mode' => 'detail', 'source_reference' => '启用日前一日盘存', 'evidence' => '统一截点数量一百、历史成本二百',
            'details' => ['quantity' => $mode === 'old_transport' ? '90.0000' : '100.0000', 'origin_reference' => '截点盘存凭据']]);
        foreach (FinanceSetupLogic::opening()['categories'] as $category) { $this->opening('review', ['category' => $category['key'], 'state' => $category['count'] ? 'complete' : 'none', 'evidence' => '逐类核实']); }
        $this->opening('submit'); $this->opening('confirm');
        $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        self::assertSame($corrected ? '94.0000' : '90.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame($corrected ? '94.000000000000' : '90.000000000000', $cost->balance($warehouse, $sku)['quantity']);
        self::assertSame($corrected ? '188.000000' : '180.000000', $cost->balance($warehouse, $sku)['value']);
        self::assertSame($mode === 'old_transport' ? '0.000000' : ($corrected ? '12.000000' : '20.000000'), $cost->destination($warehouse, $sku, $bucket, $destination)['cost']);
        if ($mode === 'transport') { self::assertSame(date('Y-m-d', strtotime($date . ' +10 days')), $cost->events($sku)[0]['business_date']); }
        if ($mode === 'old_transport') { self::assertSame([], $cost->events($sku)); }
        self::assertTrue(\app\api\jxc\logic\StockService::outbound($warehouse, $goods, '5', 9828, 'sales', 'AFTER-ACTIVATION', '', $sku));
        self::assertSame($corrected ? '89.0000' : '85.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame($corrected ? '178.000000' : '170.000000', $cost->balance($warehouse, $sku)['value']);
        self::assertSame('10.000000', $cost->destination($warehouse, $sku, 'sale', 'sales_order:9828')['cost']);
        self::assertSame($mode === 'old_transport' ? '0.000000' : ($corrected ? '12.000000' : '20.000000'), $cost->destination($warehouse, $sku, $bucket, $destination)['cost']);
    }

    public static function historicalSaleDates(): array { return [[true], [false]]; }

    /** @dataProvider historicalSaleDates */
    public function test_activation_keeps_return_from_pre_cutoff_sale_unpriced_without_borrowing_opening_unit_cost(bool $knownDate): void
    {
        $warehouse = $this->createCustomerReportWarehouse('旧售退回仓');
        $goods = $this->createCustomerReportGoods('旧售退回商品', 'FIN-OLD-RETURN'); $sku = $this->customerReportSkuId($goods);
        $stock = (int)Db::name('warehouse_sku_balance')->insertGetId(['tenant_id' => self::TENANT_ID,
            'warehouse_id' => $warehouse, 'goods_id' => $goods, 'sku_id' => $sku, 'on_hand_qty' => '100', 'available_qty' => '100']);
        self::assertNotFalse(FinanceSetupLogic::savePreparation($this->command(0) + ['activation_date' => date('Y-m-d'),
            'inventory_cost_reviewed' => 1, 'legacy_settlement_reviewed' => 1, 'excluded_business_reviewed' => 1]));
        $sale = (int)Db::name('sales_order')->insertGetId(['tenant_id' => self::TENANT_ID, 'warehouse_id' => $warehouse,
            'order_sn' => 'PRE-CUTOFF-SALE', 'datetimesingle' => $knownDate ? strtotime('yesterday') : 0]);
        $return = (int)Db::name('sales_return_order')->insertGetId(['tenant_id' => self::TENANT_ID, 'warehouse_id' => $warehouse,
            'original_sales_order_id' => $sale, 'order_sn' => 'OLD-SALE-RETURN']);
        self::assertTrue(\app\api\jxc\logic\StockService::inbound($warehouse, $goods, '4', $return, 'sales-return', 'OLD-SALE-RETURN', '', $sku));
        $this->opening('item', ['category' => 'inventory', 'subject_id' => $stock, 'amount' => '200.00', 'historical_date' => null, 'due_date' => null,
            'source_mode' => 'detail', 'source_reference' => '旧售退回前截点盘存', 'evidence' => '截点库存一百；旧销售成本尚待核对',
            'details' => ['quantity' => '100', 'origin_reference' => '期初盘存']]);
        foreach (FinanceSetupLogic::opening()['categories'] as $category) { $this->opening('review', ['category' => $category['key'], 'state' => $category['count'] ? 'complete' : 'none', 'evidence' => '逐类核实']); }
        $this->opening('submit'); $active = $this->opening('confirm');
        $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID); $balance = $cost->balance($warehouse, $sku);
        self::assertSame('104.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame('104.000000000000', $balance['quantity']); self::assertSame('200.000000', $balance['known_value']);
        self::assertNull($balance['value']); self::assertSame('4.000000000000', $balance['pending_quantity']);
        self::assertCount(1, $active['cost_bootstrap']['pending_flow_ids']);
        self::assertSame('0.000000000000', $cost->destination($warehouse, $sku, 'sale', 'sales_order:' . $sale)['quantity']);
    }

    public static function unresolvedBootstrapFlows(): array { return [['purchase', true], ['warehouse-transfer', false]]; }

    /** @dataProvider unresolvedBootstrapFlows */
    public function test_activation_rejects_unverified_inbound_cost_or_unpaired_stock_destination_atomically(string $type, bool $inbound): void
    {
        $warehouse = $this->createCustomerReportWarehouse('未核实承接仓');
        $goods = $this->createCustomerReportGoods('未核实承接商品', 'FIN-BOOT-BLOCK'); $sku = $this->customerReportSkuId($goods);
        $stock = (int)Db::name('warehouse_sku_balance')->insertGetId(['tenant_id' => self::TENANT_ID,
            'warehouse_id' => $warehouse, 'goods_id' => $goods, 'sku_id' => $sku, 'on_hand_qty' => '100', 'available_qty' => '100']);
        self::assertNotFalse(FinanceSetupLogic::savePreparation($this->command(0) + ['activation_date' => date('Y-m-d'),
            'inventory_cost_reviewed' => 1, 'legacy_settlement_reviewed' => 1, 'excluded_business_reviewed' => 1]));
        $method = $inbound ? 'inbound' : 'outbound';
        self::assertTrue(\app\api\jxc\logic\StockService::$method($warehouse, $goods, '10', 9827, $type, 'UNVERIFIED-STOCK', '', $sku));
        $this->opening('item', ['category' => 'inventory', 'subject_id' => $stock, 'amount' => '200.00', 'historical_date' => null, 'due_date' => null,
            'source_mode' => 'detail', 'source_reference' => '截点库存凭据', 'evidence' => '截点库存一百',
            'details' => ['quantity' => '100', 'origin_reference' => '期初盘存']]);
        foreach (FinanceSetupLogic::opening()['categories'] as $category) { $this->opening('review', ['category' => $category['key'], 'state' => $category['count'] ? 'complete' : 'none', 'evidence' => '逐类核实']); }
        $pending = $this->opening('submit');
        self::assertFalse(FinanceSetupLogic::openingAction('confirm', $this->command((int)$pending['version'])));
        self::assertStringContainsString('尚未核实', FinanceSetupLogic::getError());
        self::assertSame('pending', FinanceSetupLogic::opening()['status']);
        self::assertSame($inbound ? '110.0000' : '90.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame([], (new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID))->events($sku));
    }

    /** @dataProvider arrivalLossPrices */
    public function test_stock_loss_keeps_unresolved_cost_separate_then_confirms_partial_loss_without_second_outbound(?string $initialPrice): void
    {
        $this->activate(); $warehouse = $this->createCustomerReportWarehouse('库内损耗仓');
        $goods = $this->createCustomerReportGoods('库内损耗商品', 'FIN-STOCK-LOSS'); $sku = $this->customerReportSkuId($goods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '库内损耗供方']);
        $arrival = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival', 'payload' => ['subject_id' => $vendor,
            'warehouse_id' => $warehouse, 'actual_date' => date('Y-m-d'), 'source_reference' => 'STOCK-LOSS-ARR', 'reason' => '原实收到货',
            'lines' => [['sku_id' => $sku, 'actual_quantity' => '100', 'reported_quantity' => '100'] + ($initialPrice === null ? [] : ['agreed_price' => $initialPrice])]]]);
        self::assertNotFalse($arrival, FinanceBusinessLogic::getError());
        $payload = ['warehouse_id' => $warehouse, 'sku_id' => $sku, 'quantity' => '10', 'actual_date' => date('Y-m-d'),
            'source_reference' => 'INCIDENT-ONE', 'reason' => '冷库异常造成商品实物减少', 'responsibility' => '交接与温控记录待核实', 'physical_confirmed' => 1];
        $command = $this->command(0) + ['type' => 'inventory_loss', 'payload' => $payload];
        foreach ([['physical_confirmed' => 0], ['quantity' => '101'], ['actual_date' => date('Y-m-d', strtotime('+1 day'))]] as $invalid) {
            self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'inventory_loss', 'payload' => array_merge($payload, $invalid)]));
            self::assertSame('100.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        }
        $incident = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($incident, FinanceBusinessLogic::getError());
        self::assertSame($incident, FinanceBusinessLogic::action('record', $command));
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'inventory_loss', 'payload' => $payload]));
        $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        $reference = 'inventory-loss:' . $incident['id'];
        self::assertSame('90.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame($initialPrice === null ? null : '180.000000', $cost->balance($warehouse, $sku)['value']);
        self::assertSame($initialPrice === null ? null : '20.000000', $cost->destination($warehouse, $sku, 'pending', $reference)['cost']);
        self::assertSame('0.000000000000', $cost->destination($warehouse, $sku, 'loss', $reference)['quantity']);
        $candidates = FinanceBusinessLogic::options(['type' => 'inventory_loss_resolution', 'warehouse_id' => $warehouse]);
        self::assertNotFalse($candidates, FinanceBusinessLogic::getError()); self::assertCount(1, $candidates['incidents']);
        self::assertSame('10.0000', $candidates['incidents'][0]['remaining_quantity']);
        $resolve = ['incident_document_id' => $incident['id'], 'expected_resolution_id' => 0, 'quantity' => '4',
            'loss_confirmed' => 1, 'reason' => '核实四单位腐坏由门店承担', 'responsibility' => '门店承担，不登记责任应收', 'source_reference' => 'INCIDENT-CHECK-ONE'];
        foreach ([['loss_confirmed' => 0], ['quantity' => '11']] as $invalid) {
            self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'inventory_loss_resolution', 'payload' => array_merge($resolve, $invalid)]));
        }
        $preview = \app\api\jxc\logic\FinancePreview::calculate($this->command(0) + ['type' => 'inventory_loss_resolution', 'action' => 'record', 'payload' => $resolve]);
        self::assertSame($initialPrice === null ? null : '20.000000', $cost->destination($warehouse, $sku, 'pending', $reference)['cost']);
        $resolved = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'inventory_loss_resolution', 'payload' => $resolve]);
        self::assertNotFalse($resolved, FinanceBusinessLogic::getError()); self::assertSame($initialPrice === null ? null : '8.000000', $resolved['confirmed_result']['loss_cost']);
        self::assertSame($initialPrice === null, $resolved['confirmed_result']['cost_pending']);
        self::assertSame($preview['cost_impacts'], $resolved['confirmed_result']['cost_impacts']);
        self::assertSame('90.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame($initialPrice === null ? null : '12.000000', $cost->destination($warehouse, $sku, 'pending', $reference)['cost']);
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'inventory_loss_resolution', 'payload' => $resolve]));
        self::assertSame('6.0000', FinanceBusinessLogic::options(['type' => 'inventory_loss_resolution', 'warehouse_id' => $warehouse])['incidents'][0]['remaining_quantity']);
        $settled = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_settlement', 'payload' => ['subject_id' => $vendor,
            'supplier_confirmed' => 1, 'supplier_confirmation' => '供方确认三元', 'reason' => '最终补价',
            'lines' => [['arrival_line_id' => $arrival['confirmed_result']['lines'][0]['arrival_line_id'], 'covered_quantity' => '100', 'settlement_quantity' => '100', 'price' => '3.00']]]]);
        self::assertNotFalse($settled, FinanceBusinessLogic::getError());
        self::assertSame('270.000000', $cost->balance($warehouse, $sku)['value']);
        self::assertSame('18.000000', $cost->destination($warehouse, $sku, 'pending', $reference)['cost']);
        self::assertSame('12.000000', $cost->destination($warehouse, $sku, 'loss', 'inventory-loss-resolution:' . $resolved['id'])['cost']);
        self::assertSame($initialPrice === null ? null : '8.000000', FinanceBusinessLogic::detail(['id' => $resolved['id']])['confirmed_result']['loss_cost']);
        $resolve['expected_resolution_id'] = $resolved['confirmed_result']['resolution_id']; $resolve['quantity'] = '6';
        $finished = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'inventory_loss_resolution', 'payload' => $resolve]);
        self::assertNotFalse($finished, FinanceBusinessLogic::getError()); self::assertSame('18.000000', $finished['confirmed_result']['loss_cost']);
        self::assertFalse($finished['confirmed_result']['resolution_pending']);
        self::assertCount(0, FinanceBusinessLogic::options(['type' => 'inventory_loss_resolution'])['incidents']);
        self::assertSame('90.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
    }

    public static function arrivalLossPrices(): array { return ['已知暂估' => ['2.00'], '未知成本' => [null]]; }

    /** @dataProvider arrivalLossPrices */
    public function test_confirmed_arrival_loss_excluded_from_stock_splits_cost_and_future_price_changes_without_second_stock_deduction(?string $initialPrice): void
    {
        $this->activate(); $warehouse = $this->createCustomerReportWarehouse('异常到货损耗仓');
        $goods = $this->createCustomerReportGoods('实收已扣损失商品', 'FIN-ARRIVAL-LOSS'); $sku = $this->customerReportSkuId($goods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '异常到货损耗供方']);
        $arrival = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival', 'payload' => ['subject_id' => $vendor,
            'warehouse_id' => $warehouse, 'actual_date' => date('Y-m-d'), 'source_reference' => 'LOSS-ARR', 'reason' => '实收只含完好商品',
            'lines' => [['sku_id' => $sku, 'actual_quantity' => '499', 'reported_quantity' => '500'] + ($initialPrice === null ? [] : ['agreed_price' => $initialPrice])]]]); self::assertNotFalse($arrival);
        $arrivalId = $arrival['confirmed_result']['lines'][0]['arrival_line_id'];
        self::assertTrue(\app\api\jxc\logic\StockService::outbound($warehouse, $goods, '100', 909, 'sales', 'SALE-BEFORE-LOSS', '', $sku));
        $review = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_difference', 'payload' => ['subject_id' => $vendor,
            'arrival_line_id' => $arrivalId, 'expected_review_id' => 0, 'classification' => 'loss', 'reason' => '装卸遗失需核实', 'responsibility' => '交接记录待确认', 'review_confirmed' => 1]]); self::assertNotFalse($review);
        $payload = ['subject_id' => $vendor, 'arrival_line_id' => $arrivalId, 'expected_review_id' => $review['confirmed_result']['review_id'],
            'quantity' => '1', 'excluded_from_received_confirmed' => 1, 'loss_confirmed' => 1, 'source_reference' => 'LOSS-HANDOFF',
            'reason' => '核实一单位装卸遗失，原实收量已排除', 'responsibility' => '最高权限确认门店承担，不登记责任应收'];
        $command = $this->command(0) + ['type' => 'purchase_arrival_loss', 'payload' => $payload];
        self::assertSame('异常到货损耗仓', FinanceBusinessLogic::options(['type' => 'purchase_arrival_loss', 'subject_id' => $vendor])['arrivals'][0]['warehouse_name']);
        $bad = $payload; $bad['excluded_from_received_confirmed'] = 0;
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival_loss', 'payload' => $bad]));
        $bad = $payload; $bad['quantity'] = '2';
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival_loss', 'payload' => $bad]));
        $preview = \app\api\jxc\logic\FinancePreview::calculate($this->command(0) + ['type' => 'purchase_arrival_loss', 'action' => 'record', 'payload' => $payload]);
        self::assertSame($initialPrice === null, $preview['purchase']['cost_pending']);
        $loss = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($loss, FinanceBusinessLogic::getError());
        self::assertSame($loss, FinanceBusinessLogic::action('record', $command));
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival_loss', 'payload' => $payload]));
        self::assertSame($initialPrice === null ? '0.00' : '2.00', $loss['confirmed_result']['loss_known_amount']);
        self::assertSame($initialPrice === null, $loss['confirmed_result']['cost_pending']);
        self::assertSame($warehouse, $loss['confirmed_result']['warehouse_id']); self::assertSame('异常到货损耗仓', $loss['confirmed_result']['warehouse_name']);
        self::assertSame($preview['cost_impacts'], $loss['confirmed_result']['cost_impacts']);
        self::assertSame($preview['posting_months'], $loss['confirmed_result']['posting_months']);
        $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        self::assertSame('399.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame($initialPrice === null ? null : '798.000000', $cost->balance($warehouse, $sku)['value']);
        self::assertSame($initialPrice === null ? null : '200.000000', $cost->destination($warehouse, $sku, 'sale', 'sales_order:909')['cost']);
        self::assertSame($initialPrice === null ? null : '2.000000', $cost->destination($warehouse, $sku, 'loss', 'purchase-arrival-loss:' . $loss['id'])['cost']);
        self::assertCount(0, FinanceBusinessLogic::options(['type' => 'purchase_difference', 'subject_id' => $vendor])['arrivals']);
        self::assertSame('0.00', (new FinanceLedger(self::TENANT_ID))->categoryBalance('payable', $vendor));
        $settled = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_settlement', 'payload' => ['subject_id' => $vendor,
            'supplier_confirmed' => 1, 'supplier_confirmation' => '供方确认计费五百、单价三元', 'reason' => '异常损失已独立确认',
            'lines' => [['arrival_line_id' => $arrivalId, 'covered_quantity' => '499', 'settlement_quantity' => '500', 'price' => '3.00',
                'arrival_difference_confirmed' => 1, 'arrival_difference_class' => 'loss', 'arrival_difference_reason' => '关联已确认损失',
                'difference_confirmed' => 1, 'difference_class' => 'normal', 'difference_reason' => '按双方约定报量计费']]]]); self::assertNotFalse($settled, FinanceBusinessLogic::getError());
        self::assertSame('1197.000000', $cost->balance($warehouse, $sku)['value']);
        self::assertSame('300.000000', $cost->destination($warehouse, $sku, 'sale', 'sales_order:909')['cost']);
        self::assertSame('3.000000', $cost->destination($warehouse, $sku, 'loss', 'purchase-arrival-loss:' . $loss['id'])['cost']);
        self::assertSame('1500.00', (new FinanceLedger(self::TENANT_ID))->categoryBalance('payable', $vendor));
        $freight = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_extra_cost', 'payload' => ['subject_id' => $vendor,
            'cost_kind' => 'freight', 'necessary_confirmed' => 1, 'amount' => '500.00', 'actual_date' => date('Y-m-d'), 'source_reference' => 'LOSS-FREIGHT',
            'reason' => '本批直接必要运输', 'attribution_basis' => '全部归属本次到货', 'lines' => [['arrival_line_id' => $arrivalId, 'amount' => '500.00']]]]);
        self::assertNotFalse($freight, FinanceBusinessLogic::getError());
        self::assertSame('1596.000000', $cost->balance($warehouse, $sku)['value']);
        self::assertSame('400.000000', $cost->destination($warehouse, $sku, 'sale', 'sales_order:909')['cost']);
        self::assertSame('4.000000', $cost->destination($warehouse, $sku, 'loss', 'purchase-arrival-loss:' . $loss['id'])['cost']);
    }

    public function test_purchase_arrival_difference_queue_retains_dispute_and_original_rule_until_explicit_resolution(): void
    {
        $this->activate(); $warehouse = $this->createCustomerReportWarehouse('到货差复核仓');
        $goods = $this->createCustomerReportGoods('差异复核商品', 'FIN-DIFF-QUEUE'); $sku = $this->customerReportSkuId($goods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '重量差复核供方']);
        $arrival = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival', 'payload' => ['subject_id' => $vendor,
            'warehouse_id' => $warehouse, 'actual_date' => date('Y-m-d'), 'source_reference' => 'DIFF-ARR', 'reason' => '实收与报量分别保留',
            'lines' => [['sku_id' => $sku, 'actual_quantity' => '499', 'reported_quantity' => '500', 'agreed_price' => '2.00']]]]);
        self::assertNotFalse($arrival, FinanceBusinessLogic::getError()); $arrivalId = $arrival['confirmed_result']['lines'][0]['arrival_line_id'];
        $options = FinanceBusinessLogic::options(['type' => 'purchase_difference', 'subject_id' => $vendor]);
        self::assertNotFalse($options, FinanceBusinessLogic::getError()); self::assertCount(1, $options['arrivals']);
        self::assertSame('-1.0000', $options['arrivals'][0]['assessment']['quantity']); self::assertTrue($options['arrivals'][0]['assessment']['requires_owner']);
        $rule = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_rules', 'payload' => ['rule_kind' => 'difference', 'scope' => 'store',
            'expected_rule_version' => 0, 'absolute_limit' => '2', 'percent_limit' => '1', 'reason' => '后设规则不改变原到货门槛']]); self::assertNotFalse($rule);
        $employee = WorkforceLogic::saveEmployee(['name' => '到货差经办', 'mobile' => '13800009929', 'bind_user_id' => 996929,
            'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => ['finance.purchase.confirm']]); self::assertNotFalse($employee);
        $payload = ['subject_id' => $vendor, 'arrival_line_id' => $arrivalId, 'expected_review_id' => 0, 'classification' => 'dispute',
            'reason' => '报量与收货记录待双方逐项核对', 'responsibility' => '暂未查明责任，不认定门店损失', 'review_confirmed' => 1];
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID]; request()->jxcFromUserToken = true; request()->userId = 996929; request()->adminId = 0;
        $normal = $payload; $normal['classification'] = 'normal';
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_difference', 'payload' => $normal]));
        self::assertTrue(FinanceBusinessLogic::options(['type' => 'purchase_difference', 'subject_id' => $vendor])['arrivals'][0]['assessment']['requires_owner']);
        $this->prepareCustomerReportRequestContext();
        $command = $this->command(0) + ['type' => 'purchase_difference', 'payload' => $payload];
        $review = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($review, FinanceBusinessLogic::getError());
        self::assertSame($review, FinanceBusinessLogic::action('record', $command)); self::assertFalse($review['confirmed_result']['resolved']);
        $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        self::assertNull($cost->balance($warehouse, $sku)['value']); self::assertSame('1000.000000', $cost->balance($warehouse, $sku)['known_value']);
        self::assertSame('499.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame('0.00', (new FinanceLedger(self::TENANT_ID))->categoryBalance('payable', $vendor));
        $settlement = ['subject_id' => $vendor, 'supplier_confirmed' => 1, 'supplier_confirmation' => '供方认可按报量计费', 'reason' => '核对正式应付',
            'lines' => [['arrival_line_id' => $arrivalId, 'covered_quantity' => '499', 'settlement_quantity' => '500', 'price' => '2.00',
                'arrival_difference_confirmed' => 1, 'arrival_difference_class' => 'normal', 'arrival_difference_reason' => '不能跳过独立争议复核',
                'difference_confirmed' => 1, 'difference_class' => 'normal', 'difference_reason' => '按报量计费', 'difference_rule_id' => $rule['confirmed_result']['rule']['id']]]];
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_settlement', 'payload' => $settlement]));
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_difference', 'payload' => $payload]));
        $payload['expected_review_id'] = $review['confirmed_result']['review_id']; $payload['classification'] = 'normal'; $payload['reason'] = '双方核实正常行业允差';
        $resolved = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_difference', 'payload' => $payload]);
        self::assertNotFalse($resolved, FinanceBusinessLogic::getError()); self::assertTrue($resolved['confirmed_result']['resolved']);
        self::assertSame('1000.000000', $cost->balance($warehouse, $sku)['value']);
        self::assertCount(0, FinanceBusinessLogic::options(['type' => 'purchase_difference', 'subject_id' => $vendor])['arrivals']);
        $original = FinanceBusinessLogic::detail(['id' => $review['id']]); self::assertSame('dispute', $original['confirmed_result']['classification']);
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_settlement', 'payload' => $settlement]), FinanceBusinessLogic::getError());
        self::assertSame('1000.00', (new FinanceLedger(self::TENANT_ID))->categoryBalance('payable', $vendor));
    }

    public static function disputeInitialPrices(): array { return ['已知暂估后补价' => ['2.00'], '未知成本后确认' => [null]]; }

    /** @dataProvider disputeInitialPrices */
    public function test_purchase_return_dispute_restores_original_cost_to_actual_warehouse_and_reclassifies_loss_without_stock_change(?string $initialPrice): void
    {
        $day = date('Y-m-01', strtotime('-1 month')); $this->activate('cash', $day);
        $warehouse = $this->createCustomerReportWarehouse('退货争议原仓'); $to = $this->createCustomerReportWarehouse('实际退回仓');
        $goods = $this->createCustomerReportGoods('退货争议商品', 'FIN-RETURN-DISPUTE'); $sku = $this->customerReportSkuId($goods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '争议处置供应商']); $arrivals = [];
        foreach ([[$warehouse, $initialPrice], [$to, '4.00']] as [$store, $price]) {
            $arrival = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival', 'payload' => ['subject_id' => $vendor,
                'warehouse_id' => $store, 'actual_date' => $store === $warehouse ? $day : date('Y-m-d'), 'source_reference' => 'DISPUTE-' . $price, 'reason' => '实际到货',
                'lines' => [['sku_id' => $sku, 'actual_quantity' => '100'] + ($price === null ? [] : ['agreed_price' => $price])]]]);
            self::assertNotFalse($arrival, FinanceBusinessLogic::getError()); $arrivals[] = $arrival['confirmed_result']['lines'][0]['arrival_line_id'];
        }
        $returned = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_return_actual', 'payload' => ['subject_id' => $vendor,
            'warehouse_id' => $warehouse, 'actual_date' => $day, 'source_reference' => 'DISPUTE-OUT', 'reason' => '实际退离待核实',
            'lines' => [['arrival_line_id' => $arrivals[0], 'quantity' => '10']]]]); self::assertNotFalse($returned, FinanceBusinessLogic::getError());
        $returnId = $returned['confirmed_result']['lines'][0]['return_line_id'];
        $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        Db::transaction(fn() => $cost->recordWithinTransaction(['reference' => 'dispute-final-price', 'type' => 'adjust', 'sku_id' => $sku,
            'warehouse_id' => $warehouse, 'business_date' => $day, 'origin' => 'purchase-arrival:' . $arrivals[0], 'amount' => '300.00']));
        $payload = ['subject_id' => $vendor, 'return_line_id' => $returnId, 'expected_resolution_id' => 0, 'kind' => 'returned',
            'quantity' => '4', 'warehouse_id' => $to, 'actual_date' => $day, 'source_reference' => 'BACK-HANDOFF', 'reason' => '供方不收、四单位已实际运回'];
        $command = $this->command(0) + ['type' => 'purchase_return_resolution', 'payload' => $payload];
        $back = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($back, FinanceBusinessLogic::getError()); self::assertSame($back, FinanceBusinessLogic::action('record', $command));
        self::assertSame('12.000000', $back['confirmed_result']['resolved_cost']); self::assertSame('6.0000', $back['confirmed_result']['remaining_quantity']);
        self::assertFalse($back['confirmed_result']['cost_pending']);
        self::assertSame('412.000000', $cost->balance($to, $sku)['value']); self::assertSame('104.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($to, $sku));
        $options = FinanceBusinessLogic::options(['type' => 'purchase_return_actual', 'subject_id' => $vendor]);
        $original = array_values(array_filter($options['arrivals'], static fn(array $row): bool => $row['arrival_line_id'] === $arrivals[0]))[0];
        self::assertSame('94.0000', $original['returnable_quantity']);
        $payload['kind'] = 'loss'; $payload['quantity'] = '2'; $payload['expected_resolution_id'] = $back['confirmed_result']['resolution_id'];
        $payload['reason'] = '已核实无法收回，最高权限确认由门店承担';
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_return_resolution', 'payload' => $payload]));
        $payload['loss_confirmed'] = 1;
        $preview = \app\api\jxc\logic\FinancePreview::calculate($this->command(0) + ['type' => 'purchase_return_resolution', 'action' => 'record', 'payload' => $payload]);
        self::assertSame('6.000000', $preview['purchase']['resolved_cost']);
        $lost = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_return_resolution', 'payload' => $payload]); self::assertNotFalse($lost, FinanceBusinessLogic::getError());
        self::assertSame('4.0000', $lost['confirmed_result']['remaining_quantity']);
        self::assertSame('90.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame('104.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($to, $sku));
        self::assertSame('6.000000', $lost['confirmed_result']['resolved_cost']);
        self::assertFalse($lost['confirmed_result']['cost_pending']);
        self::assertSame($preview['cost_impacts'], $lost['confirmed_result']['cost_impacts']);
        self::assertSame($preview['posting_months'], $lost['confirmed_result']['posting_months']);
        self::assertNotEmpty($lost['confirmed_result']['cost_impacts']);
        $stored = \app\api\jxc\logic\FinanceValue::decode(Db::name('finance_purchase_return_resolution')->where('id', $lost['confirmed_result']['resolution_id'])->value('snapshot'));
        $sortFields = static function (array $row): array { ksort($row); return $row; };
        self::assertSame(array_map($sortFields, $lost['confirmed_result']['cost_impacts']), array_map($sortFields, $stored['cost_impacts']));
        self::assertSame('6.000000', $cost->destination($warehouse, $sku, 'loss', 'purchase-return-loss:' . $lost['id'])['cost']);
        self::assertSame('12.000000', $cost->destination($warehouse, $sku, 'return', 'purchase-return:' . $returnId)['cost']);
        self::assertSame('0.00', (new FinanceLedger(self::TENANT_ID))->categoryBalance('payable', $vendor));
    }

    public function test_return_back_heals_its_own_negative_source_before_an_earlier_unrelated_return(): void
    {
        $this->activate(); $from = $this->createCustomerReportWarehouse('原到货足量仓'); $warehouse = $this->createCustomerReportWarehouse('漏记调拨退离仓');
        $goods = $this->createCustomerReportGoods('负库存返回商品', 'FIN-NEG-BACK'); $sku = $this->customerReportSkuId($goods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '返回原负量供方']);
        $arrival = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival', 'payload' => ['subject_id' => $vendor,
            'warehouse_id' => $from, 'actual_date' => date('Y-m-d'), 'source_reference' => 'NEG-BACK-ARR', 'reason' => '成本待确认的实际到货',
            'lines' => [['sku_id' => $sku, 'actual_quantity' => '100']]]]); self::assertNotFalse($arrival);
        $returns = [];
        for ($i = 0; $i < 2; $i++) {
            $return = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_return_actual', 'payload' => ['subject_id' => $vendor,
                'warehouse_id' => $warehouse, 'actual_date' => date('Y-m-d'), 'source_reference' => 'NEG-BACK-OUT-' . $i, 'reason' => '实际退离，漏记来源待查',
                'lines' => [['arrival_line_id' => $arrival['confirmed_result']['lines'][0]['arrival_line_id'], 'quantity' => '10']]]]); self::assertNotFalse($return);
            $returns[] = $return['confirmed_result']['lines'][0];
        }
        $back = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_return_resolution', 'payload' => ['subject_id' => $vendor,
            'return_line_id' => $returns[1]['return_line_id'], 'expected_resolution_id' => 0, 'kind' => 'returned', 'quantity' => '10', 'warehouse_id' => $warehouse,
            'actual_date' => date('Y-m-d'), 'source_reference' => 'NEG-BACK-IN', 'reason' => '第二次退离的十单位全部实际返回']]); self::assertNotFalse($back, FinanceBusinessLogic::getError());
        $sources = Db::name('negative_inventory_attribution')->where('tenant_id', self::TENANT_ID)->column('remaining_qty', 'id');
        self::assertSame('0.0000', $sources[$returns[1]['negative_attribution_id']]); self::assertSame('10.0000', $sources[$returns[0]['negative_attribution_id']]);
        self::assertCount(1, \app\api\jxc\logic\NegativeInventoryLogic::todos()['lists']);
        self::assertSame('-10.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
    }

    public function test_supplier_return_acceptance_closes_unsettled_quantity_and_explicitly_credits_settled_remainder(): void
    {
        $day = date('Y-m-d', strtotime('-1 day')); $this->activate('cash', date('Y-m-01', strtotime('-1 month')));
        $warehouse = $this->createCustomerReportWarehouse('退货认可仓');
        $goods = $this->createCustomerReportGoods('供方部分认可商品', 'FIN-RETURN-ACCEPT'); $sku = $this->customerReportSkuId($goods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '退货认可供应商']);
        $arrival = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival', 'payload' => ['subject_id' => $vendor,
            'warehouse_id' => $warehouse, 'actual_date' => $day, 'source_reference' => 'ACCEPT-ARRIVAL', 'reason' => '真实到货暂无价格',
            'lines' => [['sku_id' => $sku, 'actual_quantity' => '100']]]]);
        self::assertNotFalse($arrival, FinanceBusinessLogic::getError()); $arrivalId = $arrival['confirmed_result']['lines'][0]['arrival_line_id'];
        $return = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_return_actual', 'payload' => ['subject_id' => $vendor,
            'warehouse_id' => $warehouse, 'actual_date' => $day, 'source_reference' => 'ACCEPT-RETURN', 'reason' => '已实际退离等待供方核实',
            'lines' => [['arrival_line_id' => $arrivalId, 'quantity' => '10']]]]);
        self::assertNotFalse($return, FinanceBusinessLogic::getError()); $returnId = $return['confirmed_result']['lines'][0]['return_line_id'];
        $payload = ['subject_id' => $vendor, 'return_line_id' => $returnId, 'expected_resolution_id' => 0,
            'accepted_quantity' => '8', 'unsettled_quantity' => '8', 'unsettled_amount' => '16.00', 'credit_amount' => '0.00',
            'actual_date' => date('Y-m-d'), 'supplier_confirmed' => 1, 'supplier_confirmation' => '供方回执确认八单位、余二待查',
            'credit_reviewed' => 1, 'credit_allocations' => [], 'reason' => '按供方实际认可登记'];
        $command = $this->command(0) + ['type' => 'purchase_return_acceptance', 'payload' => $payload];
        $accepted = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($accepted, FinanceBusinessLogic::getError());
        self::assertSame($accepted, FinanceBusinessLogic::action('record', $command));
        self::assertSame('2.0000', $accepted['confirmed_result']['disputed_quantity']);
        $ledger = new FinanceLedger(self::TENANT_ID); self::assertSame('0.00', $ledger->categoryBalance('payable', $vendor));
        $options = FinanceBusinessLogic::options(['type' => 'purchase_settlement', 'subject_id' => $vendor]); self::assertSame('92.0000', $options['arrivals'][0]['pending_quantity']);
        $old = \app\api\jxc\logic\FinanceStatementSnapshot::pendingArrivals($vendor, $day); self::assertSame('100.0000', $old[0]['pending_quantity']);
        $current = \app\api\jxc\logic\FinanceStatementSnapshot::pendingArrivals($vendor, date('Y-m-d')); self::assertSame('92.0000', $current[0]['pending_quantity']);
        $bad = $payload; $bad['expected_resolution_id'] = $accepted['confirmed_result']['resolution_id']; $bad['accepted_quantity'] = '3'; $bad['unsettled_quantity'] = '3';
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_return_acceptance', 'payload' => $bad]));
        $settled = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_settlement', 'payload' => ['subject_id' => $vendor,
            'reason' => '剩余到货结算', 'supplier_confirmation' => '供方确认剩余九十二单位', 'supplier_confirmed' => 1,
            'lines' => [['arrival_line_id' => $arrivalId, 'covered_quantity' => '92', 'settlement_quantity' => '92', 'price' => '2.00']]]]);
        self::assertNotFalse($settled, FinanceBusinessLogic::getError()); self::assertSame('184.00', $ledger->categoryBalance('payable', $vendor));
        $payload['expected_resolution_id'] = $accepted['confirmed_result']['resolution_id']; $payload['accepted_quantity'] = '2';
        $payload['unsettled_quantity'] = '0'; $payload['unsettled_amount'] = '0.00'; $payload['credit_amount'] = '4.00';
        $payload['credit_allocations'] = [['source' => $settled['confirmed_result']['created_sources'][0], 'amount' => '4.00']];
        $last = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_return_acceptance', 'payload' => $payload]); self::assertNotFalse($last, FinanceBusinessLogic::getError());
        self::assertSame('0.0000', $last['confirmed_result']['disputed_quantity']);
        self::assertSame('180.00', $ledger->categoryBalance('payable', $vendor)); self::assertSame('0.00', $ledger->categoryBalance('supplier_refund', $vendor));
        self::assertSame('90.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID); self::assertSame('180.000000', $cost->balance($warehouse, $sku)['value']);
        self::assertFalse($cost->balance($warehouse, $sku)['pending']);
        self::assertSame('20.000000', $cost->destination($warehouse, $sku, 'return', 'purchase-return:' . $returnId)['cost']);
        $paid = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'supplier_payment', 'payload' => ['subject_id' => $vendor,
            'account_id' => $this->accountId, 'actual_date' => date('Y-m-d'), 'amount' => '180.00', 'reason' => '支付已确认未付采购',
            'allocations' => [['source' => $settled['confirmed_result']['created_sources'][0], 'amount' => '180.00']]]]);
        self::assertNotFalse($paid, FinanceBusinessLogic::getError());
        $extra = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_return_actual', 'payload' => ['subject_id' => $vendor,
            'warehouse_id' => $warehouse, 'actual_date' => date('Y-m-d'), 'source_reference' => 'ACCEPT-EXTRA', 'reason' => '另两单位已真实退离',
            'lines' => [['arrival_line_id' => $arrivalId, 'quantity' => '2']]]]); self::assertNotFalse($extra, FinanceBusinessLogic::getError());
        $payload['return_line_id'] = $extra['confirmed_result']['lines'][0]['return_line_id']; $payload['expected_resolution_id'] = 0;
        $payload['credit_allocations'] = []; $payload['accepted_quantity'] = '0'; $payload['credit_amount'] = '0.00';
        $rejected = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_return_acceptance', 'payload' => $payload]); self::assertNotFalse($rejected, FinanceBusinessLogic::getError());
        self::assertSame('2.0000', $rejected['confirmed_result']['disputed_quantity']); self::assertSame([], $rejected['confirmed_result']['created_sources']);
        $payload['accepted_quantity'] = '2'; $payload['credit_amount'] = '4.00'; $payload['expected_resolution_id'] = $rejected['confirmed_result']['resolution_id'];
        $foreign = $payload; $foreign['subject_id'] = $vendor + 1;
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_return_acceptance', 'payload' => $foreign]));
        $refundCommand = $this->command(0) + ['type' => 'purchase_return_acceptance', 'payload' => $payload];
        $preview = \app\api\jxc\logic\FinancePreview::calculate($refundCommand + ['action' => 'record']); self::assertSame('4.00', $preview['purchase']['refund_remaining']);
        self::assertSame('0.00', $ledger->categoryBalance('supplier_refund', $vendor));
        $refund = FinanceBusinessLogic::action('record', $refundCommand); self::assertNotFalse($refund, FinanceBusinessLogic::getError());
        self::assertSame('4.00', $ledger->categoryBalance('supplier_refund', $vendor)); self::assertSame('0.00', $ledger->categoryBalance('payable', $vendor));
        self::assertSame('4820.00', $ledger->account($this->accountId)['balance']);
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_return_acceptance', 'payload' => $payload]));
        self::assertStringContainsString('后续处理', FinanceBusinessLogic::getError());
        $employee = WorkforceLogic::saveEmployee(['name' => '退货经办', 'mobile' => '13800009928', 'bind_user_id' => 996928,
            'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => ['finance.purchase.prepare']]); self::assertNotFalse($employee);
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID]; request()->jxcFromUserToken = true; request()->userId = 996928; request()->adminId = 0;
        self::assertFalse(FinanceBusinessLogic::action('record', $refundCommand));
        self::assertFalse(FinanceBusinessLogic::options(['type' => 'purchase_return_acceptance', 'subject_id' => $vendor])['can_confirm']);
    }

    public function test_physical_purchase_return_uses_warehouse_average_cost_without_reducing_payable_and_preview_rolls_back(): void
    {
        $this->activate(); $warehouse = $this->createCustomerReportWarehouse('退货仓');
        $goods = $this->createCustomerReportGoods('混批退货商品', 'FIN-PUR-RETURN'); $sku = $this->customerReportSkuId($goods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '实物退货供应商']);
        $arrivals = [];
        foreach (['2.00', '4.00'] as $price) {
            $arrival = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival', 'payload' => ['subject_id' => $vendor,
                'warehouse_id' => $warehouse, 'actual_date' => date('Y-m-d'), 'source_reference' => 'RET-ARR-' . $price, 'reason' => '实际验收',
                'lines' => [['sku_id' => $sku, 'actual_quantity' => '100', 'agreed_price' => $price]]]]);
            self::assertNotFalse($arrival, FinanceBusinessLogic::getError()); $arrivals[] = $arrival['confirmed_result']['lines'][0]['arrival_line_id'];
        }
        $settled = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_settlement', 'payload' => ['subject_id' => $vendor,
            'reason' => '第一批确认', 'supplier_confirmation' => '供方确认', 'supplier_confirmed' => 1,
            'lines' => [['arrival_line_id' => $arrivals[0], 'covered_quantity' => '100', 'settlement_quantity' => '100', 'price' => '2.00']]]]);
        self::assertNotFalse($settled, FinanceBusinessLogic::getError()); $ledger = new FinanceLedger(self::TENANT_ID); $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        $payload = ['subject_id' => $vendor, 'warehouse_id' => $warehouse, 'actual_date' => date('Y-m-d'), 'source_reference' => '实物交接 RET-001',
            'reason' => '商品已实际退离门店，等待供应商认可', 'lines' => [['arrival_line_id' => $arrivals[0], 'quantity' => '10']]];
        $command = $this->command(0) + ['type' => 'purchase_return_actual', 'payload' => $payload];
        $preview = \app\api\jxc\logic\FinancePreview::calculate($command + ['action' => 'record']);
        self::assertSame('30.000000', $preview['purchase']['lines'][0]['return_cost']);
        self::assertSame('200.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame('600.000000', $cost->balance($warehouse, $sku)['value']);
        $returned = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($returned, FinanceBusinessLogic::getError());
        self::assertSame($returned, FinanceBusinessLogic::action('record', $command));
        self::assertSame('30.000000', $returned['confirmed_result']['lines'][0]['return_cost']);
        self::assertSame('190.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame('570.000000', $cost->balance($warehouse, $sku)['value']);
        self::assertSame('200.00', $ledger->categoryBalance('payable', $vendor)); self::assertSame('0.00', $ledger->categoryBalance('supplier_refund', $vendor));
        $bad = $payload; $bad['lines'][] = ['arrival_line_id' => 999999999, 'quantity' => '1'];
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_return_actual', 'payload' => $bad]));
        self::assertSame('190.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame('570.000000', $cost->balance($warehouse, $sku)['value']);
    }

    public function test_backdated_purchase_return_replays_actual_cost_order_and_keeps_later_stock(): void
    {
        $firstDay = date('Y-m-01', strtotime('-1 month')); $returnDay = date('Y-m-d', strtotime($firstDay . ' +1 day')); $laterDay = date('Y-m-01');
        $this->activate('cash', date('Y-m-01', strtotime('-1 month')));
        $warehouse = $this->createCustomerReportWarehouse('历史退离仓');
        $goods = $this->createCustomerReportGoods('乱序到货商品', 'FIN-HISTORY'); $sku = $this->customerReportSkuId($goods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '历史退离供应商']);
        $arrivals = [];
        foreach ([[$firstDay, '2.00'], [$laterDay, '4.00']] as [$date, $price]) {
            $arrival = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival', 'payload' => ['subject_id' => $vendor,
                'warehouse_id' => $warehouse, 'actual_date' => $date, 'source_reference' => 'HISTORY-' . $price, 'reason' => '实际验收',
                'lines' => [['sku_id' => $sku, 'actual_quantity' => '100', 'agreed_price' => $price]]]]);
            self::assertNotFalse($arrival, FinanceBusinessLogic::getError()); $arrivals[] = $arrival['confirmed_result']['lines'][0]['arrival_line_id'];
        }
        $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        $sold = Db::transaction(fn() => $cost->recordWithinTransaction(['reference' => 'later-sale', 'type' => 'issue', 'sku_id' => $sku,
            'warehouse_id' => $warehouse, 'business_date' => date('Y-m-d'), 'quantity' => '95', 'bucket' => 'sale', 'target_reference' => 'later-sale']));
        self::assertSame('285.000000', $sold['cost']);
        $command = $this->command(0) + ['type' => 'purchase_return_actual', 'payload' => ['subject_id' => $vendor, 'warehouse_id' => $warehouse,
            'actual_date' => $returnDay, 'source_reference' => '历史交接记录', 'reason' => '补录已实际退离', 'lines' => [['arrival_line_id' => $arrivals[0], 'quantity' => '10']]]];
        $return = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($return, FinanceBusinessLogic::getError());
        self::assertSame('20.000000', $return['confirmed_result']['lines'][0]['return_cost']);
        self::assertSame('290.000000', $cost->balance($warehouse, $sku)['value']);
        self::assertSame('290.000000', $cost->destination($warehouse, $sku, 'sale', 'later-sale')['cost']);
        $previousInventory = Db::name('finance_cost_effect')->where('tenant_id', self::TENANT_ID)->where('sku_id', $sku)->where('bucket', 'inventory')
            ->where('business_date', '<', $laterDay)->field('SUM(value_delta) AS amount')->find();
        self::assertSame('180.000000', $previousInventory['amount']);
        $effects = Db::name('finance_cost_effect')->where('tenant_id', self::TENANT_ID)->where('reference', 'later-sale')->select()->toArray();
        foreach ($effects as $effect) { self::assertSame(date('Y-m-d'), $effect['business_date']); }
        self::assertSame('190.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame($return, FinanceBusinessLogic::action('record', $command));
        self::assertSame('290.000000', $cost->balance($warehouse, $sku)['value']);
        Db::transaction(fn() => $cost->recordWithinTransaction(['reference' => 'historical-price', 'type' => 'adjust', 'sku_id' => $sku,
            'warehouse_id' => $warehouse, 'business_date' => $firstDay, 'origin' => 'purchase-arrival:' . $arrivals[0], 'amount' => '240.00']));
        self::assertSame('308.000000', $cost->balance($warehouse, $sku)['value']);
        self::assertSame('308.000000', $cost->destination($warehouse, $sku, 'sale', 'later-sale')['cost']);
        self::assertSame('24.000000', $cost->destination($warehouse, $sku, 'return', 'purchase-return:' . $return['confirmed_result']['lines'][0]['return_line_id'])['cost']);
    }

    public function test_purchase_return_retains_unknown_cost_and_negative_source_and_rejects_duplicate_or_excess_quantity(): void
    {
        $this->activate(); $from = $this->createCustomerReportWarehouse('原到货仓'); $warehouse = $this->createCustomerReportWarehouse('实物退离但漏记调拨仓');
        $goods = $this->createCustomerReportGoods('退货成本待确认商品', 'FIN-PUR-UNKNOWN'); $sku = $this->customerReportSkuId($goods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '退货待核实供应商']);
        $arrival = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival', 'payload' => ['subject_id' => $vendor,
            'warehouse_id' => $from, 'actual_date' => date('Y-m-d'), 'source_reference' => 'RET-UNKNOWN', 'reason' => '已到货尚未取得价格',
            'lines' => [['sku_id' => $sku, 'actual_quantity' => '100']]]]);
        self::assertNotFalse($arrival, FinanceBusinessLogic::getError()); $arrivalId = $arrival['confirmed_result']['lines'][0]['arrival_line_id'];
        $payload = ['subject_id' => $vendor, 'warehouse_id' => $warehouse, 'actual_date' => date('Y-m-d'), 'source_reference' => 'RET-FACT',
            'reason' => '已核实商品实际从本仓退离，漏记调拨待补录', 'lines' => [['arrival_line_id' => $arrivalId, 'quantity' => '10']]];
        $return = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_return_actual', 'payload' => $payload]);
        self::assertNotFalse($return, FinanceBusinessLogic::getError()); $line = $return['confirmed_result']['lines'][0];
        self::assertTrue($line['cost_pending']); self::assertNull($line['return_cost']); self::assertSame('10.0000', $line['negative_stock_quantity']);
        self::assertSame('-10.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        self::assertSame('10.000000000000', $cost->destination($warehouse, $sku, 'return', 'purchase-return:' . $line['return_line_id'])['pending_quantity']);
        $todos = \app\api\jxc\logic\NegativeInventoryLogic::todos();
        self::assertNotFalse($todos); self::assertCount(1, $todos['lists']);
        self::assertSame('10.0000', $todos['lists'][0]['remaining_qty']);
        self::assertStringContainsString('采购实际退货', $todos['lists'][0]['reason']);
        $bad = $payload; $bad['lines'][0]['quantity'] = '95';
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_return_actual', 'payload' => $bad]));
        $bad = $payload; $bad['lines'][] = $bad['lines'][0];
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_return_actual', 'payload' => $bad]));
        self::assertSame('-10.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        $options = FinanceBusinessLogic::options(['type' => 'purchase_return_actual', 'subject_id' => $vendor]);
        self::assertNotFalse($options); self::assertSame('90.0000', $options['arrivals'][0]['returnable_quantity']);
        self::assertFalse(FinanceBusinessLogic::action('reverse', $this->command(1) + ['id' => $return['id'], 'correction_reason' => '误操作请求']));
        self::assertStringContainsString('实际退离', FinanceBusinessLogic::getError());
        $again = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_return_actual', 'payload' => $payload]);
        self::assertNotFalse($again, FinanceBusinessLogic::getError());
        $todos = \app\api\jxc\logic\NegativeInventoryLogic::todos(); self::assertCount(2, $todos['lists']);
        foreach ($todos['lists'] as $todo) { self::assertSame('10.0000', $todo['remaining_qty']); }
    }

    public function test_purchase_arrival_preview_rolls_back_stock_and_unknown_cost_and_rejects_invalid_later_line(): void
    {
        $this->activate();
        $warehouse = $this->createCustomerReportWarehouse('预览到货仓');
        $goods = $this->createCustomerReportGoods('预览到货商品', 'FIN-ARR-PREVIEW'); $sku = $this->customerReportSkuId($goods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '待确认价格供应商']);
        $payload = ['subject_id' => $vendor, 'warehouse_id' => $warehouse, 'actual_date' => date('Y-m-d'),
            'source_reference' => '验收记录 ARR-002', 'reason' => '实际已到货，未取得可靠报价', 'lines' => [['sku_id' => $sku, 'actual_quantity' => '10']]];
        $preview = \app\api\jxc\logic\FinancePreview::calculate($this->command(0) + ['action' => 'record', 'type' => 'purchase_arrival', 'payload' => $payload]);
        self::assertTrue($preview['purchase']['cost_pending']);
        self::assertNull($preview['purchase']['lines'][0]['estimated_amount']);
        self::assertTrue($preview['cost_impacts'][0]['cost_pending']);
        self::assertNull($preview['cost_impacts'][0]['value_delta']);
        self::assertSame('0.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertCount(0, (new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID))->events($sku));
        $payload['lines'][] = ['sku_id' => 999999999, 'actual_quantity' => '2'];
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival', 'payload' => $payload]));
        self::assertSame('0.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame(0, Db::name('finance_purchase_arrival_line')->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_purchase_arrival_options_page_real_skus_and_keep_supplier_last_price_separate(): void
    {
        $this->activate();
        $warehouse = $this->createCustomerReportWarehouse('采购选择仓');
        $goods = $this->createCustomerReportGoods('采购选择商品', 'FIN-ARR-OPTIONS'); $sku = $this->customerReportSkuId($goods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '有历史结算供应商']);
        Db::name('finance_purchase_price')->insert(['tenant_id' => self::TENANT_ID, 'vendor_id' => $vendor, 'sku_id' => $sku,
            'document_id' => 901, 'price' => '3.00', 'business_date' => date('Y-m-d'), 'create_time' => time()]);
        $options = FinanceBusinessLogic::options(['type' => 'purchase_arrival', 'subject_id' => $vendor, 'keyword' => '采购选择商品']);
        self::assertNotFalse($options, FinanceBusinessLogic::getError());
        self::assertContains($warehouse, array_column($options['warehouses'], 'id'));
        self::assertSame($sku, $options['sku_choices'][0]['sku_id']);
        self::assertSame('3.00', $options['sku_choices'][0]['last_formal_price']['price']);
        self::assertSame([], $options['sources']);
        self::assertTrue($options['can_prepare']);
    }

    public function test_purchase_arrival_prepare_permission_cannot_receive_and_revocation_blocks_cached_draft(): void
    {
        $this->activate();
        $employee = WorkforceLogic::saveEmployee(['name' => '到货经办', 'mobile' => '13800009928', 'bind_user_id' => 996928,
            'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => ['finance.purchase.prepare']]);
        self::assertNotFalse($employee, WorkforceLogic::getError());
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID]; request()->jxcFromUserToken = true; request()->userId = 996928; request()->adminId = 0;
        $command = $this->command(0) + ['type' => 'purchase_arrival', 'payload' => []];
        self::assertFalse(FinanceBusinessLogic::action('record', $command));
        self::assertNotFalse(FinanceBusinessLogic::action('prepare', $command), FinanceBusinessLogic::getError());
        self::assertFalse(FinanceBusinessLogic::options(['type' => 'purchase_arrival'])['can_confirm']);
        Db::name('employee_permission')->where('tenant_id', self::TENANT_ID)->where('employee_id', $employee['id'])->delete();
        self::assertFalse(FinanceBusinessLogic::action('prepare', $command));
        self::assertSame(0, Db::name('stock_flow')->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_purchase_arrival_preview_includes_cost_of_negative_sales_in_an_earlier_open_month(): void
    {
        $date = date('Y-m-01', strtotime('first day of last month')); $this->activate('cash', $date);
        $warehouse = $this->createCustomerReportWarehouse('补负库存仓');
        $goods = $this->createCustomerReportGoods('补负库存商品', 'FIN-ARR-NEGATIVE'); $sku = $this->customerReportSkuId($goods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '补负库存供应商']);
        $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        Db::transaction(fn() => $cost->recordWithinTransaction(['reference' => 'prior-open-negative-sale', 'type' => 'issue',
            'sku_id' => $sku, 'warehouse_id' => $warehouse, 'business_date' => $date, 'quantity' => '3', 'bucket' => 'sale', 'target_reference' => 'prior-sale']));
        $payload = ['subject_id' => $vendor, 'warehouse_id' => $warehouse, 'actual_date' => date('Y-m-d'), 'source_reference' => 'ARR-NEG',
            'reason' => '取得可靠到货报价并补缺', 'lines' => [['sku_id' => $sku, 'actual_quantity' => '10', 'agreed_price' => '2.00']]];
        $preview = \app\api\jxc\logic\FinancePreview::calculate($this->command(0) + ['action' => 'record', 'type' => 'purchase_arrival', 'payload' => $payload]);
        self::assertContains(substr($date, 0, 7), $preview['posting_months']);
        self::assertContains(date('Y-m'), $preview['posting_months']);
        $sales = array_values(array_filter($preview['cost_impacts'], static fn(array $row): bool => $row['bucket'] === 'sale'));
        self::assertCount(1, $sales); self::assertSame('6.000000', $sales[0]['value_delta']);
        self::assertSame(substr($date, 0, 7), $sales[0]['posting_month']);
        self::assertNull($cost->destination($warehouse, $sku, 'sale', 'prior-sale')['cost']);
    }

    public function test_multi_sku_arrival_preserves_document_order_and_stock_when_lock_order_differs(): void
    {
        $this->activate(); $warehouse = $this->createCustomerReportWarehouse('多商品到货仓');
        $firstGoods = $this->createCustomerReportGoods('先建商品', 'FIN-ARR-FIRST'); $firstSku = $this->customerReportSkuId($firstGoods);
        $secondGoods = $this->createCustomerReportGoods('后建商品', 'FIN-ARR-SECOND'); $secondSku = $this->customerReportSkuId($secondGoods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '多商品到货供应商']);
        $payload = ['subject_id' => $vendor, 'warehouse_id' => $warehouse, 'actual_date' => date('Y-m-d'), 'source_reference' => 'ARR-MULTI', 'reason' => '按送货单顺序核实',
            'lines' => [['sku_id' => $secondSku, 'actual_quantity' => '20', 'agreed_price' => '3.00'], ['sku_id' => $firstSku, 'actual_quantity' => '10', 'agreed_price' => '2.00']]];
        $result = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival', 'payload' => $payload]);
        self::assertNotFalse($result, FinanceBusinessLogic::getError());
        self::assertSame([$secondSku, $firstSku], array_column($result['confirmed_result']['lines'], 'sku_id'));
        self::assertSame('10.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $firstSku));
        self::assertSame('20.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $secondSku));
        self::assertFalse(FinanceBusinessLogic::action('correct', $this->command(1) + ['id' => $result['id'], 'payload' => $payload, 'correction_reason' => '试图覆盖实物记录']));
        self::assertSame('20.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $secondSku));
    }

    public function test_supplier_partial_settlement_creates_payables_and_completes_actual_coverage_without_receiving_again(): void
    {
        $this->activate(); $warehouse = $this->createCustomerReportWarehouse('分次供应商结算仓');
        $goods = $this->createCustomerReportGoods('分次供应商结算商品', 'FIN-PURCHASE-BATCH'); $sku = $this->customerReportSkuId($goods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '分次结算供应商']);
        $arrival = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival', 'payload' => ['subject_id' => $vendor,
            'warehouse_id' => $warehouse, 'actual_date' => date('Y-m-d'), 'source_reference' => 'ARR-PART', 'reason' => '尚无可靠报价',
            'lines' => [['sku_id' => $sku, 'actual_quantity' => '100']]]]);
        self::assertNotFalse($arrival, FinanceBusinessLogic::getError()); $line = $arrival['confirmed_result']['lines'][0]['arrival_line_id'];
        self::assertTrue(\app\api\jxc\logic\StockService::outbound($warehouse, $goods, '30', 901, 'sales', 'SALE-PART', '', $sku));
        $payload = ['subject_id' => $vendor, 'reason' => '已逐项与供应商核对', 'supplier_confirmation' => '供应商结算单 SET-PART', 'supplier_confirmed' => 1,
            'lines' => [['arrival_line_id' => $line, 'covered_quantity' => '40', 'settlement_quantity' => '41', 'price' => '2.00', 'terms_version' => 0,
                'difference_class' => 'normal', 'difference_reason' => '双方确认一斤行业允差', 'difference_confirmed' => 1]]];
        $command = $this->command(0) + ['type' => 'purchase_settlement', 'payload' => $payload];
        $first = FinanceBusinessLogic::action('record', $command);
        self::assertNotFalse($first, FinanceBusinessLogic::getError()); self::assertSame($first, FinanceBusinessLogic::action('record', $command));
        $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        self::assertNull($cost->balance($warehouse, $sku)['value']); self::assertSame('57.400000', $cost->balance($warehouse, $sku)['known_value']);
        self::assertSame('82.00', (new FinanceLedger(self::TENANT_ID))->source($first['confirmed_result']['created_sources'][0])['balance']);
        $options = FinanceBusinessLogic::options(['type' => 'purchase_settlement', 'subject_id' => $vendor]);
        self::assertSame('60.0000', $options['arrivals'][0]['pending_quantity']);
        self::assertSame('100.0000', $options['arrivals'][0]['actual_quantity']);
        $payload['lines'][0]['covered_quantity'] = '60'; $payload['lines'][0]['settlement_quantity'] = '60';
        $last = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_settlement', 'payload' => $payload]);
        self::assertNotFalse($last, FinanceBusinessLogic::getError());
        self::assertSame('0.0000', $last['confirmed_result']['lines'][0]['pending_quantity']);
        self::assertSame('70.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame('141.400000', $cost->balance($warehouse, $sku)['value']);
        self::assertSame('60.600000', $cost->destination($warehouse, $sku, 'sale', 'sales_order:901')['cost']);
        self::assertSame('202.00', (new FinanceLedger(self::TENANT_ID))->categoryBalance('payable', $vendor));
        self::assertSame([], FinanceBusinessLogic::options(['type' => 'purchase_settlement', 'subject_id' => $vendor])['arrivals']);
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_settlement', 'payload' => $payload]));
        self::assertSame(2, Db::name('stock_flow')->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_necessary_purchase_freight_remains_in_cost_after_formal_settlement_without_extra_stock_or_cash(): void
    {
        $this->activate(); $warehouse = $this->createCustomerReportWarehouse('采购运费仓');
        $goods = $this->createCustomerReportGoods('采购运费商品', 'FIN-FREIGHT'); $sku = $this->customerReportSkuId($goods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '商品供应商']);
        $carrier = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '运输服务商']);
        $arrival = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival', 'payload' => ['subject_id' => $vendor,
            'warehouse_id' => $warehouse, 'actual_date' => date('Y-m-d'), 'source_reference' => 'ARR-FREIGHT', 'reason' => '验收入库尚未取得商品报价',
            'lines' => [['sku_id' => $sku, 'actual_quantity' => '100']]]]);
        self::assertNotFalse($arrival, FinanceBusinessLogic::getError()); $line = $arrival['confirmed_result']['lines'][0]['arrival_line_id'];
        self::assertTrue(\app\api\jxc\logic\StockService::outbound($warehouse, $goods, '30', 902, 'sales', 'SALE-FREIGHT', '', $sku));
        $payload = ['subject_id' => $carrier, 'actual_date' => date('Y-m-d'), 'amount' => '20.00', 'cost_kind' => 'freight',
            'source_reference' => '运输账单 FR-001', 'reason' => '入库前直接必要运输', 'attribution_basis' => '本次运输仅此到货', 'necessary_confirmed' => 1,
            'lines' => [['arrival_line_id' => $line, 'amount' => '20.00']]];
        $command = $this->command(0) + ['type' => 'purchase_extra_cost', 'payload' => $payload];
        $preview = \app\api\jxc\logic\FinancePreview::calculate($command + ['action' => 'record']);
        self::assertSame('20.00', $preview['purchase']['amount']); self::assertNotEmpty($preview['cost_impacts']);
        self::assertSame(0, Db::name('finance_purchase_cost_change')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame('0.00', (new FinanceLedger(self::TENANT_ID))->categoryBalance('expense_payable', $carrier));
        $extra = FinanceBusinessLogic::action('record', $command);
        self::assertNotFalse($extra, FinanceBusinessLogic::getError()); self::assertSame($extra, FinanceBusinessLogic::action('record', $command));
        $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID); $ledger = new FinanceLedger(self::TENANT_ID);
        self::assertNull($cost->balance($warehouse, $sku)['value']); self::assertSame('14.000000', $cost->balance($warehouse, $sku)['known_value']);
        self::assertSame('20.00', $ledger->categoryBalance('expense_payable', $carrier));
        self::assertSame('5000.00', $ledger->account($this->accountId)['balance']);
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_extra_cost', 'payload' => $payload]));
        self::assertStringContainsString('已登记', FinanceBusinessLogic::getError());
        $invalid = $payload; $invalid['source_reference'] = 'FR-BAD-TOTAL'; $invalid['amount'] = '21.00';
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_extra_cost', 'payload' => $invalid]));
        self::assertSame('20.00', $ledger->categoryBalance('expense_payable', $carrier));
        $settled = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_settlement', 'payload' => ['subject_id' => $vendor,
            'reason' => '确认商品价', 'supplier_confirmation' => '供方确认', 'supplier_confirmed' => 1,
            'lines' => [['arrival_line_id' => $line, 'covered_quantity' => '100', 'settlement_quantity' => '100', 'price' => '2.00']]]]);
        self::assertNotFalse($settled, FinanceBusinessLogic::getError());
        self::assertSame('154.000000', $cost->balance($warehouse, $sku)['value']);
        self::assertSame('66.000000', $cost->destination($warehouse, $sku, 'sale', 'sales_order:902')['cost']);
        self::assertSame('200.00', $ledger->categoryBalance('payable', $vendor));
        self::assertSame('70.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame(2, Db::name('stock_flow')->where('tenant_id', self::TENANT_ID)->count());
        $payload['cost_kind'] = 'sales_delivery';
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_extra_cost', 'payload' => $payload]));
        self::assertSame('154.000000', $cost->balance($warehouse, $sku)['value']);
    }

    public function test_purchase_rule_priority_and_arrival_snapshot_do_not_change_when_later_rules_are_saved(): void
    {
        $this->activate(); $warehouse = $this->createCustomerReportWarehouse('采购规则仓');
        $goods = $this->createCustomerReportGoods('采购规则商品', 'FIN-PUR-RULE'); $sku = $this->customerReportSkuId($goods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '采购规则供应商']);
        $rule = ['rule_kind' => 'difference', 'scope' => 'store', 'expected_rule_version' => 0, 'absolute_limit' => '2', 'percent_limit' => '1', 'reason' => '门店复核基准'];
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_rules', 'payload' => $rule]), FinanceBusinessLogic::getError());
        $rule['scope'] = 'sku'; $rule['sku_id'] = $sku; $rule['absolute_limit'] = '3';
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_rules', 'payload' => $rule]), FinanceBusinessLogic::getError());
        $before = \app\api\jxc\logic\FinancePurchaseRuleBook::threshold($vendor, $sku, 0);
        self::assertSame('sku', $before['scope']); self::assertSame('3.0000', $before['absolute_limit']);
        $arrival = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival', 'payload' => ['subject_id' => $vendor,
            'warehouse_id' => $warehouse, 'actual_date' => date('Y-m-d'), 'source_reference' => 'ARR-RULE', 'reason' => '实际验收入库',
            'lines' => [['sku_id' => $sku, 'actual_quantity' => '100', 'reported_quantity' => '101', 'agreed_price' => '2.00']]]]);
        self::assertNotFalse($arrival, FinanceBusinessLogic::getError());
        $rule['scope'] = 'vendor_sku'; $rule['subject_id'] = $vendor; $rule['absolute_limit'] = '0.1';
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_rules', 'payload' => $rule]), FinanceBusinessLogic::getError());
        self::assertSame('vendor_sku', \app\api\jxc\logic\FinancePurchaseRuleBook::threshold($vendor, $sku, 0)['scope']);
        $stored = FinanceBusinessLogic::detail(['id' => $arrival['id']]);
        self::assertSame('sku', $stored['confirmed_result']['lines'][0]['difference_rule']['scope']);
        self::assertSame('3.0000', $stored['confirmed_result']['lines'][0]['difference_rule']['absolute_limit']);
    }

    public function test_purchase_amount_adjustment_keeps_coverage_and_uses_explicit_supplier_credit_with_refund_remainder(): void
    {
        $this->activate(); $warehouse = $this->createCustomerReportWarehouse('采购调价仓');
        $goods = $this->createCustomerReportGoods('采购调价商品', 'FIN-PUR-ADJUST'); $sku = $this->customerReportSkuId($goods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '调价供应商']);
        $arrival = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival', 'payload' => ['subject_id' => $vendor,
            'warehouse_id' => $warehouse, 'actual_date' => date('Y-m-d'), 'source_reference' => 'ARR-ADJUST', 'reason' => '已验收',
            'lines' => [['sku_id' => $sku, 'actual_quantity' => '100', 'agreed_price' => '2.00']]]]);
        self::assertNotFalse($arrival, FinanceBusinessLogic::getError()); $line = $arrival['confirmed_result']['lines'][0]['arrival_line_id'];
        $settled = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_settlement', 'payload' => ['subject_id' => $vendor,
            'reason' => '双方确认', 'supplier_confirmation' => '结算单确认', 'supplier_confirmed' => 1,
            'lines' => [['arrival_line_id' => $line, 'covered_quantity' => '100', 'settlement_quantity' => '100', 'price' => '2.00']]]]);
        self::assertNotFalse($settled, FinanceBusinessLogic::getError());
        self::assertTrue(\app\api\jxc\logic\StockService::outbound($warehouse, $goods, '30', 903, 'sales', 'SALE-ADJUST', '', $sku));
        $options = FinanceBusinessLogic::options(['type' => 'purchase_adjustment', 'subject_id' => $vendor]);
        self::assertNotFalse($options, FinanceBusinessLogic::getError()); self::assertSame('200.00', $options['settlements'][0]['current_amount']);
        self::assertSame(0, $options['settlements'][0]['expected_adjustment_id']);
        $original = $settled['confirmed_result']['created_sources'][0];
        $settlementLine = (int)Db::name('finance_purchase_settlement_line')->where('tenant_id', self::TENANT_ID)->where('document_id', $settled['id'])->value('id');
        $payload = ['subject_id' => $vendor, 'settlement_line_id' => $settlementLine, 'expected_adjustment_id' => 0, 'new_amount' => '150.00',
            'reason' => '双方另行约定降价，不变更实物与处理量', 'supplier_confirmed' => 1, 'supplier_confirmation' => '调价确认 ADJ-1',
            'credit_reviewed' => 1, 'credit_allocations' => [['source' => $original, 'amount' => '30.00']]];
        $command = $this->command(0) + ['type' => 'purchase_adjustment', 'payload' => $payload];
        $adjusted = FinanceBusinessLogic::action('record', $command);
        self::assertNotFalse($adjusted, FinanceBusinessLogic::getError()); self::assertSame($adjusted, FinanceBusinessLogic::action('record', $command));
        $ledger = new FinanceLedger(self::TENANT_ID); $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        self::assertSame('170.00', $ledger->source($original)['balance']);
        self::assertSame('20.00', $ledger->categoryBalance('supplier_refund', $vendor));
        self::assertSame('105.000000', $cost->balance($warehouse, $sku)['value']);
        self::assertSame('45.000000', $cost->destination($warehouse, $sku, 'sale', 'sales_order:903')['cost']);
        self::assertSame('100.0000', Db::name('finance_purchase_settlement_line')->where('id', $settlementLine)->value('covered_quantity'));
        self::assertSame('200.00', Db::name('finance_purchase_settlement_line')->where('id', $settlementLine)->value('amount'));
        self::assertSame('5000.00', $ledger->account($this->accountId)['balance']);
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_adjustment', 'payload' => $payload]));
        $payload['expected_adjustment_id'] = $adjusted['confirmed_result']['adjustment_id']; $payload['new_amount'] = '180.00'; $payload['credit_allocations'] = [];
        $increased = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_adjustment', 'payload' => $payload]);
        self::assertNotFalse($increased, FinanceBusinessLogic::getError());
        self::assertSame('126.000000', $cost->balance($warehouse, $sku)['value']);
        self::assertSame('200.00', $ledger->categoryBalance('payable', $vendor));
        self::assertSame('20.00', $ledger->categoryBalance('supplier_refund', $vendor));
        self::assertSame(2, Db::name('stock_flow')->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_supplier_refund_credit_can_offset_later_payable_and_reverse_without_cash_or_second_cost(): void
    {
        $oldDate = date('Y-m-01', strtotime('first day of last month')); $this->activate('cash', $oldDate);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '后续抵扣供应商']);
        $ledger = new FinanceLedger(self::TENANT_ID);
        $credit = $ledger->createSource(2001, 'supplier_refund', $vendor, '50', $oldDate, null, ['subject_name' => '后续抵扣供应商']);
        $payable = $ledger->createSource(2002, 'payable', $vendor, '100', date('Y-m-d'), null, ['subject_name' => '后续抵扣供应商']);
        Db::name('finance_period')->insert(['tenant_id' => self::TENANT_ID, 'month' => substr($oldDate, 0, 7), 'status' => 'closed', 'snapshot' => '{}', 'closed_by' => '{}', 'closed_at' => time()]);
        $payload = ['subject_id' => $vendor, 'credit_source' => $credit, 'reason' => '双方确认使用原应退款抵扣后续采购', 'allocations' => [['source' => $payable, 'amount' => '30']]];
        $command = $this->command(0) + ['type' => 'supplier_credit_allocate', 'payload' => $payload];
        $applied = FinanceBusinessLogic::action('record', $command);
        self::assertNotFalse($applied, FinanceBusinessLogic::getError()); self::assertSame($applied, FinanceBusinessLogic::action('record', $command));
        self::assertSame('20.00', $ledger->source($credit)['balance']); self::assertSame('70.00', $ledger->source($payable)['balance']);
        $entries = Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('document_id', $applied['id'])->select()->toArray();
        self::assertCount(2, $entries); self::assertSame([date('Y-m')], array_values(array_unique(array_column($entries, 'posting_month'))));
        self::assertSame([date('Y-m-d')], array_values(array_unique(array_column($entries, 'effective_date'))));
        $reversed = FinanceBusinessLogic::action('reverse', $this->command($applied['version']) + ['id' => $applied['id'], 'correction_reason' => '双方取消本次抵扣，恢复原未结项']);
        self::assertNotFalse($reversed, FinanceBusinessLogic::getError());
        self::assertSame('50.00', $ledger->source($credit)['balance']); self::assertSame('100.00', $ledger->source($payable)['balance']);
        self::assertSame('5000.00', $ledger->account($this->accountId)['balance']);
        self::assertSame(0, Db::name('finance_money_transaction')->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_unknown_date_opening_supplier_credit_uses_target_month_and_rejects_foreign_or_excess_allocation(): void
    {
        $oldDate = date('Y-m-01', strtotime('first day of last month')); $this->activate('cash', $oldDate);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '期初贷项供应商']);
        $creditId = (int)Db::name('finance_opening_source')->insertGetId(['tenant_id' => self::TENANT_ID, 'opening_item_id' => 990010,
            'category' => 'supplier_refund', 'subject_id' => $vendor, 'amount' => '50.00', 'activation_date' => $oldDate,
            'source_snapshot' => json_encode(['historical_date' => null, 'subject_name' => '期初贷项供应商', 'source_reference' => '已核实期初应退款']), 'create_time' => time()]);
        $credit = 'o:' . $creditId; $ledger = new FinanceLedger(self::TENANT_ID);
        $payable = $ledger->createSource(2011, 'expense_payable', $vendor, '100', date('Y-m-d'), null, []);
        $foreign = $ledger->createSource(2012, 'payable', $vendor + 1, '100', date('Y-m-d'), null, []);
        $payload = ['subject_id' => $vendor, 'credit_source' => $credit, 'reason' => '明确使用核实的期初应退款', 'allocations' => [['source' => $foreign, 'amount' => '30']]];
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'supplier_credit_allocate', 'payload' => $payload]));
        $payload['allocations'] = [['source' => $payable, 'amount' => '60']];
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'supplier_credit_allocate', 'payload' => $payload]));
        self::assertSame('100.00', $ledger->source($payable)['balance']); self::assertSame('50.00', $ledger->source($credit)['balance']);
        $payload['allocations'][0]['amount'] = '30';
        $result = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'supplier_credit_allocate', 'payload' => $payload]);
        self::assertNotFalse($result, FinanceBusinessLogic::getError());
        $entries = Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('document_id', $result['id'])->select()->toArray();
        self::assertCount(2, $entries);
        foreach ($entries as $entry) { self::assertSame(date('Y-m'), $entry['posting_month']); self::assertNull($entry['effective_date']); }
        $options = FinanceBusinessLogic::options(['type' => 'supplier_credit_allocate', 'subject_id' => $vendor, 'role' => 'fund']);
        self::assertNotFalse($options, FinanceBusinessLogic::getError()); self::assertSame($credit, $options['sources'][0]['reference']); self::assertSame('20.00', $options['sources'][0]['balance']);
        $oldStatement = \app\api\jxc\logic\FinanceStatements::action('generate', $this->command(0) + ['subject_kind' => 'vendor', 'vendor_id' => $vendor,
            'date_from' => $oldDate, 'date_to' => date('Y-m-t', strtotime($oldDate))]);
        self::assertSame('50.00', $oldStatement['snapshot']['balances']['supplier_refund']['closing']);
        self::assertSame('0.00', $oldStatement['snapshot']['balances']['expense_payable']['closing']);
        $currentStatement = \app\api\jxc\logic\FinanceStatements::action('generate', $this->command(0) + ['subject_kind' => 'vendor', 'vendor_id' => $vendor]);
        self::assertSame('20.00', $currentStatement['snapshot']['balances']['supplier_refund']['closing']);
        self::assertSame('70.00', $currentStatement['snapshot']['balances']['expense_payable']['closing']);
        $reverse = FinanceBusinessLogic::action('reverse', $this->command($result['version']) + ['id' => $result['id'], 'correction_reason' => '撤销本次抵扣，恢复待退款']);
        self::assertNotFalse($reverse, FinanceBusinessLogic::getError());
        $reversals = Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('document_id', $reverse['id'])->select()->toArray();
        self::assertCount(2, $reversals);
        foreach ($reversals as $entry) { self::assertSame(date('Y-m'), $entry['posting_month']); self::assertNull($entry['effective_date']); }
        self::assertSame('50.00', $ledger->source($credit)['balance']); self::assertSame('100.00', $ledger->source($payable)['balance']);
    }

    public function test_paid_purchase_overhead_reduction_revalues_cost_and_creates_refund_without_reopening_paid_bill(): void
    {
        $this->activate(); $warehouse = $this->createCustomerReportWarehouse('附加成本更正仓');
        $goods = $this->createCustomerReportGoods('附加成本更正商品', 'FIN-EXTRA-REV'); $sku = $this->customerReportSkuId($goods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '附加成本服务方']);
        $arrival = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival', 'payload' => ['subject_id' => $vendor,
            'warehouse_id' => $warehouse, 'actual_date' => date('Y-m-d'), 'source_reference' => 'ARR-EXTRA-REV', 'reason' => '实际入库',
            'lines' => [['sku_id' => $sku, 'actual_quantity' => '100', 'agreed_price' => '2.00']]]]);
        self::assertNotFalse($arrival, FinanceBusinessLogic::getError()); $line = $arrival['confirmed_result']['lines'][0]['arrival_line_id'];
        $extra = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_extra_cost', 'payload' => ['subject_id' => $vendor,
            'actual_date' => date('Y-m-d'), 'amount' => '20.00', 'cost_kind' => 'loading', 'source_reference' => 'LOAD-REV-1',
            'reason' => '入库必要装卸', 'attribution_basis' => '全部归属该批', 'necessary_confirmed' => 1, 'lines' => [['arrival_line_id' => $line, 'amount' => '20.00']]]]);
        self::assertNotFalse($extra, FinanceBusinessLogic::getError()); $payable = $extra['confirmed_result']['created_sources'][0];
        self::assertTrue(\app\api\jxc\logic\StockService::outbound($warehouse, $goods, '30', 904, 'sales', 'SALE-EXTRA-REV', '', $sku));
        $paid = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'supplier_payment', 'payload' => ['subject_id' => $vendor,
            'account_id' => $this->accountId, 'actual_date' => date('Y-m-d'), 'amount' => '20.00', 'reason' => '实际支付装卸费用', 'allocations' => [['source' => $payable, 'amount' => '20.00']]]]);
        self::assertNotFalse($paid, FinanceBusinessLogic::getError());
        $payload = ['subject_id' => $vendor, 'original_cost_document_id' => $extra['id'], 'expected_revision_id' => 0, 'new_amount' => '10.00',
            'reason' => '装卸数量核对后减费', 'supplier_confirmation' => '服务方明确退还差额', 'supplier_confirmed' => 1,
            'attribution_basis' => '仍归属同一批入库', 'lines' => [['arrival_line_id' => $line, 'amount' => '10.00']], 'credit_reviewed' => 1, 'credit_allocations' => []];
        $command = $this->command(0) + ['type' => 'purchase_extra_adjustment', 'payload' => $payload];
        $preview = \app\api\jxc\logic\FinancePreview::calculate($command + ['action' => 'record']);
        self::assertSame('-10.00', $preview['purchase']['amount_change']); self::assertNotEmpty($preview['cost_impacts']);
        self::assertSame(0, Db::name('finance_purchase_cost_revision')->where('tenant_id', self::TENANT_ID)->count());
        $adjusted = FinanceBusinessLogic::action('record', $command);
        self::assertNotFalse($adjusted, FinanceBusinessLogic::getError()); self::assertSame($adjusted, FinanceBusinessLogic::action('record', $command));
        $ledger = new FinanceLedger(self::TENANT_ID); $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        self::assertSame('0.00', $ledger->source($payable)['balance']); self::assertSame('10.00', $ledger->categoryBalance('supplier_refund', $vendor));
        self::assertSame('147.000000', $cost->balance($warehouse, $sku)['value']); self::assertSame('63.000000', $cost->destination($warehouse, $sku, 'sale', 'sales_order:904')['cost']);
        self::assertSame('4980.00', $ledger->account($this->accountId)['balance']);
        self::assertSame('20.00', Db::name('finance_purchase_cost_bill')->where('tenant_id', self::TENANT_ID)->where('document_id', $extra['id'])->value('amount'));
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_extra_adjustment', 'payload' => $payload]));
        $options = FinanceBusinessLogic::options(['type' => 'purchase_extra_adjustment', 'subject_id' => $vendor]);
        self::assertNotFalse($options, FinanceBusinessLogic::getError()); self::assertSame('10.00', $options['bills'][0]['current_amount']);
        self::assertSame($adjusted['confirmed_result']['revision_id'], $options['bills'][0]['expected_revision_id']);
        $payload['expected_revision_id'] = $adjusted['confirmed_result']['revision_id']; $payload['new_amount'] = '0.00'; $payload['lines'] = [];
        $cancelled = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_extra_adjustment', 'payload' => $payload]);
        self::assertNotFalse($cancelled, FinanceBusinessLogic::getError());
        self::assertSame('140.000000', $cost->balance($warehouse, $sku)['value']);
        self::assertSame('20.00', $ledger->categoryBalance('supplier_refund', $vendor));
        self::assertSame([], FinanceBusinessLogic::options(['type' => 'purchase_extra_adjustment', 'subject_id' => $vendor])['bills'][0]['lines']);
        self::assertSame(2, Db::name('stock_flow')->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_supplier_settlement_within_threshold_still_needs_confirmation_and_uses_versioned_supplier_terms(): void
    {
        $this->activate(); $warehouse = $this->createCustomerReportWarehouse('采购复核仓');
        $goods = $this->createCustomerReportGoods('采购复核商品', 'FIN-PUR-REVIEW'); $sku = $this->customerReportSkuId($goods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '有付款期限供应商']);
        $rule = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_rules', 'payload' => ['rule_kind' => 'difference', 'scope' => 'store',
            'expected_rule_version' => 0, 'absolute_limit' => '2', 'percent_limit' => '2', 'reason' => '双方业务复核基准']]);
        self::assertNotFalse($rule, FinanceBusinessLogic::getError());
        $terms = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_rules', 'payload' => ['rule_kind' => 'terms', 'subject_id' => $vendor,
            'expected_rule_version' => 0, 'mode' => 'days_after', 'days' => 7, 'reason' => '约定到货七天后付款']]);
        self::assertNotFalse($terms, FinanceBusinessLogic::getError());
        $arrival = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival', 'payload' => ['subject_id' => $vendor,
            'warehouse_id' => $warehouse, 'actual_date' => date('Y-m-d'), 'source_reference' => 'ARR-REVIEW', 'reason' => '实际验收',
            'lines' => [['sku_id' => $sku, 'actual_quantity' => '100', 'reported_quantity' => '101', 'agreed_price' => '2.00']]]]);
        self::assertNotFalse($arrival, FinanceBusinessLogic::getError());
        $employee = WorkforceLogic::saveEmployee(['name' => '采购结算经办', 'mobile' => '13800009928', 'bind_user_id' => 996928,
            'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => ['finance.purchase.confirm']]);
        self::assertNotFalse($employee, WorkforceLogic::getError());
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID]; request()->jxcFromUserToken = true; request()->userId = 996928; request()->adminId = 0;
        $payload = ['subject_id' => $vendor, 'supplier_confirmation' => '结算单 REVIEW-1', 'supplier_confirmed' => 1, 'reason' => '核实本次结算',
            'lines' => [['arrival_line_id' => $arrival['confirmed_result']['lines'][0]['arrival_line_id'], 'covered_quantity' => '50', 'settlement_quantity' => '50.5',
                'price' => '2.00', 'terms_version' => 1, 'difference_rule_id' => $rule['confirmed_result']['rule']['id'],
                'difference_class' => 'normal', 'difference_reason' => '已核对计费允差', 'arrival_difference_class' => 'normal', 'arrival_difference_reason' => '已核对到货允差']]];
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_settlement', 'payload' => $payload]));
        $payload['lines'][0]['difference_confirmed'] = 1; $payload['lines'][0]['arrival_difference_confirmed'] = 1;
        $command = $this->command(0) + ['type' => 'purchase_settlement', 'payload' => $payload];
        $result = FinanceBusinessLogic::action('record', $command);
        self::assertNotFalse($result, FinanceBusinessLogic::getError());
        self::assertFalse($result['confirmed_result']['lines'][0]['settlement_review']['requires_owner']);
        self::assertSame(date('Y-m-d', strtotime('+7 days')), $result['confirmed_result']['lines'][0]['due_date']);
        Db::name('employee_permission')->where('tenant_id', self::TENANT_ID)->where('employee_id', $employee['id'])->delete();
        self::assertFalse(FinanceBusinessLogic::action('record', $command));
    }

    public function test_purchase_arrival_records_actual_stock_and_estimate_once_without_formal_payable(): void
    {
        $this->activate();
        $warehouse = $this->createCustomerReportWarehouse('采购到货仓');
        $goods = $this->createCustomerReportGoods('采购到货商品', 'FIN-ARRIVAL'); $sku = $this->customerReportSkuId($goods);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '采购到货供应商']);
        $payload = ['subject_id' => $vendor, 'warehouse_id' => $warehouse, 'actual_date' => date('Y-m-d'),
            'source_reference' => '供应商送货单 ARR-001', 'reason' => '已过磅验收入库',
            'lines' => [['sku_id' => $sku, 'actual_quantity' => '100', 'reported_quantity' => '101', 'agreed_price' => '2.50']]];
        $command = $this->command(0) + ['type' => 'purchase_arrival', 'payload' => $payload];
        $first = FinanceBusinessLogic::action('record', $command);
        self::assertNotFalse($first, FinanceBusinessLogic::getError());
        self::assertSame($first, FinanceBusinessLogic::action('record', $command));
        self::assertSame('100.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame('252.500000', (new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID))->balance($warehouse, $sku)['value']);
        self::assertSame('101.0000', $first['confirmed_result']['lines'][0]['estimated_quantity']);
        self::assertSame('100.0000', $first['confirmed_result']['lines'][0]['pending_quantity']);
        self::assertSame([], (new FinanceLedger(self::TENANT_ID))->sources('payable', $vendor));
        self::assertSame(1, Db::name('stock_flow')->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_negative_sale_cost_confirmation_keeps_original_date_and_posts_only_to_current_open_month(): void
    {
        $originalDate = date('Y-m-01', strtotime('first day of last month'));
        $this->activate('cash', $originalDate);
        $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        Db::transaction(fn() => $cost->recordWithinTransaction(['reference' => 'old-negative-sale', 'type' => 'issue', 'sku_id' => 20, 'warehouse_id' => 10,
            'business_date' => $originalDate, 'quantity' => '30', 'bucket' => 'sale', 'target_reference' => 'old-negative-sale']));
        self::assertNull($cost->destination(10, 20, 'sale', 'old-negative-sale')['cost']);
        Db::name('finance_period')->insert(['tenant_id' => self::TENANT_ID, 'month' => substr($originalDate, 0, 7), 'status' => 'closed', 'snapshot' => '{}', 'closed_by' => '{}', 'closed_at' => time()]);
        Db::transaction(fn() => $cost->recordWithinTransaction(['reference' => 'new-arrival', 'type' => 'receive', 'sku_id' => 20, 'warehouse_id' => 10,
            'business_date' => date('Y-m-d'), 'quantity' => '100', 'origin' => 'new-arrival', 'amount' => '1000.00']));
        self::assertSame('300.000000', $cost->destination(10, 20, 'sale', 'old-negative-sale')['cost']);
        $posts = array_values(array_filter($cost->postings(20), static fn(array $row): bool => $row['bucket'] === 'sale' && $row['value_delta'] === '300.000000'));
        self::assertCount(1, $posts); self::assertSame($originalDate, $posts[0]['business_date']); self::assertSame(date('Y-m'), $posts[0]['posting_month']);
        self::assertSame('700.000000', $cost->balance(10, 20)['value']);
        $this->prepareCustomerReportRequestContext(self::OTHER_TENANT_ID);
        self::assertSame([], (new \app\api\jxc\logic\FinanceCostLedger(self::OTHER_TENANT_ID))->events(20));
    }

    public function test_cost_book_carries_confirmed_opening_once_including_zero_inventory_without_new_stock(): void
    {
        $warehouse = $this->createCustomerReportWarehouse('期初成本仓库');
        $skuIds = [];
        self::assertNotFalse(FinanceSetupLogic::savePreparation($this->command(0) + ['activation_date' => date('Y-m-01'),
            'inventory_cost_reviewed' => 1, 'legacy_settlement_reviewed' => 1, 'excluded_business_reviewed' => 1]));
        foreach ([['10', '100'], ['0', '0']] as [$quantity, $amount]) {
            $goods = $this->createCustomerReportGoods('期初成本商品' . $quantity, 'COST-OPEN-' . $quantity); $sku = $this->customerReportSkuId($goods); $skuIds[] = $sku;
            $stock = (int)Db::name('warehouse_sku_balance')->insertGetId(['tenant_id' => self::TENANT_ID, 'warehouse_id' => $warehouse,
                'goods_id' => $goods, 'sku_id' => $sku, 'on_hand_qty' => $quantity, 'available_qty' => $quantity]);
            $this->opening('item', ['category' => 'inventory', 'subject_id' => $stock, 'amount' => $amount, 'historical_date' => null, 'due_date' => null,
                'source_mode' => 'detail', 'source_reference' => '截点库存' . $quantity, 'evidence' => '盘点核实', 'details' => ['quantity' => $quantity, 'origin_reference' => '成本核对']]);
        }
        foreach (FinanceSetupLogic::opening()['categories'] as $category) { $this->opening('review', ['category' => $category['key'], 'state' => $category['count'] ? 'complete' : 'none', 'evidence' => '逐类核实']); }
        $this->opening('submit'); $this->opening('confirm');
        $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        self::assertSame('100.000000', $cost->balance($warehouse, $skuIds[0])['value']);
        self::assertSame('0.000000', $cost->balance($warehouse, $skuIds[1])['value']);
        $result = Db::transaction(fn() => $cost->recordWithinTransaction(['reference' => 'opening-sale', 'type' => 'issue', 'sku_id' => $skuIds[0], 'warehouse_id' => $warehouse,
            'business_date' => date('Y-m-d'), 'quantity' => '4', 'bucket' => 'sale', 'target_reference' => 'opening-sale']));
        self::assertSame('40.000000', $result['cost']); self::assertSame('60.000000', $cost->balance($warehouse, $skuIds[0])['value']);
        self::assertSame('10.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $skuIds[0]));
    }

    public function test_real_stock_flows_carry_cost_through_sale_transfer_and_actual_delivery_correction(): void
    {
        $this->activate();
        $warehouse = $this->createCustomerReportWarehouse('成本实物仓库'); $target = $this->createCustomerReportWarehouse('成本调入仓库');
        $goods = $this->createCustomerReportGoods('成本实物商品', 'COST-PHYSICAL'); $sku = $this->customerReportSkuId($goods);
        self::assertTrue(\app\api\jxc\logic\StockService::inbound($warehouse, $goods, '10', 71, 'supply', 'COST-P71', '', $sku));
        $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        self::assertNull($cost->balance($warehouse, $sku)['value']);
        $events = $cost->events($sku); self::assertCount(1, $events);
        Db::transaction(fn() => $cost->recordWithinTransaction(['reference' => 'physical-price-confirm', 'type' => 'adjust', 'sku_id' => $sku, 'warehouse_id' => $warehouse,
            'business_date' => date('Y-m-d'), 'origin' => $events[0]['snapshot']['origin'], 'amount' => '200.00']));
        self::assertTrue(\app\api\jxc\logic\StockService::outbound($warehouse, $goods, '4', 77, 'sales', 'COST-S77', '', $sku));
        self::assertSame('80.000000', $cost->destination($warehouse, $sku, 'sale', 'sales_order:77')['cost']);
        self::assertTrue(\app\api\jxc\logic\StockService::transfer($warehouse, $target, $goods, '2', 88, 'warehouse_transfer', 'COST-T88', '', $sku));
        self::assertSame('40.000000', $cost->balance($target, $sku)['value']);
        self::assertNotFalse(Db::transaction(fn() => \app\api\jxc\logic\StockService::inboundDeliveryCorrectionWithinTransaction($warehouse, $goods, $sku, '1', 77, 'COST-S77', 777, 888)));
        self::assertSame('60.000000', $cost->destination($warehouse, $sku, 'sale', 'sales_order:77')['cost']);
        self::assertSame('100.000000', $cost->balance($warehouse, $sku)['value']);
        self::assertSame('5.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame('2.0000', \app\api\jxc\logic\WarehouseSkuBalanceService::onHand($target, $sku));
    }

    public function test_cost_confirmation_reloads_committed_facts_after_an_outer_transaction_created_an_older_snapshot(): void
    {
        $this->activate(); $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        Db::transaction(fn() => $cost->recordWithinTransaction(['reference' => 'snapshot-arrival', 'type' => 'receive', 'sku_id' => 20, 'warehouse_id' => 10,
            'business_date' => date('Y-m-d'), 'quantity' => '10', 'origin' => 'snapshot-arrival', 'amount' => '100.00']));
        $event = ['reference' => 'snapshot-worker-sale', 'type' => 'issue', 'sku_id' => 20, 'warehouse_id' => 10,
            'business_date' => date('Y-m-d'), 'quantity' => '2', 'bucket' => 'sale', 'target_reference' => 'worker-sale'];
        $input = tempnam(sys_get_temp_dir(), 'cost-in-'); $output = tempnam(sys_get_temp_dir(), 'cost-out-');
        file_put_contents($input, json_encode(['tenant_id' => self::TENANT_ID, 'admin_id' => self::ADMIN_ID, 'event' => $event], JSON_THROW_ON_ERROR));
        Db::execute('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'); Db::startTrans();
        try {
            Db::name('finance_cost_origin')->where('tenant_id', self::TENANT_ID)->select();
            $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/fixtures/finance_cost_worker.php', $input, $output],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), null, ['bypass_shell' => true]);
            self::assertIsResource($process); fclose($pipes[0]); $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $stdout . $stderr);
            self::assertSame('20.000000', json_decode(file_get_contents($output), true, 512, JSON_THROW_ON_ERROR)['cost']);
            $event['reference'] = 'snapshot-parent-sale'; $event['quantity'] = '3'; $event['target_reference'] = 'parent-sale';
            $cost->recordWithinTransaction($event); Db::commit();
            self::assertSame('50.000000', $cost->balance(10, 20)['value']);
            self::assertSame('20.000000', $cost->destination(10, 20, 'sale', 'worker-sale')['cost']);
            self::assertSame('30.000000', $cost->destination(10, 20, 'sale', 'parent-sale')['cost']);
        } finally {
            if (Db::connect()->getPdo()->inTransaction()) { Db::rollback(); }
            unlink($input); unlink($output);
        }
    }

    public function test_unpriced_cross_warehouse_return_survives_reload_and_partial_cost_funding(): void
    {
        $this->activate(); $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        $record = fn(array $event): array => Db::transaction(fn() => $cost->recordWithinTransaction($event + ['sku_id' => 20, 'warehouse_id' => 10, 'business_date' => date('Y-m-d')]));
        $record(['reference' => 'cross-sale', 'type' => 'issue', 'quantity' => '10', 'bucket' => 'sale', 'target_reference' => 'cross-sale']);
        $record(['reference' => 'cross-return', 'type' => 'restore', 'to_warehouse_id' => 11, 'quantity' => '4', 'bucket' => 'sale', 'target_reference' => 'cross-sale']);
        $record(['reference' => 'cross-resale', 'type' => 'issue', 'warehouse_id' => 11, 'quantity' => '2', 'bucket' => 'sale', 'target_reference' => 'cross-resale']);
        $record(['reference' => 'cross-arrival-1', 'type' => 'receive', 'origin' => 'cross-arrival-1', 'quantity' => '8', 'amount' => '80.00']);
        self::assertTrue($cost->balance(11, 20)['pending']); self::assertSame('10.000000', $cost->balance(11, 20)['known_value']);
        self::assertNull($cost->destination(11, 20, 'sale', 'cross-resale')['cost']);
        $record(['reference' => 'cross-arrival-2', 'type' => 'receive', 'origin' => 'cross-arrival-2', 'quantity' => '2', 'amount' => '40.00']);
        self::assertSame('30.000000', $cost->balance(11, 20)['value']);
        self::assertSame('30.000000', $cost->destination(11, 20, 'sale', 'cross-resale')['cost']);
        self::assertSame('60.000000', $cost->destination(10, 20, 'sale', 'cross-sale')['cost']);
        self::assertSame('0.000000000000', $cost->balance(10, 20)['quantity']);
    }

    public function test_cost_confirmation_cannot_ignore_a_month_closed_after_outer_transaction_snapshot(): void
    {
        $this->activate(); $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        $config = config('database.connections.mysql');
        $other = new \PDO('mysql:host=' . $config['hostname'] . ';port=' . $config['hostport'] . ';dbname=' . $config['database'], $config['username'], $config['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        Db::execute('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'); Db::startTrans();
        try {
            Db::name('finance_period')->where('tenant_id', self::TENANT_ID)->select();
            $other->beginTransaction();
            $lock = $other->prepare('SELECT tenant_id FROM ' . Db::name('finance_preparation')->getTable() . ' WHERE tenant_id=? FOR UPDATE'); $lock->execute([self::TENANT_ID]);
            $close = $other->prepare('INSERT INTO ' . Db::name('finance_period')->getTable() . ' (tenant_id,month,status,snapshot,closed_by,closed_at) VALUES (?, ?, ?, ?, ?, ?)');
            $close->execute([self::TENANT_ID, date('Y-m'), 'closed', '{}', '{}', time()]); $other->commit();
            $this->expectException(\DomainException::class); $this->expectExceptionMessage('当前自然月已结账');
            $cost->recordWithinTransaction(['reference' => 'closed-snapshot-arrival', 'type' => 'receive', 'sku_id' => 20, 'warehouse_id' => 10,
                'business_date' => date('Y-m-d'), 'quantity' => '1', 'origin' => 'closed-snapshot-arrival', 'amount' => '10.00']);
        } finally { Db::rollback(); }
    }

    public function test_loss_only_event_uses_exception_time_and_missing_inbound_amount_supplies_its_cost(): void
    {
        $this->activate(); $warehouse = $this->createCustomerReportWarehouse('损耗成本仓');
        $goods = $this->createCustomerReportGoods('损耗成本商品', 'LOSS-COST'); $sku = $this->customerReportSkuId($goods);
        $event = (int)Db::name('fulfillment_delivery_event')->insertGetId(['tenant_id' => self::TENANT_ID, 'idempotency_key' => 'loss-only-cost-event',
            'event_type' => 'delivery_exception', 'delivered_time' => 0, 'actual_handoff_time' => 0, 'create_time' => time()]);
        self::assertNotFalse(Db::transaction(fn() => \app\api\jxc\logic\StockService::outboundTransportLossWithinTransaction($warehouse, $goods, $sku, '2', '0', $event)));
        $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
        self::assertSame(date('Y-m-d'), $cost->events($sku)[0]['business_date']);
        self::assertNull($cost->destination($warehouse, $sku, 'loss', 'delivery_loss:' . $event)['cost']);
        self::assertNotFalse(Db::transaction(fn() => \app\api\jxc\logic\StockService::adjustNegativeWithinTransaction($warehouse, $goods, $sku, '2', 17,
            'record_missing_inbound', '核实遗漏进货金额', '50.00')));
        self::assertSame('50.000000', $cost->destination($warehouse, $sku, 'loss', 'delivery_loss:' . $event)['cost']);
        self::assertSame('0.000000000000', $cost->balance($warehouse, $sku)['quantity']);
    }

    public function test_processed_reduction_records_internal_loss_cost_but_keeps_other_consumption_unclassified(): void
    {
        $this->activate();
        foreach (['internal_loss' => 'loss', 'other' => 'pending'] as $disposition => $bucket) {
            $warehouse = $this->createCustomerReportWarehouse('加工成本仓' . $disposition); $goods = $this->createCustomerReportGoods('加工成本商品' . $disposition, 'PROCESS-COST-' . $disposition);
            $sku = $this->customerReportSkuId($goods); $cost = new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID);
            self::assertTrue(\app\api\jxc\logic\StockService::inbound($warehouse, $goods, '6', 72, 'supply', 'PROCESS-COST', '', $sku));
            $origin = $cost->events($sku)[0]['snapshot']['origin'];
            Db::transaction(fn() => $cost->recordWithinTransaction(['reference' => 'process-price-' . $disposition, 'type' => 'adjust', 'sku_id' => $sku,
                'warehouse_id' => $warehouse, 'business_date' => date('Y-m-d'), 'origin' => $origin, 'amount' => '120.00']));
            $report = \app\api\jxc\logic\CustomerReportLogic::submit(['main_customer_id' => $this->customerId, 'delivery_date' => date('Y-m-d'), 'is_supplement' => 0,
                'idempotency_key' => 'process-report-' . $disposition, 'items' => [['goods_id' => $goods, 'warehouse_id' => $warehouse, 'unit_id' => 0, 'unit_name' => '件',
                    'order_qty' => '5', 'piece_weight_confirmed' => 1, 'piece_weight_min' => '1.00', 'piece_weight_max' => '1.00', 'price_status' => 'unpriced', 'processing_requirement' => '杀好']]]);
            self::assertNotFalse($report, \app\api\jxc\logic\CustomerReportLogic::getError()); $item = (int)$report['items'][0]['id'];
            $reduced = \app\api\jxc\logic\FulfillmentChangeLogic::reduceItem(['report_item_id' => $item, 'new_expected_base_qty' => '3', 'processed_reduction_qty' => '1',
                'processed_disposition' => $disposition, 'other_inventory_action' => 'consume', 'reason' => '记录已加工部分的真实去向', 'idempotency_key' => 'process-reduce-' . $disposition]);
            self::assertNotFalse($reduced, \app\api\jxc\logic\FulfillmentChangeLogic::getError());
            self::assertSame('100.000000', $cost->balance($warehouse, $sku)['value']);
            self::assertSame('20.000000', $cost->destination($warehouse, $sku, $bucket, 'fulfillment_loss:' . $item)['cost']);
            if ($bucket === 'pending') { self::assertSame('0.000000', $cost->destination($warehouse, $sku, 'loss', 'fulfillment_loss:' . $item)['cost']); }
        }
    }

    public function test_receipt_allocates_opening_debt_and_explicit_excess_once_without_new_revenue(): void
    {
        $this->activate();
        $command = $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('1500', '1000', '500')];
        $result = FinanceBusinessLogic::action('record', $command);
        self::assertNotFalse($result, FinanceBusinessLogic::getError());
        self::assertSame('confirmed', $result['status']);
        self::assertSame($result, FinanceBusinessLogic::action('record', $command));
        $ledger = new FinanceLedger(self::TENANT_ID);
        self::assertSame('0.00', $ledger->source($this->receivable)['balance']);
        self::assertSame('6500.00', $ledger->account($this->accountId)['balance']);
        self::assertSame('500.00', $ledger->sources('advance', $this->customerId)[0]['balance']);
        self::assertSame(0, Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('metric', 'revenue')->count());
        self::assertSame(1, Db::name('finance_money_transaction')->where('tenant_id', self::TENANT_ID)->count());
        $command['payload']['amount'] = '1600';
        self::assertFalse(FinanceBusinessLogic::action('record', $command));
        self::assertStringContainsString('同一提交标识', FinanceBusinessLogic::getError());
    }

    public function test_shared_draft_and_pending_have_no_money_effect_and_stale_version_is_rejected(): void
    {
        $this->activate();
        $draft = FinanceBusinessLogic::action('save', $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('100', '100')]);
        self::assertNotFalse($draft, FinanceBusinessLogic::getError());
        self::assertSame('draft', $draft['status']);
        $pending = FinanceBusinessLogic::action('submit', $this->command(1) + ['id' => $draft['id']]);
        self::assertNotFalse($pending, FinanceBusinessLogic::getError());
        self::assertSame('pending', $pending['status']);
        self::assertSame('5000.00', (new FinanceLedger(self::TENANT_ID))->account($this->accountId)['balance']);
        self::assertFalse(FinanceBusinessLogic::action('confirm', $this->command(1) + ['id' => $draft['id']]));
        $confirmed = FinanceBusinessLogic::action('confirm', $this->command(2) + ['id' => $draft['id']]);
        self::assertNotFalse($confirmed, FinanceBusinessLogic::getError());
        self::assertSame('5100.00', (new FinanceLedger(self::TENANT_ID))->account($this->accountId)['balance']);
        self::assertFalse(FinanceBusinessLogic::action('save', $this->command(3) + ['id' => $draft['id'], 'payload' => []]));
    }

    public function test_invalid_composition_and_electronic_evidence_roll_back_every_effect(): void
    {
        $this->activate('bank');
        $payload = $this->receipt('100', '100');
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $payload]));
        self::assertStringContainsString('截图', FinanceBusinessLogic::getError());
        $ledger = new FinanceLedger(self::TENANT_ID);
        self::assertSame('1000.00', $ledger->source($this->receivable)['balance']);
        self::assertSame('5000.00', $ledger->account($this->accountId)['balance']);
        self::assertSame(0, Db::name('finance_document')->where('tenant_id', self::TENANT_ID)->count());
        $payload += ['missing_evidence_reason' => '历史渠道无法下载截图', 'alternative_evidence' => '已人工核对银行柜台流水'];
        $confirmed = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $payload]);
        self::assertNotFalse($confirmed, FinanceBusinessLogic::getError());
        self::assertTrue($confirmed['confirmed_result']['money']['evidence']['missing_screenshot']);
        $bad = $this->receipt('50', '40');
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $bad]));
        self::assertSame('900.00', $ledger->source($this->receivable)['balance']);
    }

    public function test_remaining_balance_and_duplicate_risk_are_rechecked_at_confirmation(): void
    {
        $this->activate();
        $first = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('600', '600')]);
        self::assertNotFalse($first, FinanceBusinessLogic::getError());
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('600', '600')]));
        self::assertStringContainsString('超过当前未结余额', FinanceBusinessLogic::getError());
        $payload = $this->receipt('600', '400', '200');
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $payload]));
        self::assertStringContainsString('相近时间', FinanceBusinessLogic::getError());
        $payload['duplicate_risk_reason'] = '客户分两次分别实际交款，已经分别核对';
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $payload]), FinanceBusinessLogic::getError());
    }

    public function test_prepare_submits_latest_payload_atomically_without_posting(): void
    {
        $this->activate();
        $draft = FinanceBusinessLogic::action('save', $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('100', '100')]);
        $command = $this->command(1) + ['id' => $draft['id'], 'payload' => $this->receipt('200', '200')];
        $pending = FinanceBusinessLogic::action('prepare', $command);
        self::assertNotFalse($pending, FinanceBusinessLogic::getError());
        self::assertSame('200', $pending['payload']['amount']);
        self::assertSame('pending', $pending['status']);
        self::assertSame($pending, FinanceBusinessLogic::action('prepare', $command));
        self::assertSame('5000.00', (new FinanceLedger(self::TENANT_ID))->account($this->accountId)['balance']);
        self::assertNotFalse(FinanceBusinessLogic::action('confirm', $this->command(2) + ['id' => $draft['id']]));
        self::assertSame('5200.00', (new FinanceLedger(self::TENANT_ID))->account($this->accountId)['balance']);
    }

    public function test_employee_permissions_guard_confirm_salary_and_cached_results(): void
    {
        $this->activate();
        $employee = WorkforceLogic::saveEmployee(['name' => '财务经办', 'mobile' => '13800009928', 'bind_user_id' => 996928,
            'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => ['finance.receipt.prepare', 'finance.payment.prepare', 'finance.salary.prepare']]);
        self::assertNotFalse($employee, WorkforceLogic::getError());
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID];
        request()->jxcFromUserToken = true; request()->userId = 996928; request()->adminId = 0;
        $command = $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('100', '100')];
        self::assertFalse(FinanceBusinessLogic::action('record', $command));
        self::assertNotFalse(FinanceBusinessLogic::action('prepare', $command));
        self::assertFalse(FinanceBusinessLogic::options(['type' => 'salary_payment']));
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'supplier_payment', 'payload' => []]));
        Db::name('employee_permission')->where('tenant_id', self::TENANT_ID)->where('employee_id', $employee['id'])->delete();
        self::assertFalse(FinanceBusinessLogic::action('prepare', $command), '撤权后不能靠命令缓存读取旧记录');
        self::assertSame([], FinanceBusinessLogic::catalog()['types']);
    }

    public function test_foreign_source_and_wrong_business_evidence_never_post(): void
    {
        $this->activate('bank');
        $ledger = new FinanceLedger(self::OTHER_TENANT_ID);
        $foreign = $ledger->createSource(123, 'receivable', $this->customerId, '100', date('Y-m-d'), null, []);
        $payload = $this->receipt('100', '100'); $payload['allocations'][0]['source'] = $foreign;
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $payload]));
        $payload = $this->receipt('100', '100');
        foreach ([[self::OTHER_TENANT_ID, 'receipt'], [self::TENANT_ID, 'salary_payment']] as [$tenant, $type]) {
            $id = Db::name('finance_evidence')->insertGetId(['tenant_id' => $tenant, 'document_type' => $type, 'file_id' => 42,
                'snapshot' => '{}', 'created_by' => '{}', 'create_time' => time()]);
            $payload['evidence_ids'] = [$id];
            self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $payload]));
            self::assertStringContainsString('截图不存在', FinanceBusinessLogic::getError());
        }
        self::assertSame(0, Db::name('finance_money_transaction')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame('1000.00', (new FinanceLedger(self::TENANT_ID))->source($this->receivable)['balance']);
    }

    public function test_external_transaction_duplicate_is_rejected_even_with_another_command(): void
    {
        $this->activate();
        $payload = $this->receipt('100', '100') + ['transaction_no' => 'REAL-20260907-1', 'transaction_scope' => '已核实账户A'];
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $payload]));
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $payload]));
        self::assertStringContainsString('该渠道交易已登记', FinanceBusinessLogic::getError());
        self::assertSame('900.00', (new FinanceLedger(self::TENANT_ID))->source($this->receivable)['balance']);
    }

    public function test_supplier_combined_payment_reduces_two_sources_without_second_expense(): void
    {
        $this->activate();
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '同一往来方']);
        $ledger = new FinanceLedger(self::TENANT_ID);
        $purchase = $ledger->createSource(1001, 'payable', $vendor, '300', date('Y-m-d'), null, []);
        $expense = $ledger->createSource(1002, 'expense_payable', $vendor, '200', date('Y-m-d'), null, []);
        $payload = ['subject_id' => $vendor, 'account_id' => $this->accountId, 'actual_date' => date('Y-m-d'), 'amount' => '400', 'reason' => '合并支付两笔真实欠款',
            'allocations' => [['source' => $purchase, 'amount' => '300'], ['source' => $expense, 'amount' => '100']]];
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'supplier_payment', 'payload' => $payload]), FinanceBusinessLogic::getError());
        self::assertSame('4600.00', $ledger->account($this->accountId)['balance']);
        self::assertSame('0.00', $ledger->source($purchase)['balance']); self::assertSame('100.00', $ledger->source($expense)['balance']);
        self::assertSame(0, Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('metric', 'expense')->count());
    }

    public function test_equipment_and_recovery_have_explicit_profit_effects_and_advance_refund_does_not(): void
    {
        $this->activate();
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '设备往来方']);
        $ledger = new FinanceLedger(self::TENANT_ID);
        foreach ([['equipment_payment', 'equipment', $vendor, '200'], ['equipment_refund', 'equipment_refund', $vendor, '30'],
            ['recovery_receipt', 'recovery', $this->customerId, '40'], ['advance_refund', 'advance', $this->customerId, '50']] as [$type, $category, $subject, $amount]) {
            $source = $ledger->createSource(1000, $category, $subject, $amount, date('Y-m-d'), null, []);
            $payload = ['subject_id' => $subject, 'account_id' => $this->accountId, 'actual_date' => date('Y-m-d'), 'amount' => $amount,
                'reason' => '核实真实业务来源', 'allocations' => [['source' => $source, 'amount' => $amount]]];
            self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => $type, 'payload' => $payload]), FinanceBusinessLogic::getError());
            self::assertSame('0.00', $ledger->source($source)['balance']);
        }
        self::assertSame('4820.00', $ledger->account($this->accountId)['balance']);
        self::assertEquals(170, Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('metric', 'expense')->sum('amount'));
        self::assertEquals(40, Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('metric', 'recovery_income')->sum('amount'));
    }

    public function test_closed_current_month_blocks_all_effects(): void
    {
        $this->activate();
        Db::name('finance_period')->insert(['tenant_id' => self::TENANT_ID, 'month' => date('Y-m'), 'status' => 'closed', 'snapshot' => '{}', 'closed_by' => '{}', 'closed_at' => time()]);
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('100', '100')]));
        self::assertStringContainsString('当前自然月已结账', FinanceBusinessLogic::getError());
        self::assertSame('5000.00', (new FinanceLedger(self::TENANT_ID))->account($this->accountId)['balance']);
    }

    public function test_correction_preserves_transaction_identity_and_replaces_all_effects_atomically(): void
    {
        $this->activate();
        $original = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('3000', '1000', '2000') +
            ['transaction_no' => 'CORRECT-SAME-TRANSACTION', 'transaction_scope' => '客户现金交款凭据']]);
        self::assertNotFalse($original);
        $payload = $this->receipt('1000', '1000') + ['transaction_no' => 'CORRECT-SAME-TRANSACTION', 'transaction_scope' => '客户现金交款凭据'];
        $command = $this->command(1) + ['id' => $original['id'], 'payload' => $payload, 'correction_reason' => '实收1000误录3000，按原始交款凭证更正'];
        $corrected = FinanceBusinessLogic::action('correct', $command);
        self::assertNotFalse($corrected, FinanceBusinessLogic::getError());
        self::assertSame($corrected, FinanceBusinessLogic::action('correct', $command));
        self::assertNotSame($original['id'], $corrected['id']);
        self::assertSame($original['confirmed_result']['money']['transaction_id'], $corrected['confirmed_result']['money']['transaction_id']);
        $ledger = new FinanceLedger(self::TENANT_ID);
        self::assertSame('6000.00', $ledger->account($this->accountId)['balance']);
        self::assertSame('0.00', $ledger->source($this->receivable)['balance']);
        self::assertSame('0.00', $ledger->sources('advance', $this->customerId)[0]['balance']);
        self::assertSame('3000', FinanceBusinessLogic::detail(['id' => $original['id']])['payload']['amount']);
        self::assertSame(1, Db::name('finance_money_transaction')->where('tenant_id', self::TENANT_ID)->count());
        self::assertFalse(FinanceBusinessLogic::action('correct', $this->command(1) + ['id' => $original['id'], 'payload' => $payload, 'correction_reason' => '重复更正']));
    }

    public function test_failed_correction_rolls_back_reversal_and_does_not_consume_original(): void
    {
        $this->activate();
        $original = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('300', '300')]);
        self::assertFalse(FinanceBusinessLogic::action('correct', $this->command(1) + ['id' => $original['id'], 'payload' => $this->receipt('2000', '2000'), 'correction_reason' => '错误组成应整笔失败']));
        $ledger = new FinanceLedger(self::TENANT_ID);
        self::assertSame('5300.00', $ledger->account($this->accountId)['balance']);
        self::assertSame('700.00', $ledger->source($this->receivable)['balance']);
        self::assertNotFalse(FinanceBusinessLogic::action('correct', $this->command(1) + ['id' => $original['id'], 'payload' => $this->receipt('200', '200'), 'correction_reason' => '已核对实际到账200']), FinanceBusinessLogic::getError());
    }

    public function test_advance_refund_and_allocation_share_one_balance_without_new_cash(): void
    {
        $this->activate(); $ledger = new FinanceLedger(self::TENANT_ID);
        $advance = $ledger->createSource(1000, 'advance', $this->customerId, '600', date('Y-m-d'), null, []);
        $payload = ['subject_id' => $this->customerId, 'account_id' => $this->accountId, 'actual_date' => date('Y-m-d'), 'amount' => '200',
            'reason' => '预收退款', 'allocations' => [['source' => $advance, 'amount' => '200']]];
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'advance_refund', 'payload' => $payload]));
        $allocation = ['subject_id' => $this->customerId, 'advance_source' => $advance, 'reason' => '明确用剩余预收结清指定欠款',
            'allocations' => [['source' => $this->receivable, 'amount' => '400']]];
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'advance_allocate', 'payload' => $allocation]), FinanceBusinessLogic::getError());
        self::assertSame('4800.00', $ledger->account($this->accountId)['balance']);
        self::assertSame('600.00', $ledger->source($this->receivable)['balance']);
        self::assertSame('0.00', $ledger->source($advance)['balance']);
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'advance_refund', 'payload' => $payload]));
        self::assertSame(1, Db::name('finance_money_transaction')->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_correction_registers_new_channel_identity_and_preserves_old_alias(): void
    {
        $this->activate();
        $payload = $this->receipt('100', '100') + ['transaction_no' => 'SAME-CHANNEL-FACT', 'transaction_scope' => '真实银行账户'];
        $original = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $payload]);
        $bank = FinanceSetupLogic::saveAccount($this->command(0) + ['name' => '真实到账银行', 'account_type' => 'bank']);
        self::assertNotFalse($bank); $payload['account_id'] = $bank['id'];
        $payload += ['missing_evidence_reason' => '历史渠道截图无法取得', 'alternative_evidence' => '已核对银行纸质流水'];
        self::assertNotFalse(FinanceBusinessLogic::action('correct', $this->command(1) + ['id' => $original['id'], 'payload' => $payload, 'correction_reason' => '现金误选，实际为银行']), FinanceBusinessLogic::getError());
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $payload]));
        self::assertStringContainsString('该渠道交易已登记', FinanceBusinessLogic::getError());
        $payload['account_id'] = $this->accountId;
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $payload]));
    }

    public function test_advance_consumption_and_receivable_allocation_use_same_effective_period(): void
    {
        $this->activate();
        $previous = (new \DateTimeImmutable('first day of last month'))->format('Y-m-d');
        Db::name('finance_preparation')->where('tenant_id', self::TENANT_ID)->update(['activation_date' => $previous]);
        $ledger = new FinanceLedger(self::TENANT_ID);
        $advance = $ledger->createSource(1000, 'advance', $this->customerId, '100', $previous, null, []);
        $receivable = $ledger->createSource(1001, 'receivable', $this->customerId, '100', date('Y-m-01'), null, []);
        $result = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'advance_allocate', 'payload' => ['subject_id' => $this->customerId,
            'advance_source' => $advance, 'reason' => '前月预收抵本月销售', 'allocations' => [['source' => $receivable, 'amount' => '100']]]]);
        self::assertNotFalse($result, FinanceBusinessLogic::getError());
        $entries = Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('document_id', $result['id'])->select()->toArray();
        self::assertCount(2, $entries);
        foreach ($entries as $entry) { self::assertSame(date('Y-m'), $entry['posting_month']); self::assertSame(date('Y-m-01'), $entry['effective_date']); }
    }

    public function test_duplicate_reversal_keeps_one_transaction_effect_and_does_not_create_refund(): void
    {
        $this->activate();
        $payload = $this->receipt('100', '100');
        $kept = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $payload]);
        $payload['duplicate_risk_reason'] = '经办误判断为第二笔';
        $duplicate = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $payload]);
        self::assertNotFalse($duplicate);
        $command = $this->command(1) + ['id' => $duplicate['id'], 'duplicate_of' => $kept['id'], 'correction_reason' => '核对原凭据确认同一笔误录两次'];
        $reversed = FinanceBusinessLogic::action('reverse_duplicate', $command);
        self::assertNotFalse($reversed, FinanceBusinessLogic::getError());
        self::assertSame($reversed, FinanceBusinessLogic::action('reverse_duplicate', $command));
        $ledger = new FinanceLedger(self::TENANT_ID);
        self::assertSame('5100.00', $ledger->account($this->accountId)['balance']); self::assertSame('900.00', $ledger->source($this->receivable)['balance']);
        self::assertSame(2, Db::name('finance_money_transaction')->where('tenant_id', self::TENANT_ID)->count(), '两份原始登记保留但不产生真实退款');
    }

    public function test_salary_evidence_is_private_and_each_read_rechecks_identity_store_and_permission(): void
    {
        $this->activate();
        $path = tempnam(sys_get_temp_dir(), 'finance-proof-');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQ0AAAAASUVORK5CYII='));
        $upload = new \think\file\UploadedFile($path, '工资核验.png', 'image/png', null, true);
        $proof = \app\api\jxc\logic\FinanceEvidence::save('salary_payment', $upload);
        self::assertArrayNotHasKey('uri', $proof['file']); self::assertArrayNotHasKey('url', $proof['file']);
        $directory = (new \ReflectionMethod(\app\api\jxc\logic\FinanceEvidence::class, 'directory'))->invoke(null, self::TENANT_ID);
        $resolved = str_replace('\\', '/', (string)realpath($directory));
        $root = str_replace('\\', '/', (string)realpath(root_path()));
        self::assertStringStartsWith($root . '/', $resolved);
        foreach (['runtime', 'public'] as $excluded) {
            self::assertFalse(str_starts_with($resolved . '/', $root . '/' . $excluded . '/'), '长期私密凭证不能落入公开目录或框架默认清理的runtime');
        }
        $content = \app\api\jxc\logic\FinanceEvidence::content($proof['id']);
        self::assertSame('image/png', $content['mime']); self::assertNotEmpty($content['base64']);
        $employee = WorkforceLogic::saveEmployee(['name' => '工资经办', 'mobile' => '13800009928', 'bind_user_id' => 996928,
            'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => ['finance.salary.prepare', 'finance.salary.view']]);
        self::assertNotFalse($employee);
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID]; request()->jxcFromUserToken = true; request()->userId = 996928; request()->adminId = 0;
        self::assertSame($content['base64'], \app\api\jxc\logic\FinanceEvidence::content($proof['id'])['base64']);
        Db::name('employee_permission')->where('tenant_id', self::TENANT_ID)->where('employee_id', $employee['id'])->where('permission_key', 'finance.salary.view')->delete();
        foreach (['revoked', 'anonymous', 'foreign'] as $case) {
            if ($case === 'anonymous') { request()->userId = 0; }
            if ($case === 'foreign') { $this->prepareCustomerReportRequestContext(self::OTHER_TENANT_ID); }
            try { \app\api\jxc\logic\FinanceEvidence::content($proof['id']); self::fail('无权用户不能读取私密图片: ' . $case); }
            catch (\DomainException $error) { self::assertNotEmpty($error->getMessage()); }
        }
    }

    public function test_source_picker_pages_only_current_open_balances_with_stable_identity(): void
    {
        $this->activate(); $ledger = new FinanceLedger(self::TENANT_ID);
        for ($i = 0; $i < 25; $i++) { $ledger->createSource(1000, 'receivable', $this->customerId, '10', date('Y-m-d'), null, []); }
        $first = FinanceBusinessLogic::options(['type' => 'receipt', 'subject_id' => $this->customerId]);
        self::assertNotFalse($first, FinanceBusinessLogic::getError()); self::assertCount(20, $first['sources']); self::assertTrue($first['has_more']);
        $second = FinanceBusinessLogic::options(['type' => 'receipt', 'subject_id' => $this->customerId, 'page' => 2]);
        self::assertNotFalse($second); self::assertCount(6, $second['sources']); self::assertFalse($second['has_more']);
        self::assertCount(26, array_unique(array_merge(array_column($first['sources'], 'reference'), array_column($second['sources'], 'reference'))));
        $ledger->add(1002, 'balance', $this->customerId, '-1000', date('Y-m-d'), date('Y-m'), 'allocation', $this->receivable);
        $last = FinanceBusinessLogic::options(['type' => 'receipt', 'subject_id' => $this->customerId, 'page' => 2]); self::assertCount(5, $last['sources']);
    }

    public function test_confirmed_sale_creates_receivable_and_revenue_once_and_receipt_uses_that_source(): void
    {
        $this->activate(); $fixture = $this->deliveredSale();
        $command = ['order_id' => $fixture['order_id'], 'expected_version' => 0, 'idempotency_key' => 'finance-sales-first-confirm',
            'due_date' => date('Y-m-d'), 'lines' => [['order_goods_id' => $fixture['line_id'], 'customer_settlement_weight' => '2', 'pricing_unit_id' => $fixture['unit_id'], 'price' => '10']]];
        $sale = SalesSettlementLogic::submit($command);
        self::assertNotFalse($sale, SalesSettlementLogic::getError());
        self::assertSame($sale, SalesSettlementLogic::submit($command));
        $ledger = new FinanceLedger(self::TENANT_ID);
        $sources = array_values(array_filter($ledger->sources('receivable', $this->customerId), static fn(array $row): bool => str_starts_with($row['reference'], 'n:')));
        self::assertCount(1, $sources); self::assertSame('20.00', $sources[0]['balance']); self::assertSame(date('Y-m-d'), $sources[0]['due_date']);
        self::assertSame('1020.00', $sale['debt_after_order']);
        self::assertEquals(20, Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('metric', 'revenue')->sum('amount'));
        $payload = $this->receipt('20', '20'); $payload['allocations'][0]['source'] = $sources[0]['reference'];
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $payload]));
        self::assertSame('0.00', $ledger->source($sources[0]['reference'])['balance']);
        self::assertSame('5020.00', $ledger->account($this->accountId)['balance']);
        self::assertEquals(20, Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('metric', 'revenue')->sum('amount'));
        self::assertSame(0, Db::name('stock_flow')->where('tenant_id', self::TENANT_ID)->count(), '金额确认不再次出库');
    }

    public function test_active_finance_blocks_legacy_manual_receipt_without_changing_balance(): void
    {
        $this->activate();
        Db::name('customer')->where('tenant_id', self::TENANT_ID)->where('id', $this->customerId)->update(['order_receivable' => '1000']);
        self::assertFalse(\app\api\jxc\logic\CustomerLogic::paymoney(['customer_id' => $this->customerId, 'money' => '100']));
        self::assertStringContainsString('财务收付款', \app\api\jxc\logic\CustomerLogic::getError());
        self::assertSame('1000.00', (new FinanceLedger(self::TENANT_ID))->source($this->receivable)['balance']);
        self::assertSame('1000.00', Db::name('customer')->where('id', $this->customerId)->value('order_receivable'));
    }

    public function test_sales_reduction_after_partial_collection_keeps_credit_refund_and_original_history(): void
    {
        $this->activate(); $fixture = $this->deliveredSale();
        $command = ['due_date_reviewed' => 1, 'due_override_reason' => '经核实本笔未约定付款日', 'order_id' => $fixture['order_id'], 'expected_version' => 0, 'idempotency_key' => 'finance-sales-credit-first',
            'lines' => [['order_goods_id' => $fixture['line_id'], 'customer_settlement_weight' => '2', 'pricing_unit_id' => $fixture['unit_id'], 'price' => '10']]];
        $first = SalesSettlementLogic::submit($command); self::assertNotFalse($first, SalesSettlementLogic::getError());
        $source = $first['finance']['source_ref'];
        $payload = $this->receipt('12', '12'); $payload['allocations'][0]['source'] = $source;
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $payload]));
        $command['expected_version'] = 1; $command['idempotency_key'] = 'finance-sales-credit-second';
        $command['lines'][0]['price'] = '5'; $command['edit_reason'] = '按真实成交价格更正，贷项先抵本单剩余';
        $command['credit_reviewed'] = 1; $command['credit_allocations'] = [['source' => $source, 'amount' => '8']];
        $second = SalesSettlementLogic::submit($command); self::assertNotFalse($second, SalesSettlementLogic::getError());
        $ledger = new FinanceLedger(self::TENANT_ID);
        self::assertSame('0.00', $ledger->source($source)['balance']);
        self::assertSame('2.00', $ledger->source($second['finance']['customer_refund_ref'])['balance']);
        self::assertSame('5012.00', $ledger->account($this->accountId)['balance']);
        self::assertEquals(10, Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('metric', 'revenue')->sum('amount'));
        self::assertSame('20.00', Db::name('sales_order_version')->where('tenant_id', self::TENANT_ID)->where('order_id', $fixture['order_id'])->where('version', 1)->value('order_money'));
        self::assertSame($second, SalesSettlementLogic::submit($command));
    }

    public function test_overdue_acknowledgement_is_required_and_partial_receipt_keeps_original_due_date(): void
    {
        $this->activate(); $ledger = new FinanceLedger(self::TENANT_ID);
        $due = date('Y-m-d', strtotime('-1 day'));
        $overdueSource = $ledger->createSource(900, 'receivable', $this->customerId, '100', date('Y-m-01'), $due, []);
        $fixture = $this->deliveredSale();
        $command = ['due_date_reviewed' => 1, 'due_override_reason' => '经核实本笔未约定付款日', 'order_id' => $fixture['order_id'], 'expected_version' => 0, 'idempotency_key' => 'finance-overdue-new-sale',
            'lines' => [['order_goods_id' => $fixture['line_id'], 'customer_settlement_weight' => '2', 'pricing_unit_id' => $fixture['unit_id'], 'price' => '10']]];
        self::assertFalse(SalesSettlementLogic::submit($command));
        self::assertStringContainsString('逾期', SalesSettlementLogic::getError());
        self::assertSame(0, Db::name('finance_sales_version')->where('tenant_id', self::TENANT_ID)->count());
        $detail = SalesSettlementLogic::detail(['id' => $fixture['order_id']]);
        self::assertSame('100.00', $detail['finance']['overdue']['amount']);
        self::assertSame(1, $detail['finance']['overdue']['days']);
        $command['overdue_acknowledged'] = 1;
        self::assertNotFalse(SalesSettlementLogic::submit($command), SalesSettlementLogic::getError());
        $payload = $this->receipt('40', '40'); $payload['allocations'][0]['source'] = $overdueSource;
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $payload]));
        self::assertSame('60.00', \app\api\jxc\logic\FinanceCustomers::overdue($this->customerId)['amount']);
        self::assertSame($due, $ledger->source($overdueSource)['due_date']);
    }

    public function test_historical_sale_adjustment_requires_explicit_opening_link_and_only_posts_delta(): void
    {
        $this->activate(); $fixture = $this->deliveredSale();
        Db::name('sales_order')->where('id', $fixture['order_id'])->update(['settlement_version' => 1, 'settlement_status' => 'formal',
            'order_money' => '20', 'goods_amount' => '20', 'datetimesingle' => strtotime(date('Y-m-01') . ' -1 day')]);
        $command = ['order_id' => $fixture['order_id'], 'expected_version' => 1, 'idempotency_key' => 'finance-historical-sale-link', 'edit_reason' => '原销售已包含期初汇总，核对成交价后调增',
            'lines' => [['order_goods_id' => $fixture['line_id'], 'customer_settlement_weight' => '2', 'pricing_unit_id' => $fixture['unit_id'], 'price' => '15']]];
        self::assertFalse(SalesSettlementLogic::submit($command));
        self::assertStringContainsString('期初', SalesSettlementLogic::getError());
        $command += ['opening_link_reviewed' => 1, 'opening_source' => $this->receivable];
        $result = SalesSettlementLogic::submit($command); self::assertNotFalse($result, SalesSettlementLogic::getError());
        self::assertSame('10.00', $result['finance']['amount_change']);
        self::assertSame('1010.00', (new FinanceLedger(self::TENANT_ID))->source($this->receivable)['balance']);
        self::assertSame(0, Db::name('finance_source')->where('tenant_id', self::TENANT_ID)->where('category', 'receivable')->count());
        self::assertEquals(10, Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('metric', 'revenue')->sum('amount'));
        self::assertSame($result, SalesSettlementLogic::submit($command));
    }

    public function test_sales_credit_over_allocation_rolls_back_order_version_sources_and_entries(): void
    {
        $this->activate(); $fixture = $this->deliveredSale();
        $command = ['due_date_reviewed' => 1, 'due_override_reason' => '经核实本笔未约定付款日', 'order_id' => $fixture['order_id'], 'expected_version' => 0, 'idempotency_key' => 'finance-credit-over-first',
            'lines' => [['order_goods_id' => $fixture['line_id'], 'customer_settlement_weight' => '2', 'pricing_unit_id' => $fixture['unit_id'], 'price' => '10']]];
        $first = SalesSettlementLogic::submit($command); self::assertNotFalse($first, SalesSettlementLogic::getError());
        $command['expected_version'] = 1; $command['idempotency_key'] = 'finance-credit-over-second'; $command['edit_reason'] = '核实成交价更正';
        $command['lines'][0]['price'] = '5'; $command['credit_reviewed'] = 1;
        $command['credit_allocations'] = [['source' => $first['finance']['source_ref'], 'amount' => '11']];
        self::assertFalse(SalesSettlementLogic::submit($command));
        self::assertStringContainsString('超过', SalesSettlementLogic::getError());
        self::assertSame('20.00', (new FinanceLedger(self::TENANT_ID))->source($first['finance']['source_ref'])['balance']);
        self::assertSame(0, Db::name('finance_source')->where('tenant_id', self::TENANT_ID)->where('category', 'customer_refund')->count());
        self::assertSame(1, (int)Db::name('sales_order')->where('id', $fixture['order_id'])->value('settlement_version'));
    }

    public function test_customer_due_rules_are_versioned_and_single_sale_override_keeps_default_and_reason(): void
    {
        $this->activate(); $fixture = $this->deliveredSale();
        $command = $this->command(0) + ['customer_id' => $this->customerId, 'mode' => 'days_after', 'days' => '7', 'reason' => '客户约定交付后一周付款'];
        $rule = \app\api\jxc\logic\FinanceSalesRules::save($command);
        request()->adminInfo = array_merge((array)request()->adminInfo, ['name' => '更改后的显示名称']);
        self::assertSame($rule, \app\api\jxc\logic\FinanceSalesRules::save($command));
        $saleCommand = ['order_id' => $fixture['order_id'], 'expected_version' => 0, 'idempotency_key' => 'finance-rule-default-sale',
            'lines' => [['order_goods_id' => $fixture['line_id'], 'customer_settlement_weight' => '2', 'pricing_unit_id' => $fixture['unit_id'], 'price' => '10']]];
        $sale = SalesSettlementLogic::submit($saleCommand); self::assertNotFalse($sale, SalesSettlementLogic::getError());
        $ledger = new FinanceLedger(self::TENANT_ID); $source = $ledger->source($sale['finance']['source_ref']);
        self::assertSame(date('Y-m-d', strtotime('+7 days')), $source['due_date']);
        \app\api\jxc\logic\FinanceSalesRules::save($this->command(1) + ['customer_id' => $this->customerId, 'mode' => 'delivery', 'reason' => '以后现结']);
        self::assertSame($source['due_date'], $ledger->source($source['reference'])['due_date']);
        self::assertSame(2, Db::name('finance_customer_terms')->where('tenant_id', self::TENANT_ID)->count());
        $fixture2 = $this->deliveredSale(); $saleCommand['order_id'] = $fixture2['order_id']; $saleCommand['lines'][0]['order_goods_id'] = $fixture2['line_id'];
        $saleCommand['lines'][0]['pricing_unit_id'] = $fixture2['unit_id']; $saleCommand['idempotency_key'] = 'finance-rule-override-sale';
        $saleCommand['due_date_reviewed'] = 1; $saleCommand['due_date'] = null;
        self::assertFalse(SalesSettlementLogic::submit($saleCommand), '覆盖为未约定仍需依据');
        $saleCommand['due_override_reason'] = '本次待客户内部核对后再约定';
        $sale = SalesSettlementLogic::submit($saleCommand); self::assertNotFalse($sale, SalesSettlementLogic::getError());
        $source = $ledger->source($sale['finance']['source_ref']);
        self::assertNull($source['due_date']); self::assertSame(date('Y-m-d'), $source['snapshot']['due_terms']['default_due_date']);
        self::assertSame($saleCommand['due_override_reason'], $source['snapshot']['due_override_reason']);
    }

    public function test_weight_approval_requires_its_own_current_overdue_acknowledgement(): void
    {
        $this->activate(); $fixture = $this->deliveredSale();
        $command = ['order_id' => $fixture['order_id'], 'expected_version' => 0, 'idempotency_key' => 'finance-weight-pending',
            'lines' => [['order_goods_id' => $fixture['line_id'], 'customer_settlement_weight' => '1.5', 'pricing_unit_id' => $fixture['unit_id'], 'price' => '10']]];
        $pending = SalesSettlementLogic::submit($command); self::assertNotFalse($pending, SalesSettlementLogic::getError());
        self::assertSame(0, Db::name('finance_sales_version')->where('tenant_id', self::TENANT_ID)->count());
        (new FinanceLedger(self::TENANT_ID))->createSource(900, 'receivable', $this->customerId, '100', date('Y-m-01'), date('Y-m-d', strtotime('-1 day')), []);
        $todo = Db::name('sales_weight_difference_todo')->where('tenant_id', self::TENANT_ID)->where('order_id', $fixture['order_id'])->value('id');
        $approve = ['id' => $todo, 'decision' => 'approve', 'reason' => '与客户核对实际按1.5斤计价', 'idempotency_key' => 'finance-weight-approve'];
        self::assertFalse(SalesSettlementLogic::resolveWeightDifference($approve)); self::assertStringContainsString('逾期', SalesSettlementLogic::getError());
        $approve['overdue_acknowledged'] = 1;
        $result = SalesSettlementLogic::resolveWeightDifference($approve); self::assertNotFalse($result, SalesSettlementLogic::getError());
        self::assertSame($result, SalesSettlementLogic::resolveWeightDifference($approve));
        $snapshot = json_decode(Db::name('sales_settlement_action')->where('tenant_id', self::TENANT_ID)->where('idempotency_key', $approve['idempotency_key'])->value('snapshot_json'), true);
        self::assertTrue($snapshot['overdue_acknowledged']);
    }

    public function test_historical_sales_credit_posts_both_sides_in_confirmation_month_even_when_activation_month_open(): void
    {
        $this->activate(); $fixture = $this->deliveredSale();
        $activation = date('Y-m-01', strtotime('first day of previous month'));
        Db::name('finance_preparation')->where('tenant_id', self::TENANT_ID)->update(['activation_date' => $activation]);
        Db::name('sales_order')->where('id', $fixture['order_id'])->update(['settlement_version' => 1, 'settlement_status' => 'formal',
            'order_money' => '20', 'goods_amount' => '20', 'datetimesingle' => strtotime($activation . ' -1 day')]);
        $command = ['order_id' => $fixture['order_id'], 'expected_version' => 1, 'idempotency_key' => 'finance-historical-credit-month', 'edit_reason' => '按凭据调减已承接旧销售',
            'opening_link_reviewed' => 1, 'opening_source' => $this->receivable, 'credit_reviewed' => 1, 'credit_allocations' => [['source' => $this->receivable, 'amount' => '10']],
            'lines' => [['order_goods_id' => $fixture['line_id'], 'customer_settlement_weight' => '2', 'pricing_unit_id' => $fixture['unit_id'], 'price' => '5']]];
        $result = SalesSettlementLogic::submit($command); self::assertNotFalse($result, SalesSettlementLogic::getError());
        $months = Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('document_id', $result['finance']['document_id'])->column('posting_month');
        self::assertCount(3, $months); self::assertSame([date('Y-m')], array_values(array_unique($months)));
    }

    public function test_sales_billing_permission_does_not_grant_due_date_override_or_customer_rule_changes(): void
    {
        $this->activate(); $fixture = $this->deliveredSale();
        $employee = WorkforceLogic::saveEmployee(['name' => '仅销售结算', 'mobile' => '13800009929', 'bind_user_id' => 996929,
            'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => ['settlement.view', 'settlement.bill']]);
        self::assertNotFalse($employee, WorkforceLogic::getError());
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID]; request()->jxcFromUserToken = true; request()->userId = 996929; request()->adminId = 0;
        $command = ['order_id' => $fixture['order_id'], 'expected_version' => 0, 'idempotency_key' => 'finance-employee-due-override',
            'due_date' => date('Y-m-d', strtotime('+1 day')), 'due_override_reason' => '希望延后一天',
            'lines' => [['order_goods_id' => $fixture['line_id'], 'customer_settlement_weight' => '2', 'pricing_unit_id' => $fixture['unit_id'], 'price' => '10']]];
        self::assertFalse(SalesSettlementLogic::submit($command)); self::assertStringContainsString('权限', SalesSettlementLogic::getError());
        unset($command['due_date'], $command['due_override_reason']);
        self::assertNotFalse(SalesSettlementLogic::submit($command), SalesSettlementLogic::getError());
        $this->expectException(\DomainException::class); $this->expectExceptionMessage('最高权限');
        \app\api\jxc\logic\FinanceSalesRules::save($this->command(0) + ['customer_id' => $this->customerId, 'mode' => 'unagreed', 'reason' => '试图修改规则']);
    }

    public function test_bad_debt_moves_only_verified_receivable_to_recovery_then_termination_does_not_repeat_loss(): void
    {
        $this->activate();
        $payload = ['subject_id' => $this->customerId, 'actual_date' => date('Y-m-d'), 'amount' => '300',
            'allocations' => [['source' => $this->receivable, 'amount' => '300']], 'debt_verified' => 1, 'undisputed' => 1,
            'reason' => '核实债务真实无争议，债务人已无清偿能力', 'basis' => '已核实原始债权和清偿情况'];
        $badDebt = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'bad_debt', 'payload' => $payload]);
        self::assertNotFalse($badDebt, FinanceBusinessLogic::getError());
        $ledger = new FinanceLedger(self::TENANT_ID);
        self::assertSame('700.00', $ledger->source($this->receivable)['balance']);
        $recovery = $badDebt['confirmed_result']['created_sources'][0]; self::assertSame('300.00', $ledger->source($recovery)['balance']);
        self::assertEquals(300, Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('metric', 'loss')->sum('amount'));
        $payload = ['subject_id' => $this->customerId, 'actual_date' => date('Y-m-d'), 'amount' => '100',
            'allocations' => [['source' => $recovery, 'amount' => '100']], 'reason' => '有明确依据终止部分追偿', 'basis' => '终止追偿依据已复核'];
        $termination = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'recovery_termination', 'payload' => $payload]);
        self::assertNotFalse($termination, FinanceBusinessLogic::getError());
        self::assertSame('200.00', $ledger->source($recovery)['balance']);
        $reverseBad = $this->command($badDebt['version']) + ['id' => $badDebt['id'], 'correction_reason' => '复核原坏账事实不成立'];
        self::assertFalse(FinanceBusinessLogic::action('reverse', $reverseBad));
        self::assertStringContainsString('后续处理', FinanceBusinessLogic::getError());
        $reverseTermination = $this->command($termination['version']) + ['id' => $termination['id'], 'correction_reason' => '复核仍应继续追偿'];
        $reversed = FinanceBusinessLogic::action('reverse', $reverseTermination);
        self::assertNotFalse($reversed, FinanceBusinessLogic::getError());
        self::assertSame($reversed, FinanceBusinessLogic::action('reverse', $reverseTermination));
        self::assertSame('300.00', $ledger->source($recovery)['balance']);
        self::assertFalse(FinanceBusinessLogic::action('reverse', $this->command($reversed['version']) + ['id' => $reversed['id'], 'correction_reason' => '不得冲销反向凭据']));
        self::assertStringContainsString('反向凭据', FinanceBusinessLogic::getError());
        self::assertNotFalse(FinanceBusinessLogic::action('reverse', $reverseBad), FinanceBusinessLogic::getError());
        self::assertSame('1000.00', $ledger->source($this->receivable)['balance']);
        self::assertSame('0.00', $ledger->source($recovery)['balance']);
        self::assertEquals(0, Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('metric', 'loss')->sum('amount'));
        $receipt = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('100', '100')]);
        self::assertNotFalse($receipt, FinanceBusinessLogic::getError());
        self::assertFalse(FinanceBusinessLogic::action('reverse', $this->command($receipt['version']) + ['id' => $receipt['id'], 'correction_reason' => '不能把真实资金当无资金撤销']));
        self::assertStringContainsString('实际收付款不能', FinanceBusinessLogic::getError());
        self::assertSame('900.00', $ledger->source($this->receivable)['balance']);
        self::assertSame('5100.00', $ledger->account($this->accountId)['balance']);
        self::assertSame(1, Db::name('finance_money_transaction')->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_bad_debt_requires_verified_undisputed_debt_before_any_effect(): void
    {
        $this->activate();
        $payload = ['subject_id' => $this->customerId, 'actual_date' => date('Y-m-d'), 'amount' => '300', 'allocations' => [['source' => $this->receivable, 'amount' => '300']], 'reason' => '仅逾期尚未核实', 'basis' => '没有充分依据'];
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'bad_debt', 'payload' => $payload]));
        self::assertStringContainsString('无争议', FinanceBusinessLogic::getError());
        $ledger = new FinanceLedger(self::TENANT_ID);
        self::assertSame('1000.00', $ledger->source($this->receivable)['balance']);
        self::assertEquals(0, Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('metric', 'loss')->sum('amount'));
        self::assertSame('5000.00', $ledger->account($this->accountId)['balance']);
        self::assertSame(0, Db::name('finance_money_transaction')->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_impact_preview_rolls_back_documents_commands_sources_and_entries_even_for_reverse(): void
    {
        $this->activate();
        $payload = ['subject_id' => $this->customerId, 'actual_date' => date('Y-m-d'), 'amount' => '300', 'allocations' => [['source' => $this->receivable, 'amount' => '300']], 'debt_verified' => 1, 'undisputed' => 1, 'reason' => '债务真实无争议且无清偿能力', 'basis' => '核实债权及清偿事实'];
        $command = $this->command(0) + ['action' => 'record', 'type' => 'bad_debt', 'payload' => $payload];
        $preview = \app\api\jxc\logic\FinancePreview::calculate($command);
        self::assertSame(['700.00', '300.00'], array_column(array_reverse($preview['balances']), 'after'));
        self::assertSame('300.00', $preview['impacts'][0]['amount']);
        self::assertSame([date('Y-m')], $preview['posting_months']);
        foreach (['finance_document', 'finance_command', 'finance_source', 'finance_entry'] as $table) { self::assertSame(0, Db::name($table)->where('tenant_id', self::TENANT_ID)->count()); }
        $bad = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($bad, FinanceBusinessLogic::getError());
        $reverse = $this->command($bad['version']) + ['action' => 'reverse', 'id' => $bad['id'], 'correction_reason' => '原坏账事实复核不成立'];
        $preview = \app\api\jxc\logic\FinancePreview::calculate($reverse);
        self::assertSame('-300.00', $preview['impacts'][0]['amount']);
        self::assertSame(0, Db::name('finance_correction')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame('700.00', (new FinanceLedger(self::TENANT_ID))->source($this->receivable)['balance']);
        self::assertNotFalse(FinanceBusinessLogic::action('reverse', $reverse), FinanceBusinessLogic::getError());
        self::assertSame('1000.00', (new FinanceLedger(self::TENANT_ID))->source($this->receivable)['balance']);
    }

    public function test_recovery_preparer_can_read_customer_sources_but_cannot_confirm_and_revocation_blocks_reads(): void
    {
        $this->activate();
        $employee = WorkforceLogic::saveEmployee(['name' => '追偿经办', 'mobile' => '13800009928', 'bind_user_id' => 996928, 'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => ['finance.recovery.prepare']]);
        self::assertNotFalse($employee, WorkforceLogic::getError());
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID]; request()->jxcFromUserToken = true; request()->userId = 996928; request()->adminId = 0;
        self::assertCount(1, \app\api\jxc\logic\FinanceCustomerBalances::lists([])['lists']);
        $detail = \app\api\jxc\logic\FinanceCustomerBalances::detail(['customer_id' => $this->customerId, 'category' => 'recovery']);
        self::assertSame('0.00', $detail['balances']['recovery']);
        self::assertSame(['recovery_receipt', 'recovery_termination'], array_column($detail['actions'], 'type'));
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'recovery_termination', 'payload' => []]));
        self::assertStringContainsString('最高权限', FinanceBusinessLogic::getError());
        Db::name('employee_permission')->where('tenant_id', self::TENANT_ID)->where('employee_id', $employee['id'])->delete();
        $this->expectException(\DomainException::class); $this->expectExceptionMessage('权限');
        \app\api\jxc\logic\FinanceCustomerBalances::lists([]);
    }

    public function test_due_draft_cannot_read_salary_source_through_current_date_projection(): void
    {
        $this->activate();
        $source = (new FinanceLedger(self::TENANT_ID))->createSource(0, 'salary', 98765, '5678', date('Y-m-d'), null, ['subject_name' => '敏感工资对象', 'salary_month' => date('Y-m')]);
        $employee = WorkforceLogic::saveEmployee(['name' => '应收经办', 'mobile' => '13800009928', 'bind_user_id' => 996928, 'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => ['finance.receivable.prepare']]);
        self::assertNotFalse($employee, WorkforceLogic::getError());
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID]; request()->jxcFromUserToken = true; request()->userId = 996928; request()->adminId = 0;
        $draft = FinanceBusinessLogic::action('save', $this->command(0) + ['type' => 'receivable_due', 'payload' => ['subject_id' => 98765, 'source' => $source]]);
        self::assertNotFalse($draft, FinanceBusinessLogic::getError());
        self::assertFalse(FinanceBusinessLogic::detail(['id' => $draft['id']]));
        self::assertStringContainsString('来源业务类型或往来主体', FinanceBusinessLogic::getError());
    }

    public function test_partial_receipt_return_reopens_only_selected_debt_and_preserves_receipt_and_source_dates(): void
    {
        $this->activate();
        $receipt = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('1000', '1000')]);
        self::assertNotFalse($receipt, FinanceBusinessLogic::getError());
        $payload = ['subject_id' => $this->customerId, 'receipt_id' => $receipt['id'], 'account_id' => $this->accountId,
            'actual_date' => date('Y-m-d'), 'amount' => '200', 'allocations' => [['source' => $this->receivable, 'amount' => '200']], 'reason' => '银行已实际退回原到账的一部分'];
        $command = $this->command(0) + ['type' => 'receipt_return', 'payload' => $payload];
        $returned = FinanceBusinessLogic::action('record', $command);
        self::assertNotFalse($returned, FinanceBusinessLogic::getError());
        self::assertSame($returned, FinanceBusinessLogic::action('record', $command));
        self::assertCount(1, FinanceBusinessLogic::lists(['type' => 'receipt_return', 'subject_id' => $this->customerId])['lists']);
        self::assertSame([], FinanceBusinessLogic::lists(['type' => 'receipt_return', 'subject_id' => $this->customerId + 100000])['lists']);
        $ledger = new FinanceLedger(self::TENANT_ID); $source = $ledger->source($this->receivable);
        self::assertSame('200.00', $source['balance']); self::assertNull($source['business_date']); self::assertNull($source['due_date']);
        self::assertSame('5800.00', $ledger->account($this->accountId)['balance']);
        self::assertSame(2, Db::name('finance_money_transaction')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame(0, Db::name('finance_correction')->where('tenant_id', self::TENANT_ID)->count());
        self::assertEquals(0, Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('metric', 'revenue')->sum('amount'));
        $payload['amount'] = '801'; $payload['allocations'][0]['amount'] = '801';
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt_return', 'payload' => $payload]));
        self::assertStringContainsString('可退回', FinanceBusinessLogic::getError());
        self::assertSame('200.00', $ledger->source($this->receivable)['balance']);
        self::assertSame('5800.00', $ledger->account($this->accountId)['balance']);
    }

    public function test_receipt_return_and_its_correction_share_original_capacity_without_second_cash_transaction(): void
    {
        $this->activate();
        $receipt = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('1200', '1000', '200')]);
        self::assertNotFalse($receipt, FinanceBusinessLogic::getError()); $advance = $receipt['confirmed_result']['created_sources'][0];
        $payload = ['subject_id' => $this->customerId, 'receipt_id' => $receipt['id'], 'account_id' => $this->accountId, 'actual_date' => date('Y-m-d'),
            'amount' => '300', 'allocations' => [['source' => $this->receivable, 'amount' => '200'], ['source' => $advance, 'amount' => '100']], 'reason' => '原到账部分失效'];
        $returned = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt_return', 'payload' => $payload]); self::assertNotFalse($returned, FinanceBusinessLogic::getError());
        $ledger = new FinanceLedger(self::TENANT_ID);
        self::assertSame('100.00', $ledger->source($advance)['balance']); self::assertSame('200.00', $ledger->source($this->receivable)['balance']);
        $payload['amount'] = '350'; $payload['allocations'][0]['amount'] = '250';
        $corrected = FinanceBusinessLogic::action('correct', $this->command($returned['version']) + ['id' => $returned['id'], 'payload' => $payload, 'correction_reason' => '核实实际失效350，原登记少计50']);
        self::assertNotFalse($corrected, FinanceBusinessLogic::getError());
        self::assertSame('250.00', $ledger->source($this->receivable)['balance']); self::assertSame('100.00', $ledger->source($advance)['balance']);
        self::assertSame('5850.00', $ledger->account($this->accountId)['balance']);
        self::assertSame(2, Db::name('finance_money_transaction')->where('tenant_id', self::TENANT_ID)->count());
        $options = (new \app\api\jxc\logic\FinanceReceiptReturns(self::TENANT_ID, $ledger))->options($receipt['id']);
        self::assertSame(['750.00', '100.00'], array_column($options['sources'], 'returnable'));
        $invalid = $this->receipt('200', '200');
        self::assertFalse(FinanceBusinessLogic::action('correct', $this->command($receipt['version']) + ['id' => $receipt['id'], 'payload' => $invalid, 'correction_reason' => '不得缩减已真实退回的原核销']));
        self::assertStringContainsString('已使用或退回', FinanceBusinessLogic::getError());
    }

    public function test_receipt_correction_preserves_real_returns_and_options_follow_latest_receipt_version(): void
    {
        $this->activate();
        $receipt = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('1000', '1000')]); self::assertNotFalse($receipt, FinanceBusinessLogic::getError());
        $payload = ['subject_id' => $this->customerId, 'receipt_id' => $receipt['id'], 'account_id' => $this->accountId, 'actual_date' => date('Y-m-d'), 'amount' => '200', 'allocations' => [['source' => $this->receivable, 'amount' => '200']], 'reason' => '银行已退回200'];
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt_return', 'payload' => $payload]), FinanceBusinessLogic::getError());
        $corrected = FinanceBusinessLogic::action('correct', $this->command($receipt['version']) + ['id' => $receipt['id'], 'payload' => $this->receipt('900', '900'), 'correction_reason' => '原到账实际900，已核实退回200不变']);
        self::assertNotFalse($corrected, FinanceBusinessLogic::getError());
        $ledger = new FinanceLedger(self::TENANT_ID);
        self::assertSame('300.00', $ledger->source($this->receivable)['balance']); self::assertSame('5700.00', $ledger->account($this->accountId)['balance']);
        $options = FinanceBusinessLogic::options(['type' => 'receipt_return', 'subject_id' => $this->customerId, 'receipt_id' => $receipt['id']]); self::assertNotFalse($options, FinanceBusinessLogic::getError());
        self::assertSame($corrected['id'], $options['receipt']['receipt_id']); self::assertSame('700.00', $options['sources'][0]['returnable']);
        $choices = FinanceBusinessLogic::options(['type' => 'receipt_return', 'subject_id' => $this->customerId]);
        self::assertSame([$corrected['id']], array_column($choices['receipt_choices'], 'id'));
        self::assertFalse(FinanceBusinessLogic::options(['type' => 'receipt_return', 'subject_id' => $this->customerId + 1, 'receipt_id' => $receipt['id']]));
        self::assertStringContainsString('不属于', FinanceBusinessLogic::getError());
    }

    public function test_consumed_advance_keeps_its_reference_when_original_receipt_account_amount_or_date_is_corrected(): void
    {
        $this->activate(); $ledger = new FinanceLedger(self::TENANT_ID);
        $otherAccount = FinanceSetupLogic::saveAccount($this->command(0) + ['name' => '实际收款现金账户', 'account_type' => 'cash']); self::assertNotFalse($otherAccount);
        $payload = $this->receipt('100', '0', '100'); $payload['allocations'] = []; $payload['actual_date'] = date('Y-m-01');
        $receipt = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $payload]); self::assertNotFalse($receipt, FinanceBusinessLogic::getError());
        $advance = $receipt['confirmed_result']['created_sources'][0];
        $returnPayload = ['subject_id' => $this->customerId, 'receipt_id' => $receipt['id'], 'account_id' => $this->accountId, 'actual_date' => date('Y-m-d'), 'amount' => '20', 'allocations' => [['source' => $advance, 'amount' => '20']], 'reason' => '原到账确实退回20'];
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt_return', 'payload' => $returnPayload]), FinanceBusinessLogic::getError());
        $payload['account_id'] = $otherAccount['id']; $payload['amount'] = '120'; $payload['advance_amount'] = '120'; $payload['actual_date'] = date('Y-m-d');
        $corrected = FinanceBusinessLogic::action('correct', $this->command($receipt['version']) + ['id' => $receipt['id'], 'payload' => $payload, 'correction_reason' => '核实到账账户、日期及金额，真实退回20保留']);
        self::assertNotFalse($corrected, FinanceBusinessLogic::getError());
        self::assertSame([$advance], $corrected['confirmed_result']['created_sources']);
        self::assertSame('120.00', $ledger->source($advance)['confirmed_amount']); self::assertSame('100.00', $ledger->source($advance)['balance']);
        self::assertSame(date('Y-m-d'), $ledger->source($advance)['business_date']); self::assertSame(date('Y-m-01'), $ledger->source($advance)['original_business_date']);
        self::assertSame('4980.00', $ledger->account($this->accountId)['balance']); self::assertSame('120.00', $ledger->account((int)$otherAccount['id'])['balance']);
        $payload['amount'] = '110'; $payload['advance_amount'] = '110';
        $again = FinanceBusinessLogic::action('correct', $this->command($corrected['version']) + ['id' => $corrected['id'], 'payload' => $payload, 'correction_reason' => '再复核原金额110']); self::assertNotFalse($again, FinanceBusinessLogic::getError());
        self::assertSame('90.00', $ledger->source($advance)['balance']); self::assertSame('110.00', $ledger->source($advance)['confirmed_amount']);
        self::assertSame(2, Db::name('finance_money_transaction')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame('100.00', Db::name('finance_source')->where('id', substr($advance, 2))->value('amount'));
        self::assertSame('90.00', FinanceBusinessLogic::options(['type' => 'receipt_return', 'subject_id' => $this->customerId, 'receipt_id' => $receipt['id']])['sources'][0]['returnable']);
        $history = \app\api\jxc\logic\FinanceCustomerBalances::source(['source' => $advance]);
        self::assertSame(['110.00', '120.00'], array_column($history['advance_history'], 'new_amount'));
        self::assertSame('110.00', $ledger->sourcePage(['advance'], $this->customerId, 1)['sources'][0]['confirmed_amount']);
    }

    public function test_receipt_correction_cannot_move_actual_receipt_after_a_real_return(): void
    {
        $this->activate(); $payload = $this->receipt('1000', '1000'); $payload['actual_date'] = date('Y-m-01');
        $receipt = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $payload]); self::assertNotFalse($receipt, FinanceBusinessLogic::getError());
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt_return', 'payload' => ['subject_id' => $this->customerId, 'receipt_id' => $receipt['id'], 'account_id' => $this->accountId,
            'actual_date' => date('Y-m-01'), 'amount' => '200', 'allocations' => [['source' => $this->receivable, 'amount' => '200']], 'reason' => '到账当日部分被退回']]), FinanceBusinessLogic::getError());
        $payload['actual_date'] = date('Y-m-02');
        self::assertFalse(FinanceBusinessLogic::action('correct', $this->command($receipt['version']) + ['id' => $receipt['id'], 'payload' => $payload, 'correction_reason' => '不能破坏已有实际资金先后']));
        self::assertStringContainsString('不能晚于已存在的真实退回日期', FinanceBusinessLogic::getError());
        self::assertSame('5800.00', (new FinanceLedger(self::TENANT_ID))->account($this->accountId)['balance']);
    }

    public function test_due_adjustment_updates_current_overdue_without_rewriting_original_source_or_amount(): void
    {
        $this->activate(); $ledger = new FinanceLedger(self::TENANT_ID); $date = date('Y-m-01'); $oldDue = date('Y-m-d', strtotime('-1 day'));
        $source = $ledger->createSource(999, 'receivable', $this->customerId, '100', $date, $oldDue, []);
        self::assertSame('100.00', \app\api\jxc\logic\FinanceCustomers::overdue($this->customerId)['amount']);
        $command = $this->command(0) + ['type' => 'receivable_due', 'payload' => ['subject_id' => $this->customerId, 'source' => $source,
            'expected_due_revision' => 0, 'new_due_date' => date('Y-m-d', strtotime('+7 days')), 'reason' => '经双方约定延期一周']];
        $result = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($result, FinanceBusinessLogic::getError());
        self::assertSame($result, FinanceBusinessLogic::action('record', $command));
        $current = $ledger->source($source); self::assertSame($command['payload']['new_due_date'], $current['due_date']);
        self::assertSame($oldDue, $current['original_due_date']); self::assertSame($date, $current['business_date']); self::assertSame('100.00', $current['balance']);
        self::assertSame('0.00', \app\api\jxc\logic\FinanceCustomers::overdue($this->customerId)['amount']);
        $newCommand = $this->command(0) + ['type' => 'receivable_due', 'payload' => array_merge($command['payload'], ['new_due_date' => null, 'reason' => '改为未约定'])];
        self::assertFalse(FinanceBusinessLogic::action('record', $newCommand)); self::assertStringContainsString('新调整', FinanceBusinessLogic::getError());
        $newCommand['payload']['expected_due_revision'] = $current['due_revision'];
        $second = FinanceBusinessLogic::action('record', $newCommand); self::assertNotFalse($second, FinanceBusinessLogic::getError());
        self::assertNull($ledger->source($source)['due_date']);
        self::assertSame(2, Db::name('finance_due_adjustment')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame(0, Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->count());
        $page = $ledger->sourcePage(['receivable'], $this->customerId, 1);
        $row = array_values(array_filter($page['sources'], static fn(array $row): bool => $row['reference'] === $source))[0];
        self::assertNull($row['due_date']); self::assertSame($oldDue, $row['original_due_date']);
    }

    public function test_customer_balances_read_ledger_instead_of_legacy_shadow_and_preserve_unknown_opening_age(): void
    {
        $this->activate();
        Db::name('customer')->where('id', $this->customerId)->update(['order_receivable' => '999999']);
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('100', '100')]));
        $result = \app\api\jxc\logic\FinanceCustomerBalances::lists(['filter' => 'debt']);
        self::assertCount(1, $result['lists']); self::assertSame('900.00', $result['lists'][0]['receivable']);
        $detail = \app\api\jxc\logic\FinanceCustomerBalances::detail(['customer_id' => $this->customerId]);
        self::assertSame('900.00', $detail['balances']['receivable']); self::assertNull($detail['sources'][0]['age_days']);
        $source = \app\api\jxc\logic\FinanceCustomerBalances::source(['source' => $this->receivable]);
        self::assertCount(1, $source['entries']); self::assertSame('-100.00', $source['entries'][0]['amount']);
        self::assertSame([], \app\api\jxc\logic\FinanceCustomerBalances::lists(['filter' => 'overdue'])['lists']);
    }

    public function test_supplier_statement_freezes_formal_payables_refunds_and_actual_payments_separately(): void
    {
        $this->activate();
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '对账供应商']);
        $ledger = new FinanceLedger(self::TENANT_ID);
        $source = $ledger->createSource(0, 'payable', $vendor, '100.00', date('Y-m-d'), null, ['reason' => '已确认采购']);
        $ledger->createSource(0, 'supplier_refund', $vendor, '20.00', date('Y-m-d'), null, ['reason' => '已确认贷项']);
        $request = $this->command(0) + ['subject_kind' => 'vendor', 'vendor_id' => $vendor];
        $first = \app\api\jxc\logic\FinanceStatements::action('generate', $request);
        self::assertSame('vendor', $first['subject_kind']); self::assertSame($vendor, $first['vendor_id']);
        self::assertSame('unanswered', $first['state']);
        self::assertSame('100.00', $first['snapshot']['balances']['payable']['closing']);
        self::assertSame('20.00', $first['snapshot']['balances']['supplier_refund']['closing']);
        self::assertSame('80.00', $first['snapshot']['net_reference']);
        self::assertArrayNotHasKey('receivable', $first['snapshot']['balances']);
        self::assertSame($first, \app\api\jxc\logic\FinanceStatements::action('generate', $request));
        $payment = $this->receipt('30', '30'); $payment['subject_id'] = $vendor; $payment['allocations'][0]['source'] = $source;
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'supplier_payment', 'payload' => $payment]), FinanceBusinessLogic::getError());
        $old = \app\api\jxc\logic\FinanceStatements::detail(['id' => $first['id'], 'subject_kind' => 'vendor']);
        self::assertSame($first['snapshot'], $old['snapshot']);
        $second = \app\api\jxc\logic\FinanceStatements::action('generate', $this->command(0) + ['subject_kind' => 'vendor', 'vendor_id' => $vendor, 'previous_id' => $first['id']]);
        self::assertSame('70.00', $second['snapshot']['balances']['payable']['closing']);
        self::assertSame('50.00', $second['snapshot']['net_reference']);
        self::assertSame('supplier_payment', $second['snapshot']['actual_money'][0]['type']);
        self::assertSame('-30.00', $second['snapshot']['actual_money'][0]['amount']);
        self::assertSame($first['id'], $second['previous_id']);
        self::assertSame('unanswered', $second['state']);
    }

    public function test_supplier_dispute_keeps_ledger_intact_and_allows_only_undisputed_payment_until_resolved(): void
    {
        $this->activate(); $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '异议供应商']);
        $ledger = new FinanceLedger(self::TENANT_ID); $source = $ledger->createSource(0, 'payable', $vendor, '100.00', date('Y-m-d'), null, ['reason' => '已确认采购']);
        $statement = \app\api\jxc\logic\FinanceStatements::action('generate', $this->command(0) + ['subject_kind' => 'vendor', 'vendor_id' => $vendor]);
        $reply = \app\api\jxc\logic\FinanceStatements::action('reply', $this->command(0) + ['subject_kind' => 'vendor', 'id' => $statement['id'],
            'response' => 'disputed', 'respondent' => '供应商负责人', 'response_date' => date('Y-m-d'), 'evidence' => '单价尚待核对',
            'items' => [['source' => $source, 'amount' => '20.00', 'reason' => '价格异议', 'ledger_uncertain' => true]]]);
        self::assertSame('disputed', $reply['state']); self::assertSame('100.00', $ledger->source($source)['balance']);
        self::assertSame([], \app\api\jxc\logic\FinanceStatements::openDisputes([$source]));
        $payment = $this->receipt('81', '81'); $payment['subject_id'] = $vendor; $payment['allocations'][0]['source'] = $source;
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'supplier_payment', 'payload' => $payment]));
        self::assertStringContainsString('争议', FinanceBusinessLogic::getError()); self::assertSame('100.00', $ledger->source($source)['balance']);
        $payment['amount'] = '80'; $payment['allocations'][0]['amount'] = '80';
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'supplier_payment', 'payload' => $payment]), FinanceBusinessLogic::getError());
        self::assertSame('20.00', $ledger->source($source)['balance']);
        $resolved = \app\api\jxc\logic\FinanceStatements::action('resolve', $this->command(1) + ['subject_kind' => 'vendor', 'id' => $statement['id'],
            'dispute_id' => $reply['disputes'][0]['id'], 'resolution' => 'ledger_verified', 'reason' => '账内价格正确，外部异议保留']);
        self::assertFalse($resolved['disputes'][0]['ledger_uncertain']);
        $resolved = \app\api\jxc\logic\FinanceStatements::action('resolve', $this->command(2) + ['subject_kind' => 'vendor', 'id' => $statement['id'],
            'dispute_id' => $reply['disputes'][0]['id'], 'resolution' => 'resolved', 'reason' => '供方核对后确认原单正确']);
        self::assertSame('awaiting_reconfirmation', $resolved['state']);
        $payment['amount'] = '20'; $payment['allocations'][0]['amount'] = '20';
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'supplier_payment', 'payload' => $payment]), FinanceBusinessLogic::getError());
        self::assertSame('0.00', $ledger->source($source)['balance']);
    }

    public function test_supplier_balances_and_statement_keep_unsettled_arrivals_out_of_formal_payables(): void
    {
        $this->activate(); $warehouse = $this->createCustomerReportWarehouse('对账仓');
        $goods = $this->createCustomerReportGoods('对账商品', 'FIN-SUP-STMT'); $sku = $this->customerReportSkuId($goods);
        $vendorName = '待结算供应商 ' . bin2hex(random_bytes(4));
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => $vendorName]);
        $arrival = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_arrival', 'payload' => ['subject_id' => $vendor,
            'warehouse_id' => $warehouse, 'actual_date' => date('Y-m-d'), 'source_reference' => 'ARR-STMT', 'reason' => '实际到货',
            'lines' => [['sku_id' => $sku, 'actual_quantity' => '100', 'agreed_price' => '2.00']]]]);
        self::assertNotFalse($arrival, FinanceBusinessLogic::getError()); $line = $arrival['confirmed_result']['lines'][0]['arrival_line_id'];
        $first = \app\api\jxc\logic\FinanceStatements::action('generate', $this->command(0) + ['subject_kind' => 'vendor', 'vendor_id' => $vendor]);
        self::assertSame('0.00', $first['snapshot']['balances']['payable']['closing']);
        self::assertSame('100.0000', $first['snapshot']['pending_arrivals'][0]['pending_quantity']);
        $settled = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'purchase_settlement', 'payload' => ['subject_id' => $vendor,
            'reason' => '先确认四十斤', 'supplier_confirmation' => '双方确认', 'supplier_confirmed' => 1,
            'lines' => [['arrival_line_id' => $line, 'covered_quantity' => '40', 'settlement_quantity' => '40', 'price' => '2.00']]]]);
        self::assertNotFalse($settled, FinanceBusinessLogic::getError());
        $detail = \app\api\jxc\logic\FinanceSupplierBalances::detail(['vendor_id' => $vendor]);
        self::assertSame('80.00', $detail['balances']['payable']); self::assertSame('80.00', $detail['sources'][0]['available_payment']);
        self::assertSame('60.0000', $detail['pending_arrivals'][0]['pending_quantity']);
        self::assertArrayNotHasKey('overdue', $detail);
        $list = \app\api\jxc\logic\FinanceSupplierBalances::lists(['keyword' => $vendorName]);
        self::assertSame($vendor, $list['lists'][0]['id']); self::assertSame('80.00', $list['lists'][0]['payable']);
        $second = \app\api\jxc\logic\FinanceStatements::action('generate', $this->command(0) + ['subject_kind' => 'vendor', 'vendor_id' => $vendor]);
        self::assertSame('60.0000', $second['snapshot']['pending_arrivals'][0]['pending_quantity']);
        self::assertSame('100.0000', \app\api\jxc\logic\FinanceStatements::detail(['subject_kind' => 'vendor', 'id' => $first['id']])['snapshot']['pending_arrivals'][0]['pending_quantity']);
    }

    public function test_supplier_statement_permissions_subjects_and_versions_cannot_cross_customer_or_store_boundaries(): void
    {
        $this->activate(); $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '权限对账供应商']);
        $statement = \app\api\jxc\logic\FinanceStatements::action('generate', $this->command(0) + ['subject_kind' => 'vendor', 'vendor_id' => $vendor]);
        $employee = WorkforceLogic::saveEmployee(['name' => '客户对账经办', 'mobile' => '13800009937', 'bind_user_id' => 996937, 'is_enabled' => 1, 'process_ids' => [],
            'permission_keys' => ['finance.receivable.view', 'finance.receivable.prepare']]);
        self::assertNotFalse($employee);
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID]; request()->jxcFromUserToken = true; request()->userId = 996937; request()->adminId = 0;
        try { \app\api\jxc\logic\FinanceStatements::detail(['subject_kind' => 'vendor', 'id' => $statement['id']]); self::fail('客户权限不能读取供应商账'); }
        catch (\DomainException $error) { self::assertStringContainsString('权限', $error->getMessage()); }
        Db::name('employee_permission')->insert(['tenant_id' => self::TENANT_ID, 'employee_id' => $employee['id'], 'permission_key' => 'finance.payable.view', 'create_time' => time()]);
        self::assertSame($statement['snapshot'], \app\api\jxc\logic\FinanceStatements::detail(['subject_kind' => 'vendor', 'id' => $statement['id']])['snapshot']);
        try { \app\api\jxc\logic\FinanceStatements::action('generate', $this->command(0) + ['subject_kind' => 'vendor', 'vendor_id' => $vendor]); self::fail('只读不能生成'); }
        catch (\DomainException $error) { self::assertStringContainsString('权限', $error->getMessage()); }
        $this->prepareCustomerReportRequestContext();
        $wrong = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::OTHER_TENANT_ID, 'supplier_name' => '异店供应商']);
        try { \app\api\jxc\logic\FinanceStatements::action('generate', $this->command(0) + ['subject_kind' => 'vendor', 'vendor_id' => $wrong]); self::fail('不允许异店对象'); }
        catch (\DomainException $error) { self::assertStringContainsString('本门店', $error->getMessage()); }
        $reply = $this->command(0) + ['subject_kind' => 'vendor', 'vendor_id' => $vendor, 'id' => $statement['id'], 'response' => 'confirmed', 'respondent' => '供方', 'response_date' => date('Y-m-d'), 'evidence' => '供方明确全部确认'];
        $confirmed = \app\api\jxc\logic\FinanceStatements::action('reply', $reply); self::assertSame('confirmed', $confirmed['state']);
        self::assertSame($confirmed, \app\api\jxc\logic\FinanceStatements::action('reply', $reply));
        $stale = $reply; $stale['idempotency_key'] = $this->command(0)['idempotency_key'];
        try { \app\api\jxc\logic\FinanceStatements::action('reply', $stale); self::fail('过期版本不能覆盖回复'); }
        catch (\DomainException $error) { self::assertStringContainsString('已变化', $error->getMessage()); }
    }

    public function test_statement_is_immutable_and_regeneration_links_old_snapshot_without_confirming_customer(): void
    {
        $this->activate();
        $request = $this->command(0) + ['customer_id' => $this->customerId, 'date_from' => date('Y-m-01'), 'date_to' => date('Y-m-d')];
        $first = \app\api\jxc\logic\FinanceStatements::action('generate', $request);
        self::assertSame('unanswered', $first['state']);
        self::assertSame('1000.00', $first['snapshot']['balances']['receivable']['opening']);
        self::assertSame($first, \app\api\jxc\logic\FinanceStatements::action('generate', $request));
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('100', '100')]));
        $old = \app\api\jxc\logic\FinanceStatements::detail(['id' => $first['id']]);
        self::assertSame('1000.00', $old['snapshot']['balances']['receivable']['closing']);
        $second = \app\api\jxc\logic\FinanceStatements::action('generate', $this->command(0) + ['customer_id' => $this->customerId,
            'date_from' => date('Y-m-01'), 'date_to' => date('Y-m-d'), 'previous_id' => $first['id']]);
        self::assertSame($first['id'], $second['previous_id']);
        self::assertSame('900.00', $second['snapshot']['balances']['receivable']['closing']);
        self::assertSame('-100.00', $second['snapshot']['balances']['receivable']['change']);
        self::assertSame('100.00', $second['snapshot']['actual_money'][0]['amount']);
        self::assertSame('receipt', $second['snapshot']['actual_money'][0]['type']);
        self::assertSame('unanswered', $second['state']);
    }

    public function test_statement_dispute_does_not_reduce_debt_and_blocks_bad_debt_until_resolved(): void
    {
        $this->activate();
        $statement = \app\api\jxc\logic\FinanceStatements::action('generate', $this->command(0) + ['customer_id' => $this->customerId]);
        $reply = \app\api\jxc\logic\FinanceStatements::action('reply', $this->command(0) + ['id' => $statement['id'],
            'response' => 'disputed', 'respondent' => '客户负责人张先生', 'response_date' => date('Y-m-d'), 'evidence' => '当面对账，客户认为重量不符',
            'items' => [['source' => $this->receivable, 'amount' => '100', 'reason' => '重量需核实', 'ledger_uncertain' => true]]]);
        self::assertSame('disputed', $reply['state']);
        self::assertSame('1000.00', (new FinanceLedger(self::TENANT_ID))->source($this->receivable)['balance']);
        self::assertCount(1, \app\api\jxc\logic\FinanceStatements::openDisputes([$this->receivable]));
        $bad = $this->command(0) + ['type' => 'bad_debt', 'payload' => ['subject_id' => $this->customerId, 'actual_date' => date('Y-m-d'),
            'amount' => '100', 'allocations' => [['source' => $this->receivable, 'amount' => '100']], 'reason' => '确认无法收回', 'basis' => '有核实依据', 'debt_verified' => 1, 'undisputed' => 1]];
        self::assertFalse(FinanceBusinessLogic::action('record', $bad));
        self::assertStringContainsString('争议', FinanceBusinessLogic::getError());
        $dispute = \app\api\jxc\logic\FinanceStatements::openDisputes([$this->receivable])[0];
        \app\api\jxc\logic\FinanceStatements::action('resolve', $this->command(1) + ['id' => $statement['id'], 'dispute_id' => $dispute['id'], 'resolution' => 'ledger_verified', 'reason' => '逐笔复核交付记录，账内无误，客户异议保留']);
        self::assertFalse(\app\api\jxc\logic\FinanceStatements::openDisputes([$this->receivable])[0]['ledger_uncertain']);
        self::assertFalse(FinanceBusinessLogic::action('record', $bad), '账内核实无误不等于客户争议消失');
        \app\api\jxc\logic\FinanceStatements::action('resolve', $this->command(2) + ['id' => $statement['id'], 'dispute_id' => $dispute['id'], 'resolution' => 'resolved', 'reason' => '客户已复核并撤回异议，留存对账回复']);
        self::assertSame([], \app\api\jxc\logic\FinanceStatements::openDisputes([$this->receivable]));
        self::assertNotFalse(FinanceBusinessLogic::action('record', $bad), FinanceBusinessLogic::getError());
    }

    public function test_statement_appendix_uses_actual_weight_and_only_formally_confirmed_coverage(): void
    {
        $this->activate(); $sale = $this->deliveredSale();
        $first = \app\api\jxc\logic\FinanceStatements::action('generate', $this->command(0) + ['customer_id' => $this->customerId]);
        self::assertCount(1, $first['snapshot']['pending_deliveries']);
        self::assertSame('2.0000', $first['snapshot']['pending_deliveries'][0]['pending_weight']);
        self::assertSame('1000.00', $first['snapshot']['balances']['receivable']['closing']);
        $confirmed = SalesSettlementLogic::submit($this->command(0) + ['order_id' => $sale['order_id'],
            'lines' => [['order_goods_id' => $sale['line_id'], 'customer_settlement_weight' => '2', 'pricing_unit_id' => $sale['unit_id'], 'price' => '10']]]);
        self::assertNotFalse($confirmed, SalesSettlementLogic::getError());
        $second = \app\api\jxc\logic\FinanceStatements::action('generate', $this->command(0) + ['customer_id' => $this->customerId]);
        self::assertSame([], $second['snapshot']['pending_deliveries']);
        self::assertSame('1020.00', $second['snapshot']['balances']['receivable']['closing']);
        self::assertCount(1, \app\api\jxc\logic\FinanceStatements::detail(['id' => $first['id']])['snapshot']['pending_deliveries']);
    }

    public function test_statement_keeps_each_partial_delivery_on_its_actual_day(): void
    {
        $this->activate(); $sale = $this->deliveredSale();
        $firstDay = date('Y-m-01'); $secondDay = date('Y-m-02');
        Db::name('sales_order')->where('id', $sale['order_id'])->update(['datetimesingle' => strtotime($firstDay)]);
        Db::name('fulfillment_delivery_event')->where('id', $sale['event_id'])->update(['delivered_time' => strtotime($firstDay)]);
        $delivery = Db::name('fulfillment_delivery_item')->where('delivery_event_id', $sale['event_id'])->find();
        $event = Db::name('fulfillment_delivery_event')->where('id', $sale['event_id'])->find(); unset($event['id']);
        $event['idempotency_key'] = 'second-' . uniqid(); $event['delivered_time'] = strtotime($secondDay);
        $eventId = Db::name('fulfillment_delivery_event')->insertGetId($event);
        unset($delivery['id']); $delivery['delivery_event_id'] = $eventId; $delivery['actual_delivery_weight'] = '3.0000';
        Db::name('fulfillment_delivery_item')->insert($delivery); Db::name('order_goods')->where('id', $sale['line_id'])->update(['base_quantity' => '5']);
        $statement = \app\api\jxc\logic\FinanceStatements::action('generate', $this->command(0) + ['customer_id' => $this->customerId, 'date_from' => $secondDay, 'date_to' => $secondDay]);
        self::assertCount(1, $statement['snapshot']['pending_deliveries']);
        self::assertSame($secondDay, $statement['snapshot']['pending_deliveries'][0]['date']);
        self::assertSame('3.0000', $statement['snapshot']['pending_deliveries'][0]['actual_weight']);
    }

    public function test_statement_allows_dispute_on_already_paid_source_and_rejects_cross_actor_replay(): void
    {
        $this->activate(); self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('1000', '1000')]));
        $statement = \app\api\jxc\logic\FinanceStatements::action('generate', $this->command(0) + ['customer_id' => $this->customerId]);
        $command = $this->command(0) + ['id' => $statement['id'], 'response' => 'disputed', 'respondent' => '客户负责人', 'response_date' => date('Y-m-d'), 'evidence' => '客户复核已付款项目仍存在差异',
            'items' => [['source' => $this->receivable, 'amount' => '100', 'reason' => '已付款不等于认可重量', 'ledger_uncertain' => false]]];
        self::assertSame('disputed', \app\api\jxc\logic\FinanceStatements::action('reply', $command)['state']);
        request()->adminId = self::ADMIN_ID + 1;
        $this->expectException(\DomainException::class); $this->expectExceptionMessage('同一提交标识');
        \app\api\jxc\logic\FinanceStatements::action('reply', $command);
    }

    public function test_statement_regeneration_projects_corrected_advance_date_without_duplicating_revision_amount(): void
    {
        $this->activate(); $payload = $this->receipt('100', '0', '100'); $payload['allocations'] = []; $payload['actual_date'] = date('Y-m-02');
        $receipt = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $payload]); self::assertNotFalse($receipt, FinanceBusinessLogic::getError());
        $advance = $receipt['confirmed_result']['created_sources'][0];
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt_return', 'payload' => ['subject_id' => $this->customerId,
            'receipt_id' => $receipt['id'], 'account_id' => $this->accountId, 'actual_date' => date('Y-m-d'), 'amount' => '20', 'allocations' => [['source' => $advance, 'amount' => '20']], 'reason' => '实际退回20']]), FinanceBusinessLogic::getError());
        $before = \app\api\jxc\logic\FinanceStatements::action('generate', $this->command(0) + ['customer_id' => $this->customerId, 'date_from' => date('Y-m-01'), 'date_to' => date('Y-m-01')]);
        self::assertSame('0.00', $before['snapshot']['balances']['advance']['closing']);
        $payload['actual_date'] = date('Y-m-01'); $payload['amount'] = '120'; $payload['advance_amount'] = '120';
        self::assertNotFalse(FinanceBusinessLogic::action('correct', $this->command($receipt['version']) + ['id' => $receipt['id'], 'payload' => $payload, 'correction_reason' => '原款实际早一天到账且为120元']), FinanceBusinessLogic::getError());
        $after = \app\api\jxc\logic\FinanceStatements::action('generate', $this->command(0) + ['customer_id' => $this->customerId, 'date_from' => date('Y-m-01'), 'date_to' => date('Y-m-01'), 'previous_id' => $before['id']]);
        self::assertSame('120.00', $after['snapshot']['balances']['advance']['closing']);
        $current = \app\api\jxc\logic\FinanceStatements::action('generate', $this->command(0) + ['customer_id' => $this->customerId]);
        self::assertSame('100.00', $current['snapshot']['balances']['advance']['closing']);
        self::assertSame(['120.00', '-20.00'], array_column($current['snapshot']['actual_money'], 'amount'));
        self::assertSame('0.00', \app\api\jxc\logic\FinanceStatements::detail(['id' => $before['id']])['snapshot']['balances']['advance']['closing']);
    }

    public function test_overdue_todo_updates_partial_balance_and_closes_without_erasing_observed_history(): void
    {
        $this->activate();
        Db::name('finance_opening_source')->where('tenant_id', self::TENANT_ID)->where('category', 'receivable')->update(['source_snapshot' => json_encode(['subject_name' => '收款主客户', 'historical_date' => date('Y-m-01'), 'due_date' => date('Y-m-01')])]);
        $first = \app\api\jxc\logic\FinanceOverdue::lists(['customer_id' => $this->customerId]);
        self::assertCount(1, $first['lists']); self::assertSame('1000.00', $first['lists'][0]['balance']);
        $originalId = $first['lists'][0]['id'];
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('200', '200')]));
        $partial = \app\api\jxc\logic\FinanceOverdue::lists(['customer_id' => $this->customerId]);
        self::assertSame('800.00', $partial['lists'][0]['balance']);
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('800', '800')]));
        self::assertSame([], \app\api\jxc\logic\FinanceOverdue::lists(['customer_id' => $this->customerId])['lists']);
        $history = \app\api\jxc\logic\FinanceOverdue::history(['source' => $this->receivable]);
        self::assertCount(3, $history['lists']); self::assertSame('closed', $history['lists'][0]['state']);
        self::assertSame($originalId, $history['lists'][2]['id']); self::assertSame('1000.00', $history['lists'][2]['balance']);
        self::assertSame('receipt', $history['lists'][0]['document_type']);
    }

    public function test_overdue_todo_preserves_late_recording_explanation_and_due_change_closes_current_case(): void
    {
        $this->activate();
        Db::name('finance_opening_source')->where('tenant_id', self::TENANT_ID)->where('category', 'receivable')->update(['source_snapshot' => json_encode(['subject_name' => '收款主客户', 'historical_date' => date('Y-m-01'), 'due_date' => date('Y-m-01')])]);
        \app\api\jxc\logic\FinanceOverdue::lists(['customer_id' => $this->customerId]);
        $receipt = $this->receipt('200', '200'); $receipt['actual_date'] = date('Y-m-01');
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $receipt]));
        $history = \app\api\jxc\logic\FinanceOverdue::history(['source' => $this->receivable]);
        self::assertSame(date('Y-m-01'), $history['lists'][0]['timing'][0]['effective_date']);
        self::assertSame('200.00', $history['lists'][0]['timing'][0]['amount']);
        $due = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receivable_due', 'payload' => ['subject_id' => $this->customerId, 'source' => $this->receivable,
            'new_due_date' => null, 'expected_due_revision' => 0, 'reason' => '双方重新商定，付款日暂未约定']]);
        self::assertNotFalse($due, FinanceBusinessLogic::getError());
        self::assertSame([], \app\api\jxc\logic\FinanceOverdue::lists(['customer_id' => $this->customerId])['lists']);
        $closed = \app\api\jxc\logic\FinanceOverdue::history(['source' => $this->receivable])['lists'][0];
        self::assertSame('closed', $closed['state']); self::assertSame('800.00', $closed['balance']); self::assertNull($closed['due_date']);
        $count = Db::name('finance_overdue_event')->where('tenant_id', self::TENANT_ID)->count();
        \app\api\jxc\logic\FinanceOverdue::capture(self::TENANT_ID);
        self::assertSame($count, Db::name('finance_overdue_event')->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_overdue_preview_failure_and_permission_revocation_do_not_leave_or_expose_observations(): void
    {
        $this->activate();
        Db::name('finance_opening_source')->where('tenant_id', self::TENANT_ID)->where('category', 'receivable')->update(['source_snapshot' => json_encode(['subject_name' => '收款主客户', 'historical_date' => date('Y-m-01'), 'due_date' => date('Y-m-01')])]);
        $preview = \app\api\jxc\logic\FinancePreview::calculate($this->command(0) + ['action' => 'record', 'type' => 'receipt', 'payload' => $this->receipt('100', '100')]);
        self::assertNotEmpty($preview);
        self::assertSame(0, Db::name('finance_overdue_event')->where('tenant_id', self::TENANT_ID)->count());
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('1500', '1500')]));
        self::assertSame(0, Db::name('finance_overdue_event')->where('tenant_id', self::TENANT_ID)->count());
        \app\api\jxc\logic\FinanceOverdue::capture(self::TENANT_ID);
        self::assertSame(1, Db::name('finance_overdue_event')->where('tenant_id', self::TENANT_ID)->count());
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID];
        $this->expectException(\DomainException::class); $this->expectExceptionMessage('权限');
        \app\api\jxc\logic\FinanceOverdue::history(['source' => $this->receivable]);
    }

    public function test_overdue_schedule_migration_replays_without_overriding_stopped_task(): void
    {
        $install = file_get_contents(dirname(__DIR__, 2) . '/public/install/db/like.sql');
        preg_match('/CREATE TABLE `\{\{prefix\}\}dev_crontab`[\s\S]*?;/u', $install, $match);
        $this->runStatements($this->prepareMigration(str_replace('CREATE TABLE ', 'CREATE TABLE IF NOT EXISTS ', $match[0])));
        $sql = $this->prepareMigration(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260907_000010_finance_overdue_schedule.sql'));
        Db::name('dev_crontab')->where('command', 'finance:refresh-overdue')->delete();
        try {
            $this->runStatements($sql); Db::name('dev_crontab')->where('command', 'finance:refresh-overdue')->update(['status' => 2]); $this->runStatements($sql);
            self::assertSame(1, Db::name('dev_crontab')->where('command', 'finance:refresh-overdue')->count());
            self::assertSame(2, (int)Db::name('dev_crontab')->where('command', 'finance:refresh-overdue')->value('status'));
        } finally { Db::name('dev_crontab')->where('command', 'finance:refresh-overdue')->delete(); }
    }

    public function test_overdue_finance_write_paths_use_existing_transaction_primitive(): void
    {
        foreach (['FinanceBusinessLogic.php', 'FinanceSales.php'] as $file) {
            $source = file_get_contents(dirname(__DIR__, 2) . '/app/api/jxc/logic/' . $file);
            self::assertStringNotContainsString('FinanceOverdue::capture(', $source);
            self::assertStringContainsString('FinanceOverdue::captureWithinTransaction(', $source);
        }
        $method = new \ReflectionMethod(\app\api\jxc\logic\FinanceOverdue::class, 'captureWithinTransaction');
        $source = implode('', array_slice(file($method->getFileName()), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
        self::assertStringNotContainsString('Db::transaction(', $source);
    }

    public function test_overdue_scheduler_isolates_bad_tenant_and_keeps_retryable_failure_visible(): void
    {
        $this->activate();
        Db::name('finance_opening_source')->where('tenant_id', self::TENANT_ID)->where('category', 'receivable')->update(['source_snapshot' => json_encode(['subject_name' => '收款主客户', 'historical_date' => date('Y-m-01'), 'due_date' => date('Y-m-01')])]);
        $badBook = Db::name('finance_opening_book')->where('tenant_id', self::TENANT_ID)->find(); $badBook['tenant_id'] = self::OTHER_TENANT_ID; $badBook['confirmed_snapshot'] = '{invalid';
        Db::name('finance_opening_book')->insert($badBook);
        $command = new \app\common\command\FinanceRefreshOverdue();
        $output = new \think\console\Output('buffer');
        $method = new \ReflectionMethod($command, 'execute');
        self::assertSame(1, $method->invoke($command, new \think\console\Input([]), $output));
        self::assertSame(1, Db::name('finance_overdue_event')->where('tenant_id', self::TENANT_ID)->count());
        Db::name('finance_opening_book')->where('tenant_id', self::OTHER_TENANT_ID)->delete();
        self::assertSame(0, $method->invoke($command, new \think\console\Input([]), $output));
        self::assertSame(1, Db::name('finance_overdue_event')->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_sales_precision_rounds_each_line_and_preserves_automatic_difference_separately_from_manual_rounding(): void
    {
        $this->activate(); $sale = $this->deliveredSale();
        Db::name('order_goods')->where('id', $sale['line_id'])->update(['base_quantity' => '2.0050']);
        Db::name('fulfillment_delivery_item')->where('delivery_event_id', $sale['event_id'])->update(['actual_delivery_weight' => '2.0050']);
        $confirmed = SalesSettlementLogic::submit($this->command(0) + ['order_id' => $sale['order_id'], 'lines' => [['order_goods_id' => $sale['line_id'],
            'customer_settlement_weight' => '2.0050', 'pricing_unit_id' => $sale['unit_id'], 'price' => '1.23']]]);
        self::assertNotFalse($confirmed, SalesSettlementLogic::getError());
        self::assertSame('2.47', $confirmed['order_money']);
        $snapshot = json_decode(Db::name('sales_order_version')->where('tenant_id', self::TENANT_ID)->where('order_id', $sale['order_id'])->value('snapshot_json'), true);
        self::assertSame('0.003850', $snapshot['automatic_rounding_difference']); self::assertSame('0.00', $snapshot['rounding_amount']);
        self::assertSame('cents', $snapshot['precision']['actual_mode']);
    }

    public function test_sales_precision_defaults_override_and_history_are_explicit_and_idempotent(): void
    {
        $this->activate();
        $saved = \app\api\jxc\logic\FinanceSalesPrecision::save($this->command(0) + ['customer_id' => 0, 'mode' => 'integer', 'reason' => '门店逐行四舍五入到整元']);
        self::assertSame('integer', $saved['rule']['default_mode']);
        $sale = $this->deliveredSale();
        $command = $this->command(0) + ['order_id' => $sale['order_id'], 'lines' => [['order_goods_id' => $sale['line_id'], 'customer_settlement_weight' => '2', 'pricing_unit_id' => $sale['unit_id'], 'price' => '1.26']]];
        $confirmed = SalesSettlementLogic::submit($command); self::assertNotFalse($confirmed, SalesSettlementLogic::getError()); self::assertSame('3.00', $confirmed['order_money']);
        self::assertSame($confirmed, SalesSettlementLogic::submit($command));
        $rule = \app\api\jxc\logic\FinanceSalesPrecision::save($this->command(0) + ['customer_id' => $this->customerId, 'mode' => 'cents', 'reason' => '该主客户采用两位小数']);
        self::assertSame('cents', $rule['rule']['default_mode']);
        $original = json_decode(Db::name('sales_order_version')->where('tenant_id', self::TENANT_ID)->where('order_id', $sale['order_id'])->value('snapshot_json'), true);
        self::assertSame('integer', $original['precision']['actual_mode']);
        $new = $this->deliveredSale();
        $request = $this->command(0) + ['order_id' => $new['order_id'], 'precision_mode' => 'integer', 'lines' => [['order_goods_id' => $new['line_id'], 'customer_settlement_weight' => '2', 'pricing_unit_id' => $new['unit_id'], 'price' => '1.26']]];
        self::assertFalse(SalesSettlementLogic::submit($request), '单笔覆盖必须填写原因');
        $request['precision_override_reason'] = '客户本次要求按整元逐行核对';
        self::assertNotFalse(SalesSettlementLogic::submit($request), SalesSettlementLogic::getError());
    }

    public function test_sales_precision_and_manual_rounding_permissions_are_independent_and_revocation_blocks_replay(): void
    {
        $this->activate(); $sale = $this->deliveredSale();
        $employee = WorkforceLogic::saveEmployee(['name' => '精度经办', 'mobile' => '13800009929', 'bind_user_id' => 996929,
            'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => ['settlement.view', 'settlement.bill']]);
        self::assertNotFalse($employee, WorkforceLogic::getError());
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID]; request()->jxcFromUserToken = true; request()->userId = 996929; request()->adminId = 0;
        $request = $this->command(0) + ['order_id' => $sale['order_id'], 'rounding_amount' => '1.00',
            'lines' => [['order_goods_id' => $sale['line_id'], 'customer_settlement_weight' => '2', 'pricing_unit_id' => $sale['unit_id'], 'price' => '1.26']]];
        self::assertFalse(SalesSettlementLogic::submit($request)); self::assertStringContainsString('抹零权限', SalesSettlementLogic::getError());
        $request['rounding_amount'] = '0'; $request['precision_mode'] = 'integer'; $request['precision_override_reason'] = '本次客户按整元结算';
        self::assertFalse(SalesSettlementLogic::submit($request)); self::assertStringContainsString('权限', SalesSettlementLogic::getError());
        foreach (['finance.sales.precision_override', 'finance.sales.rounding'] as $permission) {
            Db::name('employee_permission')->insert(['tenant_id' => self::TENANT_ID, 'employee_id' => $employee['id'], 'permission_key' => $permission, 'create_time' => time()]);
        }
        $request['rounding_amount'] = '2.00';
        $confirmed = SalesSettlementLogic::submit($request); self::assertNotFalse($confirmed, SalesSettlementLogic::getError());
        self::assertSame('1.00', $confirmed['order_money'], '有独立权限即可抹零，不采用旧阈值或二次确认开关');
        Db::name('employee_permission')->where('tenant_id', self::TENANT_ID)->where('employee_id', $employee['id'])->where('permission_key', 'finance.sales.precision_override')->delete();
        self::assertFalse(SalesSettlementLogic::submit($request), '撤销精度覆盖权限后不得读取原覆盖请求缓存'); self::assertStringContainsString('权限', SalesSettlementLogic::getError());
        self::assertSame(1, Db::name('sales_order_version')->where('tenant_id', self::TENANT_ID)->where('order_id', $sale['order_id'])->count());
    }

    public function test_sales_precision_rejects_stale_rules_and_keeps_customer_inheritance_explicit(): void
    {
        $this->activate(); $sale = $this->deliveredSale();
        $request = $this->command(0) + ['order_id' => $sale['order_id'], 'precision_rules' => ['store_version' => 0, 'customer_version' => 0],
            'lines' => [['order_goods_id' => $sale['line_id'], 'customer_settlement_weight' => '2', 'pricing_unit_id' => $sale['unit_id'], 'price' => '1.26']]];
        $ruleCommand = $this->command(0) + ['customer_id' => 0, 'mode' => 'integer', 'reason' => '门店默认整元'];
        $saved = \app\api\jxc\logic\FinanceSalesPrecision::save($ruleCommand);
        self::assertSame($saved, \app\api\jxc\logic\FinanceSalesPrecision::save($ruleCommand));
        self::assertSame('inherit', $saved['rule']['customer_mode']);
        self::assertFalse(SalesSettlementLogic::submit($request)); self::assertStringContainsString('默认规则已变化', SalesSettlementLogic::getError());
        self::assertSame(0, Db::name('sales_order_version')->where('tenant_id', self::TENANT_ID)->where('order_id', $sale['order_id'])->count());
        $request['precision_rules']['store_version'] = 1;
        self::assertNotFalse(SalesSettlementLogic::submit($request), SalesSettlementLogic::getError());
    }

    public function test_sales_batch_partially_covers_multiple_deliveries_on_each_real_day_without_touching_stock(): void
    {
        $this->activate(); $first = $this->deliveredSale(); $second = $this->deliveredSale();
        $day = date('Y-m-01');
        Db::name('fulfillment_delivery_event')->where('id', $first['event_id'])->update(['delivered_time' => strtotime($day . ' 10:00:00')]);
        $firstItem = (int)Db::name('fulfillment_delivery_item')->where('delivery_event_id', $first['event_id'])->value('id');
        $secondItem = (int)Db::name('fulfillment_delivery_item')->where('delivery_event_id', $second['event_id'])->value('id');
        $payload = ['subject_id' => $this->customerId, 'reason' => '主客户两次交付合并部分确认', 'rounding_amount' => '0', 'precision_mode' => 'cents', 'lines' => [
            ['delivery_item_id' => $firstItem, 'covered_weight' => '1', 'settlement_weight' => '1', 'price' => '10'],
            ['delivery_item_id' => $secondItem, 'covered_weight' => '2', 'settlement_weight' => '2', 'price' => '20'],
        ]];
        $command = $this->command(0) + ['type' => 'sales_batch', 'payload' => $payload];
        $preview = \app\api\jxc\logic\FinancePreview::calculate($command + ['action' => 'record']);
        self::assertSame('50.00', $preview['impacts'][0]['amount']);
        $confirmed = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($confirmed, FinanceBusinessLogic::getError());
        self::assertSame($confirmed, FinanceBusinessLogic::action('record', $command));
        self::assertSame('50.00', $confirmed['confirmed_result']['amount']);
        $dates = Db::name('finance_entry')->where('tenant_id', self::TENANT_ID)->where('document_id', $confirmed['id'])->where('metric', 'revenue')->order('id')->column('business_date');
        self::assertSame([$day, date('Y-m-d')], $dates);
        self::assertSame('0.00', Db::name('sales_order')->where('id', $first['order_id'])->value('order_money'));
        $snapshot = \app\api\jxc\logic\FinanceStatementSnapshot::capture($this->customerId, $day, date('Y-m-d'));
        self::assertCount(1, $snapshot['pending_deliveries']); self::assertSame('1.0000', $snapshot['pending_deliveries'][0]['pending_weight']);
        $payload['lines'] = [$payload['lines'][0]]; $payload['overdue_acknowledged'] = 1;
        $secondCommand = $this->command(0) + ['type' => 'sales_batch', 'payload' => $payload];
        self::assertNotFalse(FinanceBusinessLogic::action('record', $secondCommand), FinanceBusinessLogic::getError());
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'sales_batch', 'payload' => $payload]));
        self::assertStringContainsString('覆盖量', FinanceBusinessLogic::getError());
        self::assertSame('1060.00', (new FinanceLedger(self::TENANT_ID))->categoryBalance('receivable', $this->customerId));
        self::assertSame(0, Db::name('finance_money_transaction')->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_sales_batch_correction_keeps_history_and_allocates_paid_reduction_as_credit_and_refund(): void
    {
        $this->activate(); $sale = $this->deliveredSale();
        $delivery = (int)Db::name('fulfillment_delivery_item')->where('delivery_event_id', $sale['event_id'])->value('id');
        $payload = ['subject_id' => $this->customerId, 'reason' => '核实客户结算', 'lines' => [
            ['delivery_item_id' => $delivery, 'covered_weight' => '2', 'settlement_weight' => '2', 'price' => '50']]];
        $original = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'sales_batch', 'payload' => $payload]);
        self::assertNotFalse($original, FinanceBusinessLogic::getError()); $source = $original['confirmed_result']['created_sources'][0];
        $receipt = $this->receipt('80', '80'); $receipt['allocations'][0]['source'] = $source;
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $receipt]));
        $payload['lines'][0]['price'] = '20';
        $correction = $this->command(1) + ['id' => $original['id'], 'payload' => $payload, 'correction_reason' => '原单价录入错误'];
        self::assertFalse(FinanceBusinessLogic::action('correct', $correction)); self::assertStringContainsString('调减须明确', FinanceBusinessLogic::getError());
        self::assertSame(0, Db::name('finance_correction')->where('tenant_id', self::TENANT_ID)->count());
        self::assertSame(1, Db::name('finance_sales_coverage')->where('tenant_id', self::TENANT_ID)->count());
        $tooMuch = $correction;
        $tooMuch['payload'] += ['credit_reviewed' => 1, 'credit_allocations' => [['source' => $source, 'amount' => '60']]];
        self::assertFalse(FinanceBusinessLogic::action('correct', $tooMuch)); self::assertStringContainsString('超过当前未结余额', FinanceBusinessLogic::getError());
        self::assertSame('20.00', (new FinanceLedger(self::TENANT_ID))->source($source)['balance']);
        $correction['payload'] += ['credit_reviewed' => 1, 'credit_allocations' => [['source' => $source, 'amount' => '20']]];
        $corrected = FinanceBusinessLogic::action('correct', $correction); self::assertNotFalse($corrected, FinanceBusinessLogic::getError());
        self::assertSame('40.00', $corrected['confirmed_result']['amount']);
        self::assertSame($corrected, FinanceBusinessLogic::action('correct', $correction));
        $ledger = new FinanceLedger(self::TENANT_ID); self::assertSame('0.00', $ledger->source($source)['balance']);
        self::assertSame('40.00', $ledger->categoryBalance('customer_refund', $this->customerId));
        self::assertSame('100.00', FinanceBusinessLogic::detail(['id' => $original['id']])['confirmed_result']['amount']);
        self::assertSame('2.0000', (string)Db::name('finance_sales_coverage')->where('tenant_id', self::TENANT_ID)->field('SUM(covered_delta) AS covered')->find()['covered']);
        self::assertSame(1, Db::name('finance_money_transaction')->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_sales_batch_pending_rechecks_coverage_and_cannot_overlap_legacy_full_order_confirmation(): void
    {
        $this->activate(); $sale = $this->deliveredSale();
        $delivery = (int)Db::name('fulfillment_delivery_item')->where('delivery_event_id', $sale['event_id'])->value('id');
        $payload = ['subject_id' => $this->customerId, 'reason' => '确认一半实际交付', 'lines' => [
            ['delivery_item_id' => $delivery, 'covered_weight' => '2', 'settlement_weight' => '2', 'price' => '10']]];
        $draft = FinanceBusinessLogic::action('prepare', $this->command(0) + ['type' => 'sales_batch', 'payload' => $payload]);
        self::assertNotFalse($draft); self::assertSame(0, Db::name('finance_sales_coverage')->where('tenant_id', self::TENANT_ID)->count());
        $payload['lines'][0]['covered_weight'] = '1'; $payload['lines'][0]['settlement_weight'] = '1';
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'sales_batch', 'payload' => $payload]));
        self::assertFalse(FinanceBusinessLogic::action('confirm', $this->command(1) + ['id' => $draft['id']]));
        self::assertStringContainsString('覆盖量', FinanceBusinessLogic::getError());
        self::assertSame('pending', FinanceBusinessLogic::detail(['id' => $draft['id']])['status']);
        $full = $this->command(0) + ['order_id' => $sale['order_id'], 'lines' => [['order_goods_id' => $sale['line_id'], 'customer_settlement_weight' => '2', 'pricing_unit_id' => $sale['unit_id'], 'price' => '10']]];
        self::assertFalse(SalesSettlementLogic::submit($full)); self::assertStringContainsString('分次结算', SalesSettlementLogic::getError());
        self::assertSame(0, Db::name('sales_order_version')->where('tenant_id', self::TENANT_ID)->where('order_id', $sale['order_id'])->count());
    }

    public function test_sales_batch_rounding_preserves_each_delivery_share_and_requires_independent_permissions(): void
    {
        $this->activate(); $a = $this->deliveredSale(); $b = $this->deliveredSale();
        $lines = [];
        foreach ([$a, $b] as $sale) { $lines[] = ['delivery_item_id' => (int)Db::name('fulfillment_delivery_item')->where('delivery_event_id', $sale['event_id'])->value('id'), 'covered_weight' => '2', 'settlement_weight' => '2', 'price' => '1.26']; }
        $employee = WorkforceLogic::saveEmployee(['name' => '销售经办', 'mobile' => '13800009929', 'bind_user_id' => 996929,
            'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => ['settlement.bill', 'settlement.view']]);
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID]; request()->jxcFromUserToken = true; request()->userId = 996929; request()->adminId = 0;
        $payload = ['subject_id' => $this->customerId, 'reason' => '逐行整元且单独抹零', 'lines' => $lines, 'precision_mode' => 'integer', 'precision_override_reason' => '与客户约定', 'rounding_amount' => '0.01'];
        $command = $this->command(0) + ['type' => 'sales_batch', 'payload' => $payload];
        self::assertFalse(FinanceBusinessLogic::action('record', $command));
        foreach (['finance.sales.precision_override', 'finance.sales.rounding'] as $permission) { Db::name('employee_permission')->insert(['tenant_id' => self::TENANT_ID, 'employee_id' => $employee['id'], 'permission_key' => $permission, 'create_time' => time()]); }
        $result = FinanceBusinessLogic::action('record', $command); self::assertNotFalse($result, FinanceBusinessLogic::getError());
        self::assertSame('5.99', $result['confirmed_result']['amount']); self::assertSame(['2.99', '3.00'], array_column($result['confirmed_result']['lines'], 'net_amount'));
        Db::name('employee_permission')->where('tenant_id', self::TENANT_ID)->where('employee_id', $employee['id'])->where('permission_key', 'finance.sales.rounding')->delete();
        self::assertFalse(FinanceBusinessLogic::action('record', $command)); self::assertStringContainsString('权限', FinanceBusinessLogic::getError());
    }

    public function test_sales_batch_positive_correction_inherits_current_adjusted_due_date_and_preserves_old_snapshot(): void
    {
        $this->activate(); $sale = $this->deliveredSale();
        $delivery = (int)Db::name('fulfillment_delivery_item')->where('delivery_event_id', $sale['event_id'])->value('id');
        $payload = ['subject_id' => $this->customerId, 'reason' => '按实际交付结算', 'lines' => [
            ['delivery_item_id' => $delivery, 'covered_weight' => '2', 'settlement_weight' => '2', 'price' => '50']]];
        $original = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'sales_batch', 'payload' => $payload]);
        self::assertNotFalse($original); $source = $original['confirmed_result']['created_sources'][0]; $due = date('Y-m-t');
        $changed = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receivable_due', 'payload' => ['subject_id' => $this->customerId, 'source' => $source, 'expected_due_revision' => 0, 'new_due_date' => $due, 'reason' => '客户重新约定月底付款']]);
        self::assertNotFalse($changed, FinanceBusinessLogic::getError());
        $payload['lines'][0]['price'] = '60'; $payload['lines'][0]['due_date'] = date('Y-m-d');
        $corrected = FinanceBusinessLogic::action('correct', $this->command(1) + ['id' => $original['id'], 'payload' => $payload, 'correction_reason' => '仅纠正单价']);
        self::assertNotFalse($corrected, FinanceBusinessLogic::getError());
        $newSource = (new FinanceLedger(self::TENANT_ID))->source($corrected['confirmed_result']['created_sources'][0]);
        self::assertSame('20.00', $newSource['balance']); self::assertSame($due, $newSource['due_date']);
        self::assertSame(date('Y-m-d'), FinanceBusinessLogic::detail(['id' => $original['id']])['confirmed_result']['lines'][0]['due_date']);
        $payload['lines'] = $corrected['confirmed_result']['lines']; $payload['lines'][0]['price'] = '65';
        $again = FinanceBusinessLogic::action('correct', $this->command(1) + ['id' => $corrected['id'], 'payload' => $payload, 'correction_reason' => '复核后再次纠正单价']);
        self::assertNotFalse($again, FinanceBusinessLogic::getError());
        self::assertSame($due, (new FinanceLedger(self::TENANT_ID))->source($again['confirmed_result']['created_sources'][0])['due_date']);
    }

    public function test_sales_batch_output_keeps_original_amount_debt_snapshot_and_server_print_numbers_across_corrections(): void
    {
        $this->activate(); $sale = $this->deliveredSale();
        $delivery = (int)Db::name('fulfillment_delivery_item')->where('delivery_event_id', $sale['event_id'])->value('id');
        $payload = ['subject_id' => $this->customerId, 'show_cumulative_debt' => 1, 'reason' => '输出确认凭证', 'lines' => [
            ['delivery_item_id' => $delivery, 'covered_weight' => '2', 'settlement_weight' => '2', 'price' => '50']]];
        $record = $this->command(0) + ['type' => 'sales_batch', 'payload' => $payload];
        $original = FinanceBusinessLogic::action('record', $record); self::assertNotFalse($original);
        $output = \app\api\jxc\logic\FinanceSalesOutput::document(['id' => $original['id']]);
        self::assertSame('100.00', $output['order_money']); self::assertSame('1100.00', $output['debt_after_order']); self::assertTrue($output['show_cumulative_debt']);
        self::assertTrue($original['output']['offline_generation_allowed']); self::assertArrayNotHasKey('offline_generation_allowed', $output);
        $print = $this->command(1) + ['id' => $original['id']];
        $prepared = \app\api\jxc\logic\FinanceSalesOutput::prepare($print);
        self::assertSame(1, $prepared['copy_no']); self::assertSame($prepared, \app\api\jxc\logic\FinanceSalesOutput::prepare($print));
        $receipt = ['expected_tenant_id' => self::TENANT_ID, 'id' => $original['id'], 'print_log_id' => $prepared['print_log_id'], 'success' => 1];
        self::assertSame(1, \app\api\jxc\logic\FinanceSalesOutput::receipt($receipt)['successful_print_count']);
        self::assertSame(1, \app\api\jxc\logic\FinanceSalesOutput::receipt($receipt)['successful_print_count']);
        $payload['lines'][0]['price'] = '60';
        $corrected = FinanceBusinessLogic::action('correct', $this->command(1) + ['id' => $original['id'], 'payload' => $payload, 'correction_reason' => '核实新单价']); self::assertNotFalse($corrected);
        $old = \app\api\jxc\logic\FinanceSalesOutput::document(['id' => $original['id']]); $new = \app\api\jxc\logic\FinanceSalesOutput::document(['id' => $corrected['id']]);
        self::assertSame('100.00', $old['order_money']); self::assertSame('1100.00', $old['debt_after_order']); self::assertStringContainsString('已被', $old['invalidation_notice']);
        self::assertSame($old['order_sn'], $new['order_sn']); self::assertSame(2, $new['version']); self::assertSame('120.00', $new['order_money']);
        $again = \app\api\jxc\logic\FinanceSalesOutput::prepare($this->command(1) + ['id' => $corrected['id']]); self::assertSame(2, $again['copy_no']);
        self::assertStringContainsString('已被', \app\api\jxc\logic\FinanceSalesOutput::prepare($print)['document']['invalidation_notice']);
        self::assertStringContainsString('已被', FinanceBusinessLogic::action('record', $record)['output']['invalidation_notice']);
        self::assertSame($again['print_log_id'], (int)\app\api\jxc\logic\FinanceSalesOutput::document(['id' => $original['id']])['pending_print']['id']);
        self::assertNotFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'receipt', 'payload' => $this->receipt('10', '10')]));
        self::assertSame('1100.00', \app\api\jxc\logic\FinanceSalesOutput::document(['id' => $original['id']])['debt_after_order']);
    }

    public function test_sales_batch_output_viewer_can_read_and_print_but_cannot_change_sales_or_other_actor_receipts(): void
    {
        $this->activate(); $sale = $this->deliveredSale();
        $delivery = (int)Db::name('fulfillment_delivery_item')->where('delivery_event_id', $sale['event_id'])->value('id');
        $payload = ['subject_id' => $this->customerId, 'reason' => '只读打印验收', 'lines' => [['delivery_item_id' => $delivery, 'covered_weight' => '2', 'settlement_weight' => '2', 'price' => '50']]];
        $draft = FinanceBusinessLogic::action('save', $this->command(0) + ['type' => 'sales_batch', 'payload' => $payload]);
        try { \app\api\jxc\logic\FinanceSalesOutput::document(['id' => $draft['id']]); self::fail('草稿不得输出'); } catch (\DomainException $error) { self::assertStringContainsString('已确认', $error->getMessage()); }
        $formal = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'sales_batch', 'payload' => $payload]); self::assertNotFalse($formal);
        $ownerPrint = $this->command(1) + ['id' => $formal['id']]; $prepared = \app\api\jxc\logic\FinanceSalesOutput::prepare($ownerPrint);
        $employee = WorkforceLogic::saveEmployee(['name' => '只读打印员', 'mobile' => '13800009939', 'bind_user_id' => 996939,
            'is_enabled' => 1, 'process_ids' => [], 'permission_keys' => ['settlement.view']]); self::assertNotFalse($employee);
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID]; request()->jxcFromUserToken = true; request()->userId = 996939; request()->adminId = 0;
        $detail = FinanceBusinessLogic::detail(['id' => $formal['id']]); self::assertNotFalse($detail);
        self::assertFalse($detail['output']['can_share']); self::assertFalse($detail['output']['pending_print']['can_resolve']);
        $options = FinanceBusinessLogic::options(['type' => 'sales_batch', 'subject_id' => $this->customerId]); self::assertNotFalse($options); self::assertFalse($options['can_confirm']); self::assertFalse($options['can_prepare']);
        self::assertFalse(FinanceBusinessLogic::action('save', $this->command(0) + ['type' => 'sales_batch', 'payload' => $payload]));
        self::assertFalse(FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'sales_batch', 'payload' => $payload]));
        try { \app\api\jxc\logic\FinanceSalesOutput::prepare($ownerPrint); self::fail('不可复用其他人员标识'); } catch (\DomainException $error) { self::assertStringContainsString('操作人', $error->getMessage()); }
        $receipt = ['expected_tenant_id' => self::TENANT_ID, 'id' => $formal['id'], 'print_log_id' => $prepared['print_log_id'], 'success' => 1];
        try { \app\api\jxc\logic\FinanceSalesOutput::receipt($receipt); self::fail('不可核实其他人员出纸'); } catch (\DomainException $error) { self::assertStringContainsString('原打印人员', $error->getMessage()); }
        $this->prepareCustomerReportRequestContext();
        \app\api\jxc\logic\FinanceSalesOutput::receipt($receipt);
        try { \app\api\jxc\logic\FinanceSalesOutput::receipt(array_replace($receipt, ['success' => 0])); self::fail('不可反向回执'); } catch (\DomainException $error) { self::assertStringContainsString('冲突', $error->getMessage()); }
        request()->adminInfo = ['root' => 0, 'tenant_id' => self::TENANT_ID]; request()->jxcFromUserToken = true; request()->userId = 996939; request()->adminId = 0;
        $own = \app\api\jxc\logic\FinanceSalesOutput::prepare($this->command(1) + ['id' => $formal['id']]); self::assertSame(2, $own['copy_no']);
        self::assertSame(1, \app\api\jxc\logic\FinanceSalesOutput::receipt(['expected_tenant_id' => self::TENANT_ID, 'id' => $formal['id'], 'print_log_id' => $own['print_log_id'], 'success' => 0])['successful_print_count']);
        $status = \app\api\jxc\logic\FinanceSalesOutput::status(['expected_tenant_id' => self::TENANT_ID, 'id' => $formal['id'], 'print_log_id' => $own['print_log_id']]); self::assertSame('failed', $status['status']);
        $next = \app\api\jxc\logic\FinanceSalesOutput::prepare($this->command(1) + ['id' => $formal['id']]); self::assertSame(3, $next['copy_no']); self::assertSame(1, $next['reprint_count'], '失败尝试占流水号，但不增加重打次数');
    }

    public function test_legacy_sale_uses_real_delivery_day_for_first_revenue_and_default_due_date(): void
    {
        $this->activate(); $sale = $this->deliveredSale(); $day = date('Y-m-01');
        Db::name('fulfillment_delivery_event')->where('id', $sale['event_id'])->update(['delivered_time' => strtotime($day)]);
        \app\api\jxc\logic\FinanceSalesRules::save($this->command(0) + ['customer_id' => $this->customerId, 'mode' => 'days_after', 'days' => 5, 'reason' => '交付后五天付款']);
        $detail = SalesSettlementLogic::detail(['id' => $sale['order_id']]); self::assertSame(date('Y-m-06'), $detail['finance']['terms']['default_due_date']);
        $result = SalesSettlementLogic::submit($this->command(0) + ['order_id' => $sale['order_id'], 'lines' => [['order_goods_id' => $sale['line_id'], 'customer_settlement_weight' => '2', 'pricing_unit_id' => $sale['unit_id'], 'price' => '50']]]);
        self::assertNotFalse($result, SalesSettlementLogic::getError());
        $version = Db::name('finance_sales_version')->where('order_id', $sale['order_id'])->find();
        self::assertSame($day, $version['business_date']); self::assertSame(date('Y-m-06'), $version['due_date']);
    }

    public function test_legacy_first_confirmation_cannot_put_multiple_delivery_days_on_one_revenue_day(): void
    {
        $this->activate(); $sale = $this->deliveredSale();
        Db::name('fulfillment_delivery_event')->where('id', $sale['event_id'])->update(['delivered_time' => strtotime(date('Y-m-01'))]);
        $event = Db::name('fulfillment_delivery_event')->where('id', $sale['event_id'])->find(); unset($event['id']); $event['idempotency_key'] = 'legacy-second-' . uniqid(); $event['delivered_time'] = strtotime(date('Y-m-02'));
        $eventId = (int)Db::name('fulfillment_delivery_event')->insertGetId($event);
        $item = Db::name('fulfillment_delivery_item')->where('delivery_event_id', $sale['event_id'])->find(); unset($item['id']); $item['delivery_event_id'] = $eventId; $item['actual_delivery_weight'] = '3'; Db::name('fulfillment_delivery_item')->insert($item);
        Db::name('order_goods')->where('id', $sale['line_id'])->update(['base_quantity' => '5']);
        $result = SalesSettlementLogic::submit($this->command(0) + ['order_id' => $sale['order_id'], 'lines' => [['order_goods_id' => $sale['line_id'], 'customer_settlement_weight' => '5', 'pricing_unit_id' => $sale['unit_id'], 'price' => '50']]]);
        self::assertFalse($result); self::assertStringContainsString('交付', SalesSettlementLogic::getError());
        self::assertSame(0, Db::name('finance_sales_version')->where('order_id', $sale['order_id'])->count());
        self::assertTrue(SalesSettlementLogic::detail(['id' => $sale['order_id']])['finance']['requires_delivery_batches']);
    }

    public function test_legacy_price_correction_after_new_delivery_batch_preserves_original_coverage_and_total_delivery(): void
    {
        $this->activate(); $sale = $this->deliveredSale();
        $request = ['order_id' => $sale['order_id'], 'lines' => [['order_goods_id' => $sale['line_id'], 'customer_settlement_weight' => '2', 'pricing_unit_id' => $sale['unit_id'], 'price' => '50']]];
        self::assertNotFalse(SalesSettlementLogic::submit($this->command(0) + $request));
        $event = Db::name('fulfillment_delivery_event')->where('id', $sale['event_id'])->find(); unset($event['id']); $event['idempotency_key'] = 'legacy-extra-' . uniqid();
        $eventId = (int)Db::name('fulfillment_delivery_event')->insertGetId($event);
        $item = Db::name('fulfillment_delivery_item')->where('delivery_event_id', $sale['event_id'])->find(); unset($item['id']); $item['delivery_event_id'] = $eventId; $item['actual_delivery_weight'] = '3'; $delivery = (int)Db::name('fulfillment_delivery_item')->insertGetId($item);
        Db::name('order_goods')->where('id', $sale['line_id'])->update(['base_quantity' => '5']);
        $batch = FinanceBusinessLogic::action('record', $this->command(0) + ['type' => 'sales_batch', 'payload' => ['subject_id' => $this->customerId, 'reason' => '新增交付单独结算', 'lines' => [['delivery_item_id' => $delivery, 'covered_weight' => '3', 'settlement_weight' => '3', 'price' => '40']]]]); self::assertNotFalse($batch, FinanceBusinessLogic::getError());
        $detail = SalesSettlementLogic::detail(['id' => $sale['order_id']]); self::assertSame('2.0000', $detail['lines'][0]['actual_delivery_weight']); self::assertTrue($detail['finance']['preserves_legacy_coverage']);
        $request['lines'][0]['price'] = '60'; $request['edit_reason'] = '原来两斤的单价更正';
        $corrected = SalesSettlementLogic::submit($this->command(1) + $request); self::assertNotFalse($corrected, SalesSettlementLogic::getError());
        self::assertSame('1240.00', (new FinanceLedger(self::TENANT_ID))->categoryBalance('receivable', $this->customerId));
        self::assertSame('5.0000', Db::name('order_goods')->where('id', $sale['line_id'])->value('base_quantity'));
        self::assertSame([], \app\api\jxc\logic\FinanceDeliveries::rows($this->customerId, date('Y-m-01'), date('Y-m-d')));
        self::assertSame(0, Db::name('sales_delivery_correction')->where('order_id', $sale['order_id'])->count());
        $request['lines'][0]['actual_delivery_weight'] = '3';
        self::assertFalse(SalesSettlementLogic::submit($this->command(2) + $request)); self::assertStringContainsString('原覆盖量', SalesSettlementLogic::getError());
        self::assertSame(0, Db::name('sales_weight_difference_todo')->where('order_id', $sale['order_id'])->count(), '禁止的覆盖量变更不能留下无法确认的待办');
    }

    public function test_legacy_actual_correction_without_later_delivery_remains_valid_and_updates_stock_only_once(): void
    {
        $this->activate(); $sale = $this->deliveredSale();
        $request = ['order_id' => $sale['order_id'], 'lines' => [['order_goods_id' => $sale['line_id'], 'customer_settlement_weight' => '2', 'pricing_unit_id' => $sale['unit_id'], 'price' => '50']]];
        $initial = SalesSettlementLogic::submit($this->command(0) + $request); self::assertNotFalse($initial);
        $request['lines'][0]['actual_delivery_weight'] = '1.5'; $request['lines'][0]['customer_settlement_weight'] = '1.5'; $request['edit_reason'] = '原实际交付误录';
        $request['credit_reviewed'] = 1; $request['credit_allocations'] = [['source' => $initial['finance']['source_ref'], 'amount' => '25']];
        $command = $this->command(1) + $request; $corrected = SalesSettlementLogic::submit($command); self::assertNotFalse($corrected, SalesSettlementLogic::getError());
        self::assertSame($corrected, SalesSettlementLogic::submit($command)); self::assertSame('75.00', $corrected['order_money']);
        self::assertSame(1, Db::name('sales_delivery_correction')->where('order_id', $sale['order_id'])->count());
        self::assertFalse(SalesSettlementLogic::detail(['id' => $sale['order_id']])['finance']['preserves_legacy_coverage']);
    }

    private function deliveredSale(): array
    {
        $unit = $this->createCustomerReportUnit('斤');
        $warehouse = $this->createCustomerReportWarehouse('已交付核算仓');
        $goods = $this->createCustomerReportGoods('已交付商品', 'FINANCE-SALE', '斤');
        $sku = $this->customerReportSkuId($goods); $now = time();
        Db::name('goods')->where('id', $goods)->update(['unit_id' => $unit]);
        Db::name('goods_sku')->where('id', $sku)->update(['base_unit_id' => $unit, 'base_unit_name' => '斤']);
        Db::name('goods_units_binding')->insert(['tenant_id' => self::TENANT_ID, 'goods_id' => $goods, 'unit_id' => $unit, 'unit_name' => '斤', 'is_base_unit' => 1, 'sort' => 0, 'status' => 1, 'create_time' => $now, 'update_time' => $now]);
        $order = (int)Db::name('sales_order')->insertGetId(['tenant_id' => self::TENANT_ID, 'order_sn' => 'FINANCE-SALE-' . uniqid(), 'customer_id' => $this->customerId,
            'customer_name' => '收款主客户', 'warehouse_id' => $warehouse, 'order_money' => '0', 'order_pay_money' => '0', 'order_arrears_money' => '0',
            'datetimesingle' => $now, 'source_type' => 'customer_report', 'source_id' => random_int(100000, 900000), 'source_version' => 1,
            'settlement_status' => 'pending', 'cost_status' => 'pending', 'profit_status' => 'pending_settlement', 'status' => 1,
            'purpose_type' => 'sales', 'remarks' => '', 'admin_id' => self::ADMIN_ID, 'idempotent_key' => '', 'create_time' => $now, 'update_time' => $now]);
        $line = (int)Db::name('order_goods')->insertGetId(['tenant_id' => self::TENANT_ID, 'order_id' => $order, 'order_type' => 'sales', 'goods_id' => $goods,
            'sku_id' => $sku, 'sku_name' => '默认规格', 'supplier_relation_id' => 0, 'name' => '已交付商品', 'units' => '斤', 'number' => '2', 'base_quantity' => '2',
            'price' => '0', 'amount' => '0', 'pricing_unit_id' => 0, 'source_line_type' => 'customer_report_item', 'source_line_id' => random_int(100000, 900000),
            'remark' => '', 'sort' => 1, 'create_time' => $now, 'update_time' => $now]);
        $event = (int)Db::name('fulfillment_delivery_event')->insertGetId(['tenant_id' => self::TENANT_ID, 'idempotency_key' => 'finance-delivery-' . uniqid(), 'delivered_time' => $now]);
        Db::name('fulfillment_delivery_item')->insert(['tenant_id' => self::TENANT_ID, 'delivery_event_id' => $event, 'sales_order_id' => $order,
            'report_item_id' => Db::name('order_goods')->where('id', $line)->value('source_line_id'), 'warehouse_id' => $warehouse, 'goods_id' => $goods, 'sku_id' => $sku, 'actual_delivery_weight' => '2.0000']);
        Db::transaction(fn() => (new \app\api\jxc\logic\FinanceCostLedger(self::TENANT_ID))->recordWithinTransaction([
            'reference' => 'fixture-delivery:' . $event, 'type' => 'issue', 'sku_id' => $sku, 'warehouse_id' => $warehouse,
            'business_date' => date('Y-m-d', $now), 'quantity' => '2', 'bucket' => 'sale', 'target_reference' => 'sales_order:' . $order]));
        return ['order_id' => $order, 'line_id' => $line, 'unit_id' => $unit, 'event_id' => $event];
    }

    private function activate(string $accountType = 'cash', ?string $activationDate = null): void
    {
        $account = FinanceSetupLogic::saveAccount($this->command(0) + ['name' => '经营账户', 'account_type' => $accountType]);
        self::assertNotFalse($account); $this->accountId = (int)$account['id'];
        $this->customerId = $this->createCustomer('收款主客户');
        self::assertNotFalse(FinanceSetupLogic::savePreparation($this->command(0) + ['activation_date' => $activationDate ?? date('Y-m-01'),
            'inventory_cost_reviewed' => 1, 'legacy_settlement_reviewed' => 1, 'excluded_business_reviewed' => 1]));
        foreach ([['account', $this->accountId, '5000'], ['receivable', $this->customerId, '1000']] as [$category, $subjectId, $amount]) {
            $this->opening('item', ['category' => $category, 'subject_id' => $subjectId, 'amount' => $amount, 'historical_date' => null, 'due_date' => null,
                'source_mode' => 'detail', 'source_reference' => '核实旧余额', 'evidence' => '按统一截点核实']);
        }
        foreach (FinanceSetupLogic::opening()['categories'] as $category) { $this->opening('review', ['category' => $category['key'], 'state' => $category['count'] ? 'complete' : 'none', 'evidence' => '逐类核实']); }
        $this->opening('submit'); $this->opening('confirm');
        $this->receivable = (new FinanceLedger(self::TENANT_ID))->sources('receivable', $this->customerId)[0]['reference'];
    }

    private function receipt(string $amount, string $allocated, string $advance = '0'): array
    {
        return ['account_id' => $this->accountId, 'subject_id' => $this->customerId, 'actual_date' => date('Y-m-d'), 'amount' => $amount,
            'allocations' => [['source' => $this->receivable, 'amount' => $allocated]], 'advance_amount' => $advance, 'reason' => '客户实际支付旧欠款'];
    }

    private function command(int $version): array { return ['expected_tenant_id' => self::TENANT_ID, 'expected_version' => $version, 'idempotency_key' => 'business-workflow-' . ++$this->sequence]; }
    private function opening(string $action, array $data = []): array
    {
        $result = FinanceSetupLogic::openingAction($action, $this->command((int)FinanceSetupLogic::opening()['version']) + $data);
        self::assertNotFalse($result, FinanceSetupLogic::getError()); return $result;
    }
    private function clean(): void
    {
        foreach (['finance_inventory_loss_resolution', 'finance_inventory_loss'] as $table) { Db::name($table)->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete(); }
        Db::name('finance_purchase_arrival_loss')->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete();
        Db::name('finance_purchase_difference_review')->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete();
        foreach (['finance_purchase_cost_revision', 'finance_purchase_cost_change', 'finance_purchase_cost_bill'] as $table) { Db::name($table)->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete(); }
        foreach (['finance_supplier_terms', 'finance_purchase_difference_rule', 'finance_purchase_settlement_line', 'finance_purchase_arrival_line', 'finance_purchase_price'] as $table) { Db::name($table)->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete(); }
        foreach (['finance_cost_effect', 'finance_cost_event', 'finance_cost_shortage', 'finance_cost_position', 'finance_cost_origin'] as $table) { Db::name($table)->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete(); }
        Db::name('finance_sales_print')->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete();
        Db::name('finance_sales_coverage')->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete();
        Db::name('finance_sales_precision_rule')->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete();
        Db::name('finance_overdue_event')->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete();
        foreach (['finance_statement_resolution', 'finance_statement_dispute', 'finance_statement_event', 'finance_statement'] as $table) { Db::name($table)->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete(); }
        foreach (['finance_supplier_statement_resolution', 'finance_supplier_statement_dispute', 'finance_supplier_statement_event', 'finance_supplier_statement'] as $table) { Db::name($table)->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete(); }
        Db::name('finance_purchase_return_line')->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete();
        Db::name('finance_purchase_return_resolution')->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete();
        Db::name('finance_advance_revision')->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete();
        Db::name('finance_due_adjustment')->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete();
        Db::name('finance_customer_terms')->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete();
        foreach (Db::name('finance_evidence')->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->select()->toArray() as $proof) {
            $snapshot = json_decode($proof['snapshot'], true); $key = $snapshot['storage_key'] ?? '';
            if (preg_match('/^[a-f0-9]{48}\.(png|jpg|webp)$/D', $key)) {
                $file = root_path() . 'storage/finance-evidence/' . (int)$proof['tenant_id'] . '/' . $key;
                if (is_file($file)) { unlink($file); }
            }
        }
        foreach (['finance_sales_version', 'finance_transaction_identity', 'finance_correction', 'finance_evidence', 'finance_period', 'finance_money_transaction', 'finance_entry', 'finance_source', 'finance_command', 'finance_document',
            'finance_opening_item_detail', 'finance_opening_source', 'finance_opening_item', 'finance_opening_book', 'finance_setup_action', 'finance_account', 'finance_preparation'] as $table) {
            Db::name($table)->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete();
        }
        $this->cleanCustomerReportData();
    }
}
