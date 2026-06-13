<?php

namespace app\api\jxc\lists;

use app\api\jxc\logic\GoodsLogic;
use app\common\lists\BaseDataLists;
use app\common\model\jxc\Goods;
use app\common\model\jxc\GoodsSupplier;

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
            'primary_supplier_id',
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

        $supplierId = (int)($this->params['supplier_id'] ?? 0);
        if ($supplierId > 0) {
            $relation = (string)($this->params['supplier_relation'] ?? 'any');
            if ($relation === 'primary') {
                $query->where('primary_supplier_id', $supplierId);
            } else {
                $goodsIds = GoodsSupplier::where('supplier_id', $supplierId)->column('goods_id');
                $query->whereIn('id', $goodsIds ?: [0]);
            }
        }

        $stockStatus = (string)($this->params['stock_status'] ?? '');
        if ($stockStatus !== '') {
            if (in_array($stockStatus, ['out', 'empty', '0'], true)) {
                $query->where('stock', '<=', 0);
            } elseif (in_array($stockStatus, ['warning', 'low'], true)) {
                $query->where('stock', '>', 0)->where('stock', '<=', 10);
            } elseif (in_array($stockStatus, ['normal', 'enough'], true)) {
                $query->where('stock', '>', 10);
            }
        }

        $isDisabled = $this->params['is_disabled'] ?? '';
        if ($isDisabled !== '') {
            $query->where('is_disabled', (int)$isDisabled);
        } else {
            $status = $this->params['status'] ?? '';
            if ($status !== '') {
                $disabledStatus = GoodsLogic::normalizeDisabledStatus($status);
                if ($disabledStatus !== null) {
                    $query->where('is_disabled', $disabledStatus);
                }
            }
        }

        // 默认排除已归档商品
        $isArchived = $this->params['is_archived'] ?? '';
        if ($isArchived !== '') {
            $query->where('is_archived', (int)$isArchived);
        } else {
            $query->where('is_archived', 0);
        }

        return $query->order(['id' => 'desc']);
    }

    public function lists(): array
    {
        $lists = $this->baseQuery()
            ->limit($this->limitOffset, $this->limitLength)
            ->select()
            ->toArray();

        return GoodsLogic::attachSupplierSummary($lists);
    }

    public function count(): int
    {
        return $this->baseQuery()->count();
    }
}
