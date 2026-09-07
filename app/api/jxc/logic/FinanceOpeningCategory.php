<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

/** 期初来源的对象、资料和后续处理口径，供校验和小程序共同使用。 */
final class FinanceOpeningCategory
{
    private const SUBJECTS = [
        'account' => ['finance_account', 'name', '资金账户'],
        'receivable' => ['customer', 'customer_name', '主客户'],
        'payable' => ['vendor', 'supplier_name', '供应商'],
        'advance' => ['customer', 'customer_name', '主客户'],
        'customer_refund' => ['customer', 'customer_name', '主客户'],
        'recovery' => ['customer', 'customer_name', '主客户'],
        'expense_payable' => ['vendor', 'supplier_name', '供应商 / 收款方'],
        'supplier_refund' => ['vendor', 'supplier_name', '供应商'],
        'expense_refund' => ['vendor', 'supplier_name', '供应商 / 退款方'],
        'salary' => ['employee', 'name', '员工'],
        'reimbursement' => ['employee', 'name', '垫付员工'],
        'equipment' => ['vendor', 'supplier_name', '设备供应商'],
        'equipment_refund' => ['vendor', 'supplier_name', '设备退款方'],
        'deferred' => ['vendor', 'supplier_name', '服务提供方'],
        'transit' => ['finance_account', 'name', '原转出账户'],
        'unclaimed' => ['finance_account', 'name', '实际到账账户'],
        'inventory' => ['warehouse_sku_balance', 'id', '核算仓库 / SKU'],
    ];
    private const ORIGINS = [
        'advance' => '原客户收款或预收凭据',
        'customer_refund' => '原退货结算或价格调整凭据',
        'recovery' => '原坏账确认凭据',
        'supplier_refund' => '原采购退货或退款确认凭据',
        'expense_refund' => '原费用及退款确认凭据',
        'expense_payable' => '原费用确认凭据',
        'salary' => '原工资确认或工资表',
        'reimbursement' => '原员工垫付及费用凭据',
        'equipment' => '原设备购置确认凭据',
        'equipment_refund' => '原设备退款待收凭据',
        'deferred' => '原费用确认及服务合同',
        'transit' => '原互转及已处理组成核对依据',
        'unclaimed' => '实际到账及已认领组成核对依据',
        'inventory' => '截点盘存及历史成本核对依据',
    ];
    private const EFFECTS = [
        'account' => '承接已核实的账户余额；不新增实际收付款。',
        'receivable' => '承接客户未收欠款；不新增销售收入。',
        'payable' => '承接采购未付欠款；不新增采购、库存或费用。',
        'advance' => '承接尚可抵扣或退还的预收；不新增资金，也不自动抵扣欠款。',
        'customer_refund' => '承接尚未退还客户的款项；不再冲减销售收入，也不重开原应收。',
        'recovery' => '只承接剩余坏账追偿备查额；不重新形成正式应收或损失。',
        'supplier_refund' => '承接供应商退款待收；后续到账结清待收，不重复冲销原采购。',
        'expense_refund' => '承接普通费用退款待收；不混入设备退款，也不重复确认费用。',
        'expense_payable' => '只承接旧费用待付款；后续付款不重复确认费用。',
        'salary' => '按员工和原受益月份承接旧工资待付；不新增启用当期工资费用。',
        'reimbursement' => '按员工和原受益月份承接未报销垫付；不重复确认费用，不承接员工借支。',
        'equipment' => '只承接设备剩余付款额度；不生成普通应付或设备费用。真实退款不恢复付款额度。',
        'equipment_refund' => '只承接设备退款待收；后续实际到账时冲减到账月设备费用。',
        'deferred' => '只承接未摊余额和逐月计划；未付义务另列费用应付，不重复确认费用。',
        'transit' => '在途已离开来源账户，单列为门店资金；额外扣费不扣减在途，未知差额不能填成手续费。',
        'unclaimed' => '到账已包含在账户余额，只承接待认领余额；不再次增加资金，不猜测客户或用途。',
        'inventory' => '按仓库和 SKU 承接截点已有数量与历史总成本；不增加第二次库存，不用售价估算成本。',
    ];

