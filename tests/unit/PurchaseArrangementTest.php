<?php
declare(strict_types=1);
namespace tests\unit;
use app\api\jxc\lists\SupplyOrderLists;
use app\api\jxc\logic\ {
    CustomerReportLogic,PurchasePlanLogic,FulfillmentTaskLogic,WorkforceLogic,WarehouseSkuBalanceService
};
use PHPUnit\Framework\TestCase;
use think\facade\Db;
require_once __DIR__.'/CustomerReportTestSupport.php';
final class PurchaseArrangementTest extends TestCase {
    use CustomerReportTestSupport;
    protected function setUp():void {
        $this->prepareCustomerReportRequestContext();
        $this->ensureCustomerReportTables();
        $this->cleanCustomerReportData();
        $this->createCustomerReportUnit('斤');
    }
    protected function tearDown():void {
        $this->cleanCustomerReportData();
    }
    private function fixture(string $requirement=''):array {
        WorkforceLogic::saveProcess(['name'=>'采购','trigger_type'=>'inventory_shortage','is_enabled'=>1,'sort'=>1]);
        $customer=$this->createCustomer('采购需求客户');
        $goods=$this->createCustomerReportGoods('采购鲈鱼','PURCHASE-V2');
        $warehouse=$this->createCustomerReportWarehouse('采购仓');
        $unit=$this->createCustomerReportUnit('斤');
        $sku=$this->customerReportSkuId($goods);
        Db::name('goods')->where('id',$goods)->update(['unit_id'=>$unit]);
        Db::name('goods_sku')->where('id',$sku)->update(['base_unit_id'=>$unit,'base_unit_name'=>'斤']);
        Db::name('goods_units_binding')->insert(['tenant_id'=>self::TENANT_ID,'goods_id'=>$goods,'unit_id'=>$unit,'unit_name'=>'斤','is_base_unit'=>1,'status'=>1]);
        $payload=$this->fulfillmentPayload($customer,$goods,$warehouse,'procurement-report-v2','100',$requirement);
        $payload['items'][0]['unit_id']=$unit;
        $payload['items'][0]['unit_name']='斤';
        $payload['items'][0]['piece_weight_confirmed']=0;
        $payload['items'][0]['sku_id']=$sku;
        $report=CustomerReportLogic::submit($payload);
        self::assertNotFalse($report,CustomerReportLogic::getError());
        return compact('warehouse','report','goods','sku','payload');
    }
    private function arrange(array $f,string $quantity='100',string $key='arrange-plan-first'):array|false {
        $i=$f['report']['items'][0];
        return PurchasePlanLogic::create(['warehouse_id'=>$f['warehouse'],'delivery_date'=>$f['report']['delivery_date'],'idempotency_key'=>$key,'lines'=>[['report_item_id'=>$i['id'],'report_version'=>$f['report']['version'],'quantity'=>$quantity]]]);
    }
    public function test_explicit_arrangement_is_idempotent_and_cannot_duplicate_source():void {
        $f=$this->fixture();
        $d=PurchasePlanLogic::demands([]);
        self::assertNotFalse($d,PurchasePlanLogic::getError());
        self::assertSame('100.0000',$d['lists'][0]['remaining_qty']);
        $p=$this->arrange($f,'60');
        self::assertNotFalse($p,PurchasePlanLogic::getError());
        self::assertSame('60.0000',$p['lines'][0]['planned_qty']);
        self::assertSame($p['id'],$this->arrange($f,'60')['id']);
        self::assertFalse($this->arrange($f,'50','arrange-conflict-second'));
        $d=PurchasePlanLogic::demands([]);
        self::assertSame('40.0000',$d['lists'][0]['remaining_qty']);
    }
    public function test_preview_is_read_only_and_prepared_sheets_are_idempotent():void {
        $f=$this->fixture();
        $p=$this->arrange($f);
        self::assertNotFalse($p,PurchasePlanLogic::getError());
        $preview = PurchasePlanLogic::preview(['id' => $p['id'], 'mode' => 'merged']);
        self::assertNotFalse($preview, PurchasePlanLogic::getError());
        self::assertCount(1, $preview['sheets']);
        $ticket = $preview['sheets'][0]['ticket'];
        self::assertSame($p['plan_no'], $ticket['plan_no']);
        self::assertSame('', $ticket['ticket_no']);
        self::assertSame(1, $ticket['content_version']);
        self::assertSame(1, $ticket['copy_no']);
        self::assertFalse($ticket['is_reprint']);
        self::assertSame('', $ticket['print_lines'][0]['requirement']);
        self::assertCount(0,PurchasePlanLogic::detail(['id'=>$p['id']])['sheets']);
        $empty=PurchasePlanLogic::preparePrint(['id'=>$p['id'],'version'=>1,'mode'=>'merged','idempotency_key'=>'recover-with-no-papers','recover_only'=>true]);
        self::assertNotFalse($empty,PurchasePlanLogic::getError());self::assertSame([],$empty['sheets']);self::assertCount(0,PurchasePlanLogic::detail(['id'=>$p['id']])['sheets']);
        $req=['id'=>$p['id'],'version'=>$p['version'],'mode'=>'merged','idempotency_key'=>'prepare-paper-first'];
        $s=PurchasePlanLogic::preparePrint($req);
        self::assertNotFalse($s,PurchasePlanLogic::getError());
        $ticket['ticket_no'] = $p['plan_no'] . '-merged-1';
        self::assertSame($ticket, $s['sheets'][0]['ticket']);
        self::assertSame($s['sheets'],PurchasePlanLogic::preparePrint($req)['sheets']);
        self::assertTrue(PurchasePlanLogic::preparePrint($req)['recover_only']);
        self::assertNotFalse(PurchasePlanLogic::printResult(['sheet_id'=>$s['sheets'][0]['id'],'success'=>1]));
        self::assertFalse(PurchasePlanLogic::printResult(['sheet_id'=>$s['sheets'][0]['id'],'success'=>0]));
    }

