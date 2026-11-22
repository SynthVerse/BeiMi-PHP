<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\CustomerReportBatchLogic;
use app\api\jxc\logic\CustomerReportLogic;
use app\api\jxc\logic\FulfillmentTaskLogic;
use app\api\jxc\logic\PurchasePlanLogic;
use app\api\jxc\logic\PendingGoodsLogic;
use app\api\jxc\logic\PurchaseReturnOrderLogic;
use app\api\jxc\logic\PurchaseBatchLogic;
use app\api\jxc\logic\SupplyOrderLogic;
use app\api\jxc\logic\WarehouseSkuBalanceService;
use app\api\jxc\logic\WorkforceLogic;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

require_once __DIR__ . '/CustomerReportTestSupport.php';

final class PendingGoodsRegressionTest extends TestCase
{
    use CustomerReportTestSupport { fulfillmentPayload as private oldFulfillmentPayload; }

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
        $this->cleanCustomerReportData();
        parent::tearDown();
    }

    public function test_pending_return_hold_uses_current_read_after_another_connection_plans_return(): void
    {
        $goods = $this->createCustomerReportGoods('并发待退鲈鱼', 'PENDING-CONCURRENT');
        $warehouse = $this->createCustomerReportWarehouse('并发待退仓'); $sku = $this->customerReportSkuId($goods);
        WarehouseSkuBalanceService::inbound($warehouse, $sku, '5'); WarehouseSkuBalanceService::reserve($warehouse, $sku, '5');
        $pending = (int)Db::name('pending_goods')->insertGetId(['tenant_id' => self::TENANT_ID, 'origin_key' => 'concurrent-test',
            'report_id' => 1, 'purchase_batch_id' => 1, 'warehouse_id' => $warehouse, 'goods_id' => $goods, 'sku_id' => $sku, 'total_qty' => '5', 'pending_qty' => '5', 'create_time' => time(), 'update_time' => time()]);
        $config = config('database.connections.mysql');
        $other = new \PDO('mysql:host=' . $config['hostname'] . ';port=' . $config['hostport'] . ';dbname=' . $config['database'], $config['username'], $config['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        Db::execute('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'); Db::startTrans();
        try {
            Db::name('pending_goods_return')->where('tenant_id', self::TENANT_ID)->select();
            $other->beginTransaction();
            $update = $other->prepare('UPDATE ' . Db::name('pending_goods')->getTable() . ' SET pending_qty=0,return_qty=5 WHERE id=? AND tenant_id=?'); $update->execute([$pending, self::TENANT_ID]);
            $insert = $other->prepare('INSERT INTO ' . Db::name('pending_goods_return')->getTable() . ' (tenant_id,pending_goods_id,supply_order_id,quantity,remaining_qty,create_time,update_time) VALUES (?,?,1,5,5,?,?)'); $insert->execute([self::TENANT_ID, $pending, time(), time()]); $other->commit();
            self::assertFalse(WarehouseSkuBalanceService::deliverAttributedWithinTransaction($warehouse, $sku, '1', '0'), '旧快照也不能忽略刚安排的待退锁定');
            self::assertSame('5.0000', WarehouseSkuBalanceService::onHand($warehouse, $sku));
        } finally { Db::rollback(); }
    }

    public function test_pending_return_rejection_and_loss_keep_auditable_facts_without_second_stock_movement(): void
    {
        $schema = (string)file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260630_000001_create_purchase_return_order.sql');
        $this->runStatements($this->authoritativeCreateTable($schema, 'purchase_return_order'));
        $this->runStatements($this->authoritativeCreateTable($schema, 'purchase_return_order_lists'));
        if (Db::query("SHOW COLUMNS FROM `la_supply_order` LIKE 'return_status'") === []) { Db::execute('ALTER TABLE la_supply_order ADD COLUMN return_status tinyint NOT NULL DEFAULT 0'); }
        Db::name('tenant')->where('id', self::TENANT_ID)->delete();
        Db::name('tenant')->insert(['id' => self::TENANT_ID, 'sn' => 'PG882101', 'name' => '待定测试门店', 'disable' => 0, 'create_time' => time(), 'update_time' => time(), 'delete_time' => null]);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '待定测试供应商', 'order_payable' => '100', 'order_money' => '100']);
        WorkforceLogic::saveProcess(['name' => '采购', 'trigger_type' => 'inventory_shortage', 'is_enabled' => 1, 'sort' => 1]);
        $customer = $this->createCustomer('分阶段退货客户'); $goods = $this->createCustomerReportGoods('退货鲈鱼', 'PENDING-RETURN');
        $warehouse = $this->createCustomerReportWarehouse('退货仓'); $sku = $this->customerReportSkuId($goods);
        $report = CustomerReportLogic::submit($this->fulfillmentPayload($customer, $goods, $warehouse, 'pending-return-report', '5', '20'));
        $plan = ['id'=>0];
        $batch = $this->createArrivalBatch((int)$plan['id'], $warehouse, $goods, $sku, '5.0000');
        WarehouseSkuBalanceService::inbound($warehouse, $sku, '5'); $this->holdArrivalForTest($batch, $warehouse, $sku, '5');
        $orderId = (int)Db::name('purchase_batch_supply_order')->where('tenant_id', self::TENANT_ID)->where('purchase_batch_id', $batch)->value('supply_order_id');
        Db::name('supply_order')->where('id', $orderId)->update(['supplier_id' => $vendor]);
        $cancelled = CustomerReportLogic::cancel(['id' => $report['id'], 'version' => $report['version'], 'reason' => '需要退货']);
        self::assertNotFalse($cancelled, CustomerReportLogic::getError());
        Db::name('pending_goods')->insert(['tenant_id'=>self::TENANT_ID,'origin_key'=>'regression-batch:'.$batch,'report_id'=>$report['id'],'purchase_batch_id'=>$batch,'warehouse_id'=>$warehouse,'goods_id'=>$goods,'sku_id'=>$sku,'total_qty'=>'5','pending_qty'=>'5','create_time'=>time(),'update_time'=>time()]);
        $pending = PendingGoodsLogic::lists(['report_id' => $report['id']])['lists'][0];
        $command = ['id' => $pending['id'], 'expected_tenant_id' => self::TENANT_ID];
        $pending = PendingGoodsLogic::action($command + ['version' => $pending['version'], 'idempotency_key' => 'pending-full-return-plan', 'action' => 'plan_return', 'quantity' => '5', 'supply_order_id' => $orderId]);
        $line = Db::name('order_goods')->where('tenant_id', self::TENANT_ID)->where('order_id', $orderId)->where('order_type', 'supply')->find();
        $departure = ['pending_return_id' => $pending['returns'][0]['id'], 'original_supply_order_id' => $orderId, 'supplier_id' => $vendor,
            'idempotent_key' => 'pending-actual-departure-test', 'goods' => [['original_supply_order_list_id' => $line['id'], 'goods_id' => $goods, 'sku_id' => $sku, 'return_num' => '5', 'price' => '20']]];
        $out = PurchaseReturnOrderLogic::publish($departure);
        self::assertNotFalse($out, PurchaseReturnOrderLogic::getError());
        self::assertSame('0.0000', WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame('100.00', (string)Db::name('vendor')->where('id', $vendor)->value('order_payable'), '退离不冲减应付');
        self::assertNotFalse(PurchaseReturnOrderLogic::publish($departure));
        $pending = PendingGoodsLogic::detail(['id' => $pending['id']]); $factId = $pending['returns'][0]['facts'][0]['id'];
        self::assertSame('supplier', $pending['returns'][0]['facts'][0]['next_step']);
        self::assertSame('5.0000', $pending['return_qty'], '离仓后仍是待处理');
        $rejection = $command + ['version' => $pending['version'], 'action' => 'accept_return', 'fact_id' => $factId,
            'quantity' => '0', 'amount' => '1', 'note' => '供应商暂不认可', 'idempotency_key' => 'pending-rejection-test'];
        self::assertFalse(PendingGoodsLogic::action($rejection));
        $rejection['amount'] = '0';
        $pending = PendingGoodsLogic::action($rejection);
        self::assertNotFalse($pending, PendingGoodsLogic::getError());
        self::assertSame('5.0000', $pending['returns'][0]['facts'][0]['unresolved_qty']);
        self::assertSame('supplier', $pending['returns'][0]['facts'][0]['next_step']);
        self::assertCount(1, $pending['returns'][0]['facts'][0]['history']);
        $loss = $command + ['version' => $pending['version'], 'action' => 'return_loss', 'fact_id' => $factId,
            'quantity' => '5', 'note' => '货物实际变质且确认损失', 'idempotency_key' => 'pending-loss-test'];
        $pending = PendingGoodsLogic::action($loss);
        self::assertNotFalse($pending, PendingGoodsLogic::getError());
        self::assertSame('0.0000', WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame('0.0000', $pending['pending_qty']);
        self::assertSame('0.0000', $pending['return_qty']);
        self::assertSame('complete', $pending['returns'][0]['facts'][0]['next_step']);
        self::assertCount(2, $pending['returns'][0]['facts'][0]['history']);
        self::assertSame('loss', $pending['returns'][0]['facts'][0]['history'][1]['kind']);
        self::assertNotFalse(PendingGoodsLogic::action($loss));
        self::assertSame('100.00', (string)Db::name('vendor')->where('id', $vendor)->value('order_payable'));
        Db::name('vendor')->where('tenant_id', self::TENANT_ID)->where('id', $vendor)->delete();
    }

    public function test_pending_return_waits_for_supplier_and_money_and_rejected_goods_return_locked(): void
    {
        $schema = (string)file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260630_000001_create_purchase_return_order.sql');
        $this->runStatements($this->authoritativeCreateTable($schema, 'purchase_return_order'));
        $this->runStatements($this->authoritativeCreateTable($schema, 'purchase_return_order_lists'));
        if (Db::query("SHOW COLUMNS FROM `la_supply_order` LIKE 'return_status'") === []) { Db::execute('ALTER TABLE la_supply_order ADD COLUMN return_status tinyint NOT NULL DEFAULT 0'); }
        Db::name('tenant')->where('id', self::TENANT_ID)->delete();
        Db::name('tenant')->insert(['id' => self::TENANT_ID, 'sn' => 'PG882101', 'name' => '待定测试门店', 'disable' => 0, 'create_time' => time(), 'update_time' => time(), 'delete_time' => null]);
        $vendor = (int)Db::name('vendor')->insertGetId(['tenant_id' => self::TENANT_ID, 'supplier_name' => '待定测试供应商', 'order_payable' => '100', 'order_money' => '100']);
        WorkforceLogic::saveProcess(['name' => '采购', 'trigger_type' => 'inventory_shortage', 'is_enabled' => 1, 'sort' => 1]);
        $customer = $this->createCustomer('分阶段退货客户'); $goods = $this->createCustomerReportGoods('退货鲈鱼', 'PENDING-RETURN');
        $warehouse = $this->createCustomerReportWarehouse('退货仓'); $sku = $this->customerReportSkuId($goods);
        $report = CustomerReportLogic::submit($this->fulfillmentPayload($customer, $goods, $warehouse, 'pending-return-report', '5', '20'));
        $plan = ['id'=>0];
        $batch = $this->createArrivalBatch((int)$plan['id'], $warehouse, $goods, $sku, '5.0000');
        WarehouseSkuBalanceService::inbound($warehouse, $sku, '5'); $this->holdArrivalForTest($batch, $warehouse, $sku, '5');
        $orderId = (int)Db::name('purchase_batch_supply_order')->where('tenant_id', self::TENANT_ID)->where('purchase_batch_id', $batch)->value('supply_order_id');
        Db::name('supply_order')->where('id', $orderId)->update(['supplier_id' => $vendor]);
        $cancelled = CustomerReportLogic::cancel(['id' => $report['id'], 'version' => $report['version'], 'reason' => '需要退货']);
        self::assertNotFalse($cancelled, CustomerReportLogic::getError());
        Db::name('pending_goods')->insert(['tenant_id'=>self::TENANT_ID,'origin_key'=>'regression-batch:'.$batch,'report_id'=>$report['id'],'purchase_batch_id'=>$batch,'warehouse_id'=>$warehouse,'goods_id'=>$goods,'sku_id'=>$sku,'total_qty'=>'5','pending_qty'=>'5','create_time'=>time(),'update_time'=>time()]);
        $pending = PendingGoodsLogic::lists(['report_id' => $report['id']])['lists'][0];
        $command = ['id' => $pending['id'], 'expected_tenant_id' => self::TENANT_ID];
        $pending = PendingGoodsLogic::action($command + ['version' => $pending['version'], 'idempotency_key' => 'pending-full-return-plan', 'action' => 'plan_return', 'quantity' => '5', 'supply_order_id' => $orderId]);
        $line = Db::name('order_goods')->where('tenant_id', self::TENANT_ID)->where('order_id', $orderId)->where('order_type', 'supply')->find();
        $departure = ['pending_return_id' => $pending['returns'][0]['id'], 'original_supply_order_id' => $orderId, 'supplier_id' => $vendor,
            'idempotent_key' => 'pending-actual-departure-test', 'goods' => [['original_supply_order_list_id' => $line['id'], 'goods_id' => $goods, 'sku_id' => $sku, 'return_num' => '5', 'price' => '20']]];
        $out = PurchaseReturnOrderLogic::publish($departure);
        self::assertNotFalse($out, PurchaseReturnOrderLogic::getError());
        self::assertSame('0.0000', WarehouseSkuBalanceService::onHand($warehouse, $sku));
        self::assertSame('100.00', (string)Db::name('vendor')->where('id', $vendor)->value('order_payable'), '退离不冲减应付');
        self::assertNotFalse(PurchaseReturnOrderLogic::publish($departure));
        $pending = PendingGoodsLogic::detail(['id' => $pending['id']]); $factId = $pending['returns'][0]['facts'][0]['id'];
        self::assertSame('supplier', $pending['returns'][0]['facts'][0]['next_step']);
        self::assertSame('5.0000', $pending['return_qty'], '离仓后仍是待处理');
        $pending = PendingGoodsLogic::action($command + ['version' => $pending['version'], 'action' => 'accept_return', 'fact_id' => $factId,
            'quantity' => '3', 'amount' => '60', 'note' => '供方签收三斤，同意六十元', 'idempotency_key' => 'pending-supplier-accept-test']);
        self::assertNotFalse($pending, PendingGoodsLogic::getError());
        self::assertSame('2.0000', $pending['returns'][0]['facts'][0]['unresolved_qty']);
        $pending = PendingGoodsLogic::action($command + ['version' => $pending['version'], 'action' => 'return_back', 'fact_id' => $factId,
            'quantity' => '2', 'note' => '未认可两斤已回仓', 'idempotency_key' => 'pending-goods-back-test']);
        self::assertNotFalse($pending, PendingGoodsLogic::getError());
        self::assertSame('2.0000', $pending['pending_qty']); self::assertSame('0.0000', WarehouseSkuBalanceService::available($warehouse, $sku));
        self::assertSame('money', $pending['returns'][0]['facts'][0]['next_step']);
        $pending = PendingGoodsLogic::action($command + ['version' => $pending['version'], 'action' => 'settle_return', 'fact_id' => $factId,
            'amount' => '60', 'settlement_kind' => 'offset', 'note' => '供应商确认抵扣本次未付六十元', 'idempotency_key' => 'pending-money-offset-test']);
        self::assertNotFalse($pending, PendingGoodsLogic::getError());
        self::assertSame('0.0000', $pending['return_qty']); self::assertSame('3.0000', $pending['returned_qty']);
        self::assertSame('complete', $pending['returns'][0]['facts'][0]['next_step']);
        self::assertSame('40.00', (string)Db::name('vendor')->where('id', $vendor)->value('order_payable'));
        Db::name('vendor')->where('tenant_id', self::TENANT_ID)->where('id', $vendor)->delete();
    }

    public function test_purchase_plan_delivery_priority_migration_is_safe_to_replay(): void
    {
        $root = dirname(__DIR__, 2);
        $migration = $this->prepareMigration((string)file_get_contents(
            $root . '/database/migrations/20260930_000004_purchase_plan_delivery_priority.sql'
        ));

        $this->runStatements($migration);
        $this->runStatements($migration);

        self::assertNotEmpty(Db::query("SHOW COLUMNS FROM `la_purchase_plan_source` LIKE 'earliest_delivery_time'"));
        self::assertNotEmpty(Db::query("SHOW INDEX FROM `la_purchase_plan_source` WHERE Key_name = 'idx_purchase_plan_source_priority'"));
    }

    protected function fulfillmentPayload(int $customerId,int $goodsId,int $warehouseId,string $key,string $quantity='3',string $processing='20'): array
    {
        $p=$this->oldFulfillmentPayload($customerId,$goodsId,$warehouseId,$key,$quantity,$processing);
        $unit=$this->createCustomerReportUnit('斤');$sku=$this->customerReportSkuId($goodsId);
        Db::name('goods')->where('id',$goodsId)->update(['unit_id'=>$unit]);Db::name('goods_sku')->where('id',$sku)->update(['base_unit_id'=>$unit,'base_unit_name'=>'斤']);
        Db::name('goods_units_binding')->insert(['tenant_id'=>self::TENANT_ID,'goods_id'=>$goodsId,'unit_id'=>$unit,'unit_name'=>'斤','is_base_unit'=>1,'status'=>1]);
        $p['items'][0]['unit_id']=$unit;$p['items'][0]['unit_name']='斤';$p['items'][0]['sku_id']=$sku;$p['items'][0]['piece_weight_confirmed']=0;return $p;
    }

    private function createArrivalBatch(
        int $planId,
        int $warehouseId,
        int $goodsId,
        int $skuId,
        string $actualQuantity
    ): int {
        $now = time();
        $batchId = (int)Db::name('purchase_batch')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'purchase_plan_id' => $planId,
            'batch_no' => 'TEST-PB-' . uniqid(),
            'warehouse_id' => $warehouseId,
            'warehouse_name' => '分批到货仓',
            'datetimesingle' => $now,
            'remarks' => '',
            'status' => 'submitted',
            'supplier_count' => 1,
            'line_count' => 1,
            'total_amount' => '0.00',
            'idempotency_key' => 'test-' . uniqid(),
            'request_fingerprint' => hash('sha256', uniqid('', true)),
            'admin_id' => self::ADMIN_ID,
            'create_time' => $now,
            'update_time' => $now,
        ]);
        $supplyOrderId = (int)Db::name('supply_order')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'order_sn' => 'TEST-SO-' . uniqid(),
            'supplier_id' => 1,
            'supplier_name' => '测试供应商',
            'warehouse_id' => $warehouseId,
            'order_money' => '0.00',
            'order_pay_money' => '0.00',
            'order_arrears_money' => '0.00',
            'datetimesingle' => $now,
            'status' => 1,
            'purpose_type' => 'supply',
            'purchase_batch_id' => $batchId,
            'remarks' => '',
            'admin_id' => self::ADMIN_ID,
            'idempotent_key' => 'test-supply-' . uniqid(),
            'create_time' => $now,
            'update_time' => $now,
        ]);
        Db::name('purchase_batch_supply_order')->insert([
            'tenant_id' => self::TENANT_ID,
            'purchase_batch_id' => $batchId,
            'supply_order_id' => $supplyOrderId,
            'supplier_id' => 1,
            'supplier_name' => '测试供应商',
            'line_count' => 1,
            'order_money' => '0.00',
            'create_time' => $now,
            'update_time' => $now,
        ]);
        Db::name('order_goods')->insert([
            'tenant_id' => self::TENANT_ID,
            'order_id' => $supplyOrderId,
            'order_type' => 'supply',
            'goods_id' => $goodsId,
            'sku_id' => $skuId,
            'sku_name' => '测试 SKU',
            'name' => '分批鲈鱼',
            'units' => '斤',
            'number' => $actualQuantity,
            'base_quantity' => $actualQuantity,
            'actual_base_qty' => $actualQuantity,
            'price' => '0.00',
            'amount' => '0.00',
            'create_time' => $now,
            'update_time' => $now,
        ]);
        return $batchId;
    }

    private function holdArrivalForTest(int $batchId, int $warehouseId, int $skuId, string $quantity): void
    {
        self::assertNotFalse(
            WarehouseSkuBalanceService::reserve($warehouseId, $skuId, $quantity),
            '测试采购到货应先从通用可用库存锁定'
        );
        Db::name('purchase_batch')->where('tenant_id', self::TENANT_ID)->where('id', $batchId)
            ->update(['plan_held_qty' => $quantity, 'update_time' => time()]);
    }
}
