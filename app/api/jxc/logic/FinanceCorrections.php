<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 调用方持有门店账套锁与事务；冲销、替代单和关联关系必须一起提交。 */
final class FinanceCorrections
{
    public function __construct(private readonly int $tenantId, private readonly FinanceLedger $ledger) {}

    public function replace(array $original, array $replacement, string $reason, int $duplicateOf = 0, bool $reverseOnly = false): array
    {
        $policy = FinanceDocumentPolicy::authorize($original['type'], true);
        if (str_starts_with($original['type'], 'inventory_count')) { throw new \DomainException('盘点快照与确认事实必须保留，请关联取消或反向调整，不能覆盖历史'); }
        if ($original['type'] === 'transit_reconcile') { throw new \DomainException('在途核对须追加新的核实结论，不能覆盖或撤销原历史'); }
        if ($original['type'] === 'account_reconcile') { throw new \DomainException('月末核对须按最新历史追加核对记录，不能覆盖或撤销原核对'); }
        if (in_array($original['type'], ['account_transfer_out', 'account_transfer_arrival', 'account_transfer_return'], true)) { throw new \DomainException('互转更正须关联两端账户、在途及后续到账，不能直接覆盖或撤销一端资金'); }
        if (in_array($original['type'], ['equipment_refund_due', 'equipment_refund_adjustment'], true)) { throw new \DomainException('设备退款约定须从原退款关联调整，不能覆盖实际到账历史'); }
        if (in_array($original['type'], ['equipment_purchase', 'equipment_adjustment'], true)) { throw new \DomainException('设备额度请从原购置记录关联调价或取消未付，不能覆盖已有付款历史'); }
        if (in_array($original['type'], ['salary_expense', 'salary_adjustment'], true)) { throw new \DomainException('工资结果须关联原工资和已发放组成调整，不能覆盖历史'); }
        if (in_array($original['type'], ['employee_expense', 'employee_expense_adjustment'], true)) { throw new \DomainException('员工垫付须关联原费用与已报销组成调整，不能覆盖历史'); }
        if (in_array($original['type'], ['expense_recurring_plan', 'expense_recurring_none', 'expense_recurring_correct'], true)) { throw new \DomainException('周期计划及逐月处理须关联原计划更正，不能覆盖已有待办或确认结果'); }
        if ($original['type'] === 'deferred_expense') { throw new \DomainException('待摊计划须关联原服务计划调整，不得覆盖已确认义务或已摊费用'); }
        if ($original['type'] === 'deferred_amortization') { throw new \DomainException('已摊费用须保留原服务月份和来源，请通过关联调整处理'); }
        if (in_array($original['type'], ['expense', 'expense_category', 'expense_adjustment', 'expense_estimate_final'], true)) { throw new \DomainException('费用请关联原费用调整；类别请按当前版本维护，不能覆盖历史确认'); }
        if ($original['type'] === 'legacy_return_cost') { throw new \DomainException('旧售退回成本须关联原来源登记新的核实金额，不得覆盖历史确认'); }
        if (in_array($original['type'], ['inventory_loss', 'inventory_loss_resolution'], true)) { throw new \DomainException('库内实物损耗与核实结论须关联后续处理，不得覆盖原记录或重复出入库'); }
        if ($original['type'] === 'purchase_arrival_loss') { throw new \DomainException('已确认损失须保留原实物和成本份额，通过关联成本调整纠正，不能覆盖或重复还原库存'); }
        if ($original['type'] === 'purchase_difference') { throw new \DomainException('到货差须追加后续复核或关联损耗处理，不能删除原复核事实'); }
        if ($original['type'] === 'purchase_return_actual') { throw new \DomainException('已实际退离不能直接覆盖或撤销，请关联退货返回入库或供应商认可处理'); }
        if ($original['type'] === 'purchase_return_resolution') { throw new \DomainException('已确认返回或门店损失须保留实际来源，请通过关联实物或成本调整纠正'); }
        if ($original['type'] === 'purchase_return_acceptance') { throw new \DomainException('已确认退货认可须保留来源，请追加剩余争议处理或关联价格调整'); }
        if ($original['type'] === 'purchase_arrival') { throw new \DomainException('已验收入库不能直接覆盖或撤销，请从原到货明细关联实物更正或采购退货'); }
        if ($original['type'] === 'purchase_settlement') { throw new \DomainException('供应商结算请关联采购金额调整，不能直接撤销已处理的实收量'); }
        if ($original['type'] === 'purchase_extra_cost') { throw new \DomainException('已确认附加成本请关联费用金额调整，不能撤销已分配成本后重复登记'); }
        if ($original['type'] === 'purchase_extra_adjustment') { throw new \DomainException('附加成本调整请从原账单读取当前版本后再次关联调整'); }
        if ($original['type'] === 'purchase_adjustment') { throw new \DomainException('采购调整请从原结算读取当前金额后追加下一次调整'); }
        if ($original['type'] === 'purchase_rules') { throw new \DomainException('请以当前采购规则版本保存新规则，历史规则不能撤销覆盖'); }
        if ($reverseOnly && $policy['direction'] !== 'none') { throw new \DomainException('实际收付款不能按无资金业务直接撤销，请区分录入更正、退款或到账失效'); }
        if (in_array($original['type'], ['receivable_due', 'payable_due'], true)) { throw new \DomainException('付款日请从原未结明细再次调整，新的调整会关联当前日期版本并保留历史'); }
        if ($original['status'] !== 'confirmed') { throw new \DomainException('仅已确认记录可关联更正'); }
        if (Db::name('finance_correction')->where('tenant_id', $this->tenantId)->where('original_document_id', $original['id'])->count()) {
            throw new \DomainException('原记录已有更正，请打开最新有效记录处理');
        }
        $reason = FinanceValue::text($reason, 1000);
        $originalResult = FinanceValue::decode($original['confirmed_result']);
        if (!empty($originalResult['duplicate_of']) || !empty($originalResult['reversal_of'])) { throw new \DomainException('反向凭据不是原业务，不能再次冲销或替代'); }
        if ($original['type'] === 'customer_return_actual') { return FinanceCustomerReturnCorrections::replace($this->ledger, $original, $replacement, $reason, $duplicateOf, $reverseOnly); }
        if ($duplicateOf) { $this->validateDuplicate($original, $originalResult, $duplicateOf); }
        $preservedAdvance = $original['type'] === 'receipt' && !$duplicateOf ? FinanceAdvanceRevisions::preserve($this->ledger, $original, FinanceValue::decode($replacement['payload'])) : null;
        if ($preservedAdvance) { $preservedAdvance['revision_reason'] = $reason; }
        (new FinanceReceiptReturns($this->tenantId, $this->ledger))->protectReceiptCorrection($original, $replacement, $duplicateOf);
        // 派生预收等已被其他业务消耗时，不能让更正形成负来源余额。
        $created = [];
        foreach ($originalResult['created_sources'] ?? [] as $reference) {
            if ($reference === ($preservedAdvance['reference'] ?? '')) { continue; }
            $source = $this->ledger->source($reference);
            $created[] = ['id' => substr($reference, 2), 'amount' => $source['confirmed_amount'], 'subject_id' => $source['subject_id'], 'business_date' => $source['business_date']];
        }
        foreach ($created as $row) {
            $source = $this->ledger->source('n:' . $row['id']);
            if (bccomp($source['balance'], $source['confirmed_amount'], 2) !== 0) { throw new \DomainException('原记录产生的余额已有后续处理，请先核对并更正关联业务'); }
        }
        $entries = Db::name('finance_entry')->where('tenant_id', $this->tenantId)->where('document_id', $original['id'])
            ->whereNotIn('purpose', ['correction_reversal', 'correction_source', 'advance_revision'])->order('id', 'desc')->select()->toArray();
        $activation = (string)Db::name('finance_preparation')->where('tenant_id', $this->tenantId)->value('activation_date');
        foreach ($entries as $entry) {
            // 冲销沿用原分录的实际入账期；未知业务日期不能把后月核销退回启用月。
            $month = $this->ledger->postingMonth(max($entry['posting_month'] . '-01', $activation));
            $this->ledger->add((int)$replacement['id'], $entry['metric'], (int)$entry['subject_id'], bcsub('0', $entry['amount'], 2),
                $entry['business_date'], $month, 'correction_reversal', $entry['source_ref'], $entry['effective_date'],
                ['original_entry_id' => (int)$entry['id'], 'original_document_id' => (int)$original['id'], 'reason' => $reason]);
        }
        foreach ($created as $row) {
            $date = $row['business_date'] ?: $activation;
            $this->ledger->add((int)$replacement['id'], 'balance', (int)$row['subject_id'], '-' . $row['amount'], $date,
                $this->ledger->postingMonth(max($date, $activation)), 'correction_source', 'n:' . $row['id'], null,
                ['original_document_id' => (int)$original['id'], 'reason' => $reason]);
        }
        $transaction = null;
        if (isset($originalResult['money']['transaction_id'])) {
            $transaction = Db::name('finance_money_transaction')->where('tenant_id', $this->tenantId)->where('id', $originalResult['money']['transaction_id'])->find();
            if (!$transaction) { throw new \DomainException('原真实资金交易不存在，不能建立更正'); }
        }
        $result = $duplicateOf || $reverseOnly ? ['type' => $original['type'], 'subject_id' => $originalResult['subject_id'], 'subject_name' => $originalResult['subject_name'],
            'allocated_amount' => '0.00', 'created_sources' => [], 'duplicate_of' => $duplicateOf, 'reversal_of' => $reverseOnly ? (int)$original['id'] : null, 'reason' => $reason]
            : (new FinancePayments($this->tenantId, $this->ledger))->confirm($replacement, $transaction, (int)$original['id'], $preservedAdvance);
        if ($original['type'] === 'equipment_payment') { FinanceEquipmentRefunds::protectPaymentCorrection($this->ledger, FinanceValue::decode($original['payload']), FinanceValue::decode($replacement['payload'])); }
        Db::name('finance_correction')->insert(['tenant_id' => $this->tenantId, 'original_document_id' => $original['id'],
            'replacement_document_id' => $replacement['id'], 'reason' => $reason, 'actor' => FinanceValue::json(FinanceAccess::actor()), 'create_time' => time()]);
        return $result + ['corrects_document_id' => (int)$original['id'], 'correction_reason' => $reason];
    }

