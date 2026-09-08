<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

final class FinanceDocumentPolicy
{
    public const TYPES = [
        'salary_adjustment' => ['title' => '工资金额关联调整', 'prepare' => 'finance.salary.prepare', 'confirm' => '', 'owner' => true, 'subject' => 'employee', 'sources' => [], 'direction' => 'none'],
        'salary_expense' => ['title' => '外部最终工资确认', 'prepare' => 'finance.salary.prepare', 'confirm' => '', 'owner' => true, 'subject' => 'employee', 'sources' => [], 'direction' => 'none'],
        'employee_expense_adjustment' => ['title' => '员工垫付关联调整', 'prepare' => 'finance.reimbursement.prepare', 'confirm' => '', 'owner' => true, 'subject' => 'employee', 'sources' => [], 'direction' => 'none'],
        'employee_expense' => ['title' => '员工实际垫付确认', 'prepare' => 'finance.reimbursement.prepare', 'confirm' => '', 'owner' => true, 'subject' => 'employee', 'sources' => [], 'direction' => 'none'],
        'expense_recurring_correct' => ['title' => '周期费用月份关联更正', 'prepare' => 'finance.expense.prepare', 'confirm' => '', 'owner' => true, 'subject' => 'vendor', 'sources' => [], 'direction' => 'none'],
        'expense_recurring_none' => ['title' => '周期费用本期不发生', 'prepare' => 'finance.expense.prepare', 'confirm' => 'finance.expense.confirm', 'owner' => false, 'subject' => 'vendor', 'sources' => [], 'direction' => 'none'],
        'expense_recurring_plan' => ['title' => '周期费用计划', 'prepare' => 'finance.expense.prepare', 'confirm' => 'finance.expense.confirm', 'owner' => false, 'subject' => 'vendor', 'sources' => [], 'direction' => 'none'],
        'expense_estimate_final' => ['title' => '费用暂估分项核实', 'prepare' => 'finance.expense.prepare', 'confirm' => '', 'owner' => true, 'subject' => 'vendor', 'sources' => [], 'direction' => 'none'],
        'deferred_expense' => ['title' => '新待摊费用计划确认', 'prepare' => 'finance.expense.prepare', 'confirm' => 'finance.expense.confirm', 'owner' => false, 'subject' => 'vendor', 'sources' => [], 'direction' => 'none'],
        'deferred_amortization' => ['title' => '待摊费用逐月确认', 'prepare' => 'finance.expense.prepare', 'confirm' => 'finance.expense.confirm', 'owner' => false, 'subject' => 'vendor', 'sources' => ['deferred'], 'direction' => 'none'],
        'expense_adjustment' => ['title' => '普通费用关联调整', 'prepare' => 'finance.expense.prepare', 'confirm' => '', 'owner' => true, 'subject' => 'vendor', 'sources' => [], 'direction' => 'none'],
        'expense' => ['title' => '普通费用确认', 'prepare' => 'finance.expense.prepare', 'confirm' => 'finance.expense.confirm', 'owner' => false, 'subject' => 'vendor', 'sources' => [], 'direction' => 'none'],
        'expense_category' => ['title' => '费用二级类别维护', 'prepare' => '', 'confirm' => '', 'owner' => true, 'subject' => 'none', 'sources' => [], 'direction' => 'none'],
        'legacy_return_cost' => ['title' => '旧售退回成本核实', 'prepare' => 'finance.inventory.prepare', 'confirm' => '', 'owner' => true, 'subject' => 'none', 'sources' => [], 'direction' => 'none'],
        'inventory_loss' => ['title' => '库内实物损耗待核实', 'prepare' => 'finance.inventory.prepare', 'confirm' => '', 'owner' => true, 'subject' => 'none', 'sources' => [], 'direction' => 'none'],
        'inventory_loss_resolution' => ['title' => '库内损耗分次确认', 'prepare' => 'finance.inventory.prepare', 'confirm' => '', 'owner' => true, 'subject' => 'none', 'sources' => [], 'direction' => 'none'],
        'purchase_arrival_loss' => ['title' => '到货已扣数量的异常损失确认', 'prepare' => 'finance.purchase.prepare', 'confirm' => '', 'owner' => true, 'subject' => 'vendor', 'sources' => [], 'direction' => 'none'],
        'purchase_difference' => ['title' => '采购到货差复核', 'prepare' => 'finance.purchase.prepare', 'confirm' => 'finance.purchase.confirm', 'owner' => false, 'subject' => 'vendor', 'sources' => [], 'direction' => 'none'],
        'purchase_return_resolution' => ['title' => '退货争议返回与门店损失', 'prepare' => 'finance.purchase.prepare', 'confirm' => '', 'owner' => true, 'subject' => 'vendor', 'sources' => [], 'direction' => 'none'],
        'purchase_return_acceptance' => ['title' => '供应商退货认可与贷项', 'prepare' => 'finance.purchase.prepare', 'confirm' => '', 'owner' => true, 'subject' => 'vendor', 'sources' => ['payable', 'expense_payable'], 'direction' => 'none'],
        'purchase_return_actual' => ['title' => '采购实际退货', 'prepare' => 'finance.purchase.prepare', 'confirm' => '', 'owner' => true, 'subject' => 'vendor', 'sources' => [], 'direction' => 'none'],
        'purchase_extra_adjustment' => ['title' => '采购附加成本关联调整', 'prepare' => 'finance.purchase.prepare', 'confirm' => '', 'owner' => true, 'subject' => 'vendor', 'sources' => ['payable', 'expense_payable'], 'direction' => 'none'],
        'supplier_credit_allocate' => ['title' => '供应商贷项抵扣应付', 'prepare' => 'finance.payment.prepare', 'confirm' => '', 'owner' => true, 'subject' => 'vendor', 'sources' => ['payable', 'expense_payable'], 'direction' => 'none'],
        'purchase_adjustment' => ['title' => '采购结算金额调整', 'prepare' => 'finance.purchase.prepare', 'confirm' => '', 'owner' => true, 'subject' => 'vendor', 'sources' => ['payable', 'expense_payable'], 'direction' => 'none'],
        'purchase_extra_cost' => ['title' => '采购必要附加成本', 'prepare' => 'finance.purchase.prepare', 'confirm' => '', 'owner' => true, 'subject' => 'vendor', 'sources' => [], 'direction' => 'none'],
        'purchase_arrival' => ['title' => '采购实际到货与暂估', 'prepare' => 'finance.purchase.prepare', 'confirm' => 'finance.purchase.receive', 'owner' => false, 'subject' => 'vendor', 'sources' => [], 'direction' => 'none'],
        'purchase_settlement' => ['title' => '供应商分次采购结算', 'prepare' => 'finance.purchase.prepare', 'confirm' => 'finance.purchase.confirm', 'owner' => false, 'subject' => 'vendor', 'sources' => ['payable'], 'direction' => 'none'],
        'purchase_rules' => ['title' => '采购复核与付款规则', 'prepare' => '', 'confirm' => '', 'owner' => true, 'subject' => 'vendor', 'sources' => [], 'direction' => 'none'],
        'sales_batch' => ['title' => '交付合并与分次销售结算', 'prepare' => 'settlement.bill', 'confirm' => 'settlement.bill', 'owner' => false, 'subject' => 'customer', 'sources' => ['receivable'], 'direction' => 'none'],
        'receipt_return' => ['title' => '客户原收款退回', 'prepare' => 'finance.refund.prepare', 'confirm' => '', 'owner' => true, 'subject' => 'customer', 'sources' => ['receivable', 'advance'], 'direction' => 'out'],
        'receivable_due' => ['title' => '调整应收付款日', 'prepare' => 'finance.receivable.prepare', 'confirm' => '', 'owner' => true, 'subject' => 'customer', 'sources' => ['receivable'], 'direction' => 'none'],
        'payable_due' => ['title' => '调整应付付款日', 'prepare' => 'finance.payment.prepare', 'confirm' => '', 'owner' => true, 'subject' => 'vendor', 'sources' => ['payable', 'expense_payable'], 'direction' => 'none'],
        'bad_debt' => ['title' => '确认客户坏账', 'prepare' => 'finance.receivable.prepare', 'confirm' => '', 'owner' => true, 'subject' => 'customer', 'sources' => ['receivable'], 'direction' => 'none'],
        'recovery_termination' => ['title' => '终止坏账追偿', 'prepare' => 'finance.recovery.prepare', 'confirm' => '', 'owner' => true, 'subject' => 'customer', 'sources' => ['recovery'], 'direction' => 'none'],
        'advance_allocate' => ['title' => '预收抵扣应收', 'prepare' => 'finance.receipt.prepare', 'confirm' => 'finance.receipt.confirm', 'owner' => false, 'subject' => 'customer', 'sources' => ['receivable'], 'direction' => 'none'],
        'receipt' => ['title' => '客户收款', 'prepare' => 'finance.receipt.prepare', 'confirm' => 'finance.receipt.confirm', 'owner' => false, 'subject' => 'customer', 'sources' => ['receivable'], 'direction' => 'in'],
        'supplier_payment' => ['title' => '供应商 / 费用付款', 'prepare' => 'finance.payment.prepare', 'confirm' => '', 'owner' => true, 'subject' => 'vendor', 'sources' => ['payable', 'expense_payable'], 'direction' => 'out'],
        'salary_payment' => ['title' => '工资发放', 'prepare' => 'finance.salary.prepare', 'confirm' => '', 'owner' => true, 'subject' => 'employee', 'sources' => ['salary'], 'direction' => 'out'],
        'reimbursement_payment' => ['title' => '垫付报销付款', 'prepare' => 'finance.reimbursement.prepare', 'confirm' => '', 'owner' => true, 'subject' => 'employee', 'sources' => ['reimbursement'], 'direction' => 'out'],
        'equipment_payment' => ['title' => '设备购置付款', 'prepare' => 'finance.equipment.prepare', 'confirm' => '', 'owner' => true, 'subject' => 'vendor', 'sources' => ['equipment'], 'direction' => 'out'],
        'customer_refund' => ['title' => '客户退款', 'prepare' => 'finance.refund.prepare', 'confirm' => '', 'owner' => true, 'subject' => 'customer', 'sources' => ['customer_refund'], 'direction' => 'out'],
        'advance_refund' => ['title' => '预收退款', 'prepare' => 'finance.refund.prepare', 'confirm' => '', 'owner' => true, 'subject' => 'customer', 'sources' => ['advance'], 'direction' => 'out'],
        'supplier_refund' => ['title' => '供应商退款到账', 'prepare' => 'finance.refund.prepare', 'confirm' => '', 'owner' => true, 'subject' => 'vendor', 'sources' => ['supplier_refund'], 'direction' => 'in'],
        'expense_refund' => ['title' => '普通费用退款到账', 'prepare' => 'finance.refund.prepare', 'confirm' => '', 'owner' => true, 'subject' => 'vendor', 'sources' => ['expense_refund'], 'direction' => 'in'],
        'equipment_refund' => ['title' => '设备退款到账', 'prepare' => 'finance.equipment.prepare', 'confirm' => '', 'owner' => true, 'subject' => 'vendor', 'sources' => ['equipment_refund'], 'direction' => 'in'],
        'recovery_receipt' => ['title' => '坏账追偿收回', 'prepare' => 'finance.recovery.prepare', 'confirm' => '', 'owner' => true, 'subject' => 'customer', 'sources' => ['recovery'], 'direction' => 'in'],
    ];

    public static function type(string $type): array
    {
        return self::TYPES[$type] ?? throw new \DomainException('不支持的财务业务类型');
    }

    public static function authorize(string $type, bool $confirm = false): array
    {
        $policy = self::type($type);
        if (in_array($type, ['salary_payment', 'salary_expense', 'salary_adjustment'], true)) { FinanceAccess::require('finance.salary.view'); }
        if ($confirm) { FinanceAccess::require($policy['confirm'], $policy['owner']); }
        elseif (!FinanceAccess::has($policy['prepare']) && !($policy['confirm'] && FinanceAccess::has($policy['confirm']))) { FinanceAccess::require($policy['prepare']); }
        return $policy;
    }

    public static function read(string $type): array
    {
        if (in_array($type, ['salary_expense', 'salary_payment', 'salary_adjustment'], true)) { FinanceAccess::require('finance.salary.view'); return self::type($type); }
        if ($type === 'sales_batch' && FinanceAccess::has('settlement.view')) { return self::type($type); }
        return self::authorize($type);
    }
}
