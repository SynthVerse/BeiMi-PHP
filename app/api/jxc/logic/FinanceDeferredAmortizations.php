<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 同一来源月份只有一个当前结果，取消和重新确认均保留原事实。 */
final class FinanceDeferredAmortizations
{
    public static function months(string $reference): array
    {
        $tenant = FinanceAccess::tenant(); $months = [];
        foreach (Db::name('finance_deferred_amortization')->where('tenant_id', $tenant)->where('source_ref', $reference)->select()->toArray() as $row) {
            $months[$row['benefit_month']] = ['document_id' => (int)$row['document_id'], 'status' => 'confirmed', 'result' => FinanceValue::decode($row['snapshot']),
                'history' => [['document_id' => (int)$row['document_id'], 'type' => 'deferred_amortization', 'status' => 'confirmed']]];
        }
        foreach (Db::name('finance_deferred_amortization_revision')->where('tenant_id', $tenant)->where('source_ref', $reference)->order('id')->select()->toArray() as $row) {
            $history = $months[$row['benefit_month']]['history']; $result = FinanceValue::decode($row['snapshot']);
            $history[] = ['document_id' => (int)$row['document_id'], 'type' => $result['type'], 'status' => $row['status']];
            $months[$row['benefit_month']] = ['document_id' => (int)$row['document_id'], 'status' => $row['status'], 'result' => $result, 'history' => $history];
        }
        return $months;
    }

    public static function append(array $document, array $result, int $previous, string $status): void
    {
        Db::name('finance_deferred_amortization_revision')->insert(['tenant_id' => FinanceAccess::tenant(), 'source_ref' => $result['source'], 'benefit_month' => $result['benefit_month'],
            'document_id' => $document['id'], 'previous_document_id' => $previous, 'status' => $status, 'snapshot' => FinanceValue::json($result), 'create_time' => time()]);
    }

    public static function cancel(FinanceLedger $ledger, array $document, array $input): array
    {
        FinanceAccess::require('', true);
        $reference = FinanceValue::text($input['source'] ?? null, 40); $source = $ledger->source($reference);
        $vendor = FinanceValue::id($input['subject_id'] ?? null); $month = FinanceValue::text($input['benefit_month'] ?? null, 7);
        if ($source['category'] !== 'deferred' || $source['subject_id'] !== $vendor) { throw new \DomainException('请选择本对象的待摊来源'); }
        $current = self::months($reference)[$month] ?? null;
        if (!$current || $current['status'] !== 'confirmed' || FinanceValue::id($input['expected_amortization_document_id'] ?? null) !== $current['document_id']) { throw new \DomainException('本月摊销已有后续处理或尚未确认，请读取最新记录'); }
        if (($input['cancellation_verified'] ?? null) !== 1) { throw new \DomainException('请核实原摊销确有错误并退回待核实'); }
        $reason = FinanceValue::text($input['reason'] ?? null, 1000); $before = $current['result']; $amount = $before['amount'];
        $posting = $ledger->postingMonth($before['actual_date']); $id = (int)$document['id'];
        $ledger->add($id, 'balance', $vendor, $amount, $before['actual_date'], $posting, 'deferred_amortization_cancel', $reference, $before['actual_date'], ['benefit_month' => $month, 'reason' => $reason]);
        $lines = [];
        foreach ($before['lines'] as $line) {
            $reverse = array_replace($line, ['amount' => '-' . $line['amount']]); $lines[] = $reverse;
            $ledger->add($id, 'expense', $vendor, $reverse['amount'], $before['actual_date'], $posting, 'deferred_amortization_cancel', $reference, null, $reverse + ['benefit_month' => $month]);
        }
        $result = ['type' => 'deferred_amortization_cancel', 'source' => $reference, 'source_reference' => $before['source_reference'], 'subject_id' => $vendor, 'subject_name' => $before['subject_name'],
            'benefit_month' => $month, 'actual_date' => $before['actual_date'], 'posting_month' => $posting, 'amount' => '-' . $amount, 'before_balance' => $source['balance'], 'after_balance' => bcadd($source['balance'], $amount, 2),
            'original_amortization_document_id' => $current['document_id'], 'lines' => $lines, 'reason' => $reason, 'confirmed_by' => FinanceAccess::actor(), 'confirmed_at' => time(), 'created_sources' => []];
        self::append($document, $result, $current['document_id'], 'pending');
        return $result;
    }
}
