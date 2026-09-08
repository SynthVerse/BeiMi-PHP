<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 结账前读取统一未决清单；调用方持有账套锁，检查本身不关闭月份。 */
final class FinancePeriodChecklist
{
    public static function collect(FinanceLedger $ledger, array $params): array
    {
        $tenant = FinanceAccess::tenant(); $activation = (string)Db::name('finance_preparation')->where('tenant_id', $tenant)->value('activation_date');
        $periods = Db::name('finance_period')->where('tenant_id', $tenant)->order('month')->select()->toArray(); $closed = array_column($periods, null, 'month');
        $next = substr($activation, 0, 7);
        while (isset($closed[$next])) { $next = date('Y-m', strtotime($next . '-01 +1 month')); }
        $month = FinanceValue::text($params['month'] ?? $next, 7);
        if (!preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D', $month)) { throw new \DomainException('请选择有效结账月份'); }
        FinanceValue::date($month . '-01');
        if ($month < substr($activation, 0, 7) || $month > date('Y-m')) { throw new \DomainException('结账检查月份须在启用月与当前月之间'); }
        $cutoff = date('Y-m-t', strtotime($month . '-01')); $items = [];
        if ($month >= date('Y-m')) { $items[] = self::item('month_not_ended', $month, '本自然月尚未结束', 'hard'); }
        if (isset($closed[$month])) { $items[] = self::item('month_closed', $month, '本月已经结账，历史快照保持不变', 'hard'); }
        elseif ($month !== $next) { $items[] = self::item('month_order', $next, '请先完成 ' . $next . ' 月结账，不能跳月', 'hard'); }
        if ($month < date('Y-m')) {
            foreach (FinanceReconciliations::options($ledger, ['month' => $month])['checks'] as $account) {
                if ($account['state'] !== 'matched') {
                    $items[] = self::item('account_reconciliation', (string)$account['account_id'], $account['account_name'] . '：' . match ($account['state']) { 'needs_review' => '账面已变，需重新核对', 'difference' => '差额待处理', default => '尚未核对' }, 'blocking',
                        ['account_id' => $account['account_id'], 'month' => $month, 'book_balance' => $account['book_balance'], 'reconciliation_document_id' => $account['latest']['document_id'] ?? null, 'route' => '/sub-finance/reconciliation/edit?type=account_reconcile&month=' . $month]);
                }
            }
            $page = 1;
            do {
                $transits = FinanceTransitReviews::options($ledger, ['month' => $month, 'page' => $page++]);
                foreach ($transits['transfers'] as $transfer) {
                    if (!$transfer['ordinary_close_allowed']) { $items[] = self::item('transit_reconciliation', $transfer['transfer_source'], $transfer['source_reference'] . '：在途尚需核对', 'blocking',
                        ['transfer_source' => $transfer['transfer_source'], 'remaining_amount' => $transfer['remaining_amount'], 'month' => $month, 'state' => $transfer['state'], 'route' => '/sub-finance/reconciliation/edit?type=transit_reconcile&month=' . $month . '&transfer_source=' . rawurlencode($transfer['transfer_source'])]); }
                }
            } while ($transits['transfer_has_more']);
        }
        foreach (Db::name('finance_document')->where('tenant_id', $tenant)->whereIn('status', ['draft', 'pending'])->order('id')->select()->toArray() as $document) {
            $payload = FinanceValue::decode($document['payload']); $businessMonth = null;
            foreach (['month', 'benefit_month', 'actual_date', 'service_start'] as $key) {
                if (is_string($payload[$key] ?? null) && preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])/', $payload[$key])) { $businessMonth = substr($payload[$key], 0, 7); break; }
            }
            if ($businessMonth !== null && $businessMonth > $month) { continue; }
            $draft = $document['status'] === 'draft';
            $items[] = self::item($draft ? 'draft' : 'pending_document', (string)$document['id'], (FinanceDocumentPolicy::TYPES[$document['type']]['title'] ?? '财务单据') . ($draft ? '：草稿尚未提交' : '：已提交，尚未确认'), $draft ? 'warning' : 'blocking',
                ['document_id' => (int)$document['id'], 'type' => $document['type'], 'business_month' => $businessMonth, 'route' => '/sub-finance/business/index?type=' . rawurlencode($document['type'])]);
        }
        foreach (Db::name('sales_order')->where('tenant_id', $tenant)->where('source_type', 'customer_report')->distinct(true)->column('customer_id') as $customer) {
            foreach (FinanceDeliveries::rows((int)$customer, $activation, $cutoff) as $delivery) {
                $items[] = self::item('unconfirmed_delivery', (string)$delivery['delivery_item_id'], $delivery['delivery_customer_name'] . '：已交付金额尚未全部确认', 'hard',
                    $delivery + ['customer_id' => (int)$customer, 'route' => '/sub-finance/sales/edit?type=sales_batch&subject_id=' . (int)$customer]);
            }
        }
        self::costItems($items, $cutoff);
        foreach (FinanceInventoryCounts::unresolved($cutoff) as $count) {
            $items[] = self::item('inventory_count', (string)$count['document_id'], '盘点差异原因或截止成本仍待核实', 'blocking',
                $count + ['route' => '/sub-finance/inventory/review?type=inventory_count_review&count_result_document_id=' . $count['document_id']]);
        }
        self::purchaseItems($items, $cutoff);
        self::disputeItems($items, $ledger, $cutoff);
        self::unclaimedItems($items, $ledger, $cutoff);
        self::expenseItems($items, $ledger, $month);
        $hard = count(array_filter($items, static fn(array $item): bool => $item['severity'] === 'hard'));
        $blocking = count(array_filter($items, static fn(array $item): bool => $item['severity'] === 'blocking'));
        return ['tenant_id' => $tenant, 'month' => $month, 'actual_cutoff' => $cutoff . ' 23:59:59', 'next_month' => $next, 'closed' => isset($closed[$month]),
            'closing_status' => $closed[$month]['status'] ?? 'open', 'items' => $items, 'hard_count' => $hard, 'blocking_count' => $blocking,
            'ordinary_ready' => $hard === 0 && $blocking === 0, 'estimated_ready' => $hard === 0, 'checked_at' => time()];
    }

