<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 客户当前往来投影；所有金额从正式来源与追加分录聚合，不读取旧余额列。 */
final class FinanceCustomerBalances
{
    private static function authorize(): void
    {
        foreach (['finance.receivable.view', 'finance.receivable.prepare', 'finance.receipt.prepare', 'finance.receipt.confirm', 'finance.refund.prepare', 'finance.recovery.prepare'] as $permission) {
            if (FinanceAccess::has($permission)) { return; }
        }
        FinanceAccess::require('finance.receivable.view');
    }

    public static function lists(array $params): array
    {
        self::authorize(); $tenant = FinanceAccess::tenant(); $page = FinanceValue::id($params['page'] ?? 1);
        if (!FinanceIntegration::active()) { return ['tenant_id' => $tenant, 'active' => false, 'lists' => [], 'has_more' => false]; }
        $sources = self::sourcesSql();
        $summary = '(SELECT subject_id,SUM(CASE WHEN category=\'receivable\' THEN balance ELSE 0 END) AS receivable,' .
            'SUM(CASE WHEN category=\'advance\' THEN balance ELSE 0 END) AS advance,' .
            'SUM(CASE WHEN category=\'customer_refund\' THEN balance ELSE 0 END) AS customer_refund,' .
            'SUM(CASE WHEN category=\'recovery\' THEN balance ELSE 0 END) AS recovery,' .
            "SUM(CASE WHEN category='receivable' AND due_date<CURRENT_DATE() AND balance>0 THEN balance ELSE 0 END) AS overdue FROM {$sources} AS src GROUP BY subject_id)";
        $query = Db::name('customer')->alias('c')->leftJoin([$summary => 'b'], 'b.subject_id=c.id')->where('c.tenant_id', $tenant)->where('c.parent_id', 0);
        $keyword = FinanceValue::text($params['keyword'] ?? '', 60, false);
        if ($keyword !== '') { $query->whereLike('c.customer_name', '%' . addcslashes($keyword, '%_\\') . '%'); }
        $filter = $params['filter'] ?? 'all';
        if (!in_array($filter, ['all', 'debt', 'overdue'], true)) { throw new \DomainException('客户往来筛选无效'); }
        if ($filter === 'debt') { $query->where('b.receivable', '>', 0); }
        if ($filter === 'overdue') { $query->where('b.overdue', '>', 0); }
        $rows = $query->field('c.id,c.customer_name,COALESCE(b.receivable,0) AS receivable,COALESCE(b.advance,0) AS advance,COALESCE(b.customer_refund,0) AS customer_refund,COALESCE(b.recovery,0) AS recovery,COALESCE(b.overdue,0) AS overdue')
            ->order('c.id')->limit(($page - 1) * 20, 21)->select()->toArray();
        $more = count($rows) > 20; $rows = array_slice($rows, 0, 20);
        foreach ($rows as &$row) { $row['id'] = (int)$row['id']; if (!self::canViewRecovery()) { $row['recovery'] = null; } }
        return ['tenant_id' => $tenant, 'active' => true, 'lists' => $rows, 'has_more' => $more, 'as_of' => date('Y-m-d H:i:s')];
    }

