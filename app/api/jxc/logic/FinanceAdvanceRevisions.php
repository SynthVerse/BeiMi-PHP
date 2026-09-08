<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 已使用的预收保持来源身份；更正金额与日期追加版本，既有引用和原始事实不覆盖。 */
final class FinanceAdvanceRevisions
{
    public static function latest(int $tenant, array $references): array
    {
        if (!$references) { return []; }
        $latest = Db::name('finance_advance_revision')->where('tenant_id', $tenant)->whereIn('source_ref', $references)->field('MAX(id) AS id')->group('source_ref')->buildSql();
        $rows = Db::name('finance_advance_revision')->where('tenant_id', $tenant)->whereRaw('id IN ' . $latest)->select()->toArray();
        return array_column($rows, null, 'source_ref');
    }

    public static function preserve(FinanceLedger $ledger, array $original, array $payload): ?array
    {
        foreach (FinanceValue::decode($original['confirmed_result'])['created_sources'] ?? [] as $reference) {
            $source = $ledger->source($reference);
            if ($source['category'] !== 'advance' || (bccomp($source['balance'], $source['confirmed_amount'], 2) === 0 && !$source['advance_revision'])) { continue; }
            $amount = FinanceValue::money($payload['advance_amount'] ?? '0', true);
            if ((int)($payload['subject_id'] ?? 0) !== $source['subject_id'] || bccomp($amount, bcsub($source['confirmed_amount'], $source['balance'], 2), 2) < 0) { throw new \DomainException('更正后的预收必须保留已使用或退回金额及原客户，请核对关联事实'); }
            $date = FinanceValue::date($payload['actual_date'] ?? null);
            $replaced = Db::name('finance_correction')->where('tenant_id', FinanceAccess::tenant())->field('original_document_id')->buildSql();
            $firstUse = Db::name('finance_entry')->where('tenant_id', FinanceAccess::tenant())->where('source_ref', $reference)->where('metric', 'balance')
                ->whereRaw('document_id NOT IN ' . $replaced)
                ->where('amount', '<', 0)->whereNotIn('purpose', ['correction_reversal', 'correction_source', 'advance_revision'])->order('business_date')->value('business_date');
            if ($firstUse && $date > $firstUse) { throw new \DomainException('更正后的到账日期不能晚于已有预收使用或真实退回日期'); }
            return $source;
        }
        return null;
    }

    /** 原来源不改写；更正的预收组成沿对应资金冲销、替代分录的入账月份投影。 */
    public static function reportMovements(int $tenant): array
    {
        $revisions = Db::name('finance_advance_revision')->where('tenant_id', $tenant)->order('id')->select()->toArray();
        if (!$revisions) { return []; }
        $entries = Db::name('finance_entry')->where('tenant_id', $tenant)->whereIn('document_id', array_column($revisions, 'document_id'))
            ->where('metric', 'cash')->whereIn('purpose', ['correction_reversal', 'correction_replacement'])->select()->toArray();
        $cashEntries = [];
        foreach ($entries as $entry) { $cashEntries[$entry['document_id']][$entry['purpose']] = $entry; }
        $movements = [];
        foreach ($revisions as $revision) {
            $posting = $cashEntries[$revision['document_id']] ?? [];
            if (!isset($posting['correction_reversal'], $posting['correction_replacement'])) { throw new \DomainException('预收更正缺少完整资金入账依据，请核查原更正记录'); }
            foreach (['correction_reversal' => bcsub('0', $revision['old_amount'], 2), 'correction_replacement' => $revision['new_amount']] as $purpose => $amount) {
                $movements[$revision['source_ref']][] = ['revision_id' => (int)$revision['id'], 'document_id' => (int)$revision['document_id'],
                    'cash_entry_id' => (int)$posting[$purpose]['id'], 'source_ref' => $revision['source_ref'], 'posting_month' => $posting[$purpose]['posting_month'],
                    'business_date' => $purpose === 'correction_reversal' ? $revision['old_business_date'] : $revision['new_business_date'],
                    'movement' => $purpose === 'correction_reversal' ? 'remove_previous' : 'recognize_replacement', 'amount' => $amount, 'reason' => $revision['reason']];
            }
        }
        return $movements;
    }

    public static function append(FinanceLedger $ledger, int $documentId, array $source, string $amount, string $date, string $month, string $reason): void
    {
        $delta = bcsub($amount, $source['confirmed_amount'], 2);
        $ledger->add($documentId, 'balance', $source['subject_id'], $delta, $date, $month, 'advance_revision', $source['reference'], null, ['reason' => $reason]);
        Db::name('finance_advance_revision')->insert(['tenant_id' => FinanceAccess::tenant(), 'document_id' => $documentId, 'source_ref' => $source['reference'],
            'old_amount' => $source['confirmed_amount'], 'new_amount' => $amount, 'old_business_date' => $source['business_date'], 'new_business_date' => $date,
            'reason' => $reason, 'actor' => FinanceValue::json(FinanceAccess::actor()), 'create_time' => time()]);
    }
}
