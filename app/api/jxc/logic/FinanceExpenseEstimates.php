<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 原暂估范围不可变；每个类别独立核实，差额与核实记录在同一账簿事务内提交。 */
final class FinanceExpenseEstimates
{
    public static function version(array $bill): int
    {
        return (int)Db::name('finance_expense_estimate_resolution')->where('tenant_id', FinanceAccess::tenant())->where('bill_id', $bill['id'])->max('id');
    }

    public static function confirm(FinanceLedger $ledger, array $document, array $data): array
    {
        FinanceAccess::require('', true); $tenant = FinanceAccess::tenant(); $id = (int)$document['id'];
        $originalId = FinanceValue::id($data['original_expense_document_id'] ?? null); $vendor = FinanceValue::id($data['subject_id'] ?? null);
        $current = FinanceExpenseAdjustments::current($originalId, $vendor); $before = $current['expense']; $bill = $current['bill'];
        if (FinanceValue::id($data['expected_revision_id'] ?? null, true) !== $current['expected_revision_id']
            || FinanceValue::id($data['expected_resolution_id'] ?? null, true) !== self::version($bill)) { throw new \DomainException('费用或核实进度已变化，请重新读取原暂估'); }
        if (($data['final_verified'] ?? null) !== 1) { throw new \DomainException('请明确核实本次最终金额与依据'); }
        $items = self::items($bill); $byCategory = array_column($items, null, 'category_id');
        $input = $data['resolutions'] ?? null;
        if (!is_array($input) || !array_is_list($input) || !$input || count($input) > 100) { throw new \DomainException('请选择一至一百项尚未核实的原暂估类别'); }
        $reason = FinanceValue::text($data['reason'] ?? null, 1000); $resolved = []; $delta = '0.00'; $changed = false;
        $lines = array_column($before['lines'], null, 'category_id');
        foreach ($input as $part) {
            if (!is_array($part)) { throw new \DomainException('暂估核实项目格式无效'); }
            $category = FinanceValue::id($part['category_id'] ?? null); $item = $byCategory[$category] ?? null;
            if (!$item || $item['status'] !== 'pending' || isset($resolved[$category])) { throw new \DomainException('该类别不是原暂估待核实项目，或已核实、重复选择'); }
            $amount = FinanceValue::money($part['final_amount'] ?? null, true); $difference = bcsub($amount, $item['estimated_amount'], 2);
            $resolved[$category] = ['category_id' => $category, 'category_name' => $item['category_name'], 'estimated_amount' => $item['estimated_amount'],
                'final_amount' => $amount, 'amount_change' => $difference, 'confirmation_basis' => FinanceValue::text($part['confirmation_basis'] ?? null, 1000),
                'benefit_month' => $item['benefit_month'], 'estimate_basis_type' => $item['estimate_basis_type'], 'estimate_basis' => $item['estimate_basis'],
                'document_id' => $id, 'confirmed_by' => FinanceAccess::actor(), 'confirmed_at' => time()];
            $delta = bcadd($delta, $difference, 2); $changed = $changed || bccomp($difference, '0', 2) !== 0;
            if (bccomp($amount, '0', 2) === 0) { unset($lines[$category]); }
            else { $lines[$category] = array_replace($lines[$category], ['amount' => $amount]); }
        }
        $activation = (string)Db::name('finance_preparation')->where('tenant_id', $tenant)->value('activation_date');
        $month = $changed ? $ledger->postingMonth(max($before['benefit_month'] . '-01', $activation)) : null;
        $expense = array_replace($before, ['amount' => bcadd($before['amount'], $delta, 2), 'lines' => array_values($lines)]);
        $result = ['type' => 'expense_estimate_final', 'subject_id' => $vendor, 'subject_name' => $before['subject_name'],
            'original_expense_document_id' => $originalId, 'expense_document_id' => $originalId, 'source_reference' => $before['source_reference'],
            'actual_date' => $before['actual_date'], 'obligation_date' => date('Y-m-d'), 'before_expense' => $before, 'expense' => $expense,
            'amount_change' => $delta, 'reason' => $reason, 'resolutions' => array_values($resolved), 'posting_months' => $month ? [$month] : [],
            'confirmed_by' => FinanceAccess::actor(), 'confirmed_at' => time()];
        foreach ($resolved as $category => $part) {
            if (bccomp($part['amount_change'], '0', 2) !== 0) {
                $ledger->add($id, 'expense', $vendor, $part['amount_change'], $before['actual_date'], $month, 'expense_estimate_difference', '', null,
                    $byCategory[$category] + ['original_expense_document_id' => $originalId, 'final_resolution' => $part]);
            }
        }
        $refs = $current['source_refs']; $balances = []; $due = $before['due_date'];
        foreach ($refs as $ref) { $source = $ledger->source($ref); $balances[$ref] = $source['balance']; if ($source['category'] === 'expense_payable') { $due = $source['due_date']; } }
        $created = bccomp($delta, '0', 2) !== 0 ? FinanceExpenseAdjustments::settle($ledger, $id, $vendor, $delta, $refs, $result, $due) : [];
        $allRefs = array_values(array_unique(array_merge($refs, $created))); $changes = [];
        foreach ($allRefs as $ref) {
            $source = $ledger->source($ref); $previous = $balances[$ref] ?? '0.00'; $change = bcsub($source['balance'], $previous, 2);
            if (bccomp($change, '0', 2) !== 0) { $changes[] = ['reference' => $ref, 'category' => $source['category'], 'subject_id' => $vendor,
                'subject_name' => $source['subject_name'], 'before' => $previous, 'change' => $change, 'after' => $source['balance']]; }
        }
        $pending = count(array_filter($items, static fn(array $item): bool => $item['status'] === 'pending')) - count($resolved);
        $result += ['created_sources' => $created, 'source_refs' => $allRefs, 'balance_changes' => $changes, 'pending_count' => $pending,
            'previous_revision_id' => $current['expected_revision_id']];
        if ($changed) {
            $result['revision_id'] = (int)Db::name('finance_expense_revision')->insertGetId(['tenant_id' => $tenant, 'bill_id' => $bill['id'], 'document_id' => $id,
                'previous_revision_id' => $current['expected_revision_id'], 'snapshot' => FinanceValue::json($result), 'create_time' => time()]);
        }
        foreach ($resolved as $category => $part) {
            Db::name('finance_expense_estimate_resolution')->insert(['tenant_id' => $tenant, 'bill_id' => $bill['id'], 'category_id' => $category,
                'document_id' => $id, 'snapshot' => FinanceValue::json($part + ['posting_month' => bccomp($part['amount_change'], '0', 2) !== 0 ? $month : null]), 'create_time' => time()]);
        }
        return $result;
    }

