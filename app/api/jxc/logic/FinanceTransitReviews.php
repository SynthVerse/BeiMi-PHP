<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 在途逐笔核对保留月末组成；正常跨月余额与无法核实的差额分别披露。 */
final class FinanceTransitReviews
{
    private static function month(mixed $value): string
    {
        $month = FinanceValue::text($value, 7);
        if (!preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D', $month)) { throw new \DomainException('请选择有效核对月份'); }
        FinanceValue::date($month . '-01');
        $activation = (string)Db::name('finance_preparation')->where('tenant_id', FinanceAccess::tenant())->value('activation_date');
        if (!$activation || $month < substr($activation, 0, 7) || $month >= date('Y-m')) { throw new \DomainException('只能核对启用后已经结束的自然月'); }
        return $month;
    }

    private static function latest(string $source, string $month): ?array
    {
        $row = Db::name('finance_transit_reconciliation')->where('tenant_id', FinanceAccess::tenant())->where('source_ref', $source)->where('month', $month)->order('id', 'desc')->find();
        return $row ? FinanceValue::decode($row['snapshot']) + ['document_id' => (int)$row['document_id']] : null;
    }

    private static function atMonth(FinanceLedger $ledger, string $reference, string $month): array
    {
        $source = $ledger->source($reference); $snapshot = $source['snapshot']; $opening = str_starts_with($reference, 'o:'); $cutoff = date('Y-m-t', strtotime($month . '-01'));
        $period = Db::name('finance_period')->where('tenant_id', FinanceAccess::tenant())->where('month', $month)->find(); $frozen = null;
        foreach ($period ? (FinanceValue::decode($period['snapshot'])['balances']['sources'] ?? []) : [] as $original) {
            if ($original['reference'] === $reference && $original['category'] === 'transit') { $frozen = $original; break; }
        }
        if ($source['category'] !== 'transit' || (!$opening && (($snapshot['type'] ?? '') !== 'account_transfer_out' || $source['business_date'] > $cutoff || ($snapshot['posting_month'] ?? '') > $month))) { throw new \DomainException('此来源不是本月末已入账的互转在途'); }
        $facts = $opening ? ($snapshot['details'] ?? []) : $snapshot;
        $account = $ledger->account($source['subject_id'], false); $targetId = isset($facts['target_account_id']) ? FinanceValue::id($facts['target_account_id']) : 0;
        $target = $targetId ? $ledger->account($targetId, false) : null;
        $row = ['transfer_source' => $reference, 'source_reference' => $snapshot['source_reference'] ?? $reference, 'source_account_id' => $source['subject_id'], 'source_account_name' => $account['name'],
            'target_account_id' => $targetId, 'target_account_name' => $target['name'] ?? null, 'actual_date' => $source['business_date'], 'actual_cutoff' => $cutoff . ' 23:59:59',
            'principal' => isset($facts['principal']) ? FinanceValue::money($facts['principal'], true) : null,
            'arrived_amount' => isset($facts['arrived_amount']) ? FinanceValue::money($facts['arrived_amount'], true) : null,
            'returned_amount' => isset($facts['returned_amount']) ? FinanceValue::money($facts['returned_amount'], true) : null,
            'withheld_fee' => isset($facts['withheld_fee']) ? FinanceValue::money($facts['withheld_fee'], true) : null,
            'extra_fee' => $opening ? (isset($facts['additional_fee']) ? FinanceValue::money($facts['additional_fee'], true) : null) : ($facts['extra_fee'] ?? null), 'original_document_id' => $source['document_id'], 'opening_item_id' => $opening ? (int)($snapshot['id'] ?? 0) : null,
            'transfer_correction_amount' => '0.00', 'rebased_arrived_amount' => '0.00', 'rebased_returned_amount' => '0.00', 'rebased_withheld_fee' => '0.00',
            'replacement_transfer_source' => null, 'replacement_document_id' => null];
        foreach (FinanceAccountTransfers::settlements($reference) as $entry) {
            $settled = FinanceValue::decode($entry['snapshot']);
            if ($settled['actual_date'] > $cutoff || (!$frozen && $settled['posting_month'] > $month)) { continue; }
            if ($entry['kind'] === 'transfer_out_correction') {
                $row['transfer_correction_amount'] = bcadd($row['transfer_correction_amount'], $entry['amount'], 2); $row['replacement_transfer_source'] = $settled['replacement_transfer_source']; $row['replacement_document_id'] = (int)$settled['replacement_document_id']; continue;
            }
            if ($entry['kind'] === 'transfer_settlement_rebase') {
                $key = ($settled['settlement_kind'] ?? '') === 'arrival' ? 'rebased_arrived_amount' : 'rebased_returned_amount';
                $row[$key] = bcadd($row[$key], $entry['amount'], 2); $row['rebased_withheld_fee'] = bcadd($row['rebased_withheld_fee'], $entry['withheld_fee'], 2); continue;
            }
            $key = $entry['kind'] === 'arrival' ? 'arrived_amount' : 'returned_amount';
            if ($row[$key] !== null) { $row[$key] = bcadd($row[$key], $entry['amount'], 2); }
            if ($row['withheld_fee'] !== null) { $row['withheld_fee'] = bcadd($row['withheld_fee'], $entry['withheld_fee'], 2); }
        }
        $changes = (string)Db::name('finance_entry')->where('tenant_id', FinanceAccess::tenant())->where('metric', 'balance')->where('source_ref', $reference)->where('business_date', '<=', $cutoff)->where('posting_month', $frozen ? '>' : '<=', $month)->sum('amount');
        $row['remaining_amount'] = bcadd($frozen ? $frozen['closing'] : $source['confirmed_amount'], $changes ?: '0', 2);
        $row['composition_known'] = $targetId > 0 && $targetId !== $source['subject_id'] && $row['actual_date'] !== null && $row['actual_date'] <= $cutoff && $row['principal'] !== null && $row['arrived_amount'] !== null && $row['returned_amount'] !== null && $row['withheld_fee'] !== null;
        if ($row['composition_known']) {
            $composed = bcsub(bcsub(bcadd($row['principal'], $row['transfer_correction_amount'], 2), $row['arrived_amount'], 2), $row['returned_amount'], 2); $composed = bcsub($composed, $row['withheld_fee'], 2);
            $composed = bcsub(bcsub(bcsub($composed, $row['rebased_arrived_amount'], 2), $row['rebased_returned_amount'], 2), $row['rebased_withheld_fee'], 2);
            $row['composition_known'] = bccomp($composed, $row['remaining_amount'], 2) === 0 && bccomp($composed, '0', 2) >= 0;
        }
        $fingerprint = $row;
        if ($fingerprint['rebased_arrived_amount'] === '0.00' && $fingerprint['rebased_returned_amount'] === '0.00' && $fingerprint['rebased_withheld_fee'] === '0.00') { unset($fingerprint['rebased_arrived_amount'], $fingerprint['rebased_returned_amount'], $fingerprint['rebased_withheld_fee']); }
        if ($fingerprint['replacement_transfer_source'] === null) { unset($fingerprint['transfer_correction_amount'], $fingerprint['replacement_transfer_source'], $fingerprint['replacement_document_id']); }
        $row['fingerprint'] = hash('sha256', FinanceValue::json($fingerprint));
        // 展示元数据不属于业务组成，新增字段不能使升级前的正式核对凭空失效。
        $row['closed_period_followup'] = $frozen !== null; $row['frozen_remaining_amount'] = $frozen['closing'] ?? null;
        $row['post_close_adjustments'] = $frozen ? bcadd($changes ?: '0', '0', 2) : '0.00';
        return $row;
    }

    private static function present(FinanceLedger $ledger, string $reference, string $month, bool $closed): array
    {
        $current = self::atMonth($ledger, $reference, $month); $latest = self::latest($reference, $month);
        $replaced = $current['replacement_transfer_source'] !== null; $allowed = !$replaced && (!$closed || $current['closed_period_followup']);
        if ($closed && !$current['closed_period_followup'] && $latest) { $current = $latest['transfer']; }
        $settled = $current['composition_known'] && $current['extra_fee'] !== null && bccomp($current['remaining_amount'], '0', 2) === 0;
        $state = $replaced ? 'replaced' : (!$latest ? ($settled ? 'settled' : 'unreviewed') : ($latest['transfer']['fingerprint'] !== $current['fingerprint'] ? 'needs_review' : $latest['review_state']));
        return $current + ['state' => $state, 'latest' => $latest, 'can_reconcile' => $allowed, 'ordinary_close_allowed' => in_array($state, ['normal', 'settled', 'replaced'], true)];
    }

    public static function followup(FinanceLedger $ledger, string $reference, string $month): array { return self::present($ledger, $reference, $month, true); }

    public static function options(FinanceLedger $ledger, array $params): array
    {
        $default = date('Y-m', strtotime('first day of last month')); $activation = (string)Db::name('finance_preparation')->where('tenant_id', FinanceAccess::tenant())->value('activation_date');
        if (empty($params['month']) && (!$activation || substr($activation, 0, 7) > $default)) { return ['month' => null, 'transfers' => [], 'month_message' => '尚未到首个可核对月末']; }
        $month = self::month($params['month'] ?? $default); $closed = (bool)Db::name('finance_period')->where('tenant_id', FinanceAccess::tenant())->where('month', $month)->find();
        $page = FinanceValue::id($params['page'] ?? 1); $exact = !empty($params['transfer_source']); $refs = [];
        if ($exact) { $refs[] = FinanceValue::text($params['transfer_source'], 40); }
        else {
            $parts = [];
            foreach (['o' => 'finance_opening_source', 'n' => 'finance_source'] as $kind => $table) {
                $query = Db::name($table)->where('tenant_id', FinanceAccess::tenant())->where('category', 'transit');
                if ($kind === 'n') { $query->where('business_date', '<=', date('Y-m-t', strtotime($month . '-01')))->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(snapshot,'$.posting_month')) <= ?", [$month]); }
                $parts[] = $query->field("CONCAT('{$kind}:',id) AS reference")->buildSql();
            }
            $refs = array_column(Db::query(implode(' UNION ALL ', $parts) . ' ORDER BY reference LIMIT ' . (($page - 1) * 20) . ',21'), 'reference');
        }
        $more = count($refs) > 20; $refs = array_slice($refs, 0, 20);
        $rows = array_map(static fn(string $ref): array => self::present($ledger, $ref, $month, $closed), $refs);
        return ['month' => $month, 'actual_cutoff' => date('Y-m-t', strtotime($month . '-01')) . ' 23:59:59', 'closed' => $closed,
            'closed_followup_allowed' => $closed && (bool)array_filter($rows, static fn(array $row): bool => $row['can_reconcile']), 'transfers' => $rows, 'transfer_has_more' => $more, 'selected_transfer' => $exact ? $rows[0] : null];
    }

    public static function confirm(FinanceLedger $ledger, array $document, array $data): array
    {
        $month = self::month($data['month'] ?? null); $tenant = FinanceAccess::tenant();
        $source = FinanceValue::text($data['transfer_source'] ?? null, 40); $current = self::atMonth($ledger, $source, $month); $latest = self::latest($source, $month);
        if ($current['replacement_transfer_source'] !== null) { throw new \DomainException('该在途已由关联更正替代，无需再核对'); }
        if (!$current['closed_period_followup'] && Db::name('finance_period')->where('tenant_id', $tenant)->where('month', $month)->find()) { throw new \DomainException('已结月份缺少该在途权威快照，不能补造历史核对'); }
        if (($data['actual_cutoff'] ?? '') !== $current['actual_cutoff'] || ($data['expected_fingerprint'] ?? '') !== $current['fingerprint']) { throw new \DomainException('原月末在途组成已变化，请刷新后重新核对'); }
        $expected = $data['expected_reconciliation_id'] ?? null;
        if ((!is_int($expected) && !(is_string($expected) && ctype_digit($expected))) || (int)$expected !== (int)($latest['document_id'] ?? 0)) { throw new \DomainException('在途核对历史已变化，请读取最新记录'); }
        $state = FinanceValue::text($data['review_state'] ?? null, 16);
        if (!in_array($state, ['normal', 'unresolved'], true)) { throw new \DomainException('请明确正常跨月在途或尚未核实'); }
        if ($state === 'normal' && (!$current['composition_known'] || ($data['transfer_verified'] ?? null) !== 1)) { throw new \DomainException('正常在途须核实来源、两端账户、本金、到账、返还、手续费及剩余组成'); }
        $verifiedFee = $state === 'normal' && $current['extra_fee'] === null ? FinanceValue::money($data['verified_extra_fee'] ?? null, true) : $current['extra_fee'];
        $snapshot = ['type' => $document['type'], 'month' => $month, 'subject_id' => $current['source_account_id'], 'subject_name' => $current['source_account_name'], 'transfer_source' => $source,
            'transfer' => $current, 'review_state' => $state, 'ordinary_close_allowed' => $state === 'normal', 'verified_extra_fee' => $verifiedFee, 'closed_period_followup' => $current['closed_period_followup'],
            'previous_document_id' => (int)($latest['document_id'] ?? 0), 'reason' => FinanceValue::text($data['reason'] ?? null, 1000), 'verified_by' => FinanceAccess::actor(), 'verified_at' => time()];
        Db::name('finance_transit_reconciliation')->insert(['tenant_id' => $tenant, 'document_id' => $document['id'], 'source_ref' => $source, 'month' => $month, 'snapshot' => FinanceValue::json($snapshot), 'create_time' => time()]);
        return $snapshot;
    }
}
