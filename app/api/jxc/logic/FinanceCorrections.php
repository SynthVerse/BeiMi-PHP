<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 调用方持有门店账套锁与事务；冲销、替代单和关联关系必须一起提交。 */
final class FinanceCorrections
{
    public function __construct(private readonly int $tenantId, private readonly FinanceLedger $ledger) {}

    public function replace(array $original, array $replacement, string $reason, int $duplicateOf = 0): array
    {
        FinanceDocumentPolicy::authorize($original['type'], true);
        if ($original['status'] !== 'confirmed') { throw new \DomainException('仅已确认记录可关联更正'); }
        if (Db::name('finance_correction')->where('tenant_id', $this->tenantId)->where('original_document_id', $original['id'])->count()) {
            throw new \DomainException('原记录已有更正，请打开最新有效记录处理');
        }
        $reason = FinanceValue::text($reason, 1000);
        $originalResult = FinanceValue::decode($original['confirmed_result']);
        if (!empty($originalResult['duplicate_of'])) { throw new \DomainException('重复反向凭据不是实际业务，不能再次冲销或替代'); }
        if ($duplicateOf) { $this->validateDuplicate($original, $originalResult, $duplicateOf); }
        // 派生预收等已被其他业务消耗时，不能让更正形成负来源余额。
        $created = Db::name('finance_source')->where('tenant_id', $this->tenantId)->where('document_id', $original['id'])->select()->toArray();
        foreach ($created as $row) {
            $source = $this->ledger->source('n:' . $row['id']);
            if (bccomp($source['balance'], $source['confirmed_amount'], 2) !== 0) { throw new \DomainException('原记录产生的余额已有后续处理，请先核对并更正关联业务'); }
        }
        $entries = Db::name('finance_entry')->where('tenant_id', $this->tenantId)->where('document_id', $original['id'])
            ->whereNotIn('purpose', ['correction_reversal', 'correction_source'])->order('id', 'desc')->select()->toArray();
        $activation = (string)Db::name('finance_preparation')->where('tenant_id', $this->tenantId)->value('activation_date');
        foreach ($entries as $entry) {
            $month = $this->ledger->postingMonth(max($entry['effective_date'] ?: $entry['business_date'], $activation));
            $this->ledger->add((int)$replacement['id'], $entry['metric'], (int)$entry['subject_id'], bcsub('0', $entry['amount'], 2),
                $entry['business_date'], $month, 'correction_reversal', $entry['source_ref'], $entry['effective_date'],
                ['original_entry_id' => (int)$entry['id'], 'original_document_id' => (int)$original['id'], 'reason' => $reason]);
        }
        foreach ($created as $row) {
            $date = $row['business_date'] ?: $activation;
            $this->ledger->add((int)$replacement['id'], 'balance', (int)$row['subject_id'], '-' . $row['amount'], $date,
                $this->ledger->postingMonth(max($date, $activation)), 'correction_source', 'n:' . $row['id'], null,
                ['original_document_id' => (int)$original['id'], 'reason' => $reason]);
        }
        $transaction = null;
        if (isset($originalResult['money']['transaction_id'])) {
            $transaction = Db::name('finance_money_transaction')->where('tenant_id', $this->tenantId)->where('id', $originalResult['money']['transaction_id'])->find();
            if (!$transaction) { throw new \DomainException('原真实资金交易不存在，不能建立更正'); }
        }
        $result = $duplicateOf ? ['type' => $original['type'], 'subject_id' => $originalResult['subject_id'], 'subject_name' => $originalResult['subject_name'],
            'allocated_amount' => '0.00', 'created_sources' => [], 'duplicate_of' => $duplicateOf, 'reason' => $reason]
            : (new FinancePayments($this->tenantId, $this->ledger))->confirm($replacement, $transaction);
        Db::name('finance_correction')->insert(['tenant_id' => $this->tenantId, 'original_document_id' => $original['id'],
            'replacement_document_id' => $replacement['id'], 'reason' => $reason, 'actor' => FinanceValue::json(FinanceAccess::actor()), 'create_time' => time()]);
        return $result + ['corrects_document_id' => (int)$original['id'], 'correction_reason' => $reason];
    }

    private function validateDuplicate(array $original, array $result, int $keptId): void
    {
        $kept = Db::name('finance_document')->where('tenant_id', $this->tenantId)->where('id', $keptId)->find();
        if (!$kept || $keptId === (int)$original['id'] || $kept['status'] !== 'confirmed' || $kept['type'] !== $original['type'] ||
            Db::name('finance_correction')->where('tenant_id', $this->tenantId)->where('original_document_id', $keptId)->count()) {
            throw new \DomainException('请选择本门店同业务类型、尚有效的保留记录');
        }
        $keptResult = FinanceValue::decode($kept['confirmed_result']);
        $oldMoney = $result['money'] ?? []; $keptMoney = $keptResult['money'] ?? [];
        $oldPayload = FinanceValue::decode($original['payload']); $keptPayload = FinanceValue::decode($kept['payload']);
        if (!$oldMoney || !$keptMoney || $oldMoney['transaction_id'] === $keptMoney['transaction_id'] ||
            $oldMoney['amount'] !== $keptMoney['amount'] || $oldMoney['actual_date'] !== $keptMoney['actual_date'] ||
            (int)$oldPayload['account_id'] !== (int)$keptPayload['account_id']) { throw new \DomainException('两笔记录的账户、方向、金额与日期不符，不能作为重复登记反向'); }
        $oldNumber = $oldMoney['evidence']['transaction_no'] ?? ''; $keptNumber = $keptMoney['evidence']['transaction_no'] ?? '';
        if ($oldNumber !== '' && $keptNumber !== '' && $oldNumber !== $keptNumber) { throw new \DomainException('外部交易号不同，不能将两笔实际交易合并'); }
    }
}
