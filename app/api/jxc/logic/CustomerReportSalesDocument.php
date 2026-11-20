<?php
declare(strict_types=1);
namespace app\api\jxc\logic;

use think\facade\Db;

/** 整单交付后签发销售单，并按已批准金额入账。 */
final class CustomerReportSalesDocument
{
    public const CLOSED_REPORT_STATUSES = ['delivered_pending_settlement','partial_pending_settlement','undelivered','fulfilled_undelivered','completed','settled'];
    private const TICKET_GOODS_NAME_LIMIT = 200;
    /** 与工作区数量保存共享事务；自动入账必须在外层提交后执行。 */
    public static function issueWithinTransaction(array $params): void
    {
        FinanceDocumentPolicy::authorize('sales_batch', true);
        $tenant=FinanceAccess::tenant(); $reportId=FinanceValue::id($params['id']??0);
        $key=FinanceValue::text($params['idempotency_key']??'',96);
        $fingerprint=hash('sha256',FinanceValue::json([$params,FinanceAccess::actor()]));
            FinanceIntegration::lock();
            $report=Db::name('customer_report')->where('tenant_id',$tenant)->where('id',$reportId)->whereNull('delete_time')->lock(true)->find();
            if(!$report) throw new \DomainException('报货单不存在');
            if (!in_array($report['status'], ['delivered_pending_settlement', 'settled', 'completed'], true)) throw new \DomainException('请先整单交付，再生成销售单');
            $doc=Db::name('customer_report_sales_document')->where('tenant_id',$tenant)->where('report_id',$reportId)->lock(true)->find();
            $replay=Db::name('customer_report_sales_revision')->where('tenant_id',$tenant)->where('operation_key',$key)->find();
            if($replay) {
                if(!$doc || (int)$replay['document_id']!==(int)$doc['id'] || !hash_equals($replay['fingerprint'],$fingerprint)) throw new \DomainException('同一提交标识不能用于不同销售内容');
                return;
            }
            if($report['status']==='cancelled') throw new \DomainException('已取消的报货不能生成销售单');
            if((int)$report['version']!==(int)($params['version']??0)) throw new \DomainException('报货数据已变化，请重新核对');
            if($doc && ($doc['accounting_status']==='posted' || $doc['status']==='voided')) throw new \DomainException('已入账或作废的销售单请使用正式更正流程');
            if($doc && (int)$doc['version']!==(int)($params['document_version']??0)) throw new \DomainException('销售单版本已变化，请重新核对');
            $reason=$doc?FinanceValue::text($params['revision_reason']??'',1000,false):'首次生成销售单';
            $items=Db::name('customer_report_item')->where('tenant_id',$tenant)->where('report_id',$reportId)->whereNull('delete_time')->order('sort,id')->lock(true)->select()->toArray();
            $snapshot=self::snapshot($report,$items,$params); $now=time(); $version=$doc?(int)$doc['version']+1:1;
            if(!$doc) {
                // 与真实交付沿用同一销售身份，但不向 order_goods 写入尚未交付的数量。
                $orders=[];
                foreach($items as $item) {
                    if(isset($orders[$item['warehouse_id']])) continue;
                    $order=SalesOrderLogic::ensurePendingDeliveryOrderWithinTransaction($report,(int)$item['warehouse_id']);
                    if($order===false) throw new \DomainException(SalesOrderLogic::getError());
                    $orders[$item['warehouse_id']]=$order;
                }
                $docId=(int)Db::name('customer_report_sales_document')->insertGetId(['tenant_id'=>$tenant,'report_id'=>$reportId,
                    'order_sn'=>reset($orders)['order_sn'],'version'=>$version,'status'=>'issued','accounting_status'=>'pending_posting',
                    'snapshot'=>FinanceValue::json($snapshot),'approval_actor'=>FinanceValue::json(FinanceAccess::actor()),'create_time'=>$now,'update_time'=>$now]);
            } else $docId=(int)$doc['id'];
            Db::name('sales_order')->where('tenant_id',$tenant)->where('source_type','customer_report')->where('source_id',$reportId)->where('settlement_status','pending')->update(['customer_id'=>(int)$report['main_customer_id'],'customer_name'=>$report['main_customer_name'],'update_time'=>$now]);
            Db::name('customer_report_sales_document')->where('tenant_id',$tenant)->where('id',$docId)->update([
                'version'=>$version,'snapshot'=>FinanceValue::json($snapshot),'approval_actor'=>FinanceValue::json(FinanceAccess::actor()),
                'accounting_status'=>'pending_posting','blocked_reason'=>'','last_operation_key'=>$key,'last_operation_fingerprint'=>$fingerprint,'update_time'=>$now]);
            Db::name('customer_report_sales_revision')->insert(['tenant_id'=>$tenant,'document_id'=>$docId,'version'=>$version,'snapshot'=>FinanceValue::json($snapshot),
                'reason'=>$reason,'actor'=>FinanceValue::json(FinanceAccess::actor()),'operation_key'=>$key,'fingerprint'=>$fingerprint,'create_time'=>$now]);
    }

