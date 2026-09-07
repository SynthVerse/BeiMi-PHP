<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 供应商往来按正式来源读取，未结到货单列，不产生应付账龄或自动抵扣。 */
final class FinanceSupplierBalances
{
    private const CATEGORIES = ['payable', 'expense_payable', 'supplier_refund', 'expense_refund'];

    public static function lists(array $params): array
    {
        FinanceAccess::require('finance.payable.view'); $tenant = FinanceAccess::tenant();
        if (!FinanceIntegration::active()) { return ['tenant_id' => $tenant, 'active' => false, 'lists' => [], 'has_more' => false]; }
        $parts = []; $fields = [];
        $changes = Db::name('finance_entry')->where('tenant_id', $tenant)->where('metric', 'balance')->field('source_ref,SUM(amount) AS delta')->group('source_ref')->buildSql();
        foreach (['o' => 'finance_opening_source', 'n' => 'finance_source'] as $kind => $table) {
            $parts[] = Db::name($table)->alias('s')->leftJoin([$changes => 'b'], "b.source_ref=CONCAT('{$kind}:',s.id)")
                ->where('s.tenant_id', $tenant)->whereIn('s.category', self::CATEGORIES)->field('s.subject_id,s.category,s.amount+COALESCE(b.delta,0) AS balance')->buildSql();
        }
        foreach (self::CATEGORIES as $category) { $fields[] = "SUM(CASE WHEN category='{$category}' THEN balance ELSE 0 END) AS {$category}"; }
        $summary = '(SELECT subject_id,' . implode(',', $fields) . ' FROM (' . implode(' UNION ALL ', $parts) . ') AS src GROUP BY subject_id)';
        $query = Db::name('vendor')->alias('v')->leftJoin([$summary => 'b'], 'b.subject_id=v.id')->where('v.tenant_id', $tenant);
        $keyword = FinanceValue::text($params['keyword'] ?? '', 60, false);
        if ($keyword !== '') { $query->whereLike('v.supplier_name', '%' . addcslashes($keyword, '%_\\') . '%'); }
        $columns = array_map(static fn(string $category): string => "COALESCE(b.{$category},0) AS {$category}", self::CATEGORIES);
        $rows = $query->field('v.id,v.supplier_name,' . implode(',', $columns))->order('v.id')
            ->limit((FinanceValue::id($params['page'] ?? 1) - 1) * 20, 21)->select()->toArray();
        $more = count($rows) > 20; $rows = array_slice($rows, 0, 20);
        foreach ($rows as &$row) { $row['id'] = (int)$row['id']; }
        return ['tenant_id' => $tenant, 'active' => true, 'lists' => $rows, 'has_more' => $more, 'as_of' => date('Y-m-d H:i:s')];
    }

    public static function detail(array $params): array
    {
        FinanceAccess::require('finance.payable.view'); $tenant = FinanceAccess::tenant(); $id = FinanceValue::id($params['vendor_id'] ?? 0);
        $vendor = Db::name('vendor')->where('tenant_id', $tenant)->where('id', $id)->field('id,supplier_name')->find();
        if (!$vendor || !FinanceIntegration::active()) { throw new \DomainException('供应商不存在或新财务账套尚未启用'); }
        $category = FinanceValue::text($params['category'] ?? 'payable', 40);
        if (!in_array($category, self::CATEGORIES, true)) { throw new \DomainException('供应商往来类别无效'); }
        return Db::transaction(function () use ($tenant, $id, $vendor, $category, $params): array {
            $ledger = new FinanceLedger($tenant); $balances = [];
            foreach (self::CATEGORIES as $key) { $balances[$key] = $ledger->categoryBalance($key, $id); }
            $page = $ledger->sourcePage([$category], $id, FinanceValue::id($params['page'] ?? 1));
            foreach ($page['sources'] as &$source) {
                $source['disputed_amount'] = FinanceStatements::disputedAmount($source['reference']);
                $available = bcsub($source['balance'], $source['disputed_amount'], 2);
                $source['available_payment'] = in_array($category, ['payable', 'expense_payable'], true) && bccomp($available, '0', 2) > 0 ? $available : '0.00';
            }
            $actions = [];
            foreach (['supplier_payment', 'supplier_credit_allocate', 'supplier_refund', 'expense_refund', 'payable_due'] as $type) {
                try { $policy = FinanceDocumentPolicy::authorize($type); $actions[] = ['type' => $type, 'title' => $policy['title']]; } catch (\DomainException) { continue; }
            }
            return ['tenant_id' => $tenant, 'vendor' => $vendor, 'balances' => $balances, 'category' => $category, 'actions' => $actions,
                'pending_arrivals' => FinanceStatementSnapshot::pendingArrivals($id, date('Y-m-d')), 'as_of' => date('Y-m-d H:i:s')] + $page;
        });
    }
}
