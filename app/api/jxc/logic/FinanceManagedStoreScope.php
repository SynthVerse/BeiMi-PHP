<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 当前最高权限人员的只读管理范围；只沿活跃父子关系向下，不接受客户端门店列表。 */
final class FinanceManagedStoreScope
{
    public static function stores(): array
    {
        FinanceAccess::require('', true);
        $root = FinanceAccess::tenant(); $queue = [$root]; $seen = [$root => true];
        while ($queue) {
            $parents = array_splice($queue, 0, 100);
            $children = Db::name('tenant_relation')->whereIn('parent_tenant_id', $parents)
                ->where('status', 1)->where('is_deleted', 0)->whereNull('delete_time')->order('id')->column('child_tenant_id');
            foreach ($children as $child) {
                $child = (int)$child;
                if ($child <= 0 || isset($seen[$child])) { continue; }
                if (count($seen) >= 2000) { throw new \DomainException('管理范围门店过多，请联系管理员分层查看'); }
                $seen[$child] = true; $queue[] = $child;
            }
        }
        $rows = Db::name('tenant')->whereIn('id', array_keys($seen))->where('disable', 0)->whereNull('delete_time')
            ->field('id,name')->select()->toArray();
        $byId = array_column($rows, null, 'id'); $result = [];
        foreach (array_keys($seen) as $id) {
            if (isset($byId[$id])) { $result[] = ['tenant_id' => (int)$id, 'store_name' => (string)$byId[$id]['name']]; }
        }
        return $result;
    }
}