    private static function item(string $category, string $reference, string $title, string $severity, array $details = []): array
    {
        return ['id' => $category . ':' . $reference, 'category' => $category, 'reference' => $reference, 'title' => $title, 'severity' => $severity, 'details' => $details];
    }

    private static function costItems(array &$items, string $cutoff): void
    {
        $tenant = FinanceAccess::tenant();
        $unknown = Db::name('finance_cost_origin')->alias('o')->join('finance_cost_effect e', 'e.tenant_id=o.tenant_id AND e.origin_key=o.origin_key')
            ->where('o.tenant_id', $tenant)->whereNull('o.current_amount')->where('e.business_date', '<=', $cutoff)->where('e.posting_month', '<=', substr($cutoff, 0, 7))
            ->distinct(true)->field('o.origin_key,o.sku_id,o.snapshot')->select()->toArray();
        foreach ($unknown as $origin) {
            $basis = FinanceValue::decode($origin['snapshot']); unset($origin['snapshot']);
            $source = $basis['source'] ?? [];
            $route = ($source['cost_basis_pending'] ?? null) === 'pre_cutoff_sales_return'
                ? '/sub-finance/inventory/cost?type=legacy_return_cost&stock_flow_id=' . (int)$source['stock_flow_id']
                : '/sub-finance/business/index?type=purchase_settlement';
            if (preg_match('/^inventory-count-gain:(\d+):/', $origin['origin_key'], $match)) {
                $route = '/sub-finance/inventory/review?type=inventory_count_review&count_result_document_id=' . $match[1];
            }
            $items[] = self::item('cost_pending', $origin['origin_key'], '原入库或退回来源成本待确认', 'blocking', $origin + ['route' => $route]);
        }
        $references = [];
        foreach (Db::name('finance_cost_event')->where('tenant_id', $tenant)->where('business_date', '<=', $cutoff)->select()->toArray() as $event) {
            $snapshot = FinanceValue::decode($event['snapshot']); $reference = $snapshot['target_reference'] ?? $snapshot['reference'] ?? $event['reference'];
            $references[(int)$event['sku_id'] . ':' . $reference] = true;
            if (isset($snapshot['to_reference'])) { $references[(int)$event['sku_id'] . ':' . $snapshot['to_reference']] = true; }
        }
        foreach (Db::name('finance_cost_shortage')->where('tenant_id', $tenant)->where('quantity', '>', 0)->select()->toArray() as $shortage) {
            if (!isset($references[(int)$shortage['sku_id'] . ':' . $shortage['reference']])) { continue; }
            $items[] = self::item('cost_pending', 'shortage:' . $shortage['id'], '负库存来源及成本仍待核实', 'blocking', ['warehouse_id' => (int)$shortage['warehouse_id'], 'sku_id' => (int)$shortage['sku_id'], 'quantity' => $shortage['quantity'], 'source_reference' => $shortage['reference'], 'route' => '/sub-finance/inventory/cost']);
        }
    }

