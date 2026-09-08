<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 对明确来源执行实际收付；不接受直接改余额或“其他”收付。 */
final class FinancePayments
{
    public function __construct(private readonly int $tenantId, private readonly FinanceLedger $ledger) {}

    public function confirm(array $document, ?array $originalTransaction = null, int $correctingDocument = 0, ?array $preservedAdvance = null): array
    {
        $type = $document['type']; $policy = FinanceDocumentPolicy::authorize($type, true);
        $data = FinanceValue::decode($document['payload']);
        if ($type === 'inventory_count_review') { return FinanceInventoryCountReviews::confirm($this->ledger, $document, $data); }
        if ($type === 'inventory_count_start') { return FinanceInventoryCounts::start($this->ledger, $document, $data); }
        if ($type === 'inventory_count') { return FinanceInventoryCounts::confirm($this->ledger, $document, $data); }
        if ($type === 'inventory_count_cancel') { return FinanceInventoryCounts::cancel($document, $data); }
        if ($type === 'transit_reconcile') { return FinanceTransitReviews::confirm($this->ledger, $document, $data); }
        if ($type === 'cash_shortage') { return FinanceReconciliations::shortage($this->ledger, $document, $data, $correctingDocument); }
        if ($type === 'account_reconcile') { return FinanceReconciliations::confirm($this->ledger, $document, $data); }
        if ($type === 'unclaimed_receipt') { return FinanceUnclaimed::receipt($this->ledger, $document, $data, $originalTransaction); }
        if (isset(FinanceUnclaimed::CLAIM_TYPES[$type])) { return FinanceUnclaimed::claim($this->ledger, $document, $data); }
        if ($type === 'account_transfer_out') { return FinanceAccountTransfers::out($this->ledger, $document, $data); }
        if (in_array($type, ['account_transfer_arrival', 'account_transfer_return'], true)) { return FinanceAccountTransfers::settle($this->ledger, $document, $data); }
        if ($type === 'equipment_refund_due') { return FinanceEquipmentRefunds::confirm($this->ledger, $document, $data); }
        if ($type === 'equipment_refund_adjustment') { return FinanceEquipmentRefunds::adjust($this->ledger, $document, $data); }
        if ($type === 'equipment_purchase') { return FinanceEquipment::confirm($this->ledger, $document, $data); }
        if ($type === 'equipment_adjustment') { return FinanceEquipment::adjust($this->ledger, $document, $data); }
        if ($type === 'salary_adjustment') { return FinanceSalaries::adjust($this->ledger, $document, $data); }
        if ($type === 'salary_expense') { return FinanceSalaries::confirm($this->ledger, $document, $data); }
        if ($type === 'employee_expense') { return FinanceEmployeeExpenses::confirm($this->ledger, $document, $data); }
        if ($type === 'employee_expense_adjustment') { return FinanceEmployeeExpenses::adjust($this->ledger, $document, $data); }
        if ($type === 'expense_recurring_plan') { return FinanceRecurringExpenses::confirmPlan($document, $data); }
        if ($type === 'expense_recurring_none') { return FinanceRecurringExpenses::confirmNone($document, $data); }
        if ($type === 'expense_recurring_correct') { return FinanceRecurringExpenses::correct($document, $data); }
        if ($type === 'expense_estimate_final') { return FinanceExpenseEstimates::confirm($this->ledger, $document, $data); }
        if ($type === 'deferred_amortization') { return FinanceDeferredExpenses::confirm($this->ledger, $document, $data); }
        if ($type === 'expense_adjustment') { return FinanceExpenseAdjustments::confirm($this->ledger, $document, $data); }
        if (in_array($type, ['expense', 'deferred_expense'], true)) { return FinanceExpenses::confirm($this->ledger, $document, $data); }
        if ($type === 'expense_category') { return FinanceExpenseCategories::confirm($document, $data); }
        if ($type === 'legacy_return_cost') { return FinanceLegacyReturnCosts::confirm($this->ledger, $document, $data); }
        if ($type === 'inventory_loss') { return FinanceInventoryLosses::confirm($this->ledger, $document, $data); }
        if ($type === 'inventory_loss_resolution') { return FinanceInventoryLosses::resolve($this->ledger, $document, $data); }
        if ($type === 'purchase_arrival_loss') { return FinancePurchaseArrivalLosses::confirm($this->ledger, $document, $data); }
        if ($type === 'purchase_difference') { return FinancePurchaseReviews::confirm($this->ledger, $document, $data); }
        if ($type === 'purchase_return_actual') { return FinancePurchaseReturns::confirm($this->ledger, $document, $data); }
        if ($type === 'purchase_return_resolution') { return FinancePurchaseReturnResolutions::confirm($this->ledger, $document, $data); }
        if ($type === 'purchase_return_acceptance') { return FinancePurchaseReturnAcceptances::confirm($this->ledger, $document, $data); }
        if ($type === 'purchase_extra_adjustment') { return FinancePurchaseExtraAdjustments::confirm($this->ledger, $document, $data); }
        if ($type === 'supplier_credit_allocate') { return FinanceSupplierCredits::confirm($this->ledger, $document, $data); }
        if ($type === 'purchase_adjustment') { return FinancePurchaseAdjustments::confirm($this->ledger, $document, $data); }
        if ($type === 'purchase_extra_cost') { return FinancePurchaseExtraCosts::confirm($this->ledger, $document, $data); }
        if ($type === 'purchase_arrival') { return FinancePurchaseArrivals::confirm($this->ledger, $document, $data); }
        if ($type === 'purchase_settlement') { return FinancePurchaseBatches::confirm($this->ledger, $document, $data); }
        if ($type === 'purchase_rules') { return FinancePurchaseRuleBook::confirm($document, $data); }
        if ($type === 'receipt_return') { return (new FinanceReceiptReturns($this->tenantId, $this->ledger))->confirm($document, $data, $originalTransaction, $correctingDocument); }
        if (in_array($type, ['receivable_due', 'payable_due'], true)) { return FinanceDueDates::confirm($this->ledger, $document, $data); }
        if (in_array($type, ['bad_debt', 'recovery_termination'], true)) { return (new FinanceReceivableActions($this->tenantId, $this->ledger))->confirm($document, $data); }
        if ($type === 'advance_allocate') { return $this->advance($document, $data); }
        $date = FinanceValue::date($data['actual_date'] ?? null);
        $month = $this->ledger->postingMonth($date);
        if ($correctingDocument) {
            $originalMonth = Db::name('finance_entry')->where('tenant_id', $this->tenantId)->where('document_id', $correctingDocument)
                ->where('metric', 'cash')->whereIn('purpose', ['actual_money', 'correction_replacement'])->value('posting_month');
            if ($originalMonth && Db::name('finance_period')->where('tenant_id', $this->tenantId)->where('month', $originalMonth)->lock(true)->find()) {
                $month = $this->ledger->postingMonth(date('Y-m-d'));
            }
        }
        $amount = FinanceValue::money($data['amount'] ?? null);
        $subjectId = FinanceValue::id($data['subject_id'] ?? 0);
        $nameColumn = ['customer' => 'customer_name', 'vendor' => 'supplier_name', 'employee' => 'name'][$policy['subject']];
        $query = Db::name($policy['subject'])->where('tenant_id', $this->tenantId)->where('id', $subjectId);
        if ($policy['subject'] === 'customer') { $query->where('parent_id', 0); }
        $subject = $query->field('id,' . $nameColumn . ' AS name')->find();
        if (!$subject) { throw new \DomainException('往来对象不存在、不属于本门店或不是主客户'); }
        $reason = FinanceValue::text($data['reason'] ?? '', 1000);
        $lines = $data['allocations'] ?? [];
        if (!is_array($lines)) { throw new \DomainException('请核对所选来源组成'); }
        if ($type === 'supplier_payment') {
            foreach ($lines as $line) {
                if (!is_array($line)) { throw new \DomainException('付款组成格式无效'); }
                $reference = FinanceValue::text($line['source'] ?? '', 40); $source = $this->ledger->source($reference);
                $available = bcsub($source['balance'], FinanceStatements::disputedAmount($reference), 2);
                if (bccomp(FinanceValue::money($line['amount'] ?? null), $available, 2) > 0) { throw new \DomainException('本次付款超过无争议可付金额，请先核实争议并完成正式调整'); }
            }
        }
        $total = $this->ledger->allocate((int)$document['id'], $lines, $policy['sources'], $subjectId, $date, $month);
        $advance = '0.00';
        if ($type === 'receipt') {
            $advance = FinanceValue::money($data['advance_amount'] ?? '0', true);
            if (bccomp(bcadd($total, $advance, 2), $amount, 2) !== 0) { throw new \DomainException('到账须等于所选欠款核销合计加明确转入的预收金额'); }
        } elseif (bccomp($total, $amount, 2) !== 0) { throw new \DomainException('本次金额必须等于所选来源的处理合计，不能超付或转为预付款'); }
        $money = (new FinanceMoney($this->tenantId, $this->ledger))->record((int)$document['id'], $type, $data, $policy['direction'], $date, $amount, $month, $originalTransaction);
        $created = [];
        if ($preservedAdvance) {
            FinanceAdvanceRevisions::append($this->ledger, (int)$document['id'], $preservedAdvance, $advance, $date, $month, $preservedAdvance['revision_reason'] ?? $reason);
            $created[] = $preservedAdvance['reference'];
        } elseif (bccomp($advance, '0', 2) > 0) {
            $created[] = $this->ledger->createSource((int)$document['id'], 'advance', $subjectId, $advance, $date, null,
                ['subject_name' => $subject['name'], 'reason' => $reason, 'transaction_id' => $money['transaction_id']]);
        }
        if (in_array($type, ['equipment_payment', 'equipment_refund'], true)) {
            $this->ledger->add((int)$document['id'], 'expense', $subjectId, ($type === 'equipment_refund' ? '-' : '') . $amount,
                $date, $month, 'equipment', '', null, ['category' => 'equipment', 'type' => $type]);
        }
        if ($type === 'recovery_receipt') { $this->ledger->add((int)$document['id'], 'recovery_income', $subjectId, $amount, $date, $month, 'bad_debt_recovery'); }
        return ['type' => $type, 'subject_id' => $subjectId, 'subject_name' => $subject['name'], 'reason' => $reason,
            'allocated_amount' => $total, 'advance_amount' => $advance, 'created_sources' => $created, 'money' => $money];
    }

