<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 原月结事项不改写；当前进度只由对应业务的正式核实记录投影。 */
final class FinancePeriodFollowups
{
    public static function read(string $month): array
    {
        FinanceAccess::require('', true); $tenant = FinanceAccess::tenant();
        $period = Db::name('finance_period')->where('tenant_id', $tenant)->where('month', $month)->find();
        if (!$period) { throw new \DomainException('请先选择本门店已结账月份'); }
        $snapshot = FinanceValue::decode($period['snapshot']);
        if (!array_key_exists('unresolved', $snapshot)) { throw new \DomainException('原月份缺少权威遗留清单，不能按当前状态补造历史'); }
        $items = []; $summary = ['total' => count($snapshot['unresolved']), 'pending' => 0, 'partial' => 0, 'resolved' => 0];
        foreach ($snapshot['unresolved'] as $original) {
            $current = self::current($original, $month); $summary[$current['status']]++;
            $items[] = ['id' => $original['id'], 'original' => $original, 'current' => $current];
        }
        return ['tenant_id' => $tenant, 'month' => $month, 'original_mode' => $snapshot['mode'] ?? $period['status'],
            'original_verification' => $snapshot['reports']['profit']['verification'] ?? null, 'items' => $items, 'summary' => $summary,
            'all_resolved' => $summary['pending'] === 0 && $summary['partial'] === 0];
    }

