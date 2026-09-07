<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 原到账真实失效；只恢复明确核销，或减少该收款尚未使用的预收。 */
final class FinanceReceiptReturns
{
    public function __construct(private readonly int $tenantId, private readonly FinanceLedger $ledger) {}

    public function options(int $receiptId, int $excludeDocument = 0): array
    {
        FinanceDocumentPolicy::authorize('receipt_return');
        $receipt = $this->receipt($receiptId); $result = FinanceValue::decode($receipt['confirmed_result']);
        $transactionId = (int)$result['money']['transaction_id']; $available = [];
        $entries = Db::name('finance_entry')->where('tenant_id', $this->tenantId)->where('document_id', $receipt['id'])->where('purpose', 'allocation')->select()->toArray();
        foreach ($entries as $entry) {
            $source = $this->ledger->source($entry['source_ref']);
            $remaining = bcsub(bcsub('0', $entry['amount'], 2), $this->returned($transactionId, $source['reference'], $excludeDocument), 2);
            if (bccomp($remaining, '0', 2) > 0) { $available[] = $source + ['returnable' => $remaining, 'return_effect' => 'reopen_receivable']; }
        }
        foreach ($result['created_sources'] ?? [] as $reference) {
            $source = $this->ledger->source($reference);
            if ($source['category'] === 'advance' && bccomp($source['balance'], '0', 2) > 0) { $available[] = $source + ['returnable' => $source['balance'], 'return_effect' => 'reduce_unused_advance']; }
        }
        return ['tenant_id' => $this->tenantId, 'receipt_id' => (int)$receipt['id'], 'transaction_id' => $transactionId, 'subject_id' => (int)$result['subject_id'],
            'subject_name' => $result['subject_name'], 'received_date' => $result['money']['actual_date'], 'received_amount' => $result['money']['amount'], 'sources' => $available];
    }

    public function confirm(array $document, array $data, ?array $originalTransaction = null, int $correctingDocument = 0): array
    {
        FinanceDocumentPolicy::authorize('receipt_return', true);
        $options = $this->options(FinanceValue::id($data['receipt_id'] ?? 0), $correctingDocument);
        if (FinanceValue::id($data['subject_id'] ?? 0) !== $options['subject_id']) { throw new \DomainException('原收款不属于所选客户'); }
        $date = FinanceValue::date($data['actual_date'] ?? null); $month = $this->ledger->postingMonth($date);
        if ($date < $options['received_date']) { throw new \DomainException('实际退回日期不能早于原到账日期'); }
        $reason = FinanceValue::text($data['reason'] ?? '', 1000); $amount = FinanceValue::money($data['amount'] ?? null);
        $lines = $data['allocations'] ?? [];
        if (!is_array($lines) || !array_is_list($lines) || !$lines || count($lines) > 200) { throw new \DomainException('请明确本次恢复应收或减少未用预收的组成'); }
        $available = array_column($options['sources'], null, 'reference'); $total = '0.00'; $seen = [];
        foreach ($lines as $line) {
            if (!is_array($line)) { throw new \DomainException('退回组成格式无效'); }
            $reference = FinanceValue::text($line['source'] ?? '', 40); $value = FinanceValue::money($line['amount'] ?? null);
            if (!isset($available[$reference]) || isset($seen[$reference]) || bccomp($value, $available[$reference]['returnable'], 2) > 0) { throw new \DomainException('所选组成超过原收款当前可退回金额，或来源重复'); }
            $seen[$reference] = true; $source = $available[$reference]; $total = bcadd($total, $value, 2);
            $this->ledger->add((int)$document['id'], 'balance', $options['subject_id'], ($source['category'] === 'advance' ? '-' : '') . $value,
                $date, $month, 'receipt_return', $reference, $date, ['receipt_transaction_id' => $options['transaction_id'], 'receipt_document_id' => $options['receipt_id'], 'reason' => $reason]);
        }
        if (bccomp($total, $amount, 2) !== 0) { throw new \DomainException('实际退回金额必须等于明确处理组成'); }
        $money = (new FinanceMoney($this->tenantId, $this->ledger))->record((int)$document['id'], 'receipt_return', $data, 'out', $date, $amount, $month, $originalTransaction);
        return ['type' => 'receipt_return', 'subject_id' => $options['subject_id'], 'subject_name' => $options['subject_name'], 'receipt_id' => $options['receipt_id'],
            'receipt_transaction_id' => $options['transaction_id'], 'allocated_amount' => $total, 'reason' => $reason, 'money' => $money, 'created_sources' => []];
    }

