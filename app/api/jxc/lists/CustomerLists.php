<?php

namespace app\api\jxc\lists;

use app\api\jxc\logic\CustomerLogic;
use app\common\lists\BaseDataLists;
use app\common\model\jxc\Customer;
use app\common\model\jxc\CustomerGroup;

class CustomerLists extends BaseDataLists
{
    protected function baseQuery()
    {
        $query = Customer::field([
            'id',
            'customer_name',
            'contact',
            'phone',
            'address',
            'remark',
            'group_id',
            'parent_id',
            'is_store',
            'children_count',
            'is_disabled',
            'order_receivable',
            'order_money',
            'order_pay_money',
            'create_time',
            'update_time',
        ]);

        $keyword = trim((string)($this->params['keyword'] ?? $this->params['name'] ?? $this->params['customer_name'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword) {
                $builder->whereLike('customer_name', '%' . $keyword . '%')
                    ->whereOr('contact', 'like', '%' . $keyword . '%')
                    ->whereOr('phone', 'like', '%' . $keyword . '%')
                    ->whereOr('address', 'like', '%' . $keyword . '%');
            });
        }

        $groupId = (int)($this->params['group_id'] ?? 0);
        $groupName = trim((string)($this->params['group_name'] ?? ''));
        if ($groupId <= 0 && $groupName !== '') {
            $groupId = (int)(CustomerGroup::where('group_name', $groupName)->value('id') ?: 0);
        }
        if ($groupId > 0) {
            $query->where('group_id', $groupId);
        }

        if (array_key_exists('parent_id', $this->params) && $this->params['parent_id'] !== '') {
            $query->where('parent_id', (int)$this->params['parent_id']);
        }

        $filter = (string)($this->params['filter'] ?? $this->params['hierarchyFilter'] ?? $this->params['customer_type'] ?? 'all');
        if (in_array($filter, ['parent', 'customer', 'master'], true)) {
            $query->where('parent_id', 0);
        } elseif ($filter === 'store') {
            $query->where('parent_id', '>', 0);
        }

        $status = $this->params['status'] ?? $this->params['is_disabled'] ?? '';
        if ($status !== '' && $status !== 'all') {
            $query->where('is_disabled', CustomerLogic::normalizeDisabled($status));
        }

        return $query->order(['customer_name' => 'asc', 'id' => 'desc']);
    }

    public function lists(): array
    {
        $lists = $this->baseQuery()
            ->limit($this->limitOffset, $this->limitLength)
            ->select()
            ->toArray();

        return CustomerLogic::formatList($lists);
    }

    public function count(): int
    {
        return $this->baseQuery()->count();
    }
}