    private static function current(array $item, string $month): array
    {
        $details = $item['details']; $tenant = FinanceAccess::tenant(); $evidence = []; $eventIds = []; $resolved = false; $partial = false;
        if ($item['category'] === 'account_reconciliation') {
            $check = FinanceReconciliations::followup((int)$details['account_id'], $month);
            if ($check['latest']) { $evidence[] = $check['latest']; }
            $resolved = $check['state'] === 'matched' && ($check['latest']['closed_period_followup'] ?? false);
        } elseif ($item['category'] === 'transit_reconciliation') {
            $check = FinanceTransitReviews::followup(new FinanceLedger($tenant), $details['transfer_source'], $month);
            if ($check['latest']) { $evidence[] = $check['latest']; }
            $resolved = $check['state'] === 'normal' && ($check['latest']['closed_period_followup'] ?? false);
        } elseif ($item['category'] === 'expense_estimate') {
            [$bill, $category] = explode(':', $item['reference']);
            $row = Db::name('finance_expense_estimate_resolution')->where('tenant_id', $tenant)->where('bill_id', (int)$bill)->where('category_id', (int)$category)->find();
            if ($row) { $evidence[] = FinanceValue::decode($row['snapshot']); $resolved = true; }
        } elseif ($item['category'] === 'pending_document') {
            $row = Db::name('finance_document')->where('tenant_id', $tenant)->where('id', $details['document_id'])->where('status', 'confirmed')->find();
            if ($row) { $evidence[] = FinanceValue::decode($row['confirmed_result']) + ['document_id' => (int)$row['id']]; $resolved = true; }
        } elseif ($item['category'] === 'deferred_amortization') {
            $row = Db::name('finance_deferred_amortization')->where('tenant_id', $tenant)->where('source_ref', $details['source'])->where('benefit_month', $details['month'])->find();
            if ($row) { $evidence[] = FinanceValue::decode($row['snapshot']) + ['document_id' => (int)$row['document_id']]; $resolved = true; }
        } elseif ($item['category'] === 'recurring_expense') {
            $plan = FinanceRecurringExpenses::plan((int)$details['plan_id']);
            $part = array_values(array_filter($plan['months'], static fn(array $row): bool => $row['month'] === $details['month']))[0] ?? null;
            if ($part && in_array($part['status'], ['none', 'expense', 'estimated'], true)) {
                $evidence[] = ($part['result']['result'] ?? []) + ['document_id' => $part['document_id'], 'outcome' => $part['status']]; $resolved = true;
                if (in_array($part['status'], ['expense', 'estimated'], true)) {
                    $bill = Db::name('finance_expense_bill')->where('tenant_id', $tenant)->where('document_id', $part['expense_document_id'])->find();
                    foreach ($bill ? FinanceExpenseEstimates::items($bill) : [] as $estimate) {
                        if ($estimate['status'] === 'pending') { $resolved = false; $partial = true; }
                        elseif ($estimate['resolution']) { $evidence[] = $estimate['resolution']; }
                    }
                }
            }
        } elseif ($item['category'] === 'purchase_difference') {
            $review = FinancePurchaseReviews::latest((int)$details['arrival_line_id']);
            if ($review) { $evidence[] = FinanceValue::decode($review['snapshot']) + ['document_id' => (int)$review['document_id']]; $resolved = (bool)$review['resolved']; $partial = !$resolved; }
        } elseif ($item['category'] === 'purchase_cost') {
            $arrival = Db::name('finance_purchase_arrival_line')->where('tenant_id', $tenant)->where('id', $details['arrival_line_id'])->find();
            if ($arrival) {
                $coverage = FinancePurchaseCoverage::totals((int)$arrival['id']); $remaining = bcsub($arrival['actual_quantity'], $coverage['covered_quantity'], 4);
                $resolved = bccomp($remaining, '0', 4) === 0; $partial = !$resolved && bccomp($remaining, $details['pending_quantity'], 4) < 0;
                $evidence = self::records('finance_purchase_settlement_line', 'arrival_line_id', $arrival['id']);
                foreach (self::records('finance_purchase_return_resolution', 'arrival_line_id', $arrival['id']) as $part) { $evidence[] = $part; }
            }
        } elseif ($item['category'] === 'purchase_return') {
            $row = Db::name('finance_purchase_return_line')->where('tenant_id', $tenant)->where('id', $details['return_line_id'])->find();
            if ($row) {
                $state = FinancePurchaseReturnAcceptances::current((int)$row['id'], (int)$row['vendor_id']);
                $resolved = bccomp($state['remaining_quantity'], '0', 4) === 0; $partial = !$resolved && bccomp($state['remaining_quantity'], $details['remaining_quantity'], 4) < 0;
                $evidence = self::records('finance_purchase_return_resolution', 'return_line_id', $row['id']);
            }
        } elseif ($item['category'] === 'inventory_count') {
            $id = (int)$details['document_id'];
            $row = Db::name('finance_document')->where('tenant_id', $tenant)->where('id', $id)->where('type', 'inventory_count')->where('status', 'confirmed')->find();
            if ($row) {
                $lines = array_column(FinanceInventoryCountReviews::lines($id, FinanceValue::decode($row['confirmed_result'])), null, 'sku_id');
                $resolved = true;
                foreach ($details['lines'] as $original) {
                    $line = $lines[$original['sku_id']] ?? null;
                    if (!$line || !$line['resolved']) { $resolved = false; }
                    if ($line && ($line['expected_review_id'] > ($original['expected_review_id'] ?? 0) || $line['expected_correction_id'] > ($original['expected_correction_id'] ?? 0) || $line['resolved'])) { $partial = true; }
                }
                $evidence = array_merge(self::records('finance_inventory_count_review', 'count_result_document_id', $id), self::records('finance_inventory_count_correction', 'count_result_document_id', $id));
            }
        } elseif ($item['category'] === 'inventory_loss') {
            $incident = (int)$details['incident_document_id'];
            $row = Db::name('finance_inventory_loss')->where('tenant_id', $tenant)->where('document_id', $incident)->find();
            if ($row) {
                $quantity = (string)Db::name('finance_inventory_loss_resolution')->where('tenant_id', $tenant)->where('incident_document_id', $incident)->sum('quantity');
                $remaining = bcsub($row['quantity'], $quantity, 4); $resolved = bccomp($remaining, '0', 4) === 0;
                $partial = !$resolved && bccomp($remaining, $details['remaining_quantity'] ?? $details['quantity'], 4) < 0;
                $evidence = self::records('finance_inventory_loss_resolution', 'incident_document_id', $incident);
            }
        } elseif ($item['category'] === 'cost_pending' && str_starts_with($item['reference'], 'shortage:')) {
            $references = self::costReferences($details); $scope = ['tenant_id' => $tenant, 'warehouse_id' => $details['warehouse_id'], 'sku_id' => $details['sku_id']];
            $remaining = (string)Db::name('finance_cost_shortage')->where($scope)->whereIn('reference', $references)->sum('quantity');
            $unknown = Db::name('finance_cost_position')->alias('p')->leftJoin('finance_cost_origin o', 'o.tenant_id=p.tenant_id AND o.origin_key=p.origin_key')
                ->where('p.tenant_id', $tenant)->where('p.warehouse_id', $details['warehouse_id'])->where('p.sku_id', $details['sku_id'])->whereIn('p.reference', $references)->where('p.quantity', '>', 0)->whereNull('o.current_amount')->sum('p.quantity');
            $remaining = bcadd($remaining, (string)$unknown, 12); $partial = bccomp($remaining, $details['quantity'], 12) < 0;
            $eventIds = Db::name('finance_cost_effect')->where($scope)->whereIn('reference', $references)->where('posting_month', '>', $month)->distinct(true)->column('event_id');
            foreach ($eventIds ? Db::name('finance_cost_event')->where('tenant_id', $tenant)->whereIn('id', $eventIds)->order('id')->select()->toArray() : [] as $event) {
                $evidence[] = FinanceValue::decode($event['snapshot']) + ['document_id' => (int)$event['document_id'], 'confirmed_at' => (int)$event['create_time'], 'confirmed_by' => FinanceValue::decode($event['actor'])];
            }
            $resolved = bccomp($remaining, '0', 12) === 0 && (bool)$evidence;
        } elseif ($item['category'] === 'cost_pending') {
            $amount = Db::name('finance_cost_origin')->where('tenant_id', $tenant)->where('origin_key', $item['reference'])->value('current_amount');
            $voidedReturn = false;
            foreach (Db::name('finance_cost_event')->where('tenant_id', $tenant)
                ->whereRaw("(JSON_UNQUOTE(JSON_EXTRACT(snapshot,'$.origin')) = ? OR (event_type='customer_return_void' AND JSON_UNQUOTE(JSON_EXTRACT(snapshot,'$.return_reference')) = ?))", [$item['reference'], $item['reference']])->order('id')->select()->toArray() as $event) {
                $eventIds[] = (int)$event['id'];
                $voidedReturn = $voidedReturn || $event['event_type'] === 'customer_return_void';
                $basis = FinanceValue::decode($event['snapshot']);
                $evidence[] = $basis + ['document_id' => (int)$event['document_id'], 'confirmed_at' => (int)$event['create_time'], 'confirmed_by' => FinanceValue::decode($event['actor'])];
            }
            $resolved = ($amount !== null || $voidedReturn) && (bool)$evidence;
            if (preg_match('/^inventory-count-gain:(\d+):(\d+):(\d+)$/D', $item['reference'], $countOrigin)) {
                $corrections = array_values(array_filter(self::records('finance_inventory_count_correction', 'count_result_document_id', (int)$countOrigin[1]), static fn(array $row): bool => (int)$row['sku_id'] === (int)$countOrigin[3]));
                $evidence = array_merge($evidence, $corrections); $partial = (bool)$corrections;
                $resolved = $resolved || FinanceInventoryCountCorrections::cancelledGainOrigin($item['reference']);
            }
        } elseif ($item['category'] === 'statement_dispute') {
            [$kind, $id] = explode(':', $item['reference']); $table = $kind === 'vendor' ? 'finance_supplier_statement' : 'finance_statement';
            $resolution = Db::name($table . '_resolution')->where('tenant_id', $tenant)->where('dispute_id', (int)$id)->order('id', 'desc')->find();
            if ($resolution && in_array($resolution['resolution'], ['ledger_verified', 'resolved'], true)) {
                $event = Db::name($table . '_event')->where('tenant_id', $tenant)->where('id', $resolution['event_id'])->find();
                if ($event) { $evidence[] = FinanceValue::decode($event['payload']) + ['statement_id' => (int)$event['statement_id'], 'confirmed_at' => (int)$event['create_time'], 'confirmed_by' => FinanceValue::decode($event['actor'])]; $resolved = true; }
            }
        }
        $documents = array_values(array_unique(array_filter(array_map(static fn(array $row): int => (int)($row['document_id'] ?? 0), $evidence))));
        $adjustments = $documents ? Db::name('finance_entry')->where('tenant_id', $tenant)->whereIn('document_id', $documents)->where('posting_month', '>', $month)->order('id')->select()->toArray() : [];
        if ($item['category'] === 'account_reconciliation') {
            $adjustments = Db::name('finance_entry')->where('tenant_id', $tenant)->where('metric', 'cash')->where('subject_id', $details['account_id'])->where('business_date', '<=', date('Y-m-t', strtotime($month . '-01')))->where('posting_month', '>', $month)->order('id')->select()->toArray();
        } elseif ($item['category'] === 'transit_reconciliation') {
            $adjustments = Db::name('finance_entry')->where('tenant_id', $tenant)->where('metric', 'balance')->where('source_ref', $details['transfer_source'])->where('business_date', '<=', date('Y-m-t', strtotime($month . '-01')))->where('posting_month', '>', $month)->order('id')->select()->toArray();
        }
        foreach ($adjustments as &$entry) { $entry['details'] = FinanceValue::decode($entry['details']); } unset($entry);
        if ($item['category'] === 'expense_estimate') {
            $adjustments = array_values(array_filter($adjustments, static fn(array $row): bool => $row['metric'] === 'expense' && (int)($row['details']['category_id'] ?? 0) === (int)$details['category_id']));
        }
        $events = array_values(array_unique(array_merge($eventIds, $documents ? Db::name('finance_cost_event')->where('tenant_id', $tenant)->whereIn('document_id', $documents)->column('id') : [])));
        $costQuery = Db::name('finance_cost_effect')->where('tenant_id', $tenant)->whereIn('event_id', $events ?: [0])->where('posting_month', '>', $month);
        if (isset($details['arrival_line_id'])) { $costQuery->where('origin_key', 'purchase-arrival:' . $details['arrival_line_id']); }
        elseif ($item['category'] === 'cost_pending') {
            if (str_starts_with($item['reference'], 'shortage:')) { $costQuery->whereIn('reference', $references)->where('warehouse_id', $details['warehouse_id'])->where('sku_id', $details['sku_id']); }
            else { $costQuery->where('origin_key', $item['reference']); }
        }
        $costAdjustments = $costQuery->order('id')->select()->toArray();
        return ['status' => $resolved ? 'resolved' : ($partial ? 'partial' : 'pending'), 'evidence' => $evidence, 'adjustments' => $adjustments, 'cost_adjustments' => $costAdjustments,
            'route' => $details['route'] ?? null];
    }

    private static function records(string $table, string $column, int|string $id): array
    {
        return array_map(static fn(array $row): array => FinanceValue::decode($row['snapshot']) + ['document_id' => (int)$row['document_id'], 'confirmed_at' => (int)$row['create_time']],
            Db::name($table)->where('tenant_id', FinanceAccess::tenant())->where($column, $id)->order('id')->select()->toArray());
    }

    /** 确定损失责任会转移成本去向，但不会自动补齐未知的来源成本。 */
    private static function costReferences(array $details): array
    {
        $references = [$details['source_reference'] => true];
        foreach (Db::name('finance_cost_event')->where('tenant_id', FinanceAccess::tenant())->where('sku_id', $details['sku_id'])->where('event_type', 'reclassify')->order('id')->column('snapshot') as $snapshot) {
            $event = FinanceValue::decode($snapshot);
            if ((int)$event['warehouse_id'] === (int)$details['warehouse_id'] && isset($references[$event['target_reference']])) { $references[$event['to_reference']] = true; }
        }
        return array_keys($references);
    }
}