    public function test_reprint_preview_exposes_remaining_and_progress_without_rewriting_frozen_ticket():void {
        $requirement = '验收标签保留 A10.0000';
        $fixture = $this->fixture($requirement);
        $plan = $this->arrange($fixture);
        self::assertNotFalse($plan, PurchasePlanLogic::getError());
        $request = ['id' => $plan['id'], 'version' => $plan['version'], 'mode' => 'merged', 'idempotency_key' => 'prepare-before-partial-buy'];
        $first = PurchasePlanLogic::preparePrint($request);
        self::assertNotFalse($first, PurchasePlanLogic::getError());
        self::assertNotFalse(PurchasePlanLogic::printResult(['sheet_id' => $first['sheets'][0]['id'], 'success' => 1]));

        $changed = PurchasePlanLogic::recordBought([
            'id' => $plan['id'], 'version' => $plan['version'], 'idempotency_key' => 'record-bought-before-reprint',
            'lines' => [['line_id' => $plan['lines'][0]['id'], 'quantity' => '40.25']],
        ]);
        self::assertNotFalse($changed, PurchasePlanLogic::getError());
        $preview = PurchasePlanLogic::preview(['id' => $plan['id'], 'mode' => 'merged']);
        self::assertNotFalse($preview, PurchasePlanLogic::getError());
        $ticket = $preview['sheets'][0]['ticket'];
        self::assertSame($changed['version'], $ticket['content_version']);
        self::assertSame(2, $ticket['copy_no']);
        self::assertTrue($ticket['is_reprint']);
        $line = $ticket['print_lines'][0];
        self::assertSame('59.7500', $line['quantity']);
        self::assertSame('100.0000', $line['planned_qty']);
        self::assertSame('40.2500', $line['bought_qty']);
        self::assertSame('0.0000', $line['fulfilled_qty']);
        self::assertSame($fixture['report']['sn'], $line['report_sn']);
        self::assertSame($requirement, $line['requirement']);
        self::assertSame($first['sheets'][0]['ticket'], PurchasePlanLogic::preparePrint($request)['sheets'][0]['ticket']);

        $split = PurchasePlanLogic::preview(['id' => $plan['id'], 'mode' => 'customer']);
        self::assertNotFalse($split, PurchasePlanLogic::getError());
        self::assertSame(1, $split['sheets'][0]['ticket']['copy_no']);
        self::assertTrue($split['sheets'][0]['ticket']['is_reprint']);
        $paper = PurchasePlanLogic::preparePrint([
            'id' => $plan['id'], 'version' => $changed['version'], 'mode' => 'merged', 'idempotency_key' => 'prepare-after-partial-buy',
        ]);
        self::assertNotFalse($paper, PurchasePlanLogic::getError());
        $ticket['ticket_no'] = $plan['plan_no'] . '-merged-2';
        self::assertSame($ticket, $paper['sheets'][0]['ticket']);
    }
    public function test_printed_plan_can_be_revised_without_losing_paper_or_bought_quantity():void
    {
        $fixture = $this->fixture();
        $plan = $this->arrange($fixture, '80');
        self::assertNotFalse($plan, PurchasePlanLogic::getError());
        $lineId = $plan['lines'][0]['id'];
        $print = PurchasePlanLogic::preparePrint([
            'id' => $plan['id'], 'version' => $plan['version'], 'mode' => 'merged',
            'idempotency_key' => 'revision-original-paper',
        ]);
        self::assertNotFalse($print, PurchasePlanLogic::getError());
        $sheet = $print['sheets'][0];
        $request = [
            'id' => $plan['id'], 'version' => $plan['version'], 'idempotency_key' => 'revision-to-sixty',
            'lines' => [['line_id' => $lineId, 'planned_qty' => '60', 'bought_qty' => '40']],
        ];
        $saved = PurchasePlanLogic::revise($request);
        self::assertNotFalse($saved, PurchasePlanLogic::getError());
        self::assertSame($saved, PurchasePlanLogic::revise($request));
        self::assertSame('60.0000', $saved['lines'][0]['planned_qty']);
        self::assertSame('40.0000', $saved['lines'][0]['bought_qty']);
        self::assertSame('40.0000', PurchasePlanLogic::demands([])['lists'][0]['remaining_qty']);
        self::assertFalse(PurchasePlanLogic::revise(array_replace($request, [
            'idempotency_key' => 'revision-stale-version',
        ])));
        self::assertFalse(PurchasePlanLogic::revise(array_replace($request, [
            'version' => $saved['version'], 'idempotency_key' => 'revision-below-bought',
            'lines' => [['line_id' => $lineId, 'planned_qty' => '39']],
        ])));
        self::assertFalse(PurchasePlanLogic::revise(array_replace($request, [
            'version' => $saved['version'], 'idempotency_key' => 'revision-over-demand',
            'lines' => [['line_id' => $lineId, 'planned_qty' => '101']],
        ])));
        $increased = PurchasePlanLogic::revise(array_replace($request, [
            'version' => $saved['version'], 'idempotency_key' => 'revision-valid-increase',
            'lines' => [['line_id' => $lineId, 'planned_qty' => '70']],
        ]));
        self::assertNotFalse($increased, PurchasePlanLogic::getError());
        self::assertSame('30.0000', PurchasePlanLogic::demands([])['lists'][0]['remaining_qty']);
        $receipt = PurchasePlanLogic::printResult(['sheet_id' => $sheet['id'], 'success' => 1]);
        self::assertSame($sheet['ticket'], $receipt['ticket']);
        $historical = PurchasePlanLogic::preparePrint([
            'id' => $plan['id'], 'version' => $increased['version'], 'original_sheet_id' => $sheet['id'],
            'idempotency_key' => 'revision-print-original',
        ]);
        self::assertNotFalse($historical, PurchasePlanLogic::getError());
        self::assertSame($sheet['plan_version'], $historical['sheets'][0]['plan_version']);
        self::assertSame($sheet['ticket']['print_lines'], $historical['sheets'][0]['ticket']['print_lines']);
        $current = PurchasePlanLogic::preview(['id' => $plan['id']]);
        self::assertSame('30.0000', $current['sheets'][0]['ticket']['print_lines'][0]['quantity']);
        self::assertSame($increased['version'], $current['sheets'][0]['ticket']['content_version']);
    }
    private function countReport(array $f, array $requirements = []):array {
        $count=$this->createCustomerReportUnit('条');
        Db::name('goods_units_binding')->insert(['tenant_id'=>self::TENANT_ID,'goods_id'=>$f['goods'],'unit_id'=>$count,'unit_name'=>'条','is_base_unit'=>0,'status'=>1]);
        $payload=$f['payload'];
        $payload['idempotency_key']='report-count-customer-2';
        $payload['main_customer_id']=$this->createCustomer('采购客户二');
        $payload['items'][0]['unit_id']=$count;
        $payload['items'][0]['unit_name']='条';
        $payload['items'][0]=array_replace($payload['items'][0],$requirements);
        $r=CustomerReportLogic::submit($payload);
        self::assertNotFalse($r,CustomerReportLogic::getError());
        return $r;
    }
    public function test_print_requirement_formats_confirmed_piece_weight_without_editing_customer_text():void {
        $fixture = $this->fixture('规格标签 A10.0000');
        $fixture['report'] = $this->countReport($fixture, [
            'piece_weight_confirmed' => 1, 'piece_weight_min' => '1.00', 'piece_weight_max' => '1.25',
        ]);
        $plan = $this->arrange($fixture);
        self::assertNotFalse($plan, PurchasePlanLogic::getError());
        $preview = PurchasePlanLogic::preview(['id' => $plan['id'], 'mode' => 'merged']);
        self::assertNotFalse($preview, PurchasePlanLogic::getError());
        self::assertSame('规格标签 A10.0000；单条 1～1.25斤', $preview['sheets'][0]['ticket']['print_lines'][0]['requirement']);
    }
    public function test_mixed_units_zero_stock_can_be_arranged_together_without_inventing_weight():void {
        $f=$this->fixture();
        $r=$this->countReport($f);
        $a=$f['report']['items'][0];
        $b=$r['items'][0];
        $ds=PurchasePlanLogic::demands([]);
        self::assertSame(2,$ds['count']);
        self::assertFalse($ds['lists'][1]['stock_verification_required']);
        self::assertSame('100.0000',$ds['lists'][1]['remaining_qty']);
        $p=PurchasePlanLogic::create(['warehouse_id'=>$f['warehouse'],'delivery_date'=>$r['delivery_date'],'idempotency_key'=>'arrange-mixed-first','lines'=>[['report_item_id'=>$a['id'],'report_version'=>$f['report']['version'],'quantity'=>'100'],['report_item_id'=>$b['id'],'report_version'=>$r['version'],'quantity'=>'100']]]);
        self::assertNotFalse($p,PurchasePlanLogic::getError());
        self::assertSame('100斤＋100条',$p['quantity_summary']);
        self::assertCount(1,PurchasePlanLogic::preview(['id'=>$p['id'],'mode'=>'merged'])['sheets']);
        self::assertCount(2,PurchasePlanLogic::preview(['id'=>$p['id'],'mode'=>'customer'])['sheets']);

        // A matching display name does not make two different unit IDs equivalent.
        Db::name('purchase_plan_source')->where('tenant_id',self::TENANT_ID)->where('report_item_id',$b['id'])
            ->update(['unit_name'=>'斤']);
        $detail = PurchasePlanLogic::detail(['id'=>$p['id']]);
        self::assertNotFalse($detail,PurchasePlanLogic::getError());
        $lines = array_column($detail['lines'],null,'report_item_id');
        self::assertSame(true,$lines[$a['id']]['same_allocation_unit'] ?? null);
        self::assertSame(false,$lines[$b['id']]['same_allocation_unit'] ?? null);

        Db::name('customer_report_item')->where('tenant_id',self::TENANT_ID)->where('id',$a['id'])
            ->update(['base_unit_id'=>0]);
        $detail = PurchasePlanLogic::detail(['id'=>$p['id']]);
        self::assertNotFalse($detail,PurchasePlanLogic::getError());
        $lines = array_column($detail['lines'],null,'report_item_id');
        self::assertArrayHasKey('same_allocation_unit',$lines[$a['id']]);
        self::assertNull($lines[$a['id']]['same_allocation_unit']);
    }
    public function test_count_stock_verification_reserves_real_base_stock_and_only_uncovered_count_can_be_arranged():void {
        $f=$this->fixture();
        $r=$this->countReport($f);
        WarehouseSkuBalanceService::inbound($f['warehouse'],$f['sku'],'50');
        $f['report']=$r;
        self::assertFalse($this->arrange($f));
        $d=PurchasePlanLogic::demands(['report_id'=>$r['id']]);
        self::assertTrue($d['lists'][0]['stock_verification_required']);
        $req=['report_item_id'=>$r['items'][0]['id'],'report_version'=>$r['version'],'covered_qty'=>'25','base_qty'=>'50','reason'=>'现场核实25条共50斤','idempotency_key'=>'stock-verified-count-25'];
        $d=PurchasePlanLogic::verifyStock($req);
        self::assertNotFalse($d,PurchasePlanLogic::getError());
        self::assertSame('25.0000',$d['covered_qty']);
        self::assertSame('75.0000',$d['remaining_qty']);
        self::assertFalse($d['stock_verification_required']);
        self::assertSame('0.0000',WarehouseSkuBalanceService::available($f['warehouse'],$f['sku']));
        self::assertSame($d,PurchasePlanLogic::verifyStock($req));
        $f['report']['version']=$d['report_version'];
        self::assertNotFalse($this->arrange($f,'75'));
    }
    private function arrival(array $f,array $plan,array $rows,int $supplierId=0):array {
        $now=time();
        $batch=(int)Db::name('purchase_batch')->insertGetId(['tenant_id'=>self::TENANT_ID,'purchase_plan_id'=>$plan['id'],'batch_no'=>'PB-'.uniqid(),'warehouse_id'=>$f['warehouse'],'warehouse_name'=>'采购仓','datetimesingle'=>$now,'status'=>'submitted','idempotency_key'=>uniqid('batch-'),'request_fingerprint'=>hash('sha256',uniqid()),'admin_id'=>self::ADMIN_ID,'create_time'=>$now,'update_time'=>$now]);
        $order=(int)Db::name('supply_order')->insertGetId(['tenant_id'=>self::TENANT_ID,'order_sn'=>'PO-'.uniqid(),'warehouse_id'=>$f['warehouse'],'supplier_id'=>$supplierId,'create_time'=>$now,'update_time'=>$now]);
        Db::name('purchase_batch_supply_order')->insert(['tenant_id'=>self::TENANT_ID,'purchase_batch_id'=>$batch,'supply_order_id'=>$order,'create_time'=>$now,'update_time'=>$now]);
        $ids=[];
        foreach($rows as $r) {
            WarehouseSkuBalanceService::inbound($f['warehouse'],$r['sku_id'],$r['base_qty']);
            $ids[]=(int)Db::name('order_goods')->insertGetId(['tenant_id'=>self::TENANT_ID,'order_type'=>'supply','order_id'=>$order,'goods_id'=>$r['goods_id'],'sku_id'=>$r['sku_id'],'actual_base_qty'=>$r['base_qty'],'create_time'=>$now,'update_time'=>$now]);
        }
        self::assertNotFalse(Db::transaction(static fn()=>PurchasePlanLogic::holdArrivalWithinTransaction((int)$plan['id'],$batch)),PurchasePlanLogic::getError());
        return ['id'=>$batch,'rows'=>$ids,'order'=>$order];
    }
    public function test_shared_plan_keeps_both_arrivals_and_supplier_lists_scope_each_batch_before_paging():void
    {
        $fixture = $this->fixture();
        $plan = $this->arrange($fixture, '20');
        self::assertNotFalse($plan, PurchasePlanLogic::getError());
        $arrivals = [];
        foreach ([55001 => '12', 55002 => '8'] as $supplier => $quantity) {
            $arrivals[$supplier] = $this->arrival($fixture, $plan, [
                ['goods_id' => $fixture['goods'], 'sku_id' => $fixture['sku'], 'base_qty' => $quantity],
            ], $supplier);
        }
        $detail = PurchasePlanLogic::detail(['id' => $plan['id']]);
        self::assertNotFalse($detail, PurchasePlanLogic::getError());
        self::assertSame('20.0000', $detail['lines'][0]['planned_qty']);
        self::assertSame(['12.0000', '8.0000'], array_column($detail['arrivals'], 'arrival_qty'));
        self::assertSame(array_column($arrivals, 'id'), array_map('intval', array_column($detail['arrivals'], 'purchase_batch_id')));

        $originalRequest = request();
        try {
            foreach ($arrivals as $supplier => $arrival) {
                foreach ([1, 2] as $page) {
                    $request = app()->make('request', [], true)->setMethod('GET')->withGet([
                        'supplier_id' => $supplier, 'page_no' => $page, 'page_size' => 1,
                    ]);
                    $request->tenantId = self::TENANT_ID;
                    app()->instance('request', $request);
                    $list = new SupplyOrderLists();
                    self::assertSame(1, $list->count());
                    self::assertSame($page === 1 ? [$arrival['order']] : [], array_column($list->lists(), 'id'));
                }
            }
        } finally {
            app()->instance('request', $originalRequest);
        }
    }