    public static function supported(string $category): bool { return isset(self::SUBJECTS[$category]); }
    public static function subject(string $category): array { return self::SUBJECTS[$category]; }
    public static function hasBenefitMonth(string $category): bool { return in_array($category, ['salary', 'reimbursement'], true); }
    public static function allowsSummary(string $category): bool
    {
        return in_array($category, ['account', 'receivable', 'payable', 'expense_payable', 'salary', 'reimbursement'], true);
    }
    public static function metadata(string $category): array
    {
        $fields = [];
        if (self::hasBenefitMonth($category)) { $fields[] = ['key' => 'benefit_month', 'label' => '原受益月份', 'type' => 'month']; }
        if (isset(self::ORIGINS[$category])) { $fields[] = ['key' => 'origin_reference', 'label' => self::ORIGINS[$category], 'type' => 'text']; }
        if ($category === 'recovery') { $fields[] = ['key' => 'receivable_reference', 'label' => '原应收凭据', 'type' => 'text']; }
        $fields = array_merge($fields, FinanceOpeningAssets::fields($category));
        return [
            'subject_label' => self::SUBJECTS[$category][2] ?? '',
            'allows_summary' => self::allowsSummary($category),
            'has_due_date' => in_array($category, ['receivable', 'payable', 'expense_payable', 'salary', 'reimbursement'], true),
            'detail_fields' => $fields, 'effect_notice' => self::EFFECTS[$category] ?? '',
            'amount_label' => match ($category) {
                'advance' => '剩余可用预收（元）', 'customer_refund' => '剩余应退款（元）',
                'recovery' => '剩余追偿备查额（元）', 'supplier_refund', 'expense_refund' => '剩余退款待收（元）',
                'salary' => '剩余工资待付（元）', 'reimbursement' => '剩余未报销垫付（元）',
                'expense_payable' => '剩余费用待付（元）', 'equipment' => '剩余可付额度（元）',
                'equipment_refund' => '剩余设备退款待收（元）', 'inventory' => '核实历史总成本（元）',
                'deferred' => '剩余未摊金额（元）', 'transit' => '剩余在途金额（元）', 'unclaimed' => '剩余待认领金额（元）', default => '核实金额（元）',
            },
        ];
    }

    public static function normalizeDetails(string $category, mixed $details): array
    {
        if (!is_array($details)) { throw new \DomainException('期初来源资料格式不正确'); }
        $result = [];
        foreach (self::metadata($category)['detail_fields'] as $field) {
            $value = $details[$field['key']] ?? '';
            $result[$field['key']] = FinanceOpeningAssets::normalize($field, $value);
        }
        return $result;
    }

    /** 每次响应（包括旧幂等结果）按当前权限过滤，不能把缓存快照当成授权。 */
    public static function visibleSnapshot(array $snapshot, array $salaryAccess): array
    {
        foreach ($snapshot['categories'] as &$category) {
            $salary = $category['key'] === 'salary';
            $category = array_merge($category, self::metadata($category['key']));
            $category['can_view'] = !$salary || $salaryAccess['view'];
            $category['can_edit'] = !$salary || $salaryAccess['prepare'];
            if (!$category['can_view']) {
                $category['count'] = null; $category['total'] = null; $category['unknown_count'] = null;
                $category['review'] = ['state' => 'restricted', 'evidence' => ''];
            }
        }
        if (!$salaryAccess['view']) {
            $snapshot['items'] = array_values(array_filter($snapshot['items'], static fn(array $item): bool => $item['category'] !== 'salary'));
            $snapshot['blockers'] = array_values(array_unique(array_map(self::restrictedError(...), $snapshot['blockers'])));
        }
        return $snapshot;
    }

    public static function restrictedError(string $message): string
    {
        return preg_replace('/工资待付[^；]*/u', '工资待付资料需由有工资权限人员核对', $message);
    }
}
