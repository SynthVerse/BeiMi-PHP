<?php

namespace app\api\jxc\lists;

use app\api\jxc\logic\WarehouseLogic;
use app\common\lists\BaseDataLists;
use app\common\model\jxc\Warehouse;

class WarehouseLists extends BaseDataLists
{
    protected function baseQuery()
    {
        $query = Warehouse::field([
            'id',
            'name',
            'province',
            'city',
            'district',
            'address',
            'address_detail',
            'contact',
            'phone',
            'is_enabled',
            'sort',
            'create_time',
            'update_time',
        ]);

        $keyword = trim((string)($this->params['keyword'] ?? $this->params['name'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword) {
                $builder->whereLike('name', '%' . $keyword . '%')
                    ->whereOr('address', 'like', '%' . $keyword . '%')
                    ->whereOr('contact', 'like', '%' . $keyword . '%')
                    ->whereOr('phone', 'like', '%' . $keyword . '%');
            });
        }

        $status = $this->params['status'] ?? $this->params['is_enabled'] ?? '';
        if ($status !== '') {
            $query->where('is_enabled', (int)$status);
        }

        return $query->order(['sort' => 'desc', 'id' => 'desc']);
    }

    public function lists(): array
    {
        $lists = $this->baseQuery()
            ->limit($this->limitOffset, $this->limitLength)
            ->select()
            ->toArray();

        return array_map([WarehouseLogic::class, 'formatItem'], $lists);
    }

    public function count(): int
    {
        return $this->baseQuery()->count();
    }
}