    private static function expenseItems(array &$items, FinanceLedger $ledger, string $month): void
    {
        $tenant = FinanceAccess::tenant();
        foreach (Db::name('finance_recurring_expense_plan')->where('tenant_id', $tenant)->order('id')->column('id') as $id) {
            $plan = FinanceRecurringExpenses::plan((int)$id);
            foreach ($plan['months'] as $part) {
                if ($part['month'] === $month && $part['status'] === 'pending') { $items[] = self::item('recurring_expense', $id . ':' . $month, $plan['subject_name'] . '：周期费用本月尚未处理', 'blocking',
                    ['plan_id' => (int)$id, 'month' => $month, 'source_reference' => $plan['source_reference'], 'route' => '/sub-finance/expense/recurring?type=expense_recurring_plan&id=' . $plan['document_id']]); }
            }
        }
        foreach (Db::name('finance_expense_bill')->where('tenant_id', $tenant)->order('id')->select()->toArray() as $bill) {
            $current = FinanceExpenseAdjustments::current((int)$bill['document_id'], 0);
            if (($current['expense']['benefit_month'] ?? null) !== $month || bccomp($current['expense']['amount'], '0', 2) === 0) { continue; }
            foreach (FinanceExpenseEstimates::items($bill) as $estimate) {
                if ($estimate['status'] === 'pending') { $items[] = self::item('expense_estimate', $bill['id'] . ':' . $estimate['category_id'], $current['expense']['subject_name'] . '：合理费用暂估待最终核实', 'estimate',
                    $estimate + ['document_id' => (int)$bill['document_id'], 'route' => '/sub-finance/expense/edit?type=expense&id=' . $bill['document_id']]); }
            }
        }
        foreach (['o' => 'finance_opening_source', 'n' => 'finance_source'] as $kind => $table) {
            foreach (Db::name($table)->where('tenant_id', $tenant)->where('category', 'deferred')->order('id')->column('id') as $id) {
                $reference = $kind . ':' . $id; $source = $ledger->source($reference);
                foreach ($source['snapshot']['details']['schedule'] ?? [] as $part) {
                    if ($part['month'] !== $month || bccomp($part['amount'], '0', 2) === 0) { continue; }
                    $confirmed = Db::name('finance_deferred_amortization')->where('tenant_id', $tenant)->where('source_ref', $reference)->where('benefit_month', $month)->find();
                    if (!$confirmed) { $items[] = self::item('deferred_amortization', $reference . ':' . $month, '本月待摊计划尚未确认摊销', 'blocking',
                        ['source' => $reference, 'subject_id' => $source['subject_id'], 'month' => $month, 'amount' => $part['amount'], 'route' => '/sub-finance/expense/deferred?type=deferred_amortization&subject_id=' . $source['subject_id'] . '&source=' . rawurlencode($reference)]); }
                }
            }
        }
    }

