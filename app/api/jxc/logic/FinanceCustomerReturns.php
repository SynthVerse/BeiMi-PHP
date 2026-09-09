<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 真实退回只恢复实物和原销售成本；贷项及退款分别保存业务事实。 */
final class FinanceCustomerReturns
{
    public static function input(string $type, array $input): array
    {
        return $type === 'customer_return_actual' ? array_intersect_key($input, array_flip(['subject_id', 'original_sales_order_id', 'sku_id', 'warehouse_id', 'actual_date', 'quantity',
            'expected_return_id', 'expected_sales_version', 'expected_cost_event_id', 'received_verified', 'source_reference', 'reason'])) : $input;
    }

    public static function present(array $document): array
    {
        if ($document['type'] !== 'customer_return_actual' || $document['status'] === 'confirmed') { return $document; }
        $input = $document['payload']; $document['payload']['selected_sale'] = null;
        if (!empty($input['original_sales_order_id']) && !empty($input['sku_id']) && !empty($input['subject_id'])) {
            $document['payload']['selected_sale'] = self::source((int)$input['original_sales_order_id'], (int)$input['sku_id'], (int)$input['subject_id'], $input['actual_date'] ?? null);
        }
        return $document;
    }

    public static function options(int $customer, array $params): array
    {
        $read = static function () use ($customer, $params): array {
            if (!FinanceIntegration::active()) { return ['sales' => [], 'sale_has_more' => false, 'selected_sale' => null,
                'warehouses' => Db::name('warehouse')->where('tenant_id', FinanceAccess::tenant())->field('id,name')->order('id')->select()->toArray()]; }
            (new FinanceLedger(FinanceAccess::tenant()))->lockBook(); $selected = null; $choices = [];
            if (!empty($params['original_sales_order_id']) && !empty($params['sku_id']) && $customer) { $selected = self::source(FinanceValue::id($params['original_sales_order_id']), FinanceValue::id($params['sku_id']), $customer, $params['actual_date'] ?? null); }
            $query = Db::name('order_goods')->alias('g')->join('sales_order o', 'o.tenant_id=g.tenant_id AND o.id=g.order_id')
                ->where('g.tenant_id', FinanceAccess::tenant())->where('g.order_type', 'sales')->where('o.customer_id', $customer)
                ->field('g.order_id,g.sku_id')->group('g.order_id,g.sku_id')->order('g.order_id desc,g.sku_id');
            if (!empty($params['original_sales_order_id'])) { $query->where('o.id', FinanceValue::id($params['original_sales_order_id'])); }
            $keyword = FinanceValue::text($params['keyword'] ?? '', 60, false);
            if ($keyword !== '') { $query->whereLike('o.order_sn|g.name|g.sku_name', '%' . addcslashes($keyword, '%_\\') . '%'); }
            $rows = $customer ? $query->limit(1001)->select()->toArray() : [];
            if (count($rows) > 1000) { throw new \DomainException('销售明细超过一千项，请输入单号或商品缩小验收来源范围'); }
            foreach ($rows as $row) {
                $source = self::source((int)$row['order_id'], (int)$row['sku_id'], $customer, $params['actual_date'] ?? null);
                if (bccomp($source['returnable_quantity'], '0', 4) > 0) { $choices[] = $source; }
            }
            $page = FinanceValue::id($params['page'] ?? 1);
            return ['sales' => array_slice($choices, ($page - 1) * 20, 20), 'sale_has_more' => count($choices) > $page * 20, 'selected_sale' => $selected,
                'warehouses' => Db::name('warehouse')->where('tenant_id', FinanceAccess::tenant())->field('id,name')->order('id')->select()->toArray()];
        };
        $pdo = Db::connect()->getPdo(); return $pdo && $pdo->inTransaction() ? $read() : Db::transaction($read);
    }

