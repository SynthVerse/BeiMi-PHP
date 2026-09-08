<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

final class FinanceExpenseCategories
{
    public const PARENTS = ['personnel' => '人员', 'premises' => '场地', 'utilities' => '水电', 'logistics' => '物流配送',
        'maintenance' => '维修耗材', 'office' => '办公管理', 'marketing' => '营销', 'loss' => '异常损失', 'other' => '其他'];

    public static function options(bool $all = false): array
    {
        $query = Db::name('finance_expense_category')->where('tenant_id', FinanceAccess::tenant());
        if (!$all) { $query->where('is_enabled', 1); }
        return ['sources' => [], 'has_more' => false, 'expense_parents' => self::PARENTS,
            'expense_categories' => $query->order('parent,id')->select()->toArray()];
    }

    public static function confirm(array $document, array $data): array
    {
        FinanceAccess::require('', true); $tenant = FinanceAccess::tenant();
        $id = FinanceValue::id($data['category_id'] ?? null, true); $version = FinanceValue::id($data['expected_category_version'] ?? null, true);
        $parent = FinanceValue::text($data['parent'] ?? null, 30); $name = FinanceValue::text($data['name'] ?? null, 80);
        $enabled = $data['is_enabled'] ?? null;
        if (!isset(self::PARENTS[$parent]) || !in_array($enabled, [0, 1], true)) { throw new \DomainException('请选择系统一级类别和有效的启停状态'); }
        $old = $id ? Db::name('finance_expense_category')->where('tenant_id', $tenant)->where('id', $id)->lock(true)->find() : null;
        if (($id && !$old) || $version !== (int)($old['version'] ?? 0)) { throw new \DomainException('费用类别不存在或版本已变化，请重新核对'); }
        if ($old && $old['used_at'] && ($parent !== $old['parent'] || $name !== $old['name'])) { throw new \DomainException('已使用费用类别只能调整启停状态，不能改名或改挂一级类别'); }
        if (Db::name('finance_expense_category')->where('tenant_id', $tenant)->where('parent', $parent)->where('name', $name)->where('id', '<>', $id)->lock(true)->find()) {
            throw new \DomainException('同一一级类别下已有该费用类别');
        }
        $row = ['tenant_id' => $tenant, 'parent' => $parent, 'name' => $name, 'is_enabled' => $enabled, 'version' => $version + 1,
            'used_at' => (int)($old['used_at'] ?? 0), 'document_id' => (int)$document['id']];
        if ($id) { Db::name('finance_expense_category')->where('tenant_id', $tenant)->where('id', $id)->update($row); }
        else { $id = (int)Db::name('finance_expense_category')->insertGetId($row); }
        return ['type' => 'expense_category', 'category' => ['id' => $id] + $row, 'previous_category' => $old, 'created_sources' => []];
    }

    public static function lines(mixed $input, string $amount): array
    {
        if (!is_array($input) || !array_is_list($input) || !$input || count($input) > 100) { throw new \DomainException('请填写一至一百条费用类别和金额明细'); }
        $total = '0.00'; $lines = []; $seen = [];
        foreach ($input as $line) {
            if (!is_array($line)) { throw new \DomainException('费用明细格式无效'); }
            $id = FinanceValue::id($line['category_id'] ?? null);
            $row = Db::name('finance_expense_category')->where('tenant_id', FinanceAccess::tenant())->where('id', $id)->lock(true)->find();
            if (!$row || !(int)$row['is_enabled'] || isset($seen[$id])) { throw new \DomainException('费用类别不存在、已停用或重复选择，请重新核对'); }
            if (FinanceValue::id($line['expected_category_version'] ?? null) !== (int)$row['version']) { throw new \DomainException('费用类别已变化，请重新选择并核对明细'); }
            $seen[$id] = true; $part = FinanceValue::money($line['amount'] ?? null); $total = bcadd($total, $part, 2);
            $lines[] = ['category_id' => $id, 'category_version' => (int)$row['version'], 'category_name' => $row['name'], 'parent' => $row['parent'],
                'parent_name' => self::PARENTS[$row['parent']], 'amount' => $part, 'reason' => FinanceValue::text($line['reason'] ?? null, 1000)];
        }
        if (bccomp($total, $amount, 2) !== 0) { throw new \DomainException('费用类别明细合计必须等于来源总额'); }
        return $lines;
    }
}