    public static function detail(array $params): array
    {
        self::authorize(); $tenant = FinanceAccess::tenant();
        $id = FinanceValue::id($params['customer_id'] ?? 0);
        $customer = Db::name('customer')->where('tenant_id', $tenant)->where('id', $id)->where('parent_id', 0)->field('id,customer_name')->find();
        if (!$customer || !FinanceIntegration::active()) { throw new \DomainException('客户不存在或新财务账套尚未启用'); }
        $category = FinanceValue::text($params['category'] ?? 'receivable', 40);
        if (!in_array($category, ['receivable', 'advance', 'customer_refund', 'recovery'], true)) { throw new \DomainException('客户往来类别无效'); }
        if ($category === 'recovery' && !self::canViewRecovery()) { throw new \DomainException('没有坏账追偿查看权限'); }
        $ledger = new FinanceLedger($tenant); $balances = [];
        foreach (['receivable', 'advance', 'customer_refund', 'recovery'] as $key) { $balances[$key] = $key === 'recovery' && !self::canViewRecovery() ? null : $ledger->categoryBalance($key, $id); }
        $page = $ledger->sourcePage([$category], $id, FinanceValue::id($params['page'] ?? 1));
        foreach ($page['sources'] as &$source) {
            $source['age_days'] = $source['business_date'] ? (int)(new \DateTimeImmutable($source['business_date']))->diff(new \DateTimeImmutable(date('Y-m-d')))->days : null;
            $source['overdue_days'] = $category === 'receivable' && $source['due_date'] && $source['due_date'] < date('Y-m-d') ? (int)(new \DateTimeImmutable($source['due_date']))->diff(new \DateTimeImmutable(date('Y-m-d')))->days : 0;
        }
        $actions = [];
        foreach (['receipt', 'advance_allocate', 'customer_refund', 'advance_refund', 'bad_debt', 'recovery_receipt', 'recovery_termination', 'receivable_due'] as $type) {
            try { $policy = FinanceDocumentPolicy::authorize($type); $actions[] = ['type' => $type, 'title' => $policy['title']]; } catch (\DomainException) { continue; }
        }
        return ['tenant_id' => $tenant, 'customer' => $customer, 'balances' => $balances, 'category' => $category, 'overdue' => FinanceCustomers::overdue($id), 'actions' => $actions, 'as_of' => date('Y-m-d H:i:s')] + $page;
    }

    public static function source(array $params): array
    {
        self::authorize(); $tenant = FinanceAccess::tenant();
        $source = (new FinanceLedger($tenant))->source(FinanceValue::text($params['source'] ?? '', 40));
        if (!in_array($source['category'], ['receivable', 'advance', 'customer_refund', 'recovery'], true) || ($source['category'] === 'recovery' && !self::canViewRecovery())) { throw new \DomainException('没有对应客户来源查看权限'); }
        $page = FinanceValue::id($params['page'] ?? 1);
        $rows = Db::name('finance_entry')->where('tenant_id', $tenant)->where('source_ref', $source['reference'])->where('metric', 'balance')->order('id', 'desc')->limit(($page - 1) * 20, 21)->select()->toArray();
        $more = count($rows) > 20; $rows = array_slice($rows, 0, 20);
        foreach ($rows as &$row) { $row['details'] = FinanceValue::decode($row['details']); }
        $dates = Db::name('finance_due_adjustment')->where('tenant_id', $tenant)->where('source_ref', $source['reference'])->order('id', 'desc')->limit(($page - 1) * 20, 21)->select()->toArray();
        $more = $more || count($dates) > 20; $dates = array_slice($dates, 0, 20);
        foreach ($dates as &$date) { $date['actor'] = FinanceValue::decode($date['actor']); $date['confirmed_at'] = date('Y-m-d H:i:s', (int)$date['create_time']); }
        return ['tenant_id' => $tenant, 'source' => $source, 'entries' => $rows, 'has_more' => $more, 'due_history' => $dates];
    }

    private static function canViewRecovery(): bool { return FinanceAccess::has('finance.receivable.view') || FinanceAccess::has('finance.recovery.prepare'); }

    private static function sourcesSql(): string
    {
        $tenant = FinanceAccess::tenant(); $parts = [];
        $changes = Db::name('finance_entry')->where('tenant_id', $tenant)->where('metric', 'balance')->field('source_ref,SUM(amount) AS delta')->group('source_ref')->buildSql();
        foreach (['o' => 'finance_opening_source', 'n' => 'finance_source'] as $kind => $table) {
            $due = $kind === 'o' ? "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(s.source_snapshot,'$.due_date')),'null')" : 's.due_date';
            $parts[] = Db::name($table)->alias('s')->leftJoin([$changes => 'b'], "b.source_ref=CONCAT('{$kind}:',s.id)")
                ->leftJoin([FinanceDueDates::latestSql($tenant) => 'd'], "d.source_ref=CONCAT('{$kind}:',s.id)")
                ->where('s.tenant_id', $tenant)->whereIn('s.category', ['receivable', 'advance', 'customer_refund', 'recovery'])
                ->field("s.subject_id,s.category,s.amount+COALESCE(b.delta,0) AS balance,CASE WHEN d.id IS NULL THEN {$due} ELSE d.new_due_date END AS due_date")->buildSql();
        }
        return '(' . implode(' UNION ALL ', $parts) . ')';
    }
}
