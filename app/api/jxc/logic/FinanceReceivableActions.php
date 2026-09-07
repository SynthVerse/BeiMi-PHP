<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

/** 应收业务事实的追加确认；坏账损失与追偿备查严格分开。 */
final class FinanceReceivableActions
{
    public function __construct(private readonly int $tenantId, private readonly FinanceLedger $ledger) {}

    public function confirm(array $document, array $data): array
    {
        $policy = FinanceDocumentPolicy::authorize($document['type'], true);
        $subject = FinanceValue::id($data['subject_id'] ?? 0); $date = FinanceValue::date($data['actual_date'] ?? null);
        $month = $this->ledger->postingMonth($date); $reason = FinanceValue::text($data['reason'] ?? '', 1000);
        $basis = FinanceValue::text($data['basis'] ?? '', 2000); $amount = FinanceValue::money($data['amount'] ?? null);
        $lines = $data['allocations'] ?? [];
        if (!is_array($lines) || !array_is_list($lines) || !$lines || count($lines) > 200) { throw new \DomainException('请逐项选择本次处理的正式来源与金额'); }
        if ($document['type'] === 'bad_debt' && ((int)($data['debt_verified'] ?? 0) !== 1 || (int)($data['undisputed'] ?? 0) !== 1)) {
            throw new \DomainException('坏账须核实债务真实、无争议，并提供充分事实依据');
        }
        $name = ''; $total = '0.00'; $created = []; $seen = [];
        foreach ($lines as $line) {
            if (!is_array($line)) { throw new \DomainException('处理组成格式无效'); }
            $source = $this->ledger->source(FinanceValue::text($line['source'] ?? '', 40));
            if (!in_array($source['category'], $policy['sources'], true) || $source['subject_id'] !== $subject || isset($seen[$source['reference']])) { throw new \DomainException('必须选择本客户不重复的对应业务来源'); }
            $seen[$source['reference']] = true; $name = $source['subject_name'];
            if ($document['type'] === 'bad_debt' && FinanceStatements::openDisputes([$source['reference']])) { throw new \DomainException('该来源仍有未解决对账争议，不能确认为无争议坏账'); }
            if ($source['business_date'] && $date < $source['business_date']) { throw new \DomainException('处理日期不能早于原债权业务日期'); }
            $value = FinanceValue::money($line['amount'] ?? null); $total = bcadd($total, $value, 2);
            $details = ['basis' => $basis, 'reason' => $reason, 'original_source' => $source['reference']];
            $this->ledger->add((int)$document['id'], 'balance', $subject, '-' . $value, $date, $month, $document['type'], $source['reference'], $date, $details);
            if ($document['type'] === 'bad_debt') {
                $created[] = $this->ledger->createSource((int)$document['id'], 'recovery', $subject, $value, $date, null,
                    $details + ['subject_name' => $name, 'receivable_business_date' => $source['business_date'], 'receivable_due_date' => $source['due_date']]);
                $this->ledger->add((int)$document['id'], 'loss', $subject, $value, $date, $month, 'bad_debt', '', null, $details);
            }
        }
        if (bccomp($total, $amount, 2) !== 0) { throw new \DomainException('本次金额须等于所选来源的处理合计'); }
        return ['type' => $document['type'], 'subject_id' => $subject, 'subject_name' => $name, 'allocated_amount' => $total,
            'created_sources' => $created, 'reason' => $reason, 'basis' => $basis, 'business_date' => $date, 'posting_month' => $month];
    }
}
