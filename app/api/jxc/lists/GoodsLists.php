<?php

namespace app\api\jxc\lists;

use app\api\jxc\logic\GoodsLogic;
use app\common\lists\BaseDataLists;
use app\common\model\jxc\Goods;

class GoodsLists extends BaseDataLists
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
            'remark',
            'create_time',
            'update_time',
        ]);

        $keyword = trim((string)($this->params['keyword'] ?? $this->params['name'] ?? $this->params['product_name'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword) {
                $builder->whereLike('name', '%' . $keyword . '%')
                    ->whereOr('product_code', 'like', '%' . $keyword . '%')
                    ->whereOr('units', 'like', '%' . $keyword . '%');
            });
        }

        $categoryId = $this->params['category_id'] ?? '';
        if ($categoryId !== '') {
            $query->where('category_id', (int)$categoryId);
        }

        $unitId = $this->params['unit_id'] ?? $this->params['units_id'] ?? '';
        if ($unitId !== '') {
            $query->where('unit_id', (int)$unitId);
        }

        $status = $this->params['status'] ?? $this->params['is_disabled'] ?? '';
        if ($status !== '') {
            $query->where('is_disabled', (int)$status);
        }

        return $query->order(['id' => 'desc']);
    }

    public function lists(): array
    {
        $lists = $this->baseQuery()
            ->limit($this->limitOffset, $this->limitLength)
            ->select()
            ->toArray();

        return array_map([GoodsLogic::class, 'formatItem'], $lists);
    }

    public function count(): int
    {
        return $this->baseQuery()->count();
    }
}
