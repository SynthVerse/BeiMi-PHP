<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 原费用与资金事实保留；调整只改变费用归属及本费用的未结义务。 */
final class FinanceExpenseAdjustments
{
    public static function current(int $documentId, int $vendor): array
    {
        $tenant = FinanceAccess::tenant();
        $bill = Db::name('finance_expense_bill')->where('tenant_id', $tenant)->where('document_id', $documentId)->lock(true)->find();
        if (!$bill) { throw new \DomainException('原普通费用不存在或不属于本门店'); }
        $revision = Db::name('finance_expense_revision')->where('tenant_id', $tenant)->where('bill_id', $bill['id'])->order('id', 'desc')->lock(true)->find();
        $state = $revision ? FinanceValue::decode($revision['snapshot']) : ['expense' => FinanceValue::decode($bill['snapshot']), 'source_refs' => [$bill['source_ref']]];
        if ($vendor && (int)$state['expense']['subject_id'] !== $vendor) { throw new \DomainException('费用当前收款对象不匹配，请重新读取原费用'); }
        return ['bill' => $bill, 'expense' => $state['expense'], 'source_refs' => $state['source_refs'], 'expected_revision_id' => (int)($revision['id'] ?? 0)];
    }

    public static function outstanding(int $documentId): array
    {
        $current = self::current($documentId, 0); $ledger = new FinanceLedger(FinanceAccess::tenant()); $sources = [];
        foreach ($current['source_refs'] as $ref) {
            $source = $ledger->source($ref);
            if (bccomp($source['balance'], '0', 2) > 0) { $sources[] = $source; }
        }
        return ['current_sources' => $sources];
    }

    public static function options(int $vendor, array $params): array
    {
        $page = FinanceValue::id($params['page'] ?? 1); $keyword = FinanceValue::text($params['keyword'] ?? '', 60, false);
        $latest = Db::name('finance_expense_revision')->where('tenant_id', FinanceAccess::tenant())->field('bill_id,MAX(id) AS revision_id')->group('bill_id')->buildSql();
        $query = Db::name('finance_expense_bill')->alias('b')->leftJoin([$latest => 'v'], 'v.bill_id=b.id')
            ->leftJoin('finance_expense_revision r', 'r.id=v.revision_id AND r.tenant_id=b.tenant_id')->where('b.tenant_id', FinanceAccess::tenant())
            ->whereLike('b.source_reference', '%' . $keyword . '%');
        if (!empty($params['original_expense_document_id'])) { $query->where('b.document_id', FinanceValue::id($params['original_expense_document_id'])); }
        else { $query->whereRaw("COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(r.snapshot,'$.expense.subject_id')) AS UNSIGNED),b.vendor_id)=?", [$vendor]); }
        $rows = $query->field('b.*')->order('b.id', 'desc')->limit(($page - 1) * 20, 21)->select()->toArray(); $choices = [];
        foreach (array_slice($rows, 0, 20) as $bill) {
            $current = self::current((int)$bill['document_id'], !empty($params['original_expense_document_id']) ? 0 : $vendor);
            $choices[] = ['original_expense_document_id' => (int)$bill['document_id'], 'expected_revision_id' => $current['expected_revision_id'], 'expense' => $current['expense']];
        }
        return FinanceExpenseCategories::options() + ['bills' => $choices, 'bill_has_more' => count($rows) > 20];
    }

