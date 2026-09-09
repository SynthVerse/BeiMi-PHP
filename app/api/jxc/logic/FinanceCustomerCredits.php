<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

/** 客户贷项与退款共用来源余额；人员指定抵扣组成，不重复确认销售或资金。 */
final class FinanceCustomerCredits
{
    public static function confirm(FinanceLedger $ledger, array $document, array $data): array
    {
        $customer = FinanceValue::id($data['subject_id'] ?? null); $reference = FinanceValue::text($data['credit_source'] ?? null, 40);
        $credit = $ledger->source($reference); $reason = FinanceValue::text($data['reason'] ?? null, 1000);
        if ($credit['category'] !== 'customer_refund' || $credit['subject_id'] !== $customer) { throw new \DomainException('请选择本客户合法贷项或应退款来源'); }
        $lines = $data['allocations'] ?? null;
        if (!is_array($lines) || !array_is_list($lines) || !$lines || count($lines) > 200) { throw new \DomainException('请选择一至二百项本客户正式应收及明确抵扣金额'); }
        $total = '0.00'; $seen = [];
        foreach ($lines as $line) {
            if (!is_array($line)) { throw new \DomainException('贷项冲抵组成无效'); }
            $target = $ledger->source(FinanceValue::text($line['source'] ?? null, 40));
            if ($target['category'] !== 'receivable' || $target['subject_id'] !== $customer || isset($seen[$target['reference']])) { throw new \DomainException('贷项只能冲抵本客户不重复的正式应收'); }
            $seen[$target['reference']] = true; $amount = FinanceValue::money($line['amount'] ?? null);
            $timing = $ledger->allocationTiming($target, $credit['business_date']);
            if ($credit['business_date'] === null) { $timing['month'] = $ledger->postingMonth(max($timing['date'], $target['business_date'] ?? $timing['date'])); }
            $details = ['credit_source' => $reference, 'receivable_source' => $target['reference'], 'reason' => $reason];
            $ledger->add((int)$document['id'], 'balance', $customer, '-' . $amount, $timing['date'], $timing['month'], 'sales_credit', $target['reference'], $timing['effective_date'], $details);
            $ledger->add((int)$document['id'], 'balance', $customer, '-' . $amount, $timing['date'], $timing['month'], 'credit_use', $reference, $timing['effective_date'], $details);
            $total = bcadd($total, $amount, 2);
        }
        return ['type' => 'customer_credit_allocate', 'subject_id' => $customer, 'subject_name' => $credit['subject_name'], 'credit_source' => $reference,
            'allocated_amount' => $total, 'credit_remaining' => $ledger->source($reference)['balance'], 'reason' => $reason, 'created_sources' => []];
    }
}
