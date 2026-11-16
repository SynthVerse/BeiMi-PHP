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
        $unit=$this->createCustomerReportUnit('斤'); $goods=$this->createCustomerReportGoods('整单交付商品','EARLY-SALE','斤');
        $sku=$this->customerReportSkuId($goods); $warehouse=$this->createCustomerReportWarehouse('开单仓');
        Db::name('goods')->where('id',$goods)->update(['unit_id'=>$unit]);
        Db::name('goods_sku')->where('id',$sku)->update(['base_unit_id'=>$unit,'base_unit_name'=>'斤']);
        Db::name('goods_units_binding')->insert(['tenant_id'=>self::TENANT_ID,'goods_id'=>$goods,'unit_id'=>$unit,'unit_name'=>'斤','is_base_unit'=>1,'status'=>1]);
        WarehouseSkuBalanceService::inbound($warehouse,$sku,$stockQuantity);
        $customer=$this->createCustomer('整单交付客户');
        $process=(int)Db::name('work_process')->where('tenant_id',self::TENANT_ID)->where('trigger_type','report_selection')->value('id');
        $report=CustomerReportLogic::submit(['main_customer_id'=>$customer,'delivery_date'=>date('Y-m-d'),'delivery_arrangement'=>['delivery_method'=>'self_delivery'],'idempotency_key'=>'early-report',
            'items'=>[['goods_id'=>$goods,'sku_id'=>$sku,'warehouse_id'=>$warehouse,'unit_id'=>$unit,'unit_name'=>'斤','order_qty'=>'10','price_status'=>'unpriced',
                'processing_groups'=>[['group_key'=>'first','planned_qty'=>'4','process_ids'=>[$process]],['group_key'=>'second','planned_qty'=>'6','process_ids'=>[$process]]]]]]);
        self::assertNotFalse($report,CustomerReportLogic::getError());
        self::assertSame(2, (int)$report['items'][0]['quantity_contract_version']);
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
    private function command(array $f, string $intent = 'save_and_deliver'): array
    {
        $sale = $this->issue($f);
        return ['id' => $sale['id'], 'version' => $sale['version'], 'workspace_version' => $f['report']['workspace']['version'],
            'idempotency_key' => 'whole-report-command', 'intent' => $intent, 'sales' => ['document_version' => 0, 'lines' => $sale['lines'], 'rounding_amount' => '0']];
    }

    public function test_all_measured_groups_deliver_and_post_as_one_command(): void
    {
        $f = $this->fixture(); $params = $this->command($f);
        $saved = CustomerReportLogic::saveWorkspace($params);
        self::assertNotFalse($saved, CustomerReportLogic::getError());
        self::assertSame('posted', $saved['sales_document']['accounting_status']);
        self::assertSame('200.00', $saved['sales_document']['amount']);
        self::assertSame('90.0000', WarehouseSkuBalanceService::onHand($f['warehouse'], $f['sku']));
        self::assertNotFalse(CustomerReportLogic::saveWorkspace($params));
        self::assertSame('90.0000', WarehouseSkuBalanceService::onHand($f['warehouse'], $f['sku']));
    }

    public function test_unmeasured_second_group_prevents_whole_delivery_without_partial_stock(): void
    {
        $f = $this->fixture(false); self::assertFalse(CustomerReportLogic::saveWorkspace($this->command($f)));
        self::assertSame('100.0000', WarehouseSkuBalanceService::onHand($f['warehouse'], $f['sku']));
    }

    public function test_fully_priced_draft_is_not_a_printable_sales_document(): void
    {
        $f = $this->fixture(); $saved = CustomerReportLogic::saveWorkspace($this->command($f, 'save'));
        self::assertNotFalse($saved, CustomerReportLogic::getError()); self::assertNull($saved['sales_document']);
        self::assertSame('20.00', $saved['workspace']['input']['sales']['lines'][0]['price']);
        self::assertSame('100.0000', WarehouseSkuBalanceService::onHand($f['warehouse'], $f['sku']));
    }

    public function test_delivery_permission_is_not_implied_by_sales_permission(): void
    {
        $f = $this->fixture();
        request()->adminInfo = array_merge(request()->adminInfo, ['root' => 0]);
        request()->userId = 0;
        request()->todoPermissionSnapshot = ['tenant_id'=>self::TENANT_ID,'operator_id'=>self::ADMIN_ID,'identity'=>'tenant_admin','owner'=>false,'permissions'=>['settlement.bill','settlement.view']];
        self::assertFalse(CustomerReportLogic::saveWorkspace($this->command($f)));
        self::assertSame('100.0000', WarehouseSkuBalanceService::onHand($f['warehouse'], $f['sku']));
    }

    public function test_delivery_employee_can_deliver_saved_facts_without_editing_quantities_or_prices(): void
    {
        $f = $this->fixture();
        $employee = (int)Db::name('employee')->insertGetId(['tenant_id' => self::TENANT_ID, 'name' => '交付员工',
            'mobile' => '13800004291', 'bind_user_id' => self::ADMIN_ID, 'is_enabled' => 1, 'create_time' => time(), 'update_time' => time()]);
        Db::name('employee_permission')->insert(['tenant_id' => self::TENANT_ID, 'employee_id' => $employee, 'permission_key' => 'delivery.confirm']);
        request()->adminInfo = array_merge(request()->adminInfo, ['root' => 0]);
        request()->todoPermissionSnapshot = ['tenant_id'=>self::TENANT_ID,'operator_id'=>self::ADMIN_ID,'identity'=>'tenant_admin','owner'=>false,'permissions'=>['delivery.confirm']];
        $params = $this->command($f); unset($params['sales']);
        $saved = CustomerReportLogic::saveWorkspace($params);
        self::assertNotFalse($saved, CustomerReportLogic::getError());
        self::assertSame('delivered_pending_settlement', $saved['status']);
        self::assertNull($saved['sales_document']);
        self::assertSame('90.0000', WarehouseSkuBalanceService::onHand($f['warehouse'], $f['sku']));
        $params['idempotency_key'] = 'employee-cannot-price'; $params['sales'] = $this->command($f)['sales'];
        self::assertFalse(CustomerReportLogic::saveWorkspace($params));
    }
}