    public static function confirm(FinanceLedger $ledger, array $document, array $data): array
    {
        FinanceAccess::require('', true); $tenant = FinanceAccess::tenant(); $id = (int)$document['id'];
        $vendor = FinanceValue::id($data['subject_id'] ?? null); $originalId = FinanceValue::id($data['original_expense_document_id'] ?? null);
        $current = self::current($originalId, $vendor); $before = $current['expense'];
        if (FinanceValue::id($data['expected_revision_id'] ?? null, true) !== $current['expected_revision_id']) { throw new \DomainException('费用已有后续调整，请重新核对最新记录'); }
        if (($data['adjustment_verified'] ?? null) !== 1) { throw new \DomainException('请明确核实原费用、本次调整内容及依据'); }
        $newVendor = FinanceValue::id($data['new_subject_id'] ?? null);
        $newName = Db::name('vendor')->where('tenant_id', $tenant)->where('id', $newVendor)->value('supplier_name');
        if ($newName === null) { throw new \DomainException('调整后收款对象不存在或不属于本门店'); }
        FinanceExpenseIdentities::claim($newVendor, $before['source_reference'], (int)$current['bill']['id'], $id);
        $due = $before['due_date']; $dueMode = $before['due_mode'];
        if ($newVendor !== $vendor) {
            $dueMode = $data['new_due_mode'] ?? null; $due = FinanceValue::date($data['new_due_date'] ?? null, true);
            if (!in_array($dueMode, ['date', 'unspecified'], true) || ($dueMode === 'date') !== ($due !== null) || ($due && $due < $before['actual_date'])) {
                throw new \DomainException('更正收款对象须重新核实付款日或明确未约定');
            }
        } else {
            foreach (array_reverse($current['source_refs']) as $ref) {
                $source = $ledger->source($ref);
                if ($source['subject_id'] === $vendor && $source['category'] === 'expense_payable') { $due = $source['due_date']; $dueMode = $due ? 'date' : 'unspecified'; break; }
            }
        }
        $amount = FinanceValue::money($data['new_amount'] ?? null, true); $delta = bcsub($amount, $before['amount'], 2);
        $benefit = FinanceValue::text($data['benefit_month'] ?? null, 7); FinanceValue::date($benefit . '-01');
        $activation = (string)Db::name('finance_preparation')->where('tenant_id', $tenant)->value('activation_date');
        if ($benefit < substr($activation, 0, 7) || $benefit > date('Y-m')) { throw new \DomainException('调整后归属须为财务启用后已发生的服务月份'); }
        $month = $ledger->postingMonth(max($benefit . '-01', $activation));
        $reverseMonth = $ledger->postingMonth(max($before['posting_month'] . '-01', $activation));
        $lines = self::lines($data['lines'] ?? null, $amount, $before['lines']);
        if ($newVendor === $vendor && bccomp($delta, '0', 2) === 0 && $benefit === $before['benefit_month'] && $lines === $before['lines']) { throw new \DomainException('费用金额、归属与明细均未变化'); }
        $reason = FinanceValue::text($data['reason'] ?? null, 1000); $basis = FinanceValue::text($data['confirmation_basis'] ?? null, 1000);
        $expense = array_replace($before, ['amount' => $amount, 'subject_id' => $newVendor, 'subject_name' => $newName,
            'benefit_month' => $benefit, 'posting_month' => $month, 'lines' => $lines, 'due_date' => $due, 'due_mode' => $dueMode]);
        $snapshot = ['type' => 'expense_adjustment', 'subject_id' => $vendor, 'subject_name' => $before['subject_name'], 'original_expense_document_id' => $originalId,
            'expense_document_id' => $originalId, 'source_reference' => $before['source_reference'], 'actual_date' => $before['actual_date'], 'obligation_date' => date('Y-m-d'),
            'before_amount' => $before['amount'], 'new_amount' => $amount, 'amount_change' => $delta, 'reason' => $reason, 'confirmation_basis' => $basis,
            'before_expense' => $before, 'expense' => $expense, 'confirmed_by' => FinanceAccess::actor(), 'confirmed_at' => time()];
        foreach ([[$before['lines'], $reverseMonth, true], [$lines, $month, false]] as [$items, $posting, $reverse]) {
            foreach ($items as $line) {
                $ledger->add($id, 'expense', $reverse ? $vendor : $newVendor, ($reverse ? '-' : '') . $line['amount'], $before['actual_date'], $posting, 'expense_adjustment', '', null,
                    $line + ['original_expense_document_id' => $originalId, 'benefit_month' => $reverse ? $before['benefit_month'] : $benefit, 'adjustment_reason' => $reason]);
                if (!$reverse) { Db::name('finance_expense_category')->where('tenant_id', $tenant)->where('id', $line['category_id'])->update(['used_at' => time()]); }
            }
        }
        $refs = $current['source_refs']; $beforeBalances = [];
        foreach ($refs as $ref) { $beforeBalances[$ref] = $ledger->source($ref)['balance']; }
        if ($newVendor === $vendor) { $created = self::settle($ledger, $id, $vendor, $delta, $refs, $snapshot, $due); }
        else {
            $created = self::settle($ledger, $id, $vendor, '-' . $before['amount'], $refs, $snapshot, null);
            $created = array_merge($created, self::settle($ledger, $id, $newVendor, $amount, $refs,
                array_replace($snapshot, ['subject_id' => $newVendor, 'subject_name' => $newName]), $due));
        }
        $allRefs = array_values(array_unique(array_merge($refs, $created))); $balanceChanges = [];
        foreach ($allRefs as $ref) {
            $source = $ledger->source($ref); $previous = $beforeBalances[$ref] ?? '0.00'; $change = bcsub($source['balance'], $previous, 2);
            if (bccomp($change, '0', 2) !== 0) { $balanceChanges[] = ['reference' => $ref, 'category' => $source['category'], 'subject_id' => $source['subject_id'],
                'subject_name' => $source['subject_name'], 'before' => $previous, 'change' => $change, 'after' => $source['balance']]; }
        }
        $result = $snapshot + ['created_sources' => $created, 'source_refs' => $allRefs, 'balance_changes' => $balanceChanges,
            'posting_months' => array_values(array_unique([$reverseMonth, $month])), 'previous_revision_id' => $current['expected_revision_id']];
        $revision = (int)Db::name('finance_expense_revision')->insertGetId(['tenant_id' => $tenant, 'document_id' => $id, 'bill_id' => $current['bill']['id'],
            'previous_revision_id' => $current['expected_revision_id'], 'snapshot' => FinanceValue::json($result), 'create_time' => time()]);
        return $result + ['revision_id' => $revision];
    }

