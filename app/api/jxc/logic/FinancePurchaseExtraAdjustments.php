<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 已确认入库附加成本的金额与归属修订；原账单、已付款和原分配永不覆盖。 */
final class FinancePurchaseExtraAdjustments
{
    public static function options(int $vendor, array $params): array
    {
        $page = FinanceValue::id($params['page'] ?? 1); $keyword = FinanceValue::text($params['keyword'] ?? '', 60, false);
        $rows = Db::name('finance_purchase_cost_bill')->where('tenant_id', FinanceAccess::tenant())->where('vendor_id', $vendor)
            ->whereLike('source_reference', '%' . $keyword . '%')->order('id', 'desc')->limit(($page - 1) * 20, 21)->select()->toArray();
        $choices = [];
        foreach (array_slice($rows, 0, 20) as $row) {
            $current = self::current((int)$row['document_id'], $vendor);
            $choices[] = ['original_cost_document_id' => (int)$row['document_id'], 'subject_id' => $vendor, 'source_reference' => $row['source_reference'],
                'current_amount' => $current['current_amount'], 'expected_revision_id' => $current['expected_revision_id'],
                'cost_kind' => $current['snapshot']['cost_kind'], 'actual_date' => $current['snapshot']['actual_date'], 'lines' => $current['lines']];
        }
        return ['sources' => [], 'has_more' => false, 'bills' => $choices, 'bill_has_more' => count($rows) > 20];
    }

    public static function current(int $documentId, int $vendor): array
    {
        $tenant = FinanceAccess::tenant();
        $bill = Db::name('finance_purchase_cost_bill')->where('tenant_id', $tenant)->where('document_id', $documentId)->where('vendor_id', $vendor)->lock(true)->find();
        if (!$bill) { throw new \DomainException('原附加费用账单不存在或不属于本门店收款方'); }
        $revision = Db::name('finance_purchase_cost_revision')->where('tenant_id', $tenant)->where('bill_id', $bill['id'])->order('id', 'desc')->lock(true)->find();
        $snapshot = FinanceValue::decode($revision ? $revision['snapshot'] : $bill['snapshot']);
        return ['bill' => $bill, 'snapshot' => $snapshot, 'expected_revision_id' => (int)($revision['id'] ?? 0),
            'current_amount' => $revision['new_amount'] ?? $bill['amount'],
            'lines' => array_values(array_filter($snapshot['lines'], static fn(array $line): bool => bccomp($line['amount'], '0', 2) > 0))];
    }

