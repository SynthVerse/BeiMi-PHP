<?php

namespace app\api\jxc\lists;

use app\api\jxc\logic\ProcurementTaskService;
use app\common\lists\BaseDataLists;
use app\common\model\jxc\ProcurementTask;

class ProcurementTaskLists extends BaseDataLists
{
    protected function baseQuery()
    {
        $query = ProcurementTask::field([
            'id', 'sn', 'source_type', 'source_key', 'source_reservation_id',
            'source_reservation_item_id', 'goods_id', 'goods_name', 'goods_code',
            'warehouse_id', 'sku_id', 'spec_id', 'required_num', 'arrived_num',
            'status', 'close_reason', 'start_time', 'finish_time', 'close_time',
            'create_by', 'update_by', 'create_time', 'update_time',
        ]);

        $keyword = trim((string)($this->params['keyword'] ?? $this->params['sn'] ?? $this->params['goods_name'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword) {
                $builder->whereLike('sn', '%' . $keyword . '%')
                    ->whereOr('goods_name', 'like', '%' . $keyword . '%')
                    ->whereOr('goods_code', 'like', '%' . $keyword . '%');
            });
        }
        if (($this->params['status'] ?? '') !== '') {
            $query->where('status', (string)$this->params['status']);
        }
        if ((int)($this->params['goods_id'] ?? 0) > 0) {
            $query->where('goods_id', (int)$this->params['goods_id']);
        }

        return $query->order(['id' => 'desc']);
    }

    public function lists(): array
    {
        return array_map([ProcurementTaskService::class, 'format'], $this->baseQuery()
            ->limit($this->limitOffset, $this->limitLength)
            ->select()
            ->toArray());
    }

    public function count(): int
    {
        return $this->baseQuery()->count();
    }
}
