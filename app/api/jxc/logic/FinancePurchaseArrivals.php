<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 到货确认只追加实际库存、暂估成本及待结算事实，正式应付由供应商结算产生。 */
final class FinancePurchaseArrivals
{
    public static function options(int $vendorId, array $params): array
    {
        $tenant = FinanceAccess::tenant();
        $page = max(1, FinanceValue::id($params['page'] ?? 1));
        $date = FinanceValue::date($params['actual_date'] ?? date('Y-m-d'));
        $keyword = FinanceValue::text($params['keyword'] ?? '', 60, false);
        $warehouses = Db::name('warehouse')->where('tenant_id', $tenant)->order('id')->field('id,name')->select()->toArray();
        foreach ($warehouses as &$warehouse) { $warehouse['id'] = (int)$warehouse['id']; } unset($warehouse);
        $query = Db::name('goods_sku')->alias('s')->join('goods g', 'g.id=s.goods_id AND g.tenant_id=s.tenant_id')->where('s.tenant_id', $tenant);
        if ($keyword !== '') { $query->whereLike('g.name', '%' . $keyword . '%'); }
        $rows = $query->field('s.id AS sku_id,s.goods_id,s.sku_name,s.base_unit_id,s.base_unit_name,g.name AS goods_name')->order('s.id')
            ->limit(($page - 1) * 20, 21)->select()->toArray();
        $more = count($rows) > 20; $rows = array_slice($rows, 0, 20);
        foreach ($rows as &$row) {
            $row['sku_id'] = (int)$row['sku_id']; $row['goods_id'] = (int)$row['goods_id'];
            $last = Db::name('finance_purchase_price')->where('tenant_id', $tenant)->where('vendor_id', $vendorId)->where('sku_id', $row['sku_id'])
                ->where('business_date', '<=', $date)->order('id', 'desc')->find();
            $row['last_formal_price'] = $last ? ['price' => (string)$last['price'], 'reference' => 'purchase-settlement:' . $last['document_id']] : null;
        } unset($row);
        return ['sources' => [], 'has_more' => false, 'warehouses' => $warehouses, 'sku_choices' => $rows, 'sku_has_more' => $more];
    }

    public static function confirm(FinanceLedger $ledger, array $document, array $data): array
    {
        $tenant = FinanceAccess::tenant();
        $vendorId = FinanceValue::id($data['subject_id'] ?? null);
        $warehouseId = FinanceValue::id($data['warehouse_id'] ?? null);
        $vendor = Db::name('vendor')->where('tenant_id', $tenant)->where('id', $vendorId)->find();
        $warehouse = Db::name('warehouse')->where('tenant_id', $tenant)->where('id', $warehouseId)->find();
        if (!$vendor || !$warehouse) { throw new \DomainException('请选择本门店有效供应商与实际收货仓库'); }
        $date = FinanceValue::date($data['actual_date'] ?? null);
        if ($date > date('Y-m-d')) { throw new \DomainException('实际到货日期不能晚于今天'); }
        $month = $ledger->postingMonth($date);
        $source = FinanceValue::text($data['source_reference'] ?? null, 160);
        $reason = FinanceValue::text($data['reason'] ?? null, 1000);
        $input = $data['lines'] ?? null;
        if (!is_array($input) || !array_is_list($input) || !$input || count($input) > 200) { throw new \DomainException('请选择一至二百行实际到货商品'); }
        // 与报货预留采用相同 SKU 升序；客户端行序只决定单据展示顺序。
        $skuIds = [];
        foreach ($input as $item) {
            if (!is_array($item)) { throw new \DomainException('到货明细格式无效'); }
            $skuIds[] = FinanceValue::id($item['sku_id'] ?? null);
        }
        $skuIds = array_values(array_unique($skuIds)); sort($skuIds, SORT_NUMERIC);
        foreach ($skuIds as $skuId) {
            if (!WarehouseSkuBalanceService::lockBalanceWithinTransaction($warehouseId, $skuId)) { throw new \DomainException('到货商品规格不属于当前门店'); }
        }
        $lines = []; $total = '0.00'; $pending = false;
        foreach ($input as $index => $item) {
            if (!is_array($item)) { throw new \DomainException('到货明细格式无效'); }
            $skuId = FinanceValue::id($item['sku_id'] ?? null);
            $sku = Db::name('goods_sku')->where('tenant_id', $tenant)->where('id', $skuId)->lock(true)->find();
            $goods = $sku ? Db::name('goods')->where('tenant_id', $tenant)->where('id', $sku['goods_id'])->lock(true)->find() : null;
            if (!$sku || !$goods) { throw new \DomainException('到货商品规格不属于当前门店'); }
            $last = Db::name('finance_purchase_price')->where('tenant_id', $tenant)->where('vendor_id', $vendorId)->where('sku_id', $skuId)
                ->where('business_date', '<=', $date)->order('id', 'desc')->lock(true)->find();
            $estimate = FinancePurchaseEstimate::line($item, $last ? ['price' => (string)$last['price'], 'reference' => 'purchase-settlement:' . $last['document_id']] : null);
            $snapshot = $estimate + ['sku_id' => $skuId, 'goods_id' => (int)$sku['goods_id'], 'goods_name' => $goods['name'], 'category_id' => (int)$goods['category_id'],
                'difference_rule' => FinancePurchaseRuleBook::threshold($vendorId, $skuId, (int)$goods['category_id']),
                'sku_name' => $sku['sku_name'], 'base_unit_name' => $sku['base_unit_name'],
                'base_unit_id' => (int)$sku['base_unit_id'], 'source_reference' => $source];
            $id = (int)Db::name('finance_purchase_arrival_line')->insertGetId(['tenant_id' => $tenant, 'document_id' => $document['id'],
                'line_number' => $index + 1, 'vendor_id' => $vendorId, 'warehouse_id' => $warehouseId, 'sku_id' => $skuId,
                'business_date' => $date, 'actual_quantity' => $estimate['actual_quantity'], 'estimated_amount' => $estimate['estimated_amount'],
                'snapshot' => FinanceValue::json($snapshot), 'create_time' => time()]);
            StockService::inboundFinancePurchaseWithinTransaction($warehouseId, (int)$sku['goods_id'], $skuId, $estimate['actual_quantity'],
                (int)$document['id'], $id, $date, $estimate['estimated_amount'], $snapshot);
            $lines[] = $snapshot + ['arrival_line_id' => $id, 'origin' => 'purchase-arrival:' . $id, 'pending_quantity' => $estimate['actual_quantity']];
            if ($estimate['estimated_amount'] === null) { $pending = true; }
            else { $total = bcadd($total, $estimate['estimated_amount'], 2); }
        }
        return ['type' => 'purchase_arrival', 'subject_id' => $vendorId, 'subject_name' => $vendor['supplier_name'], 'warehouse_id' => $warehouseId,
            'actual_date' => $date, 'posting_month' => $month, 'source_reference' => $source, 'reason' => $reason, 'lines' => $lines,
            'known_estimated_amount' => $total, 'cost_pending' => $pending, 'created_sources' => []];
    }
}