    public static function declaration(array $data): array
    {
        $status = $data['amount_status'] ?? 'final';
        if (!in_array($status, ['final', 'estimated'], true)) { throw new \DomainException('请选择费用金额已确定或有依据暂估'); }
        if ($status === 'final') { return ['amount_status' => 'final']; }
        $type = $data['estimate_basis_type'] ?? null;
        if (!in_array($type, ['contract', 'measurement', 'history'], true)) { throw new \DomainException('费用暂估须依据合同、计量数据或可靠历史资料'); }
        return ['amount_status' => 'estimated', 'estimate_basis_type' => $type, 'estimate_basis' => FinanceValue::text($data['estimate_basis'] ?? null, 1000)];
    }

    public static function items(array $bill): array
    {
        $original = FinanceValue::decode($bill['snapshot']);
        if (($original['amount_status'] ?? 'final') !== 'estimated') { return []; }
        $resolved = Db::name('finance_expense_estimate_resolution')->where('tenant_id', FinanceAccess::tenant())->where('bill_id', $bill['id'])->select()->toArray();
        $byCategory = array_column($resolved, null, 'category_id'); $items = [];
        foreach ($original['lines'] as $line) {
            $row = $byCategory[$line['category_id']] ?? null;
            $items[] = $line + ['estimated_amount' => $line['amount'], 'benefit_month' => $original['benefit_month'],
                'estimate_basis_type' => $original['estimate_basis_type'], 'estimate_basis' => $original['estimate_basis'],
                'status' => $row ? 'resolved' : 'pending', 'resolution' => $row ? FinanceValue::decode($row['snapshot']) : null];
        }
        return $items;
    }
}