    public static function source(int $orderId, int $sku, int $customer, ?string $asOf = null): array
    {
        $tenant = FinanceAccess::tenant();
        $asOf = $asOf ? FinanceValue::date($asOf) : date('Y-m-d');
        $order = Db::name('sales_order')->where('tenant_id', $tenant)->where('id', $orderId)->where('customer_id', $customer)->lock(true)->find();
        $name = Db::name('customer')->where('tenant_id', $tenant)->where('id', $customer)->where('parent_id', 0)->value('customer_name');
        $goods = Db::name('order_goods')->where('tenant_id', $tenant)->where('order_id', $orderId)->where('order_type', 'sales')->where('sku_id', $sku)->lock(true)->select()->toArray();
        if (!$order || $name === null || !$goods) { throw new \DomainException('请选择本门店主客户的原销售与商品'); }
        $actual = '0'; foreach ($goods as $line) { $actual = bcadd($actual, $line['base_quantity'], 4); }
        $flowQuantity = '0'; $preCutoff = '0';
        $activation = (string)Db::name('finance_preparation')->where('tenant_id', $tenant)->value('activation_date');
        foreach (Db::name('stock_flow')->where('tenant_id', $tenant)->where('order_id', $orderId)->where('warehouse_id', $order['warehouse_id'])->where('sku_id', $sku)
            ->whereIn('order_type', ['sales', 'sales_delivery', 'sales_delivery_correction'])->order('id')->lock(true)->select()->toArray() as $flow) {
            $date = Db::name('finance_cost_event')->where('tenant_id', $tenant)->where('reference', 'stock:' . $flow['id'])->value('business_date') ?: date('Y-m-d', FinanceStockFactTime::resolve($tenant, $flow, true));
            if ($date > $asOf) { continue; }
            $flowQuantity = (int)$flow['flow_type'] === 2 ? bcadd($flowQuantity, $flow['quantity'], 4) : bcsub($flowQuantity, $flow['quantity'], 4);
            if ($date < $activation) { $preCutoff = (int)$flow['flow_type'] === 2 ? bcadd($preCutoff, $flow['quantity'], 4) : bcsub($preCutoff, $flow['quantity'], 4); }
        }
        if (bccomp($flowQuantity, $actual, 4) < 0) { $actual = $flowQuantity; }
        if (bccomp($actual, '0', 4) < 0) { throw new \DomainException('原销售实物数量异常，请先核对'); }
        $records = Db::name('finance_customer_return')->where('tenant_id', $tenant)->where('sales_order_id', $orderId)->where('sku_id', $sku)->order('id')->lock(true)->select()->toArray();
        $returned = self::legacyReturned($orderId, $sku); $latest = 0;
        foreach ($records as $record) { $returned = bcadd($returned, $record['quantity'], 4); $latest = (int)$record['id']; }
        $unit = Db::name('goods_sku')->where('tenant_id', $tenant)->where('id', $sku)->value('base_unit_name');
        $basis = bccomp($preCutoff, '0', 4) > 0 ? (bccomp($flowQuantity, $preCutoff, 4) > 0 ? 'mixed' : 'pre_cutoff') : 'sale';
        return ['original_sales_order_id' => $orderId, 'order_sn' => $order['order_sn'], 'subject_id' => $customer, 'subject_name' => $name,
            'cost_basis' => $basis, 'selection_error' => $basis === 'mixed' ? '同一商品包含启用前后交付，须先核实本次退回的原交付批次' : '',
            'sku_id' => $sku, 'goods_id' => (int)$goods[0]['goods_id'], 'goods_name' => $goods[0]['name'], 'sku_name' => $goods[0]['sku_name'], 'base_unit_name' => $unit ?: $goods[0]['units'],
            'original_warehouse_id' => (int)$order['warehouse_id'], 'original_warehouse_name' => Db::name('warehouse')->where('tenant_id', $tenant)->where('id', $order['warehouse_id'])->value('name'),
            'sales_date' => date('Y-m-d', (int)$order['datetimesingle']), 'as_of' => $asOf, 'actual_quantity' => bcadd($actual, '0', 4), 'returned_quantity' => bcadd($returned, '0', 4), 'returnable_quantity' => bcsub($actual, $returned, 4),
            'expected_return_id' => $latest, 'expected_sales_version' => (int)$order['settlement_version'],
            'expected_cost_event_id' => (int)Db::name('finance_cost_event')->where('tenant_id', $tenant)->where('sku_id', $sku)->order('id desc')->value('id')];
    }