    private function validateDuplicate(array $original, array $result, int $keptId): void
    {
        $kept = Db::name('finance_document')->where('tenant_id', $this->tenantId)->where('id', $keptId)->find();
        if (!$kept || $keptId === (int)$original['id'] || $kept['status'] !== 'confirmed' || $kept['type'] !== $original['type'] ||
            Db::name('finance_correction')->where('tenant_id', $this->tenantId)->where('original_document_id', $keptId)->count()) {
            throw new \DomainException('请选择本门店同业务类型、尚有效的保留记录');
        }
        $keptResult = FinanceValue::decode($kept['confirmed_result']);
        $oldMoney = $result['money'] ?? []; $keptMoney = $keptResult['money'] ?? [];
        $oldPayload = FinanceValue::decode($original['payload']); $keptPayload = FinanceValue::decode($kept['payload']);
        if (!$oldMoney || !$keptMoney || $oldMoney['transaction_id'] === $keptMoney['transaction_id'] ||
            $oldMoney['amount'] !== $keptMoney['amount'] || $oldMoney['actual_date'] !== $keptMoney['actual_date'] ||
            (int)$oldPayload['account_id'] !== (int)$keptPayload['account_id']) { throw new \DomainException('两笔记录的账户、方向、金额与日期不符，不能作为重复登记反向'); }
        $oldNumber = $oldMoney['evidence']['transaction_no'] ?? ''; $keptNumber = $keptMoney['evidence']['transaction_no'] ?? '';
        if ($oldNumber !== '' && $keptNumber !== '' && $oldNumber !== $keptNumber) { throw new \DomainException('外部交易号不同，不能将两笔实际交易合并'); }
    }
}
