<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

final class FinanceDocumentPolicy
{
    public const TYPES = [
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
        if ($type === 'salary_payment') { FinanceAccess::require('finance.salary.view'); }
        if ($confirm) { FinanceAccess::require($policy['confirm'], $policy['owner']); }
        elseif (!FinanceAccess::has($policy['prepare']) && !($policy['confirm'] && FinanceAccess::has($policy['confirm']))) { FinanceAccess::require($policy['prepare']); }
        return $policy;
    }
}