    private function advance(array $document, array $data): array
    {
        $subjectId = FinanceValue::id($data['subject_id'] ?? 0);
        $source = $this->ledger->source(FinanceValue::text($data['advance_source'] ?? '', 40));
        if ($source['category'] !== 'advance' || $source['subject_id'] !== $subjectId) { throw new \DomainException('请选择本客户的合法预收来源'); }
        $reason = FinanceValue::text($data['reason'] ?? '', 1000);
        $lines = $data['allocations'] ?? [];
        if (!is_array($lines) || !$lines) { throw new \DomainException('请明确选择本次抵扣的应收与金额'); }
        $date = $source['business_date'];
        $activation = (string)Db::name('finance_preparation')->where('tenant_id', $this->tenantId)->value('activation_date');
        $postingDate = max($date ?? $activation, $activation);
        $month = $this->ledger->postingMonth($postingDate);
        $total = $this->ledger->allocate((int)$document['id'], $lines, ['receivable'], $subjectId, $date ?? $postingDate, $month, $date === null);
        $allocations = Db::name('finance_entry')->where('tenant_id', $this->tenantId)->where('document_id', $document['id'])
            ->where('purpose', 'allocation')->order('id')->select()->toArray();
        foreach ($allocations as $allocation) {
            $this->ledger->add((int)$document['id'], 'balance', $subjectId, $allocation['amount'], $allocation['business_date'], $allocation['posting_month'],
                'advance_use', $source['reference'], $allocation['effective_date'], ['reason' => $reason, 'allocation_entry_id' => (int)$allocation['id']]);
        }
        return ['type' => 'advance_allocate', 'subject_id' => $subjectId, 'subject_name' => $source['subject_name'],
            'allocated_amount' => $total, 'advance_source' => $source['reference'], 'reason' => $reason, 'created_sources' => []];
    }
}
