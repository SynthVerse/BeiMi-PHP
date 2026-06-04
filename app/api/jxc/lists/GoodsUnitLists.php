<?php

namespace app\api\jxc\lists;

use app\api\jxc\logic\GoodsUnitLogic;
use app\common\lists\BaseDataLists;
use app\common\model\jxc\GoodsUnit;

class GoodsUnitLists extends BaseDataLists
{
    protected function baseQuery()
    {
        $query = GoodsUnit::field(['id', 'name', 'sort', 'status', 'create_time', 'update_time']);
        $query->where('tenant_id', (int)(request()->tenantId ?? 0));
        $keyword = trim((string)($this->params['keyword'] ?? $this->params['name'] ?? ''));
        if ($keyword !== '') {
            $query->whereLike('name', '%' . $keyword . '%');
        }

        $status = $this->params['status'] ?? '';
        if ($status !== '') {
            $query->where('status', (int)$status);
        }

        return $query->order(['sort' => 'desc', 'id' => 'desc']);
    }

    public function lists(): array
    {
        $lists = $this->baseQuery()
            ->limit($this->limitOffset, $this->limitLength)
            ->select()
            ->toArray();

        return array_map([GoodsUnitLogic::class, 'formatItem'], $lists);
    }

    public function count(): int
    {
        return $this->baseQuery()->count();
    }
}