    public static function confirm(FinanceLedger $ledger, array $document, array $data): array
    {
        FinanceAccess::require('', true); $tenant = FinanceAccess::tenant(); $id = (int)$document['id'];
        $vendor = FinanceValue::id($data['subject_id'] ?? null); $originalDocument = FinanceValue::id($data['original_cost_document_id'] ?? null);
        $current = self::current($originalDocument, $vendor); $bill = $current['bill']; $original = FinanceValue::decode($bill['snapshot']);
        if (FinanceValue::id($data['expected_revision_id'] ?? null, true) !== $current['expected_revision_id']) { throw new \DomainException('附加成本已有后续修订，请读取当前金额及分配'); }
        if (($data['supplier_confirmed'] ?? null) !== 1) { throw new \DomainException('请明确核实收款方认可本次费用金额'); }
        $amount = FinanceValue::money($data['new_amount'] ?? null, true); $delta = bcsub($amount, $current['current_amount'], 2);
        $date = $original['actual_date']; $month = $ledger->postingMonth($date);
        $snapshot = ['type' => 'purchase_extra_adjustment', 'subject_id' => $vendor, 'subject_name' => $original['subject_name'],
            'original_cost_document_id' => $originalDocument, 'bill_id' => (int)$bill['id'], 'source_reference' => $bill['source_reference'], 'cost_kind' => $original['cost_kind'],
            'actual_date' => $date, 'before_amount' => $current['current_amount'], 'new_amount' => $amount, 'amount_change' => $delta,
            'reason' => FinanceValue::text($data['reason'] ?? null, 1000), 'supplier_confirmation' => FinanceValue::text($data['supplier_confirmation'] ?? null, 1000),
            'attribution_basis' => FinanceValue::text($data['attribution_basis'] ?? null, 1000), 'confirmed_by' => FinanceAccess::actor(), 'confirmed_at' => time()];
        $input = $data['lines'] ?? null;
        if (!is_array($input) || !array_is_list($input) || count($input) > 200) { throw new \DomainException('请明确核对调整后最多二百条到货分配'); }
        $before = array_column($current['lines'], null, 'arrival_line_id'); $next = []; $total = '0.00';
        foreach ($input as $item) {
            if (!is_array($item)) { throw new \DomainException('附加成本分配格式无效'); }
            $arrivalId = FinanceValue::id($item['arrival_line_id'] ?? null);
            if (isset($next[$arrivalId])) { throw new \DomainException('调整后的到货分配不能重复'); }
            $next[$arrivalId] = FinanceValue::money($item['amount'] ?? null, true); $total = bcadd($total, $next[$arrivalId], 2);
        }
        if (bccomp($total, $amount, 2) !== 0) { throw new \DomainException('调整后到货分配合计必须等于调整后费用总额'); }
        $ids = array_values(array_unique(array_merge(array_keys($before), array_keys($next)))); sort($ids, SORT_NUMERIC);
        $lines = []; $changed = false; $months = [$month];
        foreach ($ids as $arrivalId) {
            $arrival = Db::name('finance_purchase_arrival_line')->where('tenant_id', $tenant)->where('id', $arrivalId)->lock(true)->find();
            if (!$arrival) { throw new \DomainException('费用关联到货不存在或不属于本门店'); }
            $lineAmount = $next[$arrivalId] ?? '0.00'; $lineDelta = bcsub($lineAmount, $before[$arrivalId]['amount'] ?? '0.00', 2);
            $line = array_merge(FinanceValue::decode($arrival['snapshot']), ['arrival_line_id' => (int)$arrivalId, 'actual_date' => $arrival['business_date'], 'amount' => $lineAmount, 'amount_change' => $lineDelta]);
            if (bccomp($lineDelta, '0', 2) !== 0) {
                $changed = true;
                Db::name('finance_purchase_cost_change')->insert(['tenant_id' => $tenant, 'document_id' => $id, 'arrival_line_id' => $arrivalId,
                    'kind' => 'extra_adjustment', 'amount' => $lineDelta, 'snapshot' => FinanceValue::json($snapshot + ['allocation' => $line]), 'create_time' => time()]);
                $line = array_merge($line, FinancePurchaseCosts::revalue($arrival, $document, $snapshot + ['allocation' => $line]));
            } else { $line = array_merge($line, FinancePurchaseCosts::value($arrival)); }
            $lines[] = $line; $months[] = $ledger->postingMonth($arrival['business_date']);
        }
        if (!$changed) { throw new \DomainException('费用金额和到货分配均未变化'); }
        $source = Db::name('finance_source')->where('tenant_id', $tenant)->where('document_id', $originalDocument)->where('category', 'expense_payable')->value('id');
        $due = $source ? $ledger->source('n:' . $source)['due_date'] : ($original['due_date'] ?? null);
        $financial = FinanceSupplierCredits::recognizeChange($ledger, $id, 'expense_payable', $delta, $due, $snapshot, $data);
        $result = $snapshot + $financial + ['lines' => $lines, 'posting_months' => array_values(array_unique($months)), 'previous_revision_id' => $current['expected_revision_id']];
        $revision = (int)Db::name('finance_purchase_cost_revision')->insertGetId(['tenant_id' => $tenant, 'document_id' => $id, 'bill_id' => $bill['id'],
            'previous_revision_id' => $current['expected_revision_id'], 'new_amount' => $amount, 'snapshot' => FinanceValue::json($result), 'create_time' => time()]);
        return $result + ['revision_id' => $revision];
    }
}
