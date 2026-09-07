<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 消费实际到货覆盖量，追加正式应付与成本差额；不在此处改变实物库存。 */
final class FinancePurchaseBatches
{
    public static function options(int $vendor, array $params): array
    {
        $tenant = FinanceAccess::tenant(); $page = max(1, FinanceValue::id($params['page'] ?? 1));
        $from = FinanceValue::date($params['date_from'] ?? '1900-01-01'); $to = FinanceValue::date($params['date_to'] ?? date('Y-m-d'));
        if ($from > $to || $to > date('Y-m-d')) { throw new \DomainException('到货日期范围无效'); }
        $coverage = Db::name('finance_purchase_settlement_line')->where('tenant_id', $tenant)->field('arrival_line_id,SUM(covered_quantity) AS quantity')->group('arrival_line_id')->buildSql();
        $query = Db::name('finance_purchase_arrival_line')->alias('a')->leftJoin([$coverage => 'c'], 'c.arrival_line_id=a.id')
            ->where('a.tenant_id', $tenant)->where('a.vendor_id', $vendor)->whereBetween('a.business_date', [$from, $to])
            ->whereRaw('a.actual_quantity>COALESCE(c.quantity,0)');
        $keyword = FinanceValue::text($params['keyword'] ?? '', 60, false);
        if ($keyword !== '') { $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(a.snapshot,'$.goods_name')) LIKE ?", ['%' . $keyword . '%']); }
        $rows = $query->field('a.*,COALESCE(c.quantity,0) AS covered_quantity,a.actual_quantity-COALESCE(c.quantity,0) AS pending_quantity')
            ->order('a.business_date,a.id')->limit(($page - 1) * 20, 21)->select()->toArray();
        $more = count($rows) > 20; $rows = array_slice($rows, 0, 20); $arrivals = [];
        foreach ($rows as $row) {
            $snapshot = FinanceValue::decode($row['snapshot']);
            $rule = FinancePurchaseRuleBook::threshold($vendor, (int)$row['sku_id'], (int)($snapshot['category_id'] ?? 0));
            $arrivals[] = array_merge($snapshot, ['arrival_line_id' => (int)$row['id'], 'arrival_document_id' => (int)$row['document_id'], 'subject_id' => $vendor,
                'warehouse_id' => (int)$row['warehouse_id'], 'actual_date' => $row['business_date'],
                'covered_quantity' => bcadd($row['covered_quantity'], '0', 4), 'pending_quantity' => bcadd($row['pending_quantity'], '0', 4),
                'terms' => FinancePurchaseRuleBook::terms($vendor, $row['business_date']), 'settlement_difference_rule' => $rule]);
        }
        return ['sources' => [], 'has_more' => false, 'arrivals' => $arrivals, 'arrival_has_more' => $more,
            'can_override_due' => FinanceAccess::has('finance.purchase.due_override'), 'can_confirm_difference' => FinanceAccess::owner()];
    }