    private static function legacyReturned(int $order, int $sku): string
    {
        $ids = \app\common\model\jxc\SalesReturnOrder::where('tenant_id', FinanceAccess::tenant())->where('original_sales_order_id', $order)->column('id');
        if (!$ids) { return '0'; }
        $returned = '0';
        foreach (Db::name('stock_flow')->where('tenant_id', FinanceAccess::tenant())->whereIn('order_id', $ids)->where('order_type', 'sales-return')->where('sku_id', $sku)->lock(true)->select()->toArray() as $flow) {
            $returned = (int)$flow['flow_type'] === 1 ? bcadd($returned, $flow['quantity'], 4) : bcsub($returned, $flow['quantity'], 4);
        }
        $recorded = (string)Db::name('order_goods')->where('tenant_id', FinanceAccess::tenant())->whereIn('order_id', $ids)->where('order_type', 'sales-return')->where('sku_id', $sku)->sum('number');
        if (bccomp($returned, '0', 4) < 0 || bccomp($returned, $recorded, 4) !== 0) { throw new \DomainException('旧退货数量与实际库存流水不一致，请先核对历史实物'); }
        return $returned;
    }

    public static function assertSalesCorrections(array $order, array $corrections): void
    {
        if (!FinanceIntegration::active()) { return; }
        $deltas = []; foreach ($corrections as $line) { $deltas[$line['sku_id']] = bcadd($deltas[$line['sku_id']] ?? '0', $line['actual_delivery_delta'], 4); }
        foreach ($deltas as $sku => $delta) {
            $returned = bcadd(self::legacyReturned((int)$order['id'], (int)$sku), (string)Db::name('finance_customer_return')->where('tenant_id', FinanceAccess::tenant())->where('sales_order_id', $order['id'])->where('sku_id', $sku)->sum('quantity'), 4);
            $actual = (string)Db::name('order_goods')->where('tenant_id', FinanceAccess::tenant())->where('order_id', $order['id'])->where('order_type', 'sales')->where('sku_id', $sku)->sum('base_quantity');
            if (bccomp(bcadd($actual, $delta, 4), $returned, 4) < 0) { throw new \DomainException('原销售实重不能低于已实际退回数量，请先核对退货及关联实物更正'); }
        }
    }