    public function test_count_receipt_uses_real_weight_but_requires_explicit_count_coverage():void {
        $f=$this->fixture();
        $r=$this->countReport($f);
        $f['report']=$r;
        $p=$this->arrange($f);
        self::assertNotFalse($p,PurchasePlanLogic::getError());
        $a=$this->arrival($f,$p,[['goods_id'=>$f['goods'],'sku_id'=>$f['sku'],'base_qty'=>'180']]);
        self::assertSame('0.0000',WarehouseSkuBalanceService::available($f['warehouse'],$f['sku']));
        $req=['id'=>$p['id'],'version'=>$p['version'],'purchase_batch_id'=>$a['id'],'idempotency_key'=>'allocate-count-real-100','allocations'=>[['line_id'=>$p['lines'][0]['id'],'arrival_line_id'=>$a['rows'][0],'quantity'=>'100','base_qty'=>'180']],'surplus'=>[]];
        $saved=PurchasePlanLogic::attachArrival($req);
        self::assertNotFalse($saved,PurchasePlanLogic::getError());
        self::assertSame('100.0000',$saved['lines'][0]['fulfilled_qty']);
        self::assertSame('180.0000',$saved['lines'][0]['allocated_qty']);
        self::assertSame('complete',$saved['status']);
        self::assertSame($saved,PurchasePlanLogic::attachArrival($req));
    }
    public function test_bought_scope_still_receives_after_customer_cancellation_releases_only_unbought():void {
        $f=$this->fixture();
        $p=$this->arrange($f);
        self::assertNotFalse($p, PurchasePlanLogic::getError());
        $p = PurchasePlanLogic::recordBought([
            'id' => $p['id'], 'version' => $p['version'], 'idempotency_key' => 'bought-before-cancel',
            'lines' => [['line_id' => $p['lines'][0]['id'], 'quantity' => '60']],
        ]);
        self::assertNotFalse($p, PurchasePlanLogic::getError());
        $cancel=CustomerReportLogic::cancel(['id'=>$f['report']['id'],'version'=>$f['report']['version'],'reason'=>'客户取消']);
        self::assertNotFalse($cancel,CustomerReportLogic::getError());
        $p = PurchasePlanLogic::detail(['id' => $p['id']]);
        self::assertSame('60.0000', $p['lines'][0]['planned_qty']);
        self::assertTrue($p['lines'][0]['source_closed']);
        $a=$this->arrival($f,$p,[['goods_id'=>$f['goods'],'sku_id'=>$f['sku'],'base_qty'=>'60']]);
        $saved=PurchasePlanLogic::attachArrival(['id'=>$p['id'],'version'=>$p['version'],'purchase_batch_id'=>$a['id'],'idempotency_key'=>'allocate-cancelled-pending','allocations'=>[['line_id'=>$p['lines'][0]['id'],'arrival_line_id'=>$a['rows'][0],'quantity'=>'60','base_qty'=>'60']],'surplus'=>[]]);
        self::assertNotFalse($saved,PurchasePlanLogic::getError());
        $pending=\app\api\jxc\logic\PendingGoodsLogic::lists([]);
        self::assertSame('60.0000',$pending['lists'][0]['pending_qty']);
        self::assertSame('0.0000',WarehouseSkuBalanceService::available($f['warehouse'],$f['sku']));
    }
    public function test_zero_stock_coverage_is_a_real_verification_and_expires_when_stock_changes():void {
        $f=$this->fixture();
        $r=$this->countReport($f);
        $f['report']=$r;
        WarehouseSkuBalanceService::inbound($f['warehouse'],$f['sku'],'50');
        $req=['report_item_id'=>$r['items'][0]['id'],'report_version'=>$r['version'],'covered_qty'=>'0','base_qty'=>'0','reason'=>'现场50斤均不符合客户大小要求','idempotency_key'=>'verify-zero-compatible'];
        $d=PurchasePlanLogic::verifyStock($req);
        self::assertNotFalse($d,PurchasePlanLogic::getError());
        self::assertFalse($d['stock_verification_required']);
        self::assertSame('100.0000',$d['remaining_qty']);
        WarehouseSkuBalanceService::inbound($f['warehouse'],$f['sku'],'10');
        $d=PurchasePlanLogic::demands(['report_id'=>$r['id']]);
        self::assertTrue($d['lists'][0]['stock_verification_required']);
    }
    public function test_partial_paper_failure_retries_only_selected_sheet_without_new_demand():void {
        $f=$this->fixture();
        $r=$this->countReport($f);
        $p=PurchasePlanLogic::create(['warehouse_id'=>$f['warehouse'],'delivery_date'=>$r['delivery_date'],'idempotency_key'=>'paper-two-customer-plan','lines'=>[['report_item_id'=>$f['report']['items'][0]['id'],'report_version'=>$f['report']['version'],'quantity'=>'100'],['report_item_id'=>$r['items'][0]['id'],'report_version'=>$r['version'],'quantity'=>'100']]]);
        $req=['id'=>$p['id'],'version'=>1,'mode'=>'customer','idempotency_key'=>'prepare-two-customers'];
        $s=PurchasePlanLogic::preparePrint($req);
        self::assertNotFalse($s,PurchasePlanLogic::getError());
        self::assertCount(2,$s['sheets']);
        PurchasePlanLogic::printResult(['sheet_id'=>$s['sheets'][0]['id'],'success'=>1]);
        PurchasePlanLogic::printResult(['sheet_id'=>$s['sheets'][1]['id'],'success'=>0]);
        $retry=PurchasePlanLogic::preparePrint(['id'=>$p['id'],'version'=>1,'mode'=>'customer','idempotency_key'=>'retry-one-customer','sheet_keys'=>[$s['sheets'][1]['sheet_key']]]);
        self::assertCount(1,$retry['sheets']);
        self::assertSame(2,$retry['sheets'][0]['copy_no']);
        self::assertSame($s['sheets'][1]['sheet_key'],$retry['sheets'][0]['sheet_key']);
        self::assertSame(0,PurchasePlanLogic::demands([])['count']);
    }
    public function test_multi_sku_arrival_holds_and_allocations_do_not_mix_stock_units():void {
        $f=$this->fixture();
        $goods=$this->createCustomerReportGoods('采购桂鱼','PURCHASE-V2-OTHER');
        $sku=$this->customerReportSkuId($goods);
        $base=(int)Db::name('goods_sku')->where('id',$f['sku'])->value('base_unit_id');
        Db::name('goods')->where('id',$goods)->update(['unit_id'=>$base]);
        Db::name('goods_sku')->where('id',$sku)->update(['base_unit_id'=>$base,'base_unit_name'=>'斤']);
        Db::name('goods_units_binding')->insert(['tenant_id'=>self::TENANT_ID,'goods_id'=>$goods,'unit_id'=>$base,'unit_name'=>'斤','is_base_unit'=>1,'status'=>1]);
        $payload=$f['payload'];
        $payload['idempotency_key']='report-second-sku';
        $payload['items'][0]['goods_id']=$goods;
        $payload['items'][0]['sku_id']=$sku;
        $r=CustomerReportLogic::submit($payload);
        self::assertNotFalse($r,CustomerReportLogic::getError());
        $p=PurchasePlanLogic::create(['warehouse_id'=>$f['warehouse'],'delivery_date'=>$r['delivery_date'],'idempotency_key'=>'plan-multi-sku-arrival','lines'=>[['report_item_id'=>$f['report']['items'][0]['id'],'report_version'=>$f['report']['version'],'quantity'=>'100'],['report_item_id'=>$r['items'][0]['id'],'report_version'=>$r['version'],'quantity'=>'100']]]);
        self::assertNotFalse($p,PurchasePlanLogic::getError());
        $a=$this->arrival($f,$p,[['goods_id'=>$f['goods'],'sku_id'=>$f['sku'],'base_qty'=>'100'],['goods_id'=>$goods,'sku_id'=>$sku,'base_qty'=>'100']]);
        $saved=PurchasePlanLogic::attachArrival(['id'=>$p['id'],'version'=>1,'purchase_batch_id'=>$a['id'],'idempotency_key'=>'allocate-multi-sku','allocations'=>[['line_id'=>$p['lines'][0]['id'],'arrival_line_id'=>$a['rows'][0],'quantity'=>'100','base_qty'=>'100'],['line_id'=>$p['lines'][1]['id'],'arrival_line_id'=>$a['rows'][1],'quantity'=>'100','base_qty'=>'100']],'surplus'=>[]]);
        self::assertNotFalse($saved,PurchasePlanLogic::getError());
        self::assertSame('complete',$saved['status']);
        self::assertCount(2,$saved['arrivals']);
        self::assertSame('0.0000',WarehouseSkuBalanceService::available($f['warehouse'],$sku));
        self::assertSame('0.0000',WarehouseSkuBalanceService::available($f['warehouse'],$f['sku']));
    }
    public function test_reduction_changes_effective_purchase_need_and_active_instructions_block_direct_reduction():void {
        $this->prepareLegacyOperatingStore();
        $f=$this->fixture();
        $item=$f['report']['items'][0];
        $r=\app\api\jxc\logic\FulfillmentChangeLogic::reduceItem(['report_item_id'=>$item['id'],'processing_group_id'=>$item['processing_groups'][0]['id'],'new_expected_base_qty'=>'60','processed_reduction_qty'=>'0','reason'=>'客户减至60斤','idempotency_key'=>'reduce-before-arrange']);
        self::assertNotFalse($r,\app\api\jxc\logic\FulfillmentChangeLogic::getError());
        $d=PurchasePlanLogic::demands([]);
        self::assertSame('100.0000',$d['lists'][0]['demand_qty']);
        self::assertSame('60.0000',$d['lists'][0]['remaining_qty']);
        $f['report']['version']=$d['lists'][0]['report_version'];
        $p=$this->arrange($f,'60');
        self::assertNotFalse($p,PurchasePlanLogic::getError());
        $r=\app\api\jxc\logic\FulfillmentChangeLogic::reduceItem(['report_item_id'=>$item['id'],'processing_group_id'=>$item['processing_groups'][0]['id'],'new_expected_base_qty'=>'50','processed_reduction_qty'=>'0','reason'=>'客户再减','idempotency_key'=>'reduce-active-must-recall']);
        self::assertFalse($r);
        self::assertStringContainsString('采购',\app\api\jxc\logic\FulfillmentChangeLogic::getError());
    }
    public function test_same_arrival_can_be_partially_allocated_then_completed_with_new_verified_facts():void {
        $f=$this->fixture();
        $r=$this->countReport($f);
        $f['report']=$r;
        $p=$this->arrange($f);
        $a=$this->arrival($f,$p,[['goods_id'=>$f['goods'],'sku_id'=>$f['sku'],'base_qty'=>'180']]);
        $req=['id'=>$p['id'],'version'=>1,'purchase_batch_id'=>$a['id'],'idempotency_key'=>'partial-arrival-first','allocations'=>[['line_id'=>$p['lines'][0]['id'],'arrival_line_id'=>$a['rows'][0],'quantity'=>'50','base_qty'=>'90']]];
        $half=PurchasePlanLogic::attachArrival($req);
        self::assertNotFalse($half,PurchasePlanLogic::getError());
        self::assertSame('90.0000',$half['arrivals'][0]['remaining_qty']);
        $req['version']=$half['version'];
        $req['idempotency_key']='partial-arrival-second';
        $done=PurchasePlanLogic::attachArrival($req);
        self::assertNotFalse($done,PurchasePlanLogic::getError());
        self::assertSame('complete',$done['status']);
        self::assertSame('100.0000',$done['lines'][0]['fulfilled_qty']);
    }
    public function test_cancellation_releases_unbought_and_closed_report_never_reappears_as_a_demand():void {
        $f=$this->fixture();
        $p=$this->arrange($f);
        self::assertNotFalse($p,PurchasePlanLogic::getError());
        $r=CustomerReportLogic::cancel(['id'=>$f['report']['id'],'version'=>$f['report']['version'],'reason'=>'客户取消且没有已买']);
        self::assertNotFalse($r,CustomerReportLogic::getError());
        self::assertSame('cancelled', PurchasePlanLogic::detail(['id' => $p['id']])['status']);
        self::assertSame(0,PurchasePlanLogic::demands([])['count']);
    }
    public function test_tenant_and_electronic_permissions_are_checked_on_plan_reads_and_print_recovery():void {
        $f=$this->fixture();
        $p=$this->arrange($f);
        $s=PurchasePlanLogic::preparePrint(['id'=>$p['id'],'version'=>1,'mode'=>'merged','idempotency_key'=>'prepare-permission-sheet']);
        self::assertNotFalse($s,PurchasePlanLogic::getError());
        $this->prepareCustomerReportRequestContext(self::OTHER_TENANT_ID);
        self::assertFalse(PurchasePlanLogic::detail(['id'=>$p['id']]));
        self::assertFalse(PurchasePlanLogic::printResult(['sheet_id'=>$s['sheets'][0]['id'],'success'=>1]));
        $this->prepareCustomerReportRequestContext();
        request()->adminInfo=['root'=>0,'tenant_id'=>self::TENANT_ID];
        request()->userId=0;
        request()->adminId=0;
        self::assertFalse(PurchasePlanLogic::preparePrint(['id'=>$p['id'],'version'=>1,'mode'=>'merged','idempotency_key'=>'different-recovery-key']));
        $this->prepareCustomerReportRequestContext();
    }
    public function test_fixed_weight_conversion_cannot_be_claimed_with_wrong_base_quantity():void {
        $f=$this->fixture();
        $kg=$this->createCustomerReportUnit('公斤');
        Db::name('goods_units_binding')->insert(['tenant_id'=>self::TENANT_ID,'goods_id'=>$f['goods'],'unit_id'=>$kg,'unit_name'=>'公斤','status'=>1,'is_base_unit'=>0]);
        $payload=$f['payload'];
        $payload['idempotency_key']='weight-kilogram-source';
        $payload['items'][0]['unit_id']=$kg;
        $payload['items'][0]['unit_name']='公斤';
        $r=CustomerReportLogic::submit($payload);
        self::assertNotFalse($r,CustomerReportLogic::getError());
        $f['report']=$r;
        $p=$this->arrange($f);
        self::assertNotFalse($p,PurchasePlanLogic::getError());
        $a=$this->arrival($f,$p,[['goods_id'=>$f['goods'],'sku_id'=>$f['sku'],'base_qty'=>'200']]);
        $req=['id'=>$p['id'],'version'=>1,'purchase_batch_id'=>$a['id'],'idempotency_key'=>'allocate-weight-kg','allocations'=>[['line_id'=>$p['lines'][0]['id'],'arrival_line_id'=>$a['rows'][0],'quantity'=>'100','base_qty'=>'100']]];
        self::assertFalse(PurchasePlanLogic::attachArrival($req));
        $req['allocations'][0]['base_qty']='200';
        $saved=PurchasePlanLogic::attachArrival($req);
        self::assertNotFalse($saved,PurchasePlanLogic::getError());
        self::assertSame('complete',$saved['status']);
    }
    public function test_two_real_connections_cannot_arrange_overlapping_source_quantities():void {
        $f=$this->fixture();
        $paths=[];
        $start=tempnam(sys_get_temp_dir(),'purchase-start-');
        unlink($start);
        $workers=[];
        try {
            foreach(['procurement-concurrent-a','procurement-concurrent-b']as$key) {
                $input=tempnam(sys_get_temp_dir(),'purchase-in-');
                $output=tempnam(sys_get_temp_dir(),'purchase-out-');
                $paths[]=$input;
                $paths[]=$output;
                file_put_contents($input,json_encode(['tenant_id'=>self::TENANT_ID,'admin_id'=>self::ADMIN_ID,'request'=>['warehouse_id'=>$f['warehouse'],'delivery_date'=>$f['report']['delivery_date'],'idempotency_key'=>$key,'lines'=>[['report_item_id'=>$f['report']['items'][0]['id'],'report_version'=>$f['report']['version'],'quantity'=>'60']]]]));
                $process=proc_open([PHP_BINARY,dirname(__DIR__).'/fixtures/purchase_arrangement_worker.php',$input,$output,$start],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
                $workers[]=[$process,$pipes,$output];
            }
            touch($start);
            $results=[];
            foreach($workers as[$process,$pipes,$output]) {
                self::assertIsResource($process);
                $stdout=stream_get_contents($pipes[1]);
                $stderr=stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0,proc_close($process),$stdout.$stderr);
                $results[]=json_decode(file_get_contents($output),true);
            }
            self::assertCount(1,array_filter($results,static fn($r)=>is_array($r['result'])),json_encode($results,JSON_UNESCAPED_UNICODE));
            self::assertSame('40.0000',PurchasePlanLogic::demands([])['lists'][0]['remaining_qty']);
        } finally {
            if(is_file($start)) {
                unlink($start);
            }
            foreach($paths as$path) {
                if(is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
    public function test_pending_print_is_recoverable_after_all_goods_are_recorded_bought():void {
        $f=$this->fixture();
        $p=$this->arrange($f);
        $s=PurchasePlanLogic::preparePrint(['id'=>$p['id'],'version'=>1,'mode'=>'merged','idempotency_key'=>'prepared-before-all-bought']);
        $b=PurchasePlanLogic::recordBought(['id'=>$p['id'],'version'=>1,'idempotency_key'=>'record-all-bought-before-receipt','lines'=>[['line_id'=>$p['lines'][0]['id'],'quantity'=>'100']]]);
        self::assertNotFalse($b,PurchasePlanLogic::getError());
        $pending=PurchasePlanLogic::preparePrint(['id'=>$p['id'],'version'=>$b['version'],'mode'=>'merged','idempotency_key'=>'recover-from-other-device']);
        self::assertNotFalse($pending,PurchasePlanLogic::getError());
        self::assertTrue($pending['recover_only']);
        self::assertSame($s['sheets'][0]['id'],$pending['sheets'][0]['id']);
        self::assertNotFalse(PurchasePlanLogic::printResult(['sheet_id'=>$pending['sheets'][0]['id'],'success'=>0]));
        self::assertFalse(PurchasePlanLogic::preview(['id'=>$p['id'],'mode'=>'merged']));
    }
    public function test_pending_paper_is_recoverable_after_all_items_are_recorded_bought():void {
        $f=$this->fixture();
        $p=$this->arrange($f);
        $papers=PurchasePlanLogic::preparePrint(['id'=>$p['id'],'version'=>1,'mode'=>'merged','idempotency_key'=>'prepare-before-all-bought']);
        self::assertNotFalse($papers,PurchasePlanLogic::getError());
        $bought=PurchasePlanLogic::recordBought(['id'=>$p['id'],'version'=>1,'idempotency_key'=>'record-bought-full-plan','lines'=>[['line_id'=>$p['lines'][0]['id'],'quantity'=>'100']]]);
        self::assertNotFalse($bought,PurchasePlanLogic::getError());
        $recovery=PurchasePlanLogic::preparePrint(['id'=>$p['id'],'version'=>1,'mode'=>'merged','idempotency_key'=>'recovery-different-device']);
        self::assertNotFalse($recovery,PurchasePlanLogic::getError());
        self::assertTrue($recovery['recover_only']);
        self::assertSame($papers['sheets'][0]['id'],$recovery['sheets'][0]['id']);
        self::assertNotFalse(PurchasePlanLogic::printResult(['sheet_id'=>$papers['sheets'][0]['id'],'success'=>0]));
        self::assertFalse(PurchasePlanLogic::preview(['id'=>$p['id'],'mode'=>'merged']));
        self::assertStringContainsString('买齐',PurchasePlanLogic::getError());
    }
}
