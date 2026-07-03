<?php

namespace app\api\jxc\lists;

use app\api\jxc\logic\SalesReservationLogic;
use app\common\lists\BaseDataLists;
use app\common\model\jxc\SalesReservation;

class SalesReservationLists extends BaseDataLists
{
    protected function baseQuery()
    {
        $query = SalesReservation::field([
            'id', 'sn', 'customer_id', 'customer_name', 'status', 'total_num',
            'reserved_num', 'shortage_num', 'converted_sales_order_id',
            'remark', 'create_by', 'update_by', 'create_time', 'update_time',
        ]);

        $keyword = trim((string)($this->params['keyword'] ?? $this->params['sn'] ?? $this->params['customer_name'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword) {
                $builder->whereLike('sn', '%' . $keyword . '%')
                    ->whereOr('customer_name', 'like', '%' . $keyword . '%');
            });
        }
        if (($this->params['status'] ?? '') !== '') {
            $query->where('status', (string)$this->params['status']);
        }
        if ((int)($this->params['customer_id'] ?? 0) > 0) {
            $query->where('customer_id', (int)$this->params['customer_id']);
        }

        return $query->order(['id' => 'desc']);
    }

    public function lists(): array
    {
        return array_map([SalesReservationLogic::class, 'format'], $this->baseQuery()
            ->limit($this->limitOffset, $this->limitLength)
            ->select()
            ->toArray());
    }

    public function count(): int
    {
        return $this->baseQuery()->count();
    }
}