    private static function purchaseItems(array &$items, string $cutoff): void
    {
        foreach (Db::name('finance_purchase_arrival_line')->where('tenant_id', FinanceAccess::tenant())->where('business_date', '<=', $cutoff)->order('id')->select()->toArray() as $arrival) {
            $basis = FinanceValue::decode($arrival['snapshot']); $review = FinancePurchaseReviews::latest((int)$arrival['id']);
            if (bccomp($basis['arrival_difference'] ?? '0', '0', 4) !== 0 && (!$review || !$review['resolved'])) {
                $items[] = self::item('purchase_difference', (string)$arrival['id'], '到货重量差或异常损失仍待处理', 'blocking',
                    ['arrival_line_id' => (int)$arrival['id'], 'business_date' => $arrival['business_date'], 'quantity' => $basis['arrival_difference'], 'classification' => $review['classification'] ?? 'unreviewed', 'route' => '/sub-finance/purchase/difference?type=purchase_difference&subject_id=' . $arrival['vendor_id']]);
            }
            $coverage = FinancePurchaseCoverage::totals((int)$arrival['id']);
            $remaining = bcsub($arrival['actual_quantity'], $coverage['covered_quantity'], 4);
            if (bccomp($remaining, '0', 4) > 0) { $items[] = self::item('purchase_cost', (string)$arrival['id'], '采购到货成本尚未全部正式确认', 'blocking',
                ['arrival_line_id' => (int)$arrival['id'], 'business_date' => $arrival['business_date'], 'pending_quantity' => $remaining, 'estimated_amount' => $arrival['estimated_amount'], 'subject_id' => (int)$arrival['vendor_id'], 'route' => '/sub-finance/purchase/settlement?type=purchase_settlement&subject_id=' . $arrival['vendor_id']]); }
        }
        foreach (Db::name('finance_purchase_return_line')->where('tenant_id', FinanceAccess::tenant())->where('business_date', '<=', $cutoff)->order('id')->select()->toArray() as $returned) {
            $state = FinancePurchaseReturnAcceptances::current((int)$returned['id'], (int)$returned['vendor_id']);
            if (bccomp($state['remaining_quantity'], '0', 4) > 0) { $items[] = self::item('purchase_return', (string)$returned['id'], '实际退离仍有未认可或未处理部分', 'blocking',
                ['return_line_id' => (int)$returned['id'], 'business_date' => $returned['business_date'], 'remaining_quantity' => $state['remaining_quantity'], 'route' => '/sub-finance/purchase/acceptance?type=purchase_return_acceptance&subject_id=' . $returned['vendor_id']]); }
        }
        $page = 1;
        do {
            $result = FinanceInventoryLosses::options(['page' => $page++]);
            foreach ($result['incidents'] as $incident) {
                if ($incident['actual_date'] <= $cutoff) { $items[] = self::item('inventory_loss', (string)$incident['incident_document_id'], '库内实物减少仍有待核实部分', 'blocking',
                    $incident + ['route' => '/sub-finance/inventory/loss?type=inventory_loss_resolution&incident_document_id=' . $incident['incident_document_id']]); }
            }
        } while ($result['incident_has_more']);
    }

    private static function disputeItems(array &$items, FinanceLedger $ledger, string $cutoff): void
    {
        foreach (['customer', 'vendor'] as $kind) {
            foreach (FinanceStatements::openDisputes([], 0, $kind) as $dispute) {
                if (!$dispute['ledger_uncertain']) { continue; }
                $source = $ledger->source($dispute['source_ref']);
                if ($source['business_date'] && $source['business_date'] > $cutoff) { continue; }
                $items[] = self::item('statement_dispute', $kind . ':' . $dispute['id'], '对账异议中的账内金额或归属尚未核实', 'blocking',
                    $dispute + ['subject_kind' => $kind, 'subject_id' => $source['subject_id'], 'route' => '/sub-finance/receivable/statements?subject_kind=' . $kind . '&subject_id=' . $source['subject_id'] . '&id=' . $dispute['statement_id']]);
            }
        }
    }

    private static function unclaimedItems(array &$items, FinanceLedger $ledger, string $cutoff): void
    {
        $month = substr($cutoff, 0, 7);
        foreach ($ledger->sources('unclaimed') as $source) {
            if ($source['business_date'] && $source['business_date'] > $cutoff) { continue; }
            if (($source['snapshot']['posting_month'] ?? $month) > $month) { continue; }
            $change = (string)Db::name('finance_entry')->where('tenant_id', FinanceAccess::tenant())->where('metric', 'balance')->where('source_ref', $source['reference'])->where('business_date', '<=', $cutoff)->where('posting_month', '<=', $month)->sum('amount');
            $remaining = bcadd($source['original_amount'], $change ?: '0', 2);
            if (bccomp($remaining, '0', 2) === 0) { continue; }
            $opening = str_starts_with($source['reference'], 'o:'); $details = $source['snapshot']['details'] ?? [];
            $verified = $source['business_date'] !== null && ($opening ? ($details['account_inclusion'] ?? null) === 'confirmed' : ($source['snapshot']['funds_verified'] ?? null) === 1);
            $account = $ledger->account($source['subject_id'], false);
            $items[] = self::item('unclaimed', $source['reference'], $verified ? '资金事实已核实，月末用途尚待认领' : '待认领到账的资金事实尚需核实', $verified ? 'warning' : 'blocking',
                ['source' => $source['reference'], 'account_id' => $source['subject_id'], 'account_name' => $account['name'], 'actual_date' => $source['business_date'], 'remaining_amount' => $remaining, 'funds_verified' => $verified,
                    'route' => $verified ? '/sub-finance/unclaimed/edit?type=unclaimed_customer_claim&unclaimed_source=' . rawurlencode($source['reference']) : '/sub-finance/opening/index']);
        }
    }
}