    public static function inspectWorkspace(array $report, array $params): array
    {
        $items = Db::name('customer_report_item')->where('tenant_id', FinanceAccess::tenant())->where('report_id', $report['id'])
            ->whereNull('delete_time')->order('sort,id')->lock(true)->select()->toArray();
        return self::snapshot($report, $items, $params, true);
    }

    private static function snapshot(array $report,array $items,array $params,bool $allowIncomplete=false): array
    {
        $requests=$params['lines']??null;
        if(!is_array($requests)||!array_is_list($requests)||count($requests)>200) throw new \DomainException('请核对商品计价明细');
        foreach($requests as $request) { if(!is_array($request)) throw new \DomainException('商品计价明细格式无效'); FinanceValue::id($request['report_item_id']??0); }
        $requestCount=count($requests); $positions=array_flip(array_column($requests,'report_item_id')); $requests=array_column($requests,null,'report_item_id');
        if(count($requests)!==$requestCount) throw new \DomainException('商品计价明细不能重复'); $lines=[]; $goods='0.00'; $missing=[]; $ownerRequired=false;
        $precision=FinanceIntegration::active()?FinanceSalesPrecision::selection((int)$report['main_customer_id'],$params):['actual_mode'=>'cents'];
        $normalizedInput = $params;
        foreach($items as $item) {
            if($item['fulfillment_status']==='undelivered') continue;
            $id=(int)$item['id']; $input=$requests[$id]??null;
            if(!$input) throw new \DomainException('每个有效商品都必须明确计价');
            $path='sales.lines.'.($positions[$id]??0); $units=self::pricingUnits($item);
            $ticketName = CustomerReportWorkspace::field($path . '.ticket_goods_name',
                static fn() => self::ticketGoodsName($input['ticket_goods_name'] ?? '', $item['goods_name']));
            $unitId=CustomerReportWorkspace::blank($input['price_unit_id']??null)?null:FinanceValue::id($input['price_unit_id']);
            $unit=$unitId===null?null:(array_column($units,null,'id')[$unitId]??null);
            if($unitId!==null && !$unit) throw new \DomainException('计价单位不属于当前商品有效绑定');
            if(!$unit) $missing[]=['path'=>$path.'.price_unit_id','message'=>'请选择计价单位'];
            $mode = $input['quantity_mode'] ?? 'actual';
            if (!in_array($mode, ['actual', 'manual'], true)) throw new \DomainException('计价模式无效');
            $referenceUnitId = CustomerReportWorkspace::blank($input['reference_price_unit_id'] ?? $unitId) ? null : FinanceValue::id($input['reference_price_unit_id'] ?? $unitId);
            $referenceUnit = $referenceUnitId === null ? null : (array_column($units, null, 'id')[$referenceUnitId] ?? null);
            if ($referenceUnitId !== null && !$referenceUnit) throw new \DomainException('原计价单位不属于当前商品有效绑定');
            if (!$referenceUnit) $missing[] = ['path' => $path . '.reference_price_unit_id', 'message' => '请选择原计价单位'];
            $referencePriceInput = array_key_exists('reference_price', $input) ? $input['reference_price'] : ($input['price'] ?? null);
            $referencePrice = CustomerReportWorkspace::blank($referencePriceInput) ? null : CustomerReportWorkspace::field($path . '.reference_price', static fn() => FinanceValue::money($referencePriceInput, true));
            if ($mode === 'actual') { $unitId = $referenceUnitId; $unit = $referenceUnit; }
            if ($mode === 'manual') {
                if (!$unit || $unit['measure_type'] !== 'weight') throw new \DomainException('仅重量计价允许调整计价数量');
                FinanceAccess::require('', true); $ownerRequired = true;
            }
            $quantity=CustomerReportWorkspace::blank($input['settlement_quantity']??null)?null:CustomerReportWorkspace::field($path.'.settlement_quantity',static fn()=>FinanceSalesQuantity::quantity($input['settlement_quantity'],(int)($unit['precision']??4)));
            $price=CustomerReportWorkspace::blank($input['price']??null)?null:CustomerReportWorkspace::field($path.'.price',static fn()=>FinanceValue::money($input['price'],true));
            $zero=FinanceValue::text($input['zero_price_reason']??'',500,false);
            $difference=FinanceValue::text($input['difference_reason']??'',1000,false);
            $groups=Db::name('customer_report_processing_group')->where('tenant_id',FinanceAccess::tenant())->where('report_item_id',$id)->order('id')->select()->toArray();
            $facts=[]; $coverage=[]; $factCount=count($groups); $actual='0.0000'; $base='0.0000'; $hasWeight=true; $hasAllFacts=true;
            foreach($groups as $group) {
                $fact=FinanceValue::decode($group['quantity_facts_snapshot']?:'{}');
                if(!isset($fact['inventory_base_quantity']) || bccomp((string)$fact['inventory_base_quantity'],'0',4)<=0) { if(self::closed($report)) continue; $hasAllFacts=false; continue; }
                $actual=$fact['actual_delivery_weight']===null?$actual:bcadd($actual,$fact['actual_delivery_weight'],4);
                if($fact['actual_delivery_weight']===null) $hasWeight=false;
                $base=bcadd($base,$fact['inventory_base_quantity'],4);
                foreach($fact['quantity_facts']??[] as $q) { $facts[(int)$q['unit_id']]=bcadd($facts[(int)$q['unit_id']]??'0',$q['quantity'],4); $coverage[(int)$q['unit_id']]=($coverage[(int)$q['unit_id']]??0)+1; }
            }
            if(!$groups) $hasAllFacts=false;
            if(self::closed($report)) {
                $delivered=Db::name('fulfillment_delivery_item')->where('tenant_id',FinanceAccess::tenant())->where('report_item_id',$id)->select()->toArray();
                $actual='0.0000'; $base='0.0000'; $facts=[]; $coverage=[]; $factCount=count($delivered); $hasWeight=true;
                foreach($delivered as $part) {
                    if($part['actual_delivery_weight']===null) $hasWeight=false;
                    else $actual=bcadd($actual,$part['actual_delivery_weight'],4);
                    $base=bcadd($base,(string)($part['inventory_base_quantity']??$part['actual_delivery_weight']??'0'),4);
                    foreach(FinanceValue::decode($part['quantity_facts']?:'[]') as $q) { $facts[(int)$q['unit_id']]=bcadd($facts[(int)$q['unit_id']]??'0',$q['quantity'],4); $coverage[(int)$q['unit_id']]=($coverage[(int)$q['unit_id']]??0)+1; }
                }
            }
            $expected=(!$unit || !$hasAllFacts)?null:($unit['measure_type']==='weight'?($hasWeight?TradeQuantityService::weightFromJin($actual,$unitId):null):((int)$item['base_unit_id']===$unitId?$base:(($coverage[$unitId]??0)===$factCount?($facts[$unitId]??null):null)));
            if ($mode === 'actual') { $quantity = $expected; $price = $referencePrice; }
            if ($referenceUnit && $referenceUnit['measure_type'] === 'count' && (($coverage[$referenceUnitId]??0) !== $factCount || $factCount === 0) && (int)$item['base_unit_id'] !== $referenceUnitId) $missing[] = ['path' => $path . '.reference_price_unit_id', 'message' => '请补充原计价单位的真实清点数量'];
            $normalizedInput['lines'][$positions[$id]] = array_merge($input, ['quantity_mode' => $mode, 'reference_price_unit_id' => $referenceUnitId,
                'ticket_goods_name' => $ticketName,
                'reference_price' => $referencePriceInput, 'price_unit_id' => $unitId,
                'settlement_quantity' => $mode === 'actual' ? ($quantity ?? '') : ($input['settlement_quantity'] ?? ''),
                'price' => $mode === 'actual' ? ($referencePriceInput ?? '') : ($input['price'] ?? '')]);
            if($quantity===null) $missing[]=['path'=>$path.'.settlement_quantity','message'=>'请补充计价数量'];
            if($price===null) $missing[]=['path'=>$path.'.price','message'=>'请补充单价'];
            if($expected===null) $missing[]=['path'=>$path.'.actual_quantity','message'=>'请补充当前商品的真实数量与称重'];
            if($quantity!==null && $expected!==null && bccomp($quantity,$expected,4)!==0) {
                if($unit['measure_type']!=='weight') throw new \DomainException('计件数量必须与已核实的完整数量一致');
                FinanceAccess::require('',true); $ownerRequired=true;
            } else $difference='';
            unset($requests[$id]);
            if(!$unit || $quantity===null || $price===null || $expected===null) continue;
            if(bccomp($price,'0',2)!==0) $zero='';
            $amount=FinanceSalesPrecision::amount($quantity,$price,$precision['actual_mode'])['amount']; $goods=bcadd($goods,$amount,2);
            $lines[]=['report_item_id'=>$id,'goods_id'=>(int)$item['goods_id'],'sku_id'=>(int)$item['sku_id'],'goods_name'=>$item['goods_name'],'sku_name'=>$item['sku_name'],
                'base_unit_name'=>$item['base_unit_name'],'warehouse_id'=>(int)$item['warehouse_id'],'unit_name'=>$item['unit_name'],'price_unit_id'=>$unitId,'price_unit_name'=>$unit['name'],'settlement_quantity'=>$quantity,'price'=>$price,'amount'=>$amount,
                'quantity_mode'=>$mode,'reference_price_unit_id'=>$referenceUnitId,'reference_price_unit_name'=>$referenceUnit['name']??'','reference_price'=>$referencePrice,
                'zero_price_reason'=>$zero,'difference_reason'=>$difference,'has_quantity_difference'=>bccomp($quantity,$expected,4)!==0,'actual_delivery_weight'=>$hasWeight?$actual:null,'inventory_base_quantity'=>$base,
                'quantity_facts'=>array_map(static fn($id,$qty)=>['unit_id'=>$id,'quantity'=>$qty],array_keys($facts),$facts),
                'exception_fingerprint'=>self::exceptionFingerprint($item),'pricing_units'=>$units,'group_fingerprints'=>self::groupFingerprints($groups),
                ...($ticketName === '' ? [] : ['ticket_goods_name' => $ticketName])];
        }
        if($requests || (!$lines && !$missing)) throw new \DomainException('商品计价明细与本报货不一致或存在重复');
        $roundingInput=array_key_exists('rounding_amount',$params)?$params['rounding_amount']:'0';
        if(CustomerReportWorkspace::blank($roundingInput)) { $missing[]=['path'=>'sales.rounding_amount','message'=>'请补充抹零金额，无抹零填写0']; $rounding='0.00'; }
        else $rounding=CustomerReportWorkspace::field('sales.rounding_amount',static fn()=>FinanceValue::money($roundingInput,true));
        if(bccomp($rounding,'0',2)>0) { FinanceAccess::require('finance.sales.rounding'); if(!$missing && bccomp($rounding,$goods,2)>=0) throw new \DomainException('抹零必须小于商品合计'); }
        $paymentTerms=FinanceIntegration::active()?FinanceSalesRules::terms((int)$report['main_customer_id'],(string)$report['delivery_date']):['default_due_date'=>null,'version'=>0,'mode'=>'unagreed','days'=>0];
        $defaultDue=$paymentTerms['default_due_date'];
        $due=array_key_exists('due_date',$params)?FinanceValue::date($params['due_date'],true):$defaultDue;
        $dueOverridden=$due!==$defaultDue;
        if($dueOverridden) FinanceAccess::require('finance.sales.due_override');
        $overdue=FinanceIntegration::active()?FinanceCustomers::overdue((int)$report['main_customer_id']):['amount'=>'0'];
        if(!$missing && bccomp($overdue['amount'],'0',2)>0 && empty($params['overdue_acknowledged'])) throw new \DomainException('客户存在逾期余额，请明确知晓后继续确认');
        if($missing) {
            if(!$allowIncomplete) throw new \DomainException($missing[0]['message']);
            return ['missing_fields'=>$missing, 'workspace_input' => $normalizedInput];
        }
        return ['customer_id'=>(int)$report['main_customer_id'],'customer_name'=>$report['main_customer_name'],'report_sn'=>$report['sn'],
            'lines'=>$lines,'goods_amount'=>$goods,'rounding_amount'=>$rounding,'amount'=>bcsub($goods,$rounding,2),'due_date'=>$due,
            'precision'=>$precision,'payment_terms'=>$paymentTerms,'approval_permissions'=>array_values(array_filter(['settlement.bill',bccomp($rounding,'0',2)>0?'finance.sales.rounding':null,$dueOverridden?'finance.sales.due_override':null,!empty($precision['overridden'])?'finance.sales.precision_override':null])),
            'approval_owner_required'=>$ownerRequired,'overdue_acknowledged'=>!empty($params['overdue_acknowledged']),'issued_at'=>time(),
            ...($allowIncomplete ? ['workspace_input' => $normalizedInput] : [])];
    }