    private static function lines(mixed $input, string $amount, array $before): array
    {
        if (!is_array($input) || !array_is_list($input) || count($input) > 100 || (bccomp($amount, '0', 2) === 0 && $input)) { throw new \DomainException('请核对调整后的费用明细，全部取消时明细应为空'); }
        $previous = array_column($before, null, 'category_id'); $total = '0.00'; $lines = []; $seen = [];
        foreach ($input as $line) {
            if (!is_array($line)) { throw new \DomainException('费用调整明细格式无效'); }
            $id = FinanceValue::id($line['category_id'] ?? null);
            if (isset($seen[$id])) { throw new \DomainException('同一费用类别不能重复选择'); } $seen[$id] = true;
            $part = FinanceValue::money($line['amount'] ?? null); $total = bcadd($total, $part, 2);
            if (isset($previous[$id]) && FinanceValue::id($line['expected_category_version'] ?? null) === $previous[$id]['category_version']) {
                $lines[] = array_replace($previous[$id], ['amount' => $part, 'reason' => FinanceValue::text($line['reason'] ?? null, 1000)]);
            } else { $lines[] = FinanceExpenseCategories::lines([$line], $part)[0]; }
        }
        if (bccomp($total, $amount, 2) !== 0) { throw new \DomainException('费用调整明细合计必须等于调整后总额'); }
        return $lines;
    }

    private static function settle(FinanceLedger $ledger, int $id, int $vendor, string $delta, array $refs, array $snapshot, ?string $due): array
    {
        $increase = bccomp($delta, '0', 2) > 0; $remaining = $increase ? $delta : bcsub('0', $delta, 2); $date = $snapshot['obligation_date'];
        foreach ($refs as $ref) {
            if (bccomp($remaining, '0', 2) === 0) { break; }
            $source = $ledger->source($ref);
            if ($source['subject_id'] !== $vendor || $source['category'] !== ($increase ? 'expense_refund' : 'expense_payable')) { continue; }
            $used = bccomp($source['balance'], $remaining, 2) < 0 ? $source['balance'] : $remaining;
            $ledger->add($id, 'balance', $vendor, '-' . $used, $snapshot['actual_date'], $ledger->postingMonth($date), 'expense_obligation_adjustment', $ref, $date,
                ['original_expense_document_id' => $snapshot['original_expense_document_id'], 'reason' => $snapshot['reason']]);
            $remaining = bcsub($remaining, $used, 2);
        }
        if (bccomp($remaining, '0', 2) === 0) { return []; }
        return [$ledger->createSource($id, $increase ? 'expense_payable' : 'expense_refund', $vendor, $remaining, $date, $increase ? $due : null, $snapshot)];
    }
}