    public static function confirm(FinanceLedger $ledger, array $document, array $input): array
    {
        $date = FinanceValue::date($input['actual_date'] ?? null);
        $source = self::source(FinanceValue::id($input['original_sales_order_id'] ?? null), FinanceValue::id($input['sku_id'] ?? null), FinanceValue::id($input['subject_id'] ?? null), $date);
        if ($source['selection_error'] !== '') { throw new \DomainException($source['selection_error']); }
        foreach (['expected_return_id', 'expected_sales_version', 'expected_cost_event_id'] as $key) {
            if (FinanceValue::id($input[$key] ?? null, true) !== $source[$key]) { throw new \DomainException('原销售、退货或成本已有变化，请重新读取验收依据'); }
        }
        if (($input['received_verified'] ?? null) !== 1) { throw new \DomainException('须人工确认实物已经实际验收返回，未收到不能增加库存'); }
        $quantity = FinancePurchaseSettlement::quantity($input['quantity'] ?? null);
        if (bccomp($quantity, $source['returnable_quantity'], 4) > 0) { throw new \DomainException('累计实际退回不能超过原销售有效交付数量'); }
        $month = $ledger->postingMonth($date);
        if ($date < $source['sales_date']) { throw new \DomainException('实际验收日期不能早于原销售'); }
        $warehouse = FinanceValue::id($input['warehouse_id'] ?? null);
        $warehouseName = Db::name('warehouse')->where('tenant_id', FinanceAccess::tenant())->where('id', $warehouse)->value('name');
        if ($warehouseName === null) { throw new \DomainException('请选择本门店实际验收仓库'); }
        $reason = FinanceValue::text($input['reason'] ?? null, 1000); $reference = FinanceValue::text($input['source_reference'] ?? null, 160);
        $cost = new FinanceCostLedger(FinanceAccess::tenant());
        $flow = StockService::inboundFinanceCustomerReturnWithinTransaction($warehouse, $source, $quantity, (int)$document['id'], $date);
        $result = FinanceValue::decode(Db::name('finance_cost_event')->where('tenant_id', FinanceAccess::tenant())->where('reference', 'stock:' . $flow)->value('result'));
        $valuation = $source['cost_basis'] === 'pre_cutoff' ? ['pending' => true, 'known_cost' => '0.000000', 'allocations' => []] : self::returnedCost($result, $source['sku_id']);
        $pending = $valuation['pending']; $known = $valuation['known_cost'];
        $snapshot = array_replace($source, ['type' => 'customer_return_actual', 'warehouse_id' => $warehouse, 'warehouse_name' => $warehouseName, 'actual_date' => $date,
            'quantity' => $quantity, 'returnable_quantity' => bcsub($source['returnable_quantity'], $quantity, 4), 'stock_flow_id' => $flow, 'return_cost' => $pending ? null : $known,
            'known_return_cost' => $known, 'cost_pending' => $pending, 'cost_allocations' => $valuation['allocations'], 'cost_impacts' => $cost->documentImpacts((int)$document['id'], $pending ? [$source['sku_id'] => true] : []),
            'posting_month' => $month, 'reason' => $reason, 'source_reference' => $reference, 'received_verified' => true, 'confirmed_by' => FinanceAccess::actor(), 'confirmed_at' => time(), 'created_sources' => []]);
        $id = (int)Db::name('finance_customer_return')->insertGetId(['tenant_id' => FinanceAccess::tenant(), 'document_id' => $document['id'], 'sales_order_id' => $source['original_sales_order_id'],
            'customer_id' => $source['subject_id'], 'sku_id' => $source['sku_id'], 'original_warehouse_id' => $source['original_warehouse_id'], 'warehouse_id' => $warehouse,
            'business_date' => $date, 'quantity' => $quantity, 'snapshot' => FinanceValue::json($snapshot), 'create_time' => time()]);
        return $snapshot + ['return_id' => $id];
    }

    /** 本次退回份额按重放后的来源价值核算；不混入同销售后续交付的重估差额。 */
    private static function returnedCost(array $result, int $sku): array
    {
        $known = '0.000000'; $pending = bccomp($result['unpriced_return'] ?? '0', '0', 12) > 0; $allocations = [];
        foreach ($result['movements'] ?? [] as $movement) {
            $origin = Db::name('finance_cost_origin')->where('tenant_id', FinanceAccess::tenant())->where('sku_id', $sku)->where('origin_key', $movement['origin'])->lock(true)->find();
            if (!$origin || bccomp($origin['quantity'], '0', 12) <= 0) { throw new \DomainException('原销售退回成本份额不完整，请核对原来源'); }
            $total = $origin['current_amount'] ?? (string)Db::name('finance_cost_position')->where('tenant_id', FinanceAccess::tenant())->where('sku_id', $sku)->where('origin_key', $movement['origin'])->sum('value');
            $value = bcdiv(bcmul($total, $movement['quantity'], 18), $origin['quantity'], 6);
            $unknown = $origin['current_amount'] === null; $pending = $pending || $unknown; $known = bcadd($known, $value, 6);
            $allocations[] = ['origin' => $movement['origin'], 'quantity' => $movement['quantity'], 'known_cost' => $value, 'pending' => $unknown];
        }
        return ['known_cost' => $known, 'pending' => $pending, 'allocations' => $allocations];
    }
}
