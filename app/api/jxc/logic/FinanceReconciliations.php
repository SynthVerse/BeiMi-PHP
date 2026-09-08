<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 同一月末截点的人工核对；业务补录改变账面时须追加核对历史。 */
final class FinanceReconciliations
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

    private static function latest(int $account, string $month): ?array
    {
        $row = Db::name('finance_account_reconciliation')->where('tenant_id', FinanceAccess::tenant())->where('account_id', $account)->where('month', $month)->order('id', 'desc')->find();
        return $row ? FinanceValue::decode($row['snapshot']) + ['document_id' => (int)$row['document_id']] : null;
    }

    private static function balance(int $account, string $month): string
    {
        return self::comparison($account, $month)['book_balance'];
    }

    /** 冻结余额加归属原截点的后续合法分录；不混入当月新发生的收付款。 */
    private static function comparison(int $account, string $month): array
    {
        $cutoff = date('Y-m-t', strtotime($month . '-01')); $tenant = FinanceAccess::tenant();
        $period = Db::name('finance_period')->where('tenant_id', $tenant)->where('month', $month)->find();
        foreach ($period ? (FinanceValue::decode($period['snapshot'])['reports']['cash']['data']['accounts'] ?? []) : [] as $frozen) {
            if ((int)$frozen['account_id'] !== $account) { continue; }
            $changes = (string)Db::name('finance_entry')->where('tenant_id', $tenant)->where('metric', 'cash')->where('subject_id', $account)->where('business_date', '<=', $cutoff)->where('posting_month', '>', $month)->sum('amount');
            return ['book_balance' => bcadd($frozen['closing'], $changes ?: '0', 2), 'frozen_book_balance' => $frozen['closing'], 'post_close_adjustments' => bcadd($changes ?: '0', '0', 2), 'closed_period_followup' => true];
        }
        $opening = (string)Db::name('finance_opening_source')->where('tenant_id', $tenant)->where('category', 'account')->where('subject_id', $account)->where('activation_date', '<=', $cutoff)->sum('amount');
        $changes = (string)Db::name('finance_entry')->where('tenant_id', $tenant)->where('metric', 'cash')->where('subject_id', $account)->where('business_date', '<=', $cutoff)->where('posting_month', '<=', $month)->sum('amount');
        return ['book_balance' => bcadd($opening ?: '0', $changes ?: '0', 2), 'frozen_book_balance' => null, 'post_close_adjustments' => '0.00', 'closed_period_followup' => false];
    }

    public static function followup(int $account, string $month): array
    {
        $comparison = self::comparison($account, $month); $latest = self::latest($account, $month);
        $state = !$latest ? 'unreconciled' : (bccomp($latest['book_balance'], $comparison['book_balance'], 2) !== 0 ? 'needs_review' : (bccomp($latest['difference'], '0', 2) === 0 ? 'matched' : 'difference'));
        return $comparison + ['latest' => $latest, 'state' => $state];
    }

    public static function confirm(FinanceLedger $ledger, array $document, array $data): array
    {
        $month = self::month($data['month'] ?? null); $tenant = FinanceAccess::tenant();
        $account = $ledger->account(FinanceValue::id($data['account_id'] ?? null), false);
        $comparison = self::comparison((int)$account['id'], $month);
        if (!$comparison['closed_period_followup'] && Db::name('finance_period')->where('tenant_id', $tenant)->where('month', $month)->find()) { throw new \DomainException('已结月份缺少该账户权威快照，不能补造历史核对'); }
        $cutoff = date('Y-m-t', strtotime($month . '-01')) . ' 23:59:59';
        if (($data['actual_cutoff'] ?? null) !== $cutoff || ($data['reconciliation_verified'] ?? null) !== 1) { throw new \DomainException('请核实实际余额与账面余额均对应本月月末同一时点'); }
        $previous = self::latest((int)$account['id'], $month);
        $expected = $data['expected_reconciliation_id'] ?? null;
        if ((!is_int($expected) && !(is_string($expected) && ctype_digit($expected))) || (int)$expected !== (int)($previous['document_id'] ?? 0)) { throw new \DomainException('核对历史已变化，请刷新后重新核实'); }
        $actual = FinanceValue::money($data['actual_balance'] ?? null, true, true); $balance = $comparison['book_balance'];
        if (bccomp(FinanceValue::money($data['expected_book_balance'] ?? null, true, true), $balance, 2) !== 0) { throw new \DomainException('月末账面余额已变化，请刷新后重新核对'); }
        $snapshot = ['type' => $document['type'], 'subject_id' => (int)$account['id'], 'subject_name' => $account['name'], 'account_id' => (int)$account['id'], 'account_type' => $account['account_type'],
            'month' => $month, 'actual_cutoff' => $cutoff, 'book_balance' => $balance, 'actual_balance' => $actual, 'difference' => bcsub($actual, $balance, 2),
            'reconciliation_verified' => 1, 'previous_document_id' => (int)($previous['document_id'] ?? 0), 'reason' => FinanceValue::text($data['reason'] ?? null, 1000),
            'verified_by' => FinanceAccess::actor(), 'verified_at' => time()] + $comparison;
        Db::name('finance_account_reconciliation')->insert(['tenant_id' => $tenant, 'document_id' => $document['id'], 'account_id' => $account['id'], 'month' => $month, 'snapshot' => FinanceValue::json($snapshot), 'create_time' => time()]);
        return $snapshot;
    }

    public static function options(FinanceLedger $ledger, array $params): array
    {
        $default = date('Y-m', strtotime('first day of last month'));
        $selected = null;
        if (!empty($params['reconciliation_document_id'])) {
            $row = Db::name('finance_account_reconciliation')->where('tenant_id', FinanceAccess::tenant())->where('document_id', FinanceValue::id($params['reconciliation_document_id']))->find();
            if (!$row) { throw new \DomainException('核对记录不存在或不属于本门店'); }
            $selected = FinanceValue::decode($row['snapshot']) + ['document_id' => (int)$row['document_id']];
            $selected['remaining_shortage'] = self::remaining((int)$row['document_id'], $selected['difference']);
            $params['month'] = $row['month'];
        }
        $activation = (string)Db::name('finance_preparation')->where('tenant_id', FinanceAccess::tenant())->value('activation_date');
        if (empty($params['month']) && (!$activation || substr($activation, 0, 7) > $default)) { return ['month' => null, 'checks' => [], 'month_message' => '尚未到首个可核对月末']; }
        $month = self::month($params['month'] ?? $default); $closed = (bool)Db::name('finance_period')->where('tenant_id', FinanceAccess::tenant())->where('month', $month)->find();
        $checks = []; $followupAllowed = false;
        foreach (Db::name('finance_account')->where('tenant_id', FinanceAccess::tenant())->order('id')->select()->toArray() as $account) {
            $current = self::followup((int)$account['id'], $month); $followupAllowed = $followupAllowed || $current['closed_period_followup'];
            $checks[] = ['account_id' => (int)$account['id'], 'account_name' => $account['name'], 'account_type' => $account['account_type'], 'is_enabled' => (bool)$account['is_enabled'],
                'can_reconcile' => !$closed || $current['closed_period_followup']] + $current;
        }
        return ['month' => $month, 'actual_cutoff' => date('Y-m-t', strtotime($month . '-01')) . ' 23:59:59', 'closed' => $closed, 'closed_followup_allowed' => $followupAllowed, 'checks' => $checks, 'selected_reconciliation' => $selected];
    }

    private static function remaining(int $reference, string $difference, int $correctingDocument = 0): string
    {
        $tenant = FinanceAccess::tenant(); $replaced = Db::name('finance_correction')->where('tenant_id', $tenant)->column('original_document_id');
        if ($correctingDocument) { $replaced[] = $correctingDocument; }
        $query = Db::name('finance_cash_shortage_application')->where('tenant_id', $tenant)->where('reconciliation_document_id', $reference);
        if ($replaced) { $query->whereNotIn('document_id', $replaced); }
        return bcsub(bcsub('0', $difference, 2), (string)$query->sum('amount') ?: '0', 2);
    }

    private static function protectShortageBasis(array $row, array $snapshot, string $amount, int $correctingDocument): void
    {
        $tenant = FinanceAccess::tenant(); $reference = (int)$row['document_id'];
        $latest = self::latest((int)$row['account_id'], $row['month']);
        $original = $correctingDocument ? Db::name('finance_cash_shortage_application')->where('tenant_id', $tenant)->where('document_id', $correctingDocument)->find() : null;
        $reducingOriginal = $original && (int)$original['reconciliation_document_id'] === $reference && bccomp($amount, $original['amount'], 2) <= 0;
        // 旧结论只能减记纠错；调增须基于最新核对，防止跨版本重复消耗同一短款。
        if ((int)$latest['document_id'] !== $reference) {
            if ($reducingOriginal) { return; }
            throw new \DomainException('原差额已有后续核对，不能按旧结论增加损失，请从最新核对处理');
        }
        if ($reducingOriginal) { return; }
        $documents = Db::name('finance_cash_shortage_application')->where('tenant_id', $tenant)->where('reconciliation_document_id', $reference)->column('document_id');
        $changes = '0';
        if ($documents) {
            // 原短款及其反向允许分次处理；其他业务改变截点账面必须重新实盘核对。
            $ids = implode(',', array_map('intval', $documents));
            $query = Db::name('finance_entry')->where('tenant_id', $tenant)->where('metric', 'cash')->where('subject_id', $row['account_id'])
                ->where('business_date', '<=', substr($snapshot['actual_cutoff'], 0, 10))
                ->whereRaw("((purpose='cash_shortage' AND document_id IN ({$ids})) OR (purpose='correction_reversal' AND JSON_UNQUOTE(JSON_EXTRACT(details,'$.original_document_id')) IN ({$ids})))");
            if (!self::comparison((int)$row['account_id'], $row['month'])['closed_period_followup']) { $query->where('posting_month', '<=', $row['month']); }
            $changes = (string)$query->sum('amount');
        }
        $expected = bcadd($snapshot['book_balance'], $changes ?: '0', 2);
        if (bccomp($expected, self::balance((int)$row['account_id'], $row['month']), 2) !== 0) { throw new \DomainException('其他业务已改变原月末账面，请重新核对后再登记现金短款'); }
    }

    public static function shortage(FinanceLedger $ledger, array $document, array $data, int $correctingDocument): array
    {
        $tenant = FinanceAccess::tenant(); $reference = FinanceValue::id($data['reconciliation_document_id'] ?? null);
        $row = Db::name('finance_account_reconciliation')->where('tenant_id', $tenant)->where('document_id', $reference)->find();
        if (!$row) { throw new \DomainException('请选择本门店已确认的现金短款核对记录'); }
        $reconciliation = FinanceValue::decode($row['snapshot']); $account = $ledger->account((int)$row['account_id'], false);
        if ($account['account_type'] !== 'cash' || bccomp($reconciliation['difference'], '0', 2) >= 0) { throw new \DomainException('只有现金账户实盘短款可登记现金遗失损失'); }
        if (!$correctingDocument && (int)self::latest((int)$row['account_id'], $row['month'])['document_id'] !== $reference) { throw new \DomainException('原差额已有新的核对，请从最新核对继续处理'); }
        if (($data['loss_verified'] ?? null) !== 1) { throw new \DomainException('须先查明现金确已遗失且损失由门店承担'); }
        $date = FinanceValue::date($data['actual_date'] ?? null);
        if ($date > substr($reconciliation['actual_cutoff'], 0, 10)) { throw new \DomainException('现金遗失实际日期不能晚于原核对截点'); }
        $month = $ledger->postingMonth($date); $amount = FinanceValue::money($data['amount'] ?? null);
        self::protectShortageBasis($row, $reconciliation, $amount, $correctingDocument);
        $remaining = self::remaining($reference, $reconciliation['difference'], $correctingDocument);
        if (bccomp($amount, $remaining, 2) > 0) { throw new \DomainException('本次损失超过原核对尚未处理的现金短款'); }
        $reason = FinanceValue::text($data['reason'] ?? null, 1000);
        $details = ['reconciliation_document_id' => $reference, 'reason' => $reason];
        $ledger->add((int)$document['id'], 'cash', (int)$account['id'], '-' . $amount, $date, $month, 'cash_shortage', '', null, $details);
        $ledger->add((int)$document['id'], 'loss', (int)$account['id'], $amount, $date, $month, 'cash_shortage', '', null, $details);
        Db::name('finance_cash_shortage_application')->insert(['tenant_id' => $tenant, 'document_id' => $document['id'], 'reconciliation_document_id' => $reference, 'amount' => $amount, 'create_time' => time()]);
        return ['type' => $document['type'], 'subject_id' => (int)$account['id'], 'subject_name' => $account['name'], 'account_id' => (int)$account['id'], 'amount' => $amount,
            'actual_date' => $date, 'posting_month' => $month, 'reason' => $reason, 'loss_verified' => 1, 'reconciliation_document_id' => $reference,
            'reconciliation' => $reconciliation, 'remaining_shortage' => bcsub($remaining, $amount, 2), 'external_payment_amount' => '0.00'];
    }
}
