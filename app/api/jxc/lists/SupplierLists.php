<?php

namespace app\api\jxc\lists;

use app\api\jxc\logic\SupplierLogic;
use app\common\lists\BaseDataLists;
use app\common\model\jxc\Vendor;

class SupplierLists extends BaseDataLists
{
    protected function baseQuery()
    {
        $query = Vendor::field([
            'id',
            'supplier_name',
            'contact',
            'phone',
            'address',
            'remark',
            'is_disabled',
            'order_money',
            'order_payable',
            'order_paid_money',
            'create_time',
            'update_time',
        ]);

        $keyword = trim((string)($this->params['keyword'] ?? $this->params['name'] ?? $this->params['supplier_name'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword) {
                $builder->whereLike('supplier_name', '%' . $keyword . '%')
                    ->whereOr('contact', 'like', '%' . $keyword . '%')
                    ->whereOr('phone', 'like', '%' . $keyword . '%')
                    ->whereOr('address', 'like', '%' . $keyword . '%');
            });
        }

        $status = $this->params['status'] ?? $this->params['is_disabled'] ?? '';
        if ($status !== '') {
            $query->where('is_disabled', (int)$status);
        }

        return $query->order(['order_payable' => 'desc', 'id' => 'desc']);
    }

    public function lists(): array
    {
        $lists = $this->baseQuery()
            ->limit($this->limitOffset, $this->limitLength)
            ->select()
            ->toArray();

        return array_map([SupplierLogic::class, 'formatItem'], $lists);
    }

    public function count(): int
    {
        return $this->baseQuery()->count();
    }
}
