<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 只核实启用承接的旧售退回成本，不能指定任意成本来源或重做实物、往来。 */
final class FinanceLegacyReturnCosts
{
    public static function options(array $params): array
    {
        $page = FinanceValue::id($params['page'] ?? 1);
        $query = Db::name('finance_cost_event')->where('tenant_id', FinanceAccess::tenant())->where('event_type', 'receive')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(snapshot,'$.snapshot.cost_basis_pending')) = 'pre_cutoff_sales_return'");
        $voids = Db::name('finance_cost_event')->where('tenant_id', FinanceAccess::tenant())->where('event_type', 'customer_return_void')
            ->field("JSON_UNQUOTE(JSON_EXTRACT(snapshot,'$.return_reference'))")->buildSql();
        $query->whereRaw('reference NOT IN ' . $voids);
        if (!empty($params['stock_flow_id'])) { $query->where('reference', 'stock:' . FinanceValue::id($params['stock_flow_id'])); }
        $rows = $query->order('id desc')->limit(($page - 1) * 20, 21)->select()->toArray(); $sources = [];
        foreach (array_slice($rows, 0, 20) as $row) {
            $event = FinanceValue::decode($row['snapshot']);
            $sources[] = self::source((int)$event['snapshot']['stock_flow_id']);
        }
        return ['sources' => [], 'has_more' => false, 'cost_sources' => $sources, 'cost_has_more' => count($rows) > 20];
    }

    private static function source(int $flow, bool $lock = false): array
    {
        $tenant = FinanceAccess::tenant(); $reference = 'stock:' . $flow;
        $row = Db::name('finance_cost_event')->where('tenant_id', $tenant)->where('reference', $reference)->lock($lock)->find();
        $event = $row ? FinanceValue::decode($row['snapshot']) : [];
        $customerReturn = false;
        if (($event['snapshot']['order_type'] ?? '') === 'finance_customer_return') {
            FinanceCustomerReturnCorrections::assertCurrent((int)$row['document_id']);
            $document = Db::name('finance_document')->where('tenant_id', $tenant)->where('id', $row['document_id'])->where('type', 'customer_return_actual')->where('status', 'confirmed')->lock($lock)->find();
            $record = Db::name('finance_customer_return')->where('tenant_id', $tenant)->where('document_id', $row['document_id'])->where('sku_id', $row['sku_id'])->where('warehouse_id', $event['warehouse_id'])->lock($lock)->find();
            $fact = $record ? FinanceValue::decode($record['snapshot']) : [];
            $customerReturn = $document && $record && (int)($fact['stock_flow_id'] ?? 0) === $flow && ($fact['cost_basis'] ?? '') === 'pre_cutoff';
        }
        if (($event['type'] ?? '') !== 'receive' || (!(($event['snapshot']['bootstrap'] ?? false) === true) && !$customerReturn)
            || ($event['snapshot']['cost_basis_pending'] ?? '') !== 'pre_cutoff_sales_return' || ($event['origin'] ?? '') !== $reference) {
            throw new \DomainException('请选择本门店已确认的旧售退回成本来源');
        }
        $origin = Db::name('finance_cost_origin')->where('tenant_id', $tenant)->where('origin_key', $reference)->lock($lock)->find();
        if (!$origin) { throw new \DomainException('旧售退回成本来源不完整，请重新核实'); }
        $latest = Db::name('finance_cost_event')->where('tenant_id', $tenant)->where('sku_id', $row['sku_id'])
            ->whereIn('event_type', ['adjust', 'reestimate'])->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(snapshot,'$.origin')) = ?", [$reference])
            ->order('id desc')->lock($lock)->find();
        $sku = Db::name('goods_sku')->where('tenant_id', $tenant)->where('id', $row['sku_id'])->find() ?: [];
        $goods = $sku ? Db::name('goods')->where('tenant_id', $tenant)->where('id', $sku['goods_id'])->find() : [];
        $warehouse = Db::name('warehouse')->where('tenant_id', $tenant)->where('id', $event['warehouse_id'])->find() ?: [];
        return ['stock_flow_id' => $flow, 'origin' => $reference, 'expected_cost_event_id' => (int)($latest['id'] ?? $row['id']),
            'warehouse_id' => (int)$event['warehouse_id'], 'warehouse_name' => $warehouse['name'] ?? '原仓库名称未留存',
            'sku_id' => (int)$row['sku_id'], 'goods_name' => $goods['name'] ?? '原商品名称未留存', 'sku_name' => $sku['sku_name'] ?? '',
            'base_unit_name' => $sku['base_unit_name'] ?? '', 'quantity' => bcadd($origin['quantity'], '0', 4),
            'actual_date' => $row['business_date'], 'amount' => $origin['current_amount'],
            'return_source' => $event['snapshot']['return_source'], 'original_flow' => $event['snapshot']['original_flow']];
    }

    public static function confirm(FinanceLedger $ledger, array $document, array $data): array
    {
        FinanceAccess::require('', true); $source = self::source(FinanceValue::id($data['stock_flow_id'] ?? null), true);
        if (FinanceValue::id($data['expected_cost_event_id'] ?? null) !== $source['expected_cost_event_id']) { throw new \DomainException('原退回成本已更新，请核对最新金额和依据'); }
        if (($data['cost_verified'] ?? null) !== 1) { throw new \DomainException('请明确核实原退回商品的总成本及其凭据'); }
        $amount = FinanceValue::money($data['amount'] ?? null, true);
        if ($source['amount'] !== null && bccomp($source['amount'], $amount, 2) === 0) { throw new \DomainException('本次核实金额没有变化，无须重复确认'); }
        $reference = FinanceValue::text($data['source_reference'] ?? null, 160); $reason = FinanceValue::text($data['reason'] ?? null, 1000);
        $month = $ledger->postingMonth($source['actual_date']); $id = (int)$document['id']; $cost = new FinanceCostLedger(FinanceAccess::tenant());
        $snapshot = array_merge($source, ['type' => 'legacy_return_cost', 'previous_amount' => $source['amount'], 'amount' => $amount,
            'source_reference' => $reference, 'reason' => $reason, 'confirmed_by' => FinanceAccess::actor(), 'cost_pending' => false]);
        $cost->recordWithinTransaction(['reference' => 'legacy-return-cost:' . $id, 'type' => 'adjust', 'origin' => $source['origin'],
            'warehouse_id' => $source['warehouse_id'], 'sku_id' => $source['sku_id'], 'business_date' => $source['actual_date'],
            'amount' => $amount, 'document_id' => $id, 'snapshot' => $snapshot]);
        $impacts = $cost->documentImpacts($id);
        return $snapshot + ['lines' => [$snapshot], 'created_sources' => [], 'cost_impacts' => $impacts,
            'posting_months' => array_values(array_unique(array_merge([$month], array_column($impacts, 'posting_month'))))];
    }
}