    private static function ticketGoodsName(mixed $input, string $standardName): string
    {
        if (!is_string($input) || preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $input) !== 0) {
            throw new \DomainException('票据品名须为一行文字，不能包含控制字符');
        }
        $name = preg_replace('/^[\p{Zs}\x{FEFF}]+|[\p{Zs}\x{FEFF}]+$/u', '', $input);
        if (mb_strlen($name) > self::TICKET_GOODS_NAME_LIMIT) {
            throw new \DomainException('票据品名最多 ' . self::TICKET_GOODS_NAME_LIMIT . ' 个字符');
        }
        return $name === $standardName ? '' : $name;
    }

    public static function pricingUnits(array $item): array
    {
        $result=[];
        foreach(Db::name('goods_units_binding')->where('tenant_id',FinanceAccess::tenant())->where('goods_id',$item['goods_id'])->where('status',1)->order('sort,id')->select()->toArray() as $binding) {
            $unit=TradeQuantityService::unit((int)$binding['unit_id']); if(!$unit) continue;
            $result[]=['id'=>(int)$binding['unit_id'],'name'=>$unit['unit_name'],'precision'=>(int)$unit['quantity_precision'],'measure_type'=>$unit['measure_type']];
        }
        return $result;
    }

    private static function exceptionFingerprint(array $item): string
    {
        return hash('sha256',FinanceValue::json([(string)($item['undelivered_total_qty']??'0'),(string)($item['delivery_loss_total_qty']??'0')]));
    }

    private static function groupFingerprints(array $groups): array
    {
        $out=[];
        foreach($groups as $g) {
            $fact=FinanceValue::decode($g['quantity_facts_snapshot']?:'{}');
            $out[(int)$g['id']]=hash('sha256',FinanceValue::json([$fact['inventory_base_quantity']??null,$fact['actual_delivery_weight']??null,$fact['quantity_facts']??[]]));
        }
        return $out;
    }

    public static function present(int $reportId): ?array
    {
        $doc=Db::name('customer_report_sales_document')->where('tenant_id',FinanceAccess::tenant())->where('report_id',$reportId)->find();
        if(!$doc || (!FinanceAccess::has('settlement.view')&&!FinanceAccess::has('settlement.bill'))) return null;
        $snapshot=FinanceValue::decode($doc['snapshot']); $report=Db::name('customer_report')->where('tenant_id',FinanceAccess::tenant())->where('id',$reportId)->find();
        $delivered=Db::name('fulfillment_delivery_item')->where('tenant_id',FinanceAccess::tenant())->where('report_id',$reportId)->count()>0;
        $closed=self::closed($report); $incomplete=CustomerReportWorkspace::hasIncomplete($reportId);
        $replacement=0; $latest=(int)$doc['finance_document_id']; $seen=[];
        while($latest && ($next=(int)Db::name('finance_correction')->where('tenant_id',FinanceAccess::tenant())->where('original_document_id',$latest)->value('replacement_document_id'))) {
            if(isset($seen[$next])) throw new \DomainException('销售金额更正链异常');
            $seen[$next]=true; if(!$replacement) $replacement=$next; $latest=$next;
        }
        return $snapshot+['id'=>(int)$doc['id'],'order_sn'=>$doc['order_sn'],'version'=>(int)$doc['version'],'status'=>$doc['status'],
            'delivery_status'=>$closed?'completed':($delivered?'partial':'undelivered'),'accounting_status'=>$doc['accounting_status'],
            'can_print'=>$doc['status']==='issued'&&!$replacement&&!$incomplete,'can_share'=>$doc['status']==='issued'&&!$replacement&&!$incomplete&&FinanceAccess::owner(),
            'workspace_incomplete'=>$incomplete,
            'can_retry_posting'=>$doc['accounting_status']==='pending_posting'&&!$incomplete&&FinanceAccess::has('settlement.bill'),
            'can_revise'=>$doc['status']==='issued'&&$doc['accounting_status']!=='posted'&&FinanceAccess::has('settlement.bill'),
            'replacement_document_id'=>$replacement,'latest_finance_document_id'=>$latest,'invalidation_notice'=>$replacement?'本版金额已被后续更正替代，请查看最新销售金额记录':'',
            'blocked_reason'=>$doc['blocked_reason'],'finance_document_id'=>(int)$doc['finance_document_id'],
            'revisions'=>array_map(static function($revision){ $old=FinanceValue::decode($revision['snapshot']); return ['id'=>(int)$revision['id'],'version'=>(int)$revision['version'],'revision_reason'=>$revision['reason'],'reason'=>$revision['reason'],'issued_at'=>(int)$revision['create_time'],'amount'=>$old['amount'],'lines'=>$old['lines'],'supplemental_notes'=>CustomerReportSalesNotes::forRevision((int)$revision['id'])]; },Db::name('customer_report_sales_revision')->where('tenant_id',FinanceAccess::tenant())->where('document_id',$doc['id'])->order('version desc')->select()->toArray())];
    }

    public static function closed(?array $report): bool
    {
        return $report && in_array($report['status'],self::CLOSED_REPORT_STATUSES,true);
    }

    /** 调用点在交付事务提交之后；异常不得撤回已经成立的库存事实。 */
    public static function afterReportChange(int $reportId): void
    {
        $tenant=FinanceAccess::tenant();
        $doc=Db::name('customer_report_sales_document')->where('tenant_id',$tenant)->where('report_id',$reportId)->find();
        if(!$doc || in_array($doc['accounting_status'],['posted','voided'],true)) return;
        $report=Db::name('customer_report')->where('tenant_id',$tenant)->where('id',$reportId)->find();
        if(!$report) return;
        if($report['status']==='cancelled' || (self::closed($report) && !Db::name('fulfillment_delivery_item')->where('tenant_id',$tenant)->where('report_id',$reportId)->count())) {
            Db::name('customer_report_sales_document')->where('tenant_id',$tenant)->where('id',$doc['id'])->where('version',$doc['version'])->where('status','issued')->where('accounting_status','<>','posted')->update(['status'=>'voided','accounting_status'=>'voided','blocked_reason'=>'未发生实际交付，销售单已作废','update_time'=>time()]); return;
        }
        if(CustomerReportWorkspace::hasIncomplete($reportId)) return;
        if(!self::closed($report)) return;
        Db::name('customer_report_sales_document')->where('tenant_id',$tenant)->where('id',$doc['id'])->whereNotIn('accounting_status',['posted','voided'])->where('version',$doc['version'])->update(['accounting_status'=>'pending_posting','update_time'=>time()]);
        try {
            Db::transaction(static function() use($tenant,$reportId,$doc): void {
                $expectedVersion=(int)$doc['version'];
                FinanceIntegration::lock();
                $report=Db::name('customer_report')->where('tenant_id',$tenant)->where('id',$reportId)->lock(true)->find();
                $doc=Db::name('customer_report_sales_document')->where('tenant_id',$tenant)->where('report_id',$reportId)->lock(true)->find();
                if((int)$doc['version']!==$expectedVersion || $doc['status']!=='issued' || $doc['accounting_status']==='posted') return;
                if(CustomerReportWorkspace::hasIncomplete($reportId,true)) return;
                if(!self::closed($report)) throw new \DomainException('整单交付尚未完成');
                $snapshot=FinanceValue::decode($doc['snapshot']);
                $items=Db::name('customer_report_item')->where('tenant_id',$tenant)->where('report_id',$reportId)->whereNull('delete_time')->select()->toArray();
                if((int)$report['main_customer_id']!==(int)$snapshot['customer_id']) throw new \DomainException('开单后主客户已变化，请重新核对销售单');
                $expectedIds=array_column($snapshot['lines'],'report_item_id');
                $currentIds=array_map('intval',array_column(array_filter($items,static fn($item)=>$item['fulfillment_status']!=='undelivered'),'id'));
                sort($expectedIds); sort($currentIds);
                if($expectedIds!==$currentIds) throw new \DomainException('开单后商品组成已变化，请修订并重新核对销售金额');
                $current=array_column($items,null,'id');
                foreach($snapshot['lines'] as $line) {
                    $item=$current[$line['report_item_id']]??null;
                    if(!$item || (int)$item['goods_id']!==(int)$line['goods_id'] || (int)$item['sku_id']!==(int)$line['sku_id'] || (int)$item['warehouse_id']!==(int)$line['warehouse_id'] || $item['fulfillment_status']==='undelivered' || $line['exception_fingerprint']!==self::exceptionFingerprint($item)) throw new \DomainException('存在未交货或损耗，请修订并核对最终销售金额');
                    $groups=Db::name('customer_report_processing_group')->where('tenant_id',$tenant)->where('report_item_id',$item['id'])->order('id')->select()->toArray();
                    if($line['group_fingerprints']!==self::groupFingerprints($groups)) throw new \DomainException('开单后数量事实已变化，请修订并重新核对销售金额');
                }
                self::assertApprovalStillValid($doc,$snapshot);
                self::post($doc,$snapshot,$report);
            });
        } catch(\Throwable $error) {
            \think\facade\Log::write('issued_sales_posting_failed report '.$reportId.': '.$error->getMessage(), 'error');
            Db::name('customer_report_sales_document')->where('tenant_id',$tenant)->where('id',$doc['id'])->where('version',$doc['version'])->where('status','issued')->whereNotIn('accounting_status',['posted','voided'])->update([
                'accounting_status'=>$error instanceof \DomainException?'review_required':'pending_posting','blocked_reason'=>mb_substr($error instanceof \DomainException?$error->getMessage():'财务入账未完成，请按原单重试',0,1000),'update_time'=>time()]);
        }
    }

    private static function assertApprovalStillValid(array $doc,array $snapshot): void
    {
        $actor=FinanceValue::decode($doc['approval_actor']); $tenant=FinanceAccess::tenant();
        if($actor===FinanceAccess::actor()) {
            foreach($snapshot['approval_permissions'] as $permission) FinanceAccess::require($permission);
            if($snapshot['approval_owner_required']) FinanceAccess::require('',true);
            return;
        }
        $owner=false; $permissions=[];
        if($actor['type']==='user') {
            $member=\app\common\service\jxc\StoreMembershipService::memberRole((int)$actor['id'],$tenant);
            if($member==='') throw new \DomainException('原金额确认人员已离开门店，请重新核对金额');
            $owner=\app\common\service\jxc\StoreMembershipService::isTenantAdmin((int)$actor['id'],$tenant);
            $employee=Db::name('employee')->where('tenant_id',$tenant)->where('bind_user_id',$actor['id'])->where('is_enabled',1)->whereNull('delete_time')->value('id');
            if($employee) $permissions=Db::name('employee_permission')->where('tenant_id',$tenant)->where('employee_id',$employee)->column('permission_key');
        } else {
            $admin=Db::name('tenant_admin')->where('tenant_id',$tenant)->where('id',$actor['id'])->where('disable',0)->whereNull('delete_time')->find();
            $owner=$admin && (int)$admin['root']===1;
        }
        if(($snapshot['approval_owner_required'] && !$owner) || (!$owner && array_diff($snapshot['approval_permissions'],$permissions))) throw new \DomainException('原金额确认权限已变化，请有权限人员重新确认销售单');
    }

    private static function post(array $doc,array $snapshot,array $report): void
    {
        $tenant=FinanceAccess::tenant(); $actor=FinanceValue::decode($doc['approval_actor']);
        if(FinanceIntegration::active()) {
            $ledger=new FinanceLedger($tenant); $ledger->lockBook(); $lines=[];
            $available=FinanceDeliveries::rows((int)$report['main_customer_id'],'1900-01-01',date('Y-m-d'),true);
            foreach($snapshot['lines'] as $line) {
                $sources=array_values(array_filter($available,static fn($s)=>(int)$s['report_item_id']===(int)$line['report_item_id']));
                if(!$sources) throw new \DomainException('销售单缺少对应的真实交付来源');
                $unitKind=array_column($line['pricing_units'],null,'id')[$line['price_unit_id']]['measure_type'];
                $total='0.0000';
                foreach($sources as $s) { $u=array_column($s['pricing_units'],null,'id')[$line['price_unit_id']]??null; if(!$u||$u['quantity']===null) throw new \DomainException('交付缺少原计价单位数量'); $total=bcadd($total,$u['quantity'],4); }
                $remaining=$line['settlement_quantity'];
                foreach($sources as $index=>$source) {
                    $unit=array_column($source['pricing_units'],null,'id')[$line['price_unit_id']];
                    $quantity=$index===count($sources)-1?$remaining:($unitKind==='weight'?bcdiv(bcmul($line['settlement_quantity'],$unit['quantity'],8),$total,4):$unit['quantity']);
                    $remaining=bcsub($remaining,$quantity,4);
                    $input=['delivery_item_id'=>$source['delivery_item_id'],'fact_version'=>2,'source_version'=>$source['source_version'],'price_unit_id'=>$line['price_unit_id'],
                        'settlement_quantity'=>$quantity,'price'=>$line['price'],'zero_price_reason'=>$line['zero_price_reason'],'difference_reason'=>$line['difference_reason']];
                    $input+=['due_date'=>$snapshot['due_date'],'due_override_reason'=>'沿用已确认销售单付款约定'];
                    $lines[]=$input;
                }
            }
            $payload=['subject_id'=>$snapshot['customer_id'],'reason'=>'报货销售单 '.$doc['order_sn'].' V'.$doc['version'].' 整单交付后入账',
                'lines'=>$lines,'rounding_amount'=>$snapshot['rounding_amount'],'overdue_acknowledged'=>$snapshot['overdue_acknowledged'],'issued_sales_document_id'=>(int)$doc['id']];
            $now=time(); $financeId=(int)Db::name('finance_document')->insertGetId(['tenant_id'=>$tenant,'type'=>'sales_batch','status'=>'pending','version'=>1,'payload'=>FinanceValue::json($payload),'confirmed_result'=>'{}',
                'created_by'=>FinanceValue::json($actor),'last_modified_by'=>FinanceValue::json($actor),'confirmed_by'=>'{}','create_time'=>$now,'update_time'=>$now]);
            $result=FinanceSalesBatches::confirmIssued($ledger,['id'=>$financeId,'payload'=>FinanceValue::json($payload)],(int)$doc['id']);
            if(bccomp($result['amount'],$snapshot['amount'],2)!==0) throw new \DomainException('按交付分组计算的金额与销售单不一致，请核对');
            Db::name('finance_document')->where('tenant_id',$tenant)->where('id',$financeId)->update(['status'=>'confirmed','confirmed_result'=>FinanceValue::json($result),'confirmed_by'=>FinanceValue::json($actor),'confirmed_at'=>$now]);
        } else {
            $order=Db::name('sales_order')->where('tenant_id',$tenant)->where('source_type','customer_report')->where('source_id',$report['id'])->order('id')->lock(true)->find();
            if(!FinanceService::addReceivableWithinTransaction((int)$report['main_customer_id'],$snapshot['amount'],(int)$order['id'],'sales',$doc['order_sn'],'报货销售单整单交付入账')) throw new \RuntimeException('issued_sales_receivable_failed');
            $financeId=0;
        }
        $orders=Db::name('sales_order')->where('tenant_id',$tenant)->where('source_type','customer_report')->where('source_id',$report['id'])->order('id')->select()->toArray();
        $unallocated=$snapshot['amount'];
        foreach($snapshot['lines'] as $line) {
            Db::name('order_goods')->where('tenant_id',$tenant)->where('source_line_type','customer_report_item')->where('source_line_id',$line['report_item_id'])
                ->whereIn('order_id',array_column($orders,'id'))->where('order_type','sales')->update(['number'=>$line['settlement_quantity'],
                    'customer_settlement_quantity'=>$line['settlement_quantity'],'price'=>$line['price'],'amount'=>$line['amount'],'pricing_unit_id'=>$line['price_unit_id'],
                    'pricing_unit_name'=>$line['price_unit_name'],'price_status'=>'priced','zero_price_reason'=>$line['zero_price_reason'],'update_time'=>time()]);
        }
        foreach($orders as $index=>$order) {
            $goods='0.00';
            foreach($snapshot['lines'] as $line) if((int)$line['warehouse_id']===(int)$order['warehouse_id']) $goods=bcadd($goods,$line['amount'],2);
            $amount=$index===count($orders)-1?$unallocated:(bccomp($snapshot['goods_amount'],'0',2)>0?bcdiv(bcmul($snapshot['amount'],$goods,4),$snapshot['goods_amount'],2):'0.00');
            $unallocated=bcsub($unallocated,$amount,2);
            Db::name('sales_order')->where('tenant_id',$tenant)->where('id',$order['id'])->update(['settlement_status'=>'formal','goods_amount'=>$goods,
                'rounding_amount'=>bcsub($goods,$amount,2),'order_money'=>$amount,'order_arrears_money'=>$amount,'update_time'=>time()]);
        }
        Db::name('customer_report_sales_document')->where('tenant_id',$tenant)->where('id',$doc['id'])->update(['accounting_status'=>'posted','finance_document_id'=>$financeId,'blocked_reason'=>'','update_time'=>time()]);
    }

    /** 禁止别的结算入口绕过整单条件或占用提前签发单据的来源。 */
    public static function assertExternalSettlementAllowed(int $reportId): void
    {
        $doc=Db::name('customer_report_sales_document')->where('tenant_id',FinanceAccess::tenant())->where('report_id',$reportId)->find();
        if($doc) throw new \DomainException('该报货已有销售单，请从原报货销售单核对或更正，不能重复结算');
        $report=Db::name('customer_report')->where('tenant_id',FinanceAccess::tenant())->where('id',$reportId)->find();
        if($report && !self::closed($report)) throw new \DomainException('整单交付尚未完成，暂不能计入销售应收');
    }
}
