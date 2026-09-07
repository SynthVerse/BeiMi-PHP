<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 只记录真实退离；供方认可、贷项及争议处理另外追加。 */
final class FinancePurchaseReturns
{
    public static function options(int $vendor, array $params): array
    {
        $tenant = FinanceAccess::tenant(); $page = FinanceValue::id($params['page'] ?? 1);
        $returned = Db::name('finance_purchase_return_line')->where('tenant_id', $tenant)->field('arrival_line_id,SUM(quantity) AS quantity')->group('arrival_line_id')->buildSql();
        $query = Db::name('finance_purchase_arrival_line')->alias('a')->leftJoin([$returned => 'r'], 'r.arrival_line_id=a.id')
            ->where('a.tenant_id', $tenant)->where('a.vendor_id', $vendor)->whereRaw('a.actual_quantity>COALESCE(r.quantity,0)');
        $keyword = FinanceValue::text($params['keyword'] ?? '', 60, false);
        if ($keyword !== '') { $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(a.snapshot,'$.goods_name')) LIKE ?", ['%' . addcslashes($keyword, '%_\\') . '%']); }
        $rows = $query->field('a.*,a.actual_quantity-COALESCE(r.quantity,0) AS returnable_quantity')->order('a.business_date,a.id')->limit(($page - 1) * 20, 21)->select()->toArray();
        $more = count($rows) > 20; $rows = array_slice($rows, 0, 20); $arrivals = [];
        foreach ($rows as $row) {
            $arrivals[] = array_merge(FinanceValue::decode($row['snapshot']), ['arrival_line_id' => (int)$row['id'], 'arrival_document_id' => (int)$row['document_id'],
                'subject_id' => $vendor, 'actual_date' => $row['business_date'], 'returnable_quantity' => bcadd($row['returnable_quantity'], '0', 4)]);
        }
        return ['sources' => [], 'has_more' => false, 'arrivals' => $arrivals, 'arrival_has_more' => $more,
            'warehouses' => Db::name('warehouse')->where('tenant_id', $tenant)->field('id,name')->order('id')->select()->toArray()];
    }

    public static function confirm(FinanceLedger $ledger, array $document, array $data): array
    {
        $tenant = FinanceAccess::tenant(); $vendor = FinanceValue::id($data['subject_id'] ?? null); $warehouse = FinanceValue::id($data['warehouse_id'] ?? null);
        $subject = Db::name('vendor')->where('tenant_id', $tenant)->where('id', $vendor)->find();
        $store = Db::name('warehouse')->where('tenant_id', $tenant)->where('id', $warehouse)->find();
        if (!$subject || !$store) { throw new \DomainException('请选择本门店供应商与实际退货仓库'); }
        $date = FinanceValue::date($data['actual_date'] ?? null); $month = $ledger->postingMonth($date);
        $reason = FinanceValue::text($data['reason'] ?? null, 1000); $reference = FinanceValue::text($data['source_reference'] ?? null, 160);
        $input = $data['lines'] ?? null;
        if (!is_array($input) || !array_is_list($input) || !$input || count($input) > 200) { throw new \DomainException('请明确选择一至二百条原到货与实际退货数量'); }
        $prepared = []; $seen = []; $skuIds = [];
        foreach ($input as $item) {
            if (!is_array($item)) { throw new \DomainException('退货明细格式无效'); }
            $arrivalId = FinanceValue::id($item['arrival_line_id'] ?? null);
            if (isset($seen[$arrivalId])) { throw new \DomainException('同一次实物退货不能重复选择原到货'); } $seen[$arrivalId] = true;
            $arrival = Db::name('finance_purchase_arrival_line')->where('tenant_id', $tenant)->where('vendor_id', $vendor)->where('id', $arrivalId)->lock(true)->find();
            if (!$arrival || $date < $arrival['business_date']) { throw new \DomainException('原到货不属于本供应商或退货日期早于实际到货'); }
            $quantity = FinancePurchaseSettlement::quantity($item['quantity'] ?? null); $previous = '0.0000';
            foreach (Db::name('finance_purchase_return_line')->where('tenant_id', $tenant)->where('arrival_line_id', $arrivalId)->lock(true)->select()->toArray() as $returned) { $previous = bcadd($previous, $returned['quantity'], 4); }
            if (bccomp(bcadd($previous, $quantity, 4), $arrival['actual_quantity'], 4) > 0) { throw new \DomainException('累计实际退货数量不能超过原实际到货数量'); }
            $prepared[] = [$arrival, $quantity]; $skuIds[] = (int)$arrival['sku_id'];
        }
        $skuIds = array_unique($skuIds); sort($skuIds, SORT_NUMERIC);
        foreach ($skuIds as $sku) { if (!WarehouseSkuBalanceService::lockBalanceWithinTransaction($warehouse, $sku)) { throw new \DomainException('实际退货仓库或商品规格无效'); } }
        $lines = []; $pending = false; $cost = new FinanceCostLedger($tenant);
        foreach ($prepared as [$arrival, $quantity]) {
            $basis = FinanceValue::decode($arrival['snapshot']);
            $snapshot = array_merge($basis, ['arrival_line_id' => (int)$arrival['id'], 'quantity' => $quantity, 'reason' => $reason, 'source_reference' => $reference]);
            $id = (int)Db::name('finance_purchase_return_line')->insertGetId(['tenant_id' => $tenant, 'document_id' => $document['id'], 'arrival_line_id' => $arrival['id'],
                'vendor_id' => $vendor, 'warehouse_id' => $warehouse, 'sku_id' => $arrival['sku_id'], 'business_date' => $date, 'quantity' => $quantity,
                'snapshot' => FinanceValue::json($snapshot), 'create_time' => time()]);
            $movement = StockService::outboundFinancePurchaseReturnWithinTransaction($warehouse, (int)$basis['goods_id'], (int)$arrival['sku_id'], $quantity, (int)$document['id'], $id, $date);
            $destination = $cost->destination($warehouse, (int)$arrival['sku_id'], 'return', 'purchase-return:' . $id);
            $negative = bccomp($movement['after_on_hand_qty'], '0', 4) < 0 ? ltrim($movement['after_on_hand_qty'], '-') : '0.0000';
            $lines[] = array_merge($snapshot, ['return_line_id' => $id, 'warehouse_id' => $warehouse, 'return_cost' => $destination['cost'],
                'known_return_cost' => $destination['known_cost'], 'cost_pending' => $destination['pending'], 'negative_stock_quantity' => $negative,
                'negative_attribution_id' => $movement['negative_attribution_id']]);
            $pending = $pending || $destination['pending'];
        }
        return ['type' => 'purchase_return_actual', 'subject_id' => $vendor, 'subject_name' => $subject['supplier_name'], 'warehouse_id' => $warehouse,
            'warehouse_name' => $store['name'], 'actual_date' => $date, 'posting_month' => $month, 'reason' => $reason, 'source_reference' => $reference,
            'lines' => $lines, 'cost_pending' => $pending, 'created_sources' => []];
    }
}
