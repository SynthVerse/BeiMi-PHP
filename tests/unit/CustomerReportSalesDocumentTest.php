<?php
declare(strict_types=1);
namespace tests\unit;

use app\api\jxc\logic\CustomerReportLogic;
use app\api\jxc\logic\DeliveryInventoryLogic;
use app\api\jxc\logic\WarehouseSkuBalanceService;
use app\api\jxc\logic\WorkforceLogic;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

require_once __DIR__ . '/CustomerReportTestSupport.php';

final class CustomerReportSalesDocumentTest extends TestCase
{
    use CustomerReportTestSupport;
    protected function setUp(): void
    {
        $this->prepareCustomerReportRequestContext(); $this->ensureCustomerReportTables(); $this->cleanCustomerReportData();
        foreach (glob(dirname(__DIR__,2).'/database/migrations/*finance*.sql') as $file) {
            if (str_ends_with($file,'20261015_000002_finance_draft_continuation.sql') && Db::query("SHOW COLUMNS FROM la_finance_document LIKE 'resume_requested'")) continue;
            $this->runStatements($this->prepareMigration((string)file_get_contents($file)));
        }
        $this->cleanFinance();
        Db::name('finance_preparation')->insert(['tenant_id'=>self::TENANT_ID,'activation_date'=>date('Y-m-01')]);
        Db::name('finance_opening_book')->insert(['tenant_id'=>self::TENANT_ID,'status'=>'active','reviews'=>'{}','confirmed_snapshot'=>'{}','created_by'=>'{}','last_modified_by'=>'{}','create_time'=>time(),'update_time'=>time()]);
        WorkforceLogic::saveProcess(['name'=>'直接备货','trigger_type'=>'report_selection','is_enabled'=>1,'sort'=>10]);
        WorkforceLogic::saveProcess(['name'=>'送货','trigger_type'=>'all_processing_completed','is_enabled'=>1,'sort'=>900]);
    }
    protected function tearDown(): void { request()->todoPermissionSnapshot=null; $this->prepareCustomerReportRequestContext(); $this->cleanFinance(); $this->cleanCustomerReportData(); }
    private function cleanFinance(): void
    {
        foreach(['finance_customer_terms','finance_correction','finance_sales_pricing_fact','finance_sales_group_coverage','finance_sales_coverage','finance_entry','finance_source','finance_document','finance_command','finance_opening_source','finance_opening_book','finance_preparation'] as $table) Db::name($table)->where('tenant_id',self::TENANT_ID)->delete();
    }
    private function fixture(bool $allMeasured=true,string $stockQuantity='100'): array
    {
        $unit=$this->createCustomerReportUnit('斤'); $goods=$this->createCustomerReportGoods('提前开单商品','EARLY-SALE','斤');
        $sku=$this->customerReportSkuId($goods); $warehouse=$this->createCustomerReportWarehouse('开单仓');
        Db::name('goods')->where('id',$goods)->update(['unit_id'=>$unit]);
        Db::name('goods_sku')->where('id',$sku)->update(['base_unit_id'=>$unit,'base_unit_name'=>'斤']);
        Db::name('goods_units_binding')->insert(['tenant_id'=>self::TENANT_ID,'goods_id'=>$goods,'unit_id'=>$unit,'unit_name'=>'斤','is_base_unit'=>1,'status'=>1]);
        WarehouseSkuBalanceService::inbound($warehouse,$sku,$stockQuantity);
        $customer=$this->createCustomer('提前开单客户');
        $process=(int)Db::name('work_process')->where('tenant_id',self::TENANT_ID)->where('trigger_type','report_selection')->value('id');
        $report=CustomerReportLogic::submit(['quantity_contract_version'=>2,'main_customer_id'=>$customer,'delivery_date'=>date('Y-m-d'),'delivery_arrangement'=>['delivery_method'=>'self_delivery'],'idempotency_key'=>'early-report',
            'items'=>[['goods_id'=>$goods,'sku_id'=>$sku,'warehouse_id'=>$warehouse,'unit_id'=>$unit,'unit_name'=>'斤','order_qty'=>'10','price_status'=>'unpriced',
                'processing_groups'=>[['group_key'=>'first','planned_qty'=>'4','process_ids'=>[$process]],['group_key'=>'second','planned_qty'=>'6','process_ids'=>[$process]]]]]]);
        self::assertNotFalse($report,CustomerReportLogic::getError());
        $groups=$report['items'][0]['processing_groups'];
        $measurements=[['id'=>$groups[0]['id'],'net_weight_input'=>['value'=>'4','unit'=>'斤'],'quantity_facts'=>[]]];
        if($allMeasured) $measurements[]=['id'=>$groups[1]['id'],'net_weight_input'=>['value'=>'6','unit'=>'斤'],'quantity_facts'=>[]];
        $report=CustomerReportLogic::saveProcessingWeights(['id'=>$report['id'],'version'=>$report['version'],'idempotency_key'=>'early-weights','groups'=>$measurements]);
        self::assertNotFalse($report,CustomerReportLogic::getError());
        return compact('report','unit','warehouse','sku','customer');
    }
    private function issue(array $f,string $key='early-issue'): array
    {
        $r=$f['report'];
        return ['id'=>$r['id'],'version'=>$r['version'],'idempotency_key'=>$key,'lines'=>[['report_item_id'=>$r['items'][0]['id'],'price_unit_id'=>$f['unit'],'settlement_quantity'=>'10','price'=>'20.00']],'rounding_amount'=>'0'];
    }
    public function test_detail_exposes_weight_adjustment_permission_and_preserves_server_authority(): void
    {
        $f=$this->fixture();
        $detail=CustomerReportLogic::detail(['id'=>$f['report']['id']]);
        self::assertNotFalse($detail,CustomerReportLogic::getError());
        self::assertTrue($detail['sales_options']['can_adjust_weight_quantity'] ?? false);

        request()->todoPermissionSnapshot=['tenant_id'=>self::TENANT_ID,'operator_id'=>self::ADMIN_ID,'identity'=>'tenant_admin','owner'=>false,'permissions'=>['settlement.bill','settlement.view']];
        $detail=CustomerReportLogic::detail(['id'=>$f['report']['id']]);
        self::assertNotFalse($detail,CustomerReportLogic::getError());
        self::assertTrue($detail['actions']['issue_sales_document']['allowed']);
        self::assertFalse($detail['sales_options']['can_adjust_weight_quantity']);
        $params=$this->issue($f,'unauthorized-weight-difference');
        $params['lines'][0]['settlement_quantity']='9';
        $params['lines'][0]['difference_reason']='客户约定按九斤收费';
        self::assertFalse(CustomerReportLogic::convert($params));
        self::assertStringContainsString('最高权限',CustomerReportLogic::getError());

        request()->todoPermissionSnapshot=null;
        $params['idempotency_key']='authorized-weight-difference';
        $issued=CustomerReportLogic::convert($params);
        self::assertNotFalse($issued,CustomerReportLogic::getError());
        self::assertSame('180.00',$issued['sales_document']['amount']);
        self::assertSame('10.0000',$issued['sales_document']['lines'][0]['actual_delivery_weight']);
    }
    public function test_shortage_detail_keeps_current_sales_contract_and_allows_pricing_before_delivery(): void
    {
        $f=$this->fixture(true,'5');
        $detail=CustomerReportLogic::detail(['id'=>$f['report']['id']]);
        self::assertNotFalse($detail,CustomerReportLogic::getError());
        self::assertSame('submitted_shortage',$detail['status']);
        self::assertArrayHasKey('sales_document',$detail);
        self::assertNull($detail['sales_document']);
        self::assertIsArray($detail['sales_options']);
        self::assertArrayHasKey('confirm_delivery',$detail['actions']);
        self::assertTrue($detail['actions']['issue_sales_document']['allowed']);
        self::assertSame('',$detail['actions']['issue_sales_document']['blocked_reason']);
        self::assertSame('weight',$detail['items'][0]['pricing_units'][0]['measure_type']);
        $issued=CustomerReportLogic::convert($this->issue($f,'issue-with-stock-shortage'));
        self::assertNotFalse($issued,CustomerReportLogic::getError());
        self::assertSame('pending_delivery',$issued['sales_document']['accounting_status']);

        request()->todoPermissionSnapshot=['tenant_id'=>self::TENANT_ID,'operator_id'=>self::ADMIN_ID,'identity'=>'tenant_admin','owner'=>false,'permissions'=>['settlement.view']];
        $readonly=CustomerReportLogic::detail(['id'=>$f['report']['id']]);
        self::assertNotFalse($readonly,CustomerReportLogic::getError());
        self::assertArrayHasKey('sales_options',$readonly);
        self::assertNull($readonly['sales_options']);
        self::assertFalse($readonly['actions']['issue_sales_document']['allowed']);
        self::assertSame('没有开销售单权限',$readonly['actions']['issue_sales_document']['blocked_reason']);
    }
    public function test_issue_before_unprinted_delivery_does_not_move_inventory_or_create_receivable(): void
    {
        $f=$this->fixture(); $params=$this->issue($f); $issued=CustomerReportLogic::convert($params);
        self::assertNotFalse($issued,CustomerReportLogic::getError());
        self::assertSame('200.00',$issued['sales_document']['amount']);
        self::assertSame('pending_delivery',$issued['sales_document']['accounting_status']);
        self::assertSame('100.0000',WarehouseSkuBalanceService::onHand($f['warehouse'],$f['sku']));
        self::assertSame('0.00',bcadd((string)Db::name('customer')->where('id',$f['customer'])->value('order_receivable'),'0',2));
        self::assertSame(0,Db::name('fulfillment_delivery_event')->where('tenant_id',self::TENANT_ID)->count());
        $replay=CustomerReportLogic::convert($params); self::assertNotFalse($replay,CustomerReportLogic::getError());
        self::assertSame($issued['sales_document']['id'],$replay['sales_document']['id']);
        $params['lines'][0]['price']='21'; self::assertFalse(CustomerReportLogic::convert($params));
    }
    public function test_revision_retains_number_and_requires_version_and_reason(): void
    {
        $f=$this->fixture(); $issued=CustomerReportLogic::convert($this->issue($f)); self::assertNotFalse($issued,CustomerReportLogic::getError());
        $params=$this->issue($f,'early-revision'); $params['document_version']=1; $params['revision_reason']='客户确认新单价'; $params['lines'][0]['price']='21';
        $revised=CustomerReportLogic::convert($params); self::assertNotFalse($revised,CustomerReportLogic::getError());
        self::assertSame($issued['sales_document']['order_sn'],$revised['sales_document']['order_sn']);
        self::assertSame(2,$revised['sales_document']['version']); self::assertSame('210.00',$revised['sales_document']['amount']);
        self::assertCount(2,$revised['sales_document']['revisions']);
        $params['idempotency_key']='stale-revision'; self::assertFalse(CustomerReportLogic::convert($params));
    }
    private function deliver(array $report,int $groupId,string $key): array
    {
        $task=array_values(array_filter($report['task_group']['tasks'],static fn($t)=>$t['source_key']==='report:'.$report['id'].':delivery'))[0];
        $group=array_values(array_filter($report['items'][0]['processing_groups'],static fn($g)=>(int)$g['id']===$groupId))[0];
        $remaining=count(array_filter($report['items'][0]['processing_groups'],static fn($g)=>empty($g['delivered'])&&(int)$g['id']!==$groupId&&bccomp((string)($g['inventory_base_quantity']??'0'),'0',4)>0))>0?'pending':'none';
        $result=DeliveryInventoryLogic::confirmSelfDelivery(['task_id'=>(int)$task['id'],'idempotency_key'=>$key,'event_type'=>'customer_handoff',
            'items'=>[['report_item_id'=>(int)$report['items'][0]['id'],'delivery_group_ids'=>[$groupId],'facts_version'=>(int)$group['version'],'remaining_action'=>$remaining]]]);
        self::assertNotFalse($result,DeliveryInventoryLogic::getError()); return $result;
    }
    public function test_partial_delivery_stock_only_then_whole_report_posts_once_to_active_ledger(): void
    {
        $f=$this->fixture(); $issued=CustomerReportLogic::convert($this->issue($f)); self::assertNotFalse($issued,CustomerReportLogic::getError());
        $groups=$issued['items'][0]['processing_groups'];
        $this->deliver($issued,(int)$groups[0]['id'],'first-delivery');
        self::assertSame('96.0000',WarehouseSkuBalanceService::onHand($f['warehouse'],$f['sku']));
        self::assertSame(0,Db::name('finance_source')->where('tenant_id',self::TENANT_ID)->where('category','receivable')->count());
        $fresh=CustomerReportLogic::detail(['id'=>$issued['id']]);
        self::assertSame('pending_delivery',$fresh['sales_document']['accounting_status']);
        $this->deliver($fresh,(int)$groups[1]['id'],'last-delivery');
        $posted=CustomerReportLogic::detail(['id'=>$issued['id']]);
        self::assertSame('posted',$posted['sales_document']['accounting_status'],$posted['sales_document']['blocked_reason']);
        self::assertSame('90.0000',WarehouseSkuBalanceService::onHand($f['warehouse'],$f['sku']));
        self::assertSame('200.00',bcadd((string)Db::name('finance_source')->where('tenant_id',self::TENANT_ID)->where('category','receivable')->sum('amount'),'0',2));
        $count=Db::name('finance_source')->where('tenant_id',self::TENANT_ID)->count();
        \app\api\jxc\logic\CustomerReportSalesDocument::afterReportChange((int)$issued['id']);
        self::assertSame($count,Db::name('finance_source')->where('tenant_id',self::TENANT_ID)->count());
        self::assertSame(0,(int)Db::name('fulfillment_task')->where('tenant_id',self::TENANT_ID)->sum('print_count'));
    }
    private function deliverAll(array $report,string $key): array
    {
        $task=array_values(array_filter($report['task_group']['tasks'],static fn($t)=>$t['source_key']==='report:'.$report['id'].':delivery'))[0];
        $items=[];
        foreach($report['items'] as $item) {
            if($item['fulfillment_status']==='undelivered') continue;
            $groups=array_values(array_filter($item['processing_groups'],static fn($g)=>empty($g['delivered'])));
            if(!$groups) continue;
            $items[]=['report_item_id'=>(int)$item['id'],'delivery_group_ids'=>array_map('intval',array_column($groups,'id')),'facts_version'=>(int)$groups[0]['version'],'remaining_action'=>'none'];
        }
        $result=DeliveryInventoryLogic::confirmSelfDelivery(['task_id'=>(int)$task['id'],'idempotency_key'=>$key,'event_type'=>'customer_handoff','items'=>$items]);
        self::assertNotFalse($result,DeliveryInventoryLogic::getError()); return $result;
    }
    public function test_withdrawn_approval_keeps_delivered_stock_but_stops_receivable(): void
    {
        $f=$this->fixture(); $issued=CustomerReportLogic::convert($this->issue($f)); self::assertNotFalse($issued,CustomerReportLogic::getError());
        request()->todoPermissionSnapshot=['tenant_id'=>self::TENANT_ID,'operator_id'=>self::ADMIN_ID,'identity'=>'tenant_admin','owner'=>false,'permissions'=>['delivery.confirm']];
        $this->deliverAll($issued,'approval-withdrawn-delivery');
        request()->todoPermissionSnapshot=null;
        $fresh=CustomerReportLogic::detail(['id'=>$issued['id']]);
        self::assertSame('review_required',$fresh['sales_document']['accounting_status']);
        self::assertSame('90.0000',WarehouseSkuBalanceService::onHand($f['warehouse'],$f['sku']));
        self::assertSame(0,Db::name('finance_source')->where('tenant_id',self::TENANT_ID)->where('category','receivable')->count());
        $retry=CustomerReportLogic::retrySalesPosting(['id'=>$issued['id'],'document_version'=>1]);
        self::assertNotFalse($retry,CustomerReportLogic::getError());
        self::assertSame('posted',$retry['sales_document']['accounting_status'],$retry['sales_document']['blocked_reason']);
        self::assertSame('90.0000',WarehouseSkuBalanceService::onHand($f['warehouse'],$f['sku']));
    }
    public function test_explicit_unagreed_payment_day_survives_posting_and_changed_default(): void
    {
        $f=$this->fixture(); $params=$this->issue($f); $params['due_date']=null;
        $issued=CustomerReportLogic::convert($params); self::assertNotFalse($issued,CustomerReportLogic::getError());
        \app\api\jxc\logic\FinanceSalesRules::save(['expected_tenant_id'=>self::TENANT_ID,'customer_id'=>$f['customer'],'expected_version'=>0,'idempotency_key'=>'payment-rule-after-early-issue',
            'mode'=>'days_after','days'=>5,'reason'=>'后续单据采用新付款规则']);
        $this->deliverAll($issued,'explicit-unagreed-delivery');
        $fresh=CustomerReportLogic::detail(['id'=>$issued['id']]);
        self::assertSame('posted',$fresh['sales_document']['accounting_status'],$fresh['sales_document']['blocked_reason']);
        self::assertNull($fresh['sales_document']['due_date']);
        foreach(Db::name('finance_source')->where('tenant_id',self::TENANT_ID)->where('category','receivable')->select()->toArray() as $source) self::assertNull($source['due_date']);
    }
    public function test_cancel_remaining_after_partial_delivery_requires_revision_then_posts_only_delivered_amount(): void
    {
        $f=$this->fixture(); $issued=CustomerReportLogic::convert($this->issue($f)); self::assertNotFalse($issued,CustomerReportLogic::getError());
        $this->deliver($issued,(int)$issued['items'][0]['processing_groups'][0]['id'],'partial-before-cancel');
        $cancelled=\app\api\jxc\logic\FulfillmentChangeLogic::markUndelivered(['report_item_id'=>(int)$issued['items'][0]['id'],'idempotency_key'=>'cancel-remaining-only','reason_code'=>'customer_cancel','reason'=>'客户取消未交剩余六斤']);
        self::assertNotFalse($cancelled,\app\api\jxc\logic\FulfillmentChangeLogic::getError());
        $fresh=CustomerReportLogic::detail(['id'=>$issued['id']]);
        self::assertSame('4.0000',bcadd($fresh['items'][0]['fulfilled_base_qty'],'0',4));
        self::assertSame('6.0000',bcadd($fresh['items'][0]['undelivered_total_qty'],'0',4));
        self::assertSame('review_required',$fresh['sales_document']['accounting_status']);
        self::assertSame('96.0000',WarehouseSkuBalanceService::onHand($f['warehouse'],$f['sku']));
        $f['report']=$fresh; $params=$this->issue($f,'cancel-revision'); $params['document_version']=1; $params['revision_reason']='取消六斤，仅按实交四斤结算'; $params['lines'][0]['settlement_quantity']='4';
        $revised=CustomerReportLogic::convert($params); self::assertNotFalse($revised,CustomerReportLogic::getError());
        self::assertSame('80.00',$revised['sales_document']['amount']);
        self::assertSame('posted',$revised['sales_document']['accounting_status'],$revised['sales_document']['blocked_reason']);
        self::assertSame('80.00',bcadd((string)Db::name('finance_source')->where('tenant_id',self::TENANT_ID)->where('category','receivable')->sum('amount'),'0',2));
    }
    public function test_replaced_report_item_after_issue_cannot_be_omitted_from_automatic_posting(): void
    {
        $f=$this->fixture(); $issued=CustomerReportLogic::convert($this->issue($f)); self::assertNotFalse($issued,CustomerReportLogic::getError());
        $original=$issued['items'][0];
        $line=$original; $line['processing_groups']=array_map(static fn($g)=>['group_key'=>$g['group_key'],'planned_qty'=>$g['planned_qty'],'process_ids'=>array_map('intval',array_column($g['processes'],'process_id'))],$original['processing_groups']);
        $extra=$line; unset($extra['id']);
        $edited=CustomerReportLogic::edit(['id'=>$issued['id'],'version'=>$issued['version'],'main_customer_id'=>$f['customer'],'items'=>[$extra]]);
        self::assertNotFalse($edited,CustomerReportLogic::getError()); self::assertCount(1,$edited['items']);
        $weights=[];
        foreach($edited['items'] as $item) foreach($item['processing_groups'] as $group) $weights[]=['id'=>$group['id'],'net_weight_input'=>['value'=>(string)$group['planned_qty'],'unit'=>'斤'],'quantity_facts'=>[]];
        $saved=CustomerReportLogic::saveProcessingWeights(['id'=>$edited['id'],'version'=>$edited['version'],'idempotency_key'=>'weight-new-full-set','groups'=>$weights]);
        self::assertNotFalse($saved,CustomerReportLogic::getError());
        $this->deliverAll($saved,'deliver-new-full-set');
        $fresh=CustomerReportLogic::detail(['id'=>$issued['id']]);
        self::assertSame('review_required',$fresh['sales_document']['accounting_status']);
        self::assertStringContainsString('商品组成',$fresh['sales_document']['blocked_reason']);
        self::assertSame('90.0000',WarehouseSkuBalanceService::onHand($f['warehouse'],$f['sku']));
        self::assertSame(0,Db::name('finance_source')->where('tenant_id',self::TENANT_ID)->where('category','receivable')->count());
    }
    public function test_cancel_unmeasured_remaining_demand_keeps_real_stock_and_allows_final_invoice(): void
    {
        $f=$this->fixture(false); $report=$f['report'];
        self::assertTrue((bool)$report['items'][0]['has_unmeasured_groups']);
        $this->deliver($report,(int)$report['items'][0]['processing_groups'][0]['id'],'partial-before-unmeasured-cancel');
        $before=CustomerReportLogic::detail(['id'=>$report['id']]);
        $actualGroup=$before['items'][0]['processing_groups'][0]['quantity_facts_snapshot'];
        $cancelled=\app\api\jxc\logic\FulfillmentChangeLogic::markUndelivered(['report_item_id'=>(int)$report['items'][0]['id'],'idempotency_key'=>'cancel-unmeasured-demand','reason_code'=>'customer_cancel','reason'=>'客户不再需要尚未称重的剩余需求']);
        self::assertNotFalse($cancelled,\app\api\jxc\logic\FulfillmentChangeLogic::getError());
        $fresh=CustomerReportLogic::detail(['id'=>$report['id']]);
        self::assertSame('partial_pending_settlement',$fresh['status']);
        self::assertFalse((bool)$fresh['items'][0]['has_unmeasured_groups']);
        self::assertSame('0.0000',bcadd((string)$fresh['items'][0]['undelivered_total_qty'],'0',4));
        self::assertSame($actualGroup,$fresh['items'][0]['processing_groups'][0]['quantity_facts_snapshot']);
        self::assertSame('96.0000',WarehouseSkuBalanceService::onHand($f['warehouse'],$f['sku']));
        $after=json_decode($cancelled['change']['after_data'],true);
        self::assertSame('6.0000',json_decode($after['quantity_facts_snapshot'],true)['cancelled_order_quantity']);
        $f['report']=$fresh; $params=$this->issue($f,'issue-only-real-delivered'); $params['lines'][0]['settlement_quantity']='4';
        $issued=CustomerReportLogic::convert($params); self::assertNotFalse($issued,CustomerReportLogic::getError());
        self::assertSame('80.00',$issued['sales_document']['amount']);
        self::assertSame('posted',$issued['sales_document']['accounting_status'],$issued['sales_document']['blocked_reason']);
        self::assertSame('96.0000',WarehouseSkuBalanceService::onHand($f['warehouse'],$f['sku']));
    }
}
