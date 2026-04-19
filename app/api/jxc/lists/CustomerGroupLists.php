<?php

namespace app\api\jxc\lists;

use app\api\jxc\logic\CustomerGroupLogic;
use app\api\jxc\logic\CustomerLogic;
use app\common\lists\BaseDataLists;
use app\common\model\jxc\CustomerGroup;

class CustomerGroupLists extends BaseDataLists
{
    protected function baseQuery()
    {
        $query = CustomerGroup::field([
            'id',
            'group_name',
            'customer_count',
            'sort',
            'create_time',
            'update_time',
        ]);

        $keyword = trim((string)($this->params['keyword'] ?? $this->params['name'] ?? $this->params['group_name'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword) {
                $builder->whereLike('group_name', '%' . $keyword . '%');
            });
        }

        return $query->order(['sort' => 'desc', 'id' => 'desc']);
    }

    public function lists(): array
    {
        $lists = $this->baseQuery()
            ->limit($this->limitOffset, $this->limitLength)
            ->select()
            ->toArray();

        $groups = array_map([CustomerGroupLogic::class, 'formatItem'], $lists);
        return CustomerLogic::groupedCustomers($groups, $this->params);
    }

    public function count(): int
    {
        return $this->baseQuery()->count();
    }
}
