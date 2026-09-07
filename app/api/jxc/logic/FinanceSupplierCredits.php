<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

/** 供应商贷项和应退款使用同一可追溯来源，只按人工组成冲抵本供应商应付。 */
final class FinanceSupplierCredits
{
    public static function allocate(FinanceLedger $ledger, int $documentId, int $vendor, string $creditReference, array $lines, string $date): string
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
            $details = ['credit_source' => $creditReference, 'payable_source' => $reference];
            $ledger->add($documentId, 'balance', $vendor, '-' . $amount, $timing['date'], $timing['month'], 'supplier_credit', $reference, $timing['effective_date'], $details);
            $ledger->add($documentId, 'balance', $vendor, '-' . $amount, $timing['date'], $timing['month'], 'credit_use', $creditReference, $timing['effective_date'], $details);
            $total = bcadd($total, $amount, 2);
        }
        return $total;
    }
}
