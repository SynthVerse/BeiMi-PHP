<?php

namespace app\api\jxc\lists;

use app\api\jxc\logic\GoodsLogic;
use app\common\lists\BaseDataLists;
use app\common\model\jxc\Goods;

class GoodsArchivedLists extends BaseDataLists
{
    protected function baseQuery()
    {
        $query = Goods::field([
            'id',
            'name',
            'product_code',
            'units',
            'unit_id',
            'price',
            'cost',
            'stock',
            'category_id',
            'is_disabled',
            'is_archived',
            'remark',
            'create_time',
            'update_time',
        ])->where('is_archived', 1);

        $keyword = trim((string)($this->params['keyword'] ?? $this->params['name'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword) {
                $builder->whereLike('name', '%' . $keyword . '%')
                    ->whereOr('product_code', 'like', '%' . $keyword . '%')
                    ->whereOr('units', 'like', '%' . $keyword . '%');
            });
        }

        return $query->order(['update_time' => 'desc', 'id' => 'desc']);
    }

    public function lists(): array
    {
        return $this->baseQuery()
            ->limit($this->limitOffset, $this->limitLength)
            ->select()
            ->toArray();
    }

    public function count(): int
    {
        return $this->baseQuery()->count();
    }
}