    public static function confirm(FinanceLedger $ledger, array $document, array $data): array
    {
        $tenant = FinanceAccess::tenant(); $id = (int)$document['id'];
        $vendor = FinanceValue::id($data['subject_id'] ?? null);
        $name = Db::name('vendor')->where('tenant_id', $tenant)->where('id', $vendor)->value('supplier_name');
        if ($name === null) { throw new \DomainException('请选择本门店供应商'); }
        $reason = FinanceValue::text($data['reason'] ?? null, 1000);
        $confirmation = FinanceValue::text($data['supplier_confirmation'] ?? null, 1000);
        if (($data['supplier_confirmed'] ?? null) !== 1) { throw new \DomainException('请明确核实供应商已确认本次结算'); }
        $input = $data['lines'] ?? null;
        if (!is_array($input) || !array_is_list($input) || !$input || count($input) > 200) { throw new \DomainException('请选择一至二百条真实到货来源'); }
        $lines = []; $created = []; $seen = []; $amount = '0.00'; $months = [];
        foreach ($input as $item) {
            if (!is_array($item)) { throw new \DomainException('采购结算明细格式无效'); }
            $arrivalId = FinanceValue::id($item['arrival_line_id'] ?? null);
            if (isset($seen[$arrivalId])) { throw new \DomainException('同一次结算不能重复选择到货来源'); } $seen[$arrivalId] = true;
            $arrival = Db::name('finance_purchase_arrival_line')->where('tenant_id', $tenant)->where('vendor_id', $vendor)->where('id', $arrivalId)->lock(true)->find();
            if (!$arrival) { throw new \DomainException('实际到货来源不存在或不属于本供应商'); }
            $snapshot = FinanceValue::decode($arrival['snapshot']);
            $covered = FinancePurchaseSettlement::quantity($item['covered_quantity'] ?? null);
            $quantity = FinancePurchaseSettlement::quantity($item['settlement_quantity'] ?? null);
            $price = FinanceValue::money($item['price'] ?? null, true);
            $lineAmount = FinanceValue::money(bcadd(bcmul($quantity, $price, 6), '0.005', 2), true);
            $zeroReason = bccomp($price, '0', 2) === 0 ? FinanceValue::text($item['zero_price_reason'] ?? null, 500) : '';
            $costValue = FinancePurchaseCosts::value($arrival, $covered, $lineAmount);
            $arrivalReview = self::difference($snapshot['arrival_difference'] ?? '0', $snapshot['reported_quantity'] ?? $arrival['actual_quantity'], $snapshot['difference_rule'] ?? null, $item, 'arrival_');
            $differenceRule = FinancePurchaseRuleBook::threshold($vendor, (int)$arrival['sku_id'], (int)($snapshot['category_id'] ?? 0));
            if (FinanceValue::id($item['difference_rule_id'] ?? 0, true) !== (int)($differenceRule['id'] ?? 0)) { throw new \DomainException('采购差异复核规则已变化，请重新核对本次结算'); }
            $difference = bcsub($quantity, $covered, 4); $settlementReview = self::difference($difference, $covered, $differenceRule, $item, '');
            $terms = FinancePurchaseRuleBook::terms($vendor, $arrival['business_date']);
            if (FinanceValue::id($item['terms_version'] ?? 0, true) !== $terms['version']) { throw new \DomainException('供应商付款规则已变化，请重新核对'); }
            $due = array_key_exists('due_date', $item) ? FinanceValue::date($item['due_date'], true) : $terms['default_due_date']; $dueReason = '';
            $dueApplied = $due !== $terms['default_due_date'];
            if ($dueApplied) { FinanceAccess::require('finance.purchase.due_override'); $dueReason = FinanceValue::text($item['due_override_reason'] ?? null, 1000); }
            if ($due && $due < $arrival['business_date']) { throw new \DomainException('付款日不能早于实际到货日期'); }
            $month = $ledger->postingMonth($arrival['business_date']); $months[] = $month;
            $line = array_merge($snapshot, $costValue, ['arrival_line_id' => $arrivalId, 'warehouse_id' => (int)$arrival['warehouse_id'], 'actual_date' => $arrival['business_date'],
                'covered_quantity' => $covered, 'settlement_quantity' => $quantity, 'price' => $price, 'amount' => $lineAmount, 'zero_price_reason' => $zeroReason,
                'settlement_difference' => $difference, 'arrival_review' => $arrivalReview, 'settlement_review' => $settlementReview,
                'terms' => $terms, 'due_date' => $due, 'due_override_reason' => $dueReason, 'due_override_applied' => $dueApplied,
                'subject_name' => $name, 'supplier_confirmation' => $confirmation, 'reason' => $reason,
                'confirmed_by' => FinanceAccess::actor(), 'confirmed_at' => time()]);
            $source = '';
            if (bccomp($lineAmount, '0', 2) > 0) { $source = $ledger->createSource($id, 'payable', $vendor, $lineAmount, $arrival['business_date'], $due, $line); $created[] = $source; }
            $cost = (new FinanceCostLedger($tenant))->recordWithinTransaction(['reference' => 'purchase-settlement:' . $id . ':' . $arrivalId, 'type' => 'reestimate',
                'sku_id' => (int)$arrival['sku_id'], 'warehouse_id' => (int)$arrival['warehouse_id'], 'document_id' => $id,
                'business_date' => $arrival['business_date'], 'origin' => 'purchase-arrival:' . $arrivalId, 'amount' => $costValue['known_amount'],
                'pending' => $costValue['cost_pending'], 'snapshot' => $line]);
            $line['payable_source'] = $source; $line['cost_changes'] = $cost['changes'];
            Db::name('finance_purchase_settlement_line')->insert(['tenant_id' => $tenant, 'document_id' => $id, 'arrival_line_id' => $arrivalId,
                'covered_quantity' => $covered, 'settlement_quantity' => $quantity, 'price' => $price, 'amount' => $lineAmount, 'payable_source' => $source,
                'snapshot' => FinanceValue::json($line), 'create_time' => time()]);
            Db::name('finance_purchase_price')->insert(['tenant_id' => $tenant, 'vendor_id' => $vendor, 'sku_id' => $arrival['sku_id'], 'document_id' => $id,
                'price' => $price, 'business_date' => $arrival['business_date'], 'create_time' => time()]);
            $lines[] = $line; $amount = bcadd($amount, $lineAmount, 2);
        }
        return ['type' => 'purchase_settlement', 'subject_id' => $vendor, 'subject_name' => $name, 'reason' => $reason, 'supplier_confirmation' => $confirmation,
            'lines' => $lines, 'amount' => FinanceValue::money($amount, true), 'created_sources' => $created, 'posting_months' => array_values(array_unique($months))];
    }

    private static function difference(?string $quantity, string $baseline, ?array $rule, array $data, string $prefix): array
    {
        $assessment = FinancePurchaseDifference::assess($quantity ?? '0', $baseline, $rule);
        if (!$assessment['requires_confirmation']) { return $assessment + ['reviewed' => false]; }
        if ($assessment['requires_owner']) { FinanceAccess::require('', true); }
        if (($data[$prefix . 'difference_confirmed'] ?? null) !== 1) { throw new \DomainException('非零采购重量差必须由人员明确确认'); }
        $class = FinanceValue::text($data[$prefix . 'difference_class'] ?? null, 40);
        if (!in_array($class, ['normal', 'supplier'], true)) { throw new \DomainException('尚未查明或异常损失的采购差异应先独立处理，不能自动并入库存成本'); }
        return $assessment + ['reviewed' => true, 'classification' => $class, 'reason' => FinanceValue::text($data[$prefix . 'difference_reason'] ?? null, 1000),
            'actor' => FinanceAccess::actor(), 'confirmed_at' => time()];
    }

    public static function reauthorize(array $result): void
    {
        foreach ($result['lines'] ?? [] as $line) {
            if (!empty($line['arrival_review']['requires_owner']) || !empty($line['settlement_review']['requires_owner'])) { FinanceAccess::require('', true); }
            if (!empty($line['due_override_applied'])) { FinanceAccess::require('finance.purchase.due_override'); }
        }
    }
}