    public function protectReceiptCorrection(array $original, array $replacement, int $duplicateOf): void
    {
        if ($original['type'] !== 'receipt') { return; }
        $result = FinanceValue::decode($original['confirmed_result']); $transactionId = (int)$result['money']['transaction_id'];
        $returned = $this->returnRows($transactionId)->field('source_ref,SUM(ABS(amount)) AS amount')->group('source_ref')->select()->toArray();
        if (!$returned) { return; }
        if ($duplicateOf) { throw new \DomainException('原到账已有真实退回，不能按重复登记整笔反向'); }
        $payload = FinanceValue::decode($replacement['payload']); $allocations = [];
        $firstReturn = $this->returnRows($transactionId)->order('business_date')->value('business_date');
        if ($firstReturn && FinanceValue::date($payload['actual_date'] ?? null) > $firstReturn) { throw new \DomainException('更正后的到账日期不能晚于已存在的真实退回日期'); }
        if ((int)($payload['subject_id'] ?? 0) !== (int)$result['subject_id']) { throw new \DomainException('原收款已有真实退回，不能改变关联客户'); }
        foreach ($payload['allocations'] ?? [] as $line) { if (is_array($line)) { $allocations[$line['source'] ?? ''] = $line['amount'] ?? '0'; } }
        foreach ($returned as $row) {
            $source = $this->ledger->source($row['source_ref']);
            $capacity = $source['category'] === 'advance' ? ($payload['advance_amount'] ?? '0') : ($allocations[$row['source_ref']] ?? '0');
            if (bccomp(FinanceValue::money($capacity, true), $row['amount'], 2) < 0) { throw new \DomainException('更正后的原核销须覆盖已真实退回的对应来源金额，请先核对关联资金事实'); }
        }
    }

    private function receipt(int $id): array
    {
        $seen = [];
        while (true) {
            if (isset($seen[$id])) { throw new \LogicException('Receipt correction cycle'); } $seen[$id] = true;
            $row = Db::name('finance_document')->where('tenant_id', $this->tenantId)->where('id', $id)->where('type', 'receipt')->where('status', 'confirmed')->find();
            if (!$row) { throw new \DomainException('请选择本店已确认的原客户收款'); }
            $next = (int)Db::name('finance_correction')->where('tenant_id', $this->tenantId)->where('original_document_id', $id)->value('replacement_document_id');
            if (!$next) { break; } $id = $next;
        }
        $result = FinanceValue::decode($row['confirmed_result']);
        if (empty($result['money']) || !empty($result['duplicate_of']) || !empty($result['reversal_of'])) { throw new \DomainException('原收款已被反向，不能登记到账失效'); }
        return $row;
    }

    private function returnRows(int $transactionId, int $excludeDocument = 0): \think\db\Query
    {
        $replaced = Db::name('finance_correction')->where('tenant_id', $this->tenantId)->field('original_document_id')->buildSql();
        return Db::name('finance_entry')->where('tenant_id', $this->tenantId)->where('purpose', 'receipt_return')->where('metric', 'balance')
            ->whereRaw('document_id NOT IN ' . $replaced)->where('document_id', '<>', $excludeDocument)
            ->whereRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(details,'$.receipt_transaction_id')) AS UNSIGNED)=?", [$transactionId]);
    }

    private function returned(int $transactionId, string $reference, int $excludeDocument): string
    {
        return (string)$this->returnRows($transactionId, $excludeDocument)->where('source_ref', $reference)->field('COALESCE(SUM(amount),0) AS total')->find()['total'];
    }
}
