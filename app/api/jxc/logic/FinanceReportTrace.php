<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 先证明来源属于当前可见报表，再读取原凭据与追加的后续记录。 */
final class FinanceReportTrace
{
    public static function read(FinanceLedger $ledger, array $params): array
    {
        $report = FinanceReportPeriods::read($ledger, $params);
        $kind = FinanceValue::text($params['target_kind'] ?? null, 16); $target = FinanceValue::text($params['target'] ?? null, 160);
        if (!in_array($kind, ['document', 'source', 'cost'], true) || !self::contains($report['data'], $kind, $target)) { throw new \DomainException('该来源不属于当前可见报表，请重新读取报表'); }
        $tenant = FinanceAccess::tenant(); $documentIds = []; $source = null; $cost = null; $costEffects = []; $sourceRefs = [];
        if ($kind === 'document') { $documentIds[] = FinanceValue::id($target); }
        elseif ($kind === 'source' || ($kind === 'cost' && str_starts_with($target, 'opening:'))) {
            $reference = $kind === 'source' ? $target : 'o:' . substr($target, 8);
            $source = $ledger->source($reference);
            if ($source['category'] === 'salary') { FinanceAccess::require('finance.salary.view'); }
            $sourceRefs[] = $reference;
            if ($source['document_id']) { $documentIds[] = $source['document_id']; }
        }
        if ($kind === 'cost') {
            $cost = Db::name('finance_cost_origin')->where('tenant_id', $tenant)->where('origin_key', $target)->find();
            if ($cost) { $cost['snapshot'] = FinanceValue::decode($cost['snapshot']); }
            $costEffects = Db::name('finance_cost_effect')->where('tenant_id', $tenant)->where('origin_key', $target)->order('id')->select()->toArray();
            $eventIds = array_unique(array_column($costEffects, 'event_id'));
            if ($eventIds) { $documentIds = array_merge($documentIds, Db::name('finance_cost_event')->where('tenant_id', $tenant)->whereIn('id', $eventIds)->where('document_id', '>', 0)->column('document_id')); }
        }
        if ($kind === 'document') {
            $original = FinanceBusinessLogic::detail(['id' => $documentIds[0]]);
            if ($original === false) { throw new \DomainException(FinanceBusinessLogic::getError()); }
        }
        $entries = [];
        do {
            $previousCount = count($documentIds) + count($sourceRefs);
            if ($documentIds) {
            // 关联更正和反向记录可以连续发生，保留完整原单链。
            $links = Db::name('finance_correction')->where('tenant_id', $tenant)->select()->toArray();
            do {
                $before = count($documentIds);
                foreach ($links as $link) {
                    if (in_array((int)$link['original_document_id'], $documentIds, true) || in_array((int)$link['replacement_document_id'], $documentIds, true)) { $documentIds[] = (int)$link['original_document_id']; $documentIds[] = (int)$link['replacement_document_id']; }
                }
                $documentIds = array_values(array_unique($documentIds));
                $relationKeys = ['count_document_id', 'count_result_document_id', 'corrects_document_id', 'reversal_of', 'receipt_id', 'original_document_id', 'original_expense_document_id', 'original_equipment_document_id', 'original_refund_document_id', 'original_cost_document_id'];
                foreach (Db::name('finance_document')->where('tenant_id', $tenant)->whereIn('id', $documentIds)->column('confirmed_result') as $json) {
                    $fact = FinanceValue::decode($json);
                    foreach ($relationKeys as $key) { if (isset($fact[$key]) && filter_var($fact[$key], FILTER_VALIDATE_INT) > 0) { $documentIds[] = (int)$fact[$key]; } }
                }
                $documentIds = array_values(array_unique($documentIds));
                $matches = []; $bindings = [];
                foreach ($relationKeys as $key) {
                    $matches[] = "CAST(JSON_UNQUOTE(JSON_EXTRACT(confirmed_result, '$." . $key . "')) AS UNSIGNED) IN (" . implode(',', array_fill(0, count($documentIds), '?')) . ')';
                    $bindings = array_merge($bindings, $documentIds);
                }
                $documentIds = array_values(array_unique(array_merge($documentIds, array_map('intval', Db::name('finance_document')->where('tenant_id', $tenant)->where('status', 'confirmed')->whereRaw('(' . implode(' OR ', $matches) . ')', $bindings)->column('id')))));
            } while (count($documentIds) !== $before);
            $sourceRefs = array_values(array_unique(array_merge($sourceRefs, array_map(static fn($id): string => 'n:' . $id, Db::name('finance_source')->where('tenant_id', $tenant)->whereIn('document_id', $documentIds)->column('id')))));
        }
        // 来源流水包含以后期间的实际处理，单独展示，不能回填原报表金额。
        if ($sourceRefs || $documentIds) {
            $entries = Db::name('finance_entry')->where('tenant_id', $tenant)->where(static function ($query) use ($sourceRefs, $documentIds): void {
                if ($sourceRefs) { $query->whereIn('source_ref', $sourceRefs); }
                if ($documentIds) { $sourceRefs ? $query->whereOr('document_id', 'in', $documentIds) : $query->whereIn('document_id', $documentIds); }
            })->order('id')->select()->toArray();
            $documentIds = array_merge($documentIds, array_map('intval', array_column($entries, 'document_id')));
        }
        foreach (['finance_due_adjustment', 'finance_advance_revision'] as $table) {
            if ($sourceRefs) { $documentIds = array_merge($documentIds, array_map('intval', Db::name($table)->where('tenant_id', $tenant)->whereIn('source_ref', $sourceRefs)->column('document_id'))); }
        }
            $documentIds = array_values(array_unique($documentIds));
        } while (count($documentIds) + count($sourceRefs) !== $previousCount);
        $documents = []; $allowedIds = [];
        if ($documentIds) {
            foreach (Db::name('finance_document')->where('tenant_id', $tenant)->whereIn('id', array_unique($documentIds))->where('status', 'confirmed')->order('id')->select()->toArray() as $document) {
                $allowed = true;
                try { FinanceDocumentPolicy::read($document['type']); } catch (\DomainException) { $allowed = false; }
                $fact = $allowed ? FinanceValue::decode($document['confirmed_result']) : []; $payload = $allowed ? FinanceValue::decode($document['payload']) : [];
                $documents[] = ['id' => (int)$document['id'], 'type' => $document['type'], 'title' => FinanceDocumentPolicy::type($document['type'])['title'], 'can_open' => $allowed,
                    'actual_date' => $fact['actual_date'] ?? $fact['business_date'] ?? $payload['actual_date'] ?? null, 'reason' => $fact['correction_reason'] ?? $fact['reason'] ?? '', 'confirmed_at' => $allowed ? (int)$document['confirmed_at'] : null,
                    'corrects_document_id' => $fact['corrects_document_id'] ?? null, 'reversal_of' => $fact['reversal_of'] ?? $fact['duplicate_of'] ?? null];
                if ($allowed) { $allowedIds[] = (int)$document['id']; }
            }
        }
        $hidden = count(array_filter($entries, static fn(array $row): bool => !in_array((int)$row['document_id'], $allowedIds, true)));
        $entries = array_values(array_filter($entries, static fn(array $row): bool => in_array((int)$row['document_id'], $allowedIds, true)));
        foreach ($entries as &$entry) { $entry['details'] = FinanceValue::decode($entry['details']); } unset($entry);
        return ['tenant_id' => $tenant, 'report' => $report['report'], 'period_type' => $report['period_type'], 'period' => $report['period'], 'cutoff' => $report['cutoff'],
            'target_kind' => $kind, 'target' => $target, 'source' => $source, 'cost' => $cost, 'cost_effects' => $costEffects, 'documents' => $documents, 'entries' => $entries, 'hidden_entries' => $hidden,
            'original_stage' => $report['stage'], 'original_closing_status' => $report['closing_status']];
    }

    private static function contains(array $data, string $kind, string $target, string $parent = ''): bool
    {
        foreach ($data as $key => $value) {
            if ($kind === 'document' && $key === 'document_id' && (string)$value === $target) { return true; }
            if ($kind === 'source' && in_array($key, ['reference', 'source_ref', 'source', 'transfer_source'], true) && $value === $target) { return true; }
            if ($kind === 'source' && $parent === 'opening_sources' && $key === 'id' && 'o:' . $value === $target) { return true; }
            if ($kind === 'cost' && $key === 'origin_key' && $value === $target) { return true; }
            if (is_array($value) && self::contains($value, $kind, $target, is_int($key) ? $parent : (string)$key)) { return true; }
        }
        return false;
    }
}
