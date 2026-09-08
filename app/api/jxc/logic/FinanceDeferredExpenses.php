<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 已核实逐月计划只释放待摊余额；原付款及另列未付义务保持独立。 */
final class FinanceDeferredExpenses
{
    public static function options(FinanceLedger $ledger, int $vendor, array $params): array
    {
        $page = $ledger->sourcePage(['deferred'], $vendor, FinanceValue::id($params['page'] ?? 1));
        if (!empty($params['source'])) {
            $source = $ledger->source(FinanceValue::text($params['source'], 40));
            if ($source['category'] !== 'deferred' || $source['subject_id'] !== $vendor) { throw new \DomainException('指定待摊来源不属于本对象'); }
            $page['selected_deferred'] = self::present($source);
        }
        $page['sources'] = array_map(self::present(...), $page['sources']);
        return $page + FinanceExpenseCategories::options();
    }

    private static function present(array $source): array
    {
        $confirmed = Db::name('finance_deferred_amortization')->where('tenant_id', FinanceAccess::tenant())->where('source_ref', $source['reference'])
            ->column('document_id', 'benefit_month');
        $source['schedule'] = array_map(static fn(array $row): array => $row + ['status' => isset($confirmed[$row['month']]) ? 'confirmed' : ($row['month'] > date('Y-m') ? 'future' : 'pending'),
            'document_id' => isset($confirmed[$row['month']]) ? (int)$confirmed[$row['month']] : null], $source['snapshot']['details']['schedule'] ?? []);
        return $source;
    }

    public static function confirm(FinanceLedger $ledger, array $document, array $data): array
    {
        $tenant = FinanceAccess::tenant(); $id = (int)$document['id'];
        $vendor = FinanceValue::id($data['subject_id'] ?? null); $ref = FinanceValue::text($data['source'] ?? null, 40);
        $source = $ledger->source($ref);
        if ($source['category'] !== 'deferred' || $source['subject_id'] !== $vendor) { throw new \DomainException('请选择本对象的合法待摊费用来源'); }
        $benefit = FinanceValue::text($data['benefit_month'] ?? null, 7); FinanceValue::date($benefit . '-01');
        if ($benefit > date('Y-m')) { throw new \DomainException('未来月份尚未受益，不能提前摊销'); }
        if (Db::name('finance_deferred_amortization')->where('tenant_id', $tenant)->where('source_ref', $ref)->where('benefit_month', $benefit)->lock(true)->find()) {
            throw new \DomainException('该来源本月已摊销，请查看原确认记录');
        }
        $schedule = $source['snapshot']['details']['schedule'] ?? [];
        $rows = array_values(array_filter($schedule, static fn(array $row): bool => $row['month'] === $benefit));
        if (count($rows) !== 1) { throw new \DomainException('所选月份没有唯一已核实的待摊计划'); }
        $amount = FinanceValue::money($rows[0]['amount']);
        if (bccomp($amount, $source['balance'], 2) > 0) { throw new \DomainException('待摊余额不足，请核对来源后续处理'); }
        if (($data['amortization_verified'] ?? null) !== 1) { throw new \DomainException('请明确核实本月服务受益及费用组成'); }
        $reason = FinanceValue::text($data['reason'] ?? null, 1000); $lines = FinanceExpenseCategories::lines($data['lines'] ?? null, $amount);
        $activation = (string)Db::name('finance_preparation')->where('tenant_id', $tenant)->value('activation_date');
        if ($benefit < substr($activation, 0, 7)) { throw new \DomainException('启用前已摊费用不能重复确认'); }
        $date = max($benefit . '-01', $activation); $posting = $ledger->postingMonth($date);
        $result = ['type' => 'deferred_amortization', 'source' => $ref, 'source_reference' => $source['snapshot']['source_reference'] ?? $ref,
            'subject_id' => $vendor, 'subject_name' => $source['subject_name'], 'benefit_month' => $benefit, 'actual_date' => $date,
            'posting_month' => $posting, 'amount' => $amount, 'before_balance' => $source['balance'], 'after_balance' => bcsub($source['balance'], $amount, 2),
            'source_snapshot' => $source['snapshot'], 'lines' => $lines, 'reason' => $reason, 'amortization_verified' => 1,
            'confirmed_by' => FinanceAccess::actor(), 'confirmed_at' => time()];
        $ledger->add($id, 'balance', $vendor, '-' . $amount, $date, $posting, 'deferred_amortization', $ref, $date, ['benefit_month' => $benefit, 'reason' => $reason]);
        foreach ($lines as $line) {
            $ledger->add($id, 'expense', $vendor, $line['amount'], $date, $posting, 'deferred_amortization', $ref, null, $line + ['benefit_month' => $benefit]);
            Db::name('finance_expense_category')->where('tenant_id', $tenant)->where('id', $line['category_id'])->update(['used_at' => time()]);
        }
        Db::name('finance_deferred_amortization')->insert(['tenant_id' => $tenant, 'document_id' => $id, 'source_ref' => $ref,
            'benefit_month' => $benefit, 'amount' => $amount, 'snapshot' => FinanceValue::json($result), 'create_time' => time()]);
        return $result;
    }
}
