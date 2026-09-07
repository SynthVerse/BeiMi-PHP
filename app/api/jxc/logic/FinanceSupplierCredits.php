<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

/** 供应商贷项和应退款使用同一可追溯来源，只按人工组成冲抵本供应商应付。 */
final class FinanceSupplierCredits
{
    public static function recognizeChange(FinanceLedger $ledger, int $documentId, string $category, string $delta, ?string $due, array $snapshot, array $data): array
    {
        if (!in_array($category, ['payable', 'expense_payable'], true)) { throw new \LogicException('Unsupported supplier obligation category'); }
        $delta = FinanceValue::money($delta, true, true); $vendor = $snapshot['subject_id']; $date = $snapshot['actual_date'];
        $created = []; $used = '0.00'; $remaining = '0.00';
        if (bccomp($delta, '0', 2) >= 0) {
            if (!empty($data['credit_allocations'])) { throw new \DomainException('本次未调减金额，请移除原贷项冲抵组成后核对'); }
            if (bccomp($delta, '0', 2) > 0) { $created[] = $ledger->createSource($documentId, $category, $vendor, $delta, $date, $due, $snapshot); }
        } else {
            if (($data['credit_reviewed'] ?? null) !== 1 || !is_array($data['credit_allocations'] ?? null)) { throw new \DomainException('调减须明确核对贷项冲抵组成及剩余应退款'); }
            $creditAmount = bcsub('0', $delta, 2);
            $credit = $ledger->createSource($documentId, 'supplier_refund', $vendor, $creditAmount, $date, null, $snapshot + ['credit_kind' => 'purchase_reduction']);
            $used = self::allocate($ledger, $documentId, $vendor, $credit, $data['credit_allocations'], $date);
            $remaining = bcsub($creditAmount, $used, 2); $created[] = $credit;
        }
        return ['created_sources' => $created, 'credit_used' => $used, 'refund_remaining' => $remaining];
    }

    public static function confirm(FinanceLedger $ledger, array $document, array $data): array
    {
        FinanceAccess::require('', true); $vendor = FinanceValue::id($data['subject_id'] ?? null);
        $reference = FinanceValue::text($data['credit_source'] ?? null, 40); $credit = $ledger->source($reference);
        $reason = FinanceValue::text($data['reason'] ?? null, 1000); $lines = $data['allocations'] ?? null;
        if (!is_array($lines) || !$lines) { throw new \DomainException('请选择本次指定抵扣的应付及金额'); }
        $total = self::allocate($ledger, (int)$document['id'], $vendor, $reference, $lines, $credit['business_date']);
        return ['type' => 'supplier_credit_allocate', 'subject_id' => $vendor, 'subject_name' => $credit['subject_name'],
            'credit_source' => $reference, 'allocated_amount' => $total, 'reason' => $reason, 'created_sources' => []];
    }

    public static function allocate(FinanceLedger $ledger, int $documentId, int $vendor, string $creditReference, array $lines, ?string $date): string
    {
        $credit = $ledger->source($creditReference);
        if ($credit['category'] !== 'supplier_refund' || $credit['subject_id'] !== $vendor) { throw new \DomainException('请选择本供应商合法贷项或应退款来源'); }
        if (!array_is_list($lines) || count($lines) > 200) { throw new \DomainException('贷项冲抵须为最多二百项明确应付组成'); }
        $seen = []; $total = '0.00';
        foreach ($lines as $line) {
            if (!is_array($line)) { throw new \DomainException('贷项冲抵组成无效'); }
            $reference = FinanceValue::text($line['source'] ?? null, 40); $source = $ledger->source($reference);
            if (isset($seen[$reference]) || $source['subject_id'] !== $vendor || !in_array($source['category'], ['payable', 'expense_payable'], true)) { throw new \DomainException('贷项只能冲抵本供应商不重复的采购或费用应付'); }
            $seen[$reference] = true; $amount = FinanceValue::money($line['amount'] ?? null);
            $timing = $ledger->allocationTiming($source, $date);
            // 期初贷项日期未知仍保留未知标记，入账不能早于已知的目标应付日期。
            if ($date === null) { $timing['month'] = $ledger->postingMonth(max($timing['date'], $source['business_date'] ?? $timing['date'])); }
            $details = ['credit_source' => $creditReference, 'payable_source' => $reference];
            $ledger->add($documentId, 'balance', $vendor, '-' . $amount, $timing['date'], $timing['month'], 'supplier_credit', $reference, $timing['effective_date'], $details);
            $ledger->add($documentId, 'balance', $vendor, '-' . $amount, $timing['date'], $timing['month'], 'credit_use', $creditReference, $timing['effective_date'], $details);
            $total = bcadd($total, $amount, 2);
        }
        return $total;
    }
}
