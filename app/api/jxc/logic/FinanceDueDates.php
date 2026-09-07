<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 付款日版本仅改变当前催收视图，不改原应收、业务日期或任何金额。 */
final class FinanceDueDates
{
    public static function latestSql(int $tenant): string
    {
        $latest = Db::name('finance_due_adjustment')->where('tenant_id', $tenant)->field('MAX(id) AS id')->group('source_ref')->buildSql();
        return Db::name('finance_due_adjustment')->alias('d')->join([$latest => 'v'], 'v.id=d.id')->where('d.tenant_id', $tenant)->field('d.*')->buildSql();
    }

    public static function forSources(int $tenant, array $references): array
    {
        if (!$references) { return []; }
        $rows = Db::query('SELECT * FROM ' . self::latestSql($tenant) . ' AS due WHERE source_ref IN (' . implode(',', array_fill(0, count($references), '?')) . ')', $references);
        return array_column($rows, null, 'source_ref');
    }

    public static function confirm(FinanceLedger $ledger, array $document, array $data): array
    {
        $tenant = FinanceAccess::tenant(); $source = $ledger->source(FinanceValue::text($data['source'] ?? '', 40));
        $policy = FinanceDocumentPolicy::authorize($document['type'], true);
        if (!in_array($source['category'], $policy['sources'], true) || $source['subject_id'] !== FinanceValue::id($data['subject_id'] ?? 0)) { throw new \DomainException('请选择本对象的正式未结来源'); }
        if (bccomp($source['balance'], '0', 2) <= 0) { throw new \DomainException('已结清来源无需调整付款日'); }
        $revision = FinanceValue::id($data['expected_due_revision'] ?? 0, true);
        if ($revision !== $source['due_revision']) { throw new \DomainException('付款日已有新调整，请核对最新日期再提交'); }
        if (!array_key_exists('new_due_date', $data)) { throw new \DomainException('请填写新付款日或明确选择未约定'); }
        $due = FinanceValue::date($data['new_due_date'], true); $reason = FinanceValue::text($data['reason'] ?? '', 1000);
        if ($due === $source['due_date']) { throw new \DomainException('新付款日与当前日期一致，无需调整'); }
        if ($due && $source['business_date'] && $due < $source['business_date']) { throw new \DomainException('付款日不能早于原业务日期'); }
        $month = $ledger->postingMonth(date('Y-m-d'));
        $id = (int)Db::name('finance_due_adjustment')->insertGetId(['tenant_id' => $tenant, 'document_id' => $document['id'], 'source_ref' => $source['reference'],
            'previous_revision' => $revision, 'old_due_date' => $source['due_date'], 'new_due_date' => $due, 'reason' => $reason,
            'actor' => FinanceValue::json(FinanceAccess::actor()), 'create_time' => time()]);
        return ['type' => $document['type'], 'subject_id' => $source['subject_id'], 'subject_name' => $source['subject_name'],
            'source' => $source['reference'], 'old_due_date' => $source['due_date'], 'new_due_date' => $due, 'due_revision' => $id,
            'business_date' => $source['business_date'], 'balance' => $source['balance'], 'posting_month' => $month, 'created_sources' => [], 'reason' => $reason];
    }
}
