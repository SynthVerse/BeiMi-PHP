<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 在独立外层事务内演算完整命令，始终回滚；确认仍重验当前版本、权限和余额。 */
final class FinancePreview
{
    public static function calculate(array $params): array
    {
        $action = FinanceValue::text($params['action'] ?? '', 40);
        if (!in_array($action, ['record', 'confirm', 'correct', 'reverse', 'reverse_duplicate'], true)) { throw new \DomainException('该操作没有记账影响预览'); }
        $params['idempotency_key'] = 'preview-' . bin2hex(random_bytes(24));
        Db::startTrans();
        try {
            $document = FinanceBusinessLogic::action($action, $params);
            if ($document === false) { throw new \DomainException(FinanceBusinessLogic::getError()); }
            $tenant = FinanceAccess::tenant(); $ledger = new FinanceLedger($tenant);
            $entries = Db::name('finance_entry')->where('tenant_id', $tenant)->where('document_id', $document['id'])->select()->toArray();
            $created = Db::name('finance_source')->where('tenant_id', $tenant)->where('document_id', $document['id'])->select()->toArray();
            $changes = []; $periods = [];
            foreach ($created as $source) { $changes['n:' . $source['id']] = $source['amount']; }
            foreach ($entries as $entry) {
                if ($entry['metric'] === 'balance') { $changes[$entry['source_ref']] = bcadd($changes[$entry['source_ref']] ?? '0', $entry['amount'], 2); }
                else {
                    $key = $entry['metric'] . ':' . $entry['posting_month'];
                    $periods[$key] ??= ['metric' => $entry['metric'], 'posting_month' => $entry['posting_month'], 'amount' => '0.00'];
                    $periods[$key]['amount'] = bcadd($periods[$key]['amount'], $entry['amount'], 2);
                }
            }
            $balances = [];
            foreach ($changes as $reference => $amount) {
                $source = $ledger->source($reference);
                $balances[] = ['category' => $source['category'], 'label' => $source['snapshot']['source_reference'] ?? $source['snapshot']['reason'] ?? '本次业务来源',
                    'before' => bcsub($source['balance'], $amount, 2), 'change' => $amount, 'after' => $source['balance']];
            }
            $months = array_values(array_unique(array_column($entries, 'posting_month')));
            if (!$months && isset($document['confirmed_result']['posting_month'])) { $months[] = $document['confirmed_result']['posting_month']; }
            $months = array_values(array_unique(array_merge($months, $document['confirmed_result']['posting_months'] ?? [])));
            $costImpacts = [];
            if (in_array($document['type'], ['legacy_return_cost', 'inventory_loss', 'inventory_loss_resolution', 'purchase_arrival_loss', 'purchase_difference', 'purchase_return_resolution', 'purchase_return_acceptance', 'purchase_return_actual', 'purchase_arrival', 'purchase_settlement', 'purchase_extra_cost', 'purchase_adjustment', 'purchase_extra_adjustment'], true)) {
                $pendingSkus = [];
                foreach ($document['confirmed_result']['lines'] as $line) { if ($line['cost_pending']) { $pendingSkus[(int)$line['sku_id']] = true; } }
                $costImpacts = (new FinanceCostLedger($tenant))->documentImpacts((int)$document['id'], $pendingSkus);
                $months = array_values(array_unique(array_merge($months, array_column($costImpacts, 'posting_month')))); sort($months);
            }
            $result = $document['confirmed_result'];
            return ['tenant_id' => $tenant, 'balances' => $balances, 'impacts' => array_values($periods), 'posting_months' => $months,
                ...($document['type'] === 'sales_batch' ? ['sales' => $result] : []),
                ...(in_array($document['type'], ['expense', 'expense_category', 'expense_adjustment', 'expense_estimate_final', 'expense_recurring_plan', 'expense_recurring_none', 'deferred_amortization', 'deferred_expense'], true) ? ['expense' => $result] : []),
                ...(in_array($document['type'], ['legacy_return_cost', 'inventory_loss', 'inventory_loss_resolution', 'purchase_arrival_loss', 'purchase_difference', 'purchase_return_resolution', 'purchase_return_acceptance', 'purchase_return_actual', 'purchase_arrival', 'purchase_settlement', 'purchase_extra_cost', 'purchase_adjustment', 'purchase_extra_adjustment'], true) ? ['purchase' => $result, 'cost_impacts' => $costImpacts] : []),
                ...($document['type'] === 'purchase_rules' ? ['purchase_rule' => $result] : []),
                'old_due_date' => $result['old_due_date'] ?? null, 'new_due_date' => $result['new_due_date'] ?? null];
        } finally { Db::rollback(); }
    }
}
