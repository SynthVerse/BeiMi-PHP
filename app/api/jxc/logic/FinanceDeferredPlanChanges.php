<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 已摊月份先取消错误确认再调整，计划本身只改变未摊金额和义务。 */
final class FinanceDeferredPlanChanges
{
    public static function project(int $tenant, array $source): array
    {
        if ($source['category'] !== 'deferred') { return $source; }
        $row = Db::name('finance_deferred_plan_revision')->where('tenant_id', $tenant)->where('source_ref', $source['reference'])->order('id', 'desc')->find();
        $source['expected_deferred_revision'] = (int)($row['id'] ?? 0);
        if ($row) {
            $result = FinanceValue::decode($row['snapshot']); $source['original_snapshot'] = $source['snapshot'];
            $source['snapshot'] = $result['plan']; $source['confirmed_amount'] = $result['new_amount']; $source['plan_document_id'] = (int)$row['document_id'];
        }
        return $source;
    }

    public static function confirm(FinanceLedger $ledger, array $document, array $input): array
    {
        FinanceAccess::require('', true); $tenant = FinanceAccess::tenant(); $id = (int)$document['id'];
        $source = $ledger->source(FinanceValue::text($input['source'] ?? null, 40)); $before = $source['snapshot'];
        $vendor = FinanceValue::id($input['subject_id'] ?? null);
        if ($source['category'] !== 'deferred' || $source['subject_id'] !== $vendor) { throw new \DomainException('请选择本对象的待摊计划'); }
        if (FinanceValue::id($input['expected_deferred_revision'] ?? null, true) !== $source['expected_deferred_revision']) { throw new \DomainException('待摊计划已有后续调整，请重新核对'); }
        $amount = FinanceValue::money($input['new_amount'] ?? null, true); $delta = bcsub($amount, $source['confirmed_amount'], 2);
        $activation = (string)Db::name('finance_preparation')->where('tenant_id', $tenant)->value('activation_date');
        if ($amount === '0.00') {
            if (($input['plan_verified'] ?? null) !== 1 || ($input['schedule'] ?? null) !== []) { throw new \DomainException('全部取消须核实计划并清空逐月计划'); }
            $details = array_replace($before['details'], ['schedule' => []]);
        } else { $details = FinanceDeferredPlans::details($input, $amount, $activation); }
        $months = FinanceDeferredAmortizations::months($source['reference']); $schedule = array_column($details['schedule'], null, 'month'); $recognized = '0.00'; $usedCategories = [];
        foreach ($months as $month => $state) {
            if ($state['status'] !== 'confirmed') { continue; }
            $fact = $state['result'];
            if (!isset($schedule[$month]) || bccomp($schedule[$month]['amount'], $fact['amount'], 2) !== 0) { throw new \DomainException('已摊月份及金额必须保留；原摊销错误时请先关联取消'); }
            $recognized = bcadd($recognized, $fact['amount'], 2);
            foreach ($fact['lines'] as $line) { $usedCategories[$line['category_id']] = bcadd($usedCategories[$line['category_id']] ?? '0', $line['amount'], 2); }
        }
        $opening = ($before['type'] ?? '') !== 'deferred_expense';
        if ($opening && bccomp($delta, '0', 2) !== 0) { throw new \DomainException('期初待摊金额调整须先逐项核清另列付款及应付组成，当前入口仅可重排原承接金额'); }
        $lines = $opening ? [] : FinanceExpenseAdjustments::lines($input['lines'] ?? null, $amount, $before['lines']);
        $totals = array_column($lines, 'amount', 'category_id');
        if (!$opening) { foreach ($usedCategories as $category => $used) { if (bccomp($totals[$category] ?? '0', $used, 2) < 0) { throw new \DomainException('调整后类别金额不能低于已摊费用组成，请先核实原摊销'); } } }
        $remaining = bcsub($amount, $recognized, 2);
        if (bccomp($remaining, '0', 2) < 0 || bccomp(bcadd($source['balance'], $delta, 2), $remaining, 2) !== 0) { throw new \DomainException('待摊金额与有效摊销不一致，请核清来源'); }
        $reason = FinanceValue::text($input['reason'] ?? null, 1000); $date = date('Y-m-d'); $posting = $ledger->postingMonth($date);
        $plan = array_replace($before, ['amount' => $amount, 'details' => array_replace($before['details'], ['service_start' => $details['service_start'], 'service_end' => $details['service_end'], 'schedule' => $details['schedule']])]);
        if (!$opening) { $plan['lines'] = $lines; }
        $result = ['type' => 'deferred_plan_adjustment', 'source' => $source['reference'], 'subject_id' => $vendor, 'subject_name' => $source['subject_name'],
            'source_reference' => $before['source_reference'] ?? $source['reference'], 'before_amount' => $source['confirmed_amount'], 'new_amount' => $amount, 'amount_change' => $delta,
            'recognized_amount' => $recognized, 'before_balance' => $source['balance'], 'after_balance' => $remaining, 'before_plan' => $before, 'plan' => $plan,
            'reason' => $reason, 'actual_date' => $source['business_date'] ?? $date, 'obligation_date' => $date, 'posting_month' => $posting,
            'original_expense_document_id' => $source['document_id'], 'previous_plan_document_id' => $source['plan_document_id'] ?? null, 'previous_revision_id' => $source['expected_deferred_revision'], 'confirmed_by' => FinanceAccess::actor(), 'confirmed_at' => time()];
        $refs = []; $created = [];
        if (!$opening) {
            foreach ($lines as $line) { Db::name('finance_expense_category')->where('tenant_id', $tenant)->where('id', $line['category_id'])->update(['used_at' => time()]); }
            $current = FinanceExpenseAdjustments::current($source['document_id'], $vendor); $refs = $current['source_refs'];
            $due = $before['due_date'];
            foreach (array_reverse($refs) as $ref) { $part = $ledger->source($ref); if ($part['category'] === 'expense_payable') { $due = $part['due_date']; break; } }
            $created = FinanceExpenseAdjustments::settle($ledger, $id, $vendor, $delta, $refs, $result, $due); $refs = array_merge($refs, $created);
            Db::name('finance_expense_revision')->insert(['tenant_id' => $tenant, 'document_id' => $id, 'bill_id' => $current['bill']['id'], 'previous_revision_id' => $current['expected_revision_id'],
                'snapshot' => FinanceValue::json(['expense' => $plan, 'source_refs' => $refs]), 'create_time' => time()]);
        }
        if (bccomp($delta, '0', 2) !== 0) { $ledger->add($id, 'balance', $vendor, $delta, $date, $posting, 'deferred_plan_adjustment', $source['reference'], $date, ['reason' => $reason]); }
        $result += ['created_sources' => $created, 'source_refs' => $refs];
        $revision = (int)Db::name('finance_deferred_plan_revision')->insertGetId(['tenant_id' => $tenant, 'source_ref' => $source['reference'], 'document_id' => $id,
            'previous_revision_id' => $source['expected_deferred_revision'], 'snapshot' => FinanceValue::json($result), 'create_time' => time()]);
        return $result + ['revision_id' => $revision];
    }
}
