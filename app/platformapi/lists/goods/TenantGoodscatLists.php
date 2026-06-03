<?php

namespace app\platformapi\lists\goods;

use app\common\lists\ListsSearchInterface;
use app\common\model\goods\TenantGoodscat;
use app\platformapi\lists\BaseAdminDataLists;

class TenantGoodscatLists extends BaseAdminDataLists implements ListsSearchInterface
{
    public function setSearch(): array
    {
        return [
            '=' => ['name', 'is_show'],
        ];
    }

    public function lists(): array
    {
        return TenantGoodscat::where('tenant_id', 0)
            ->where($this->searchWhere)
            ->field(['id', 'name', 'sort', 'is_show'])
            ->limit($this->limitOffset, $this->limitLength)
            ->order(['sort' => 'desc', 'id' => 'desc'])
            ->select()
            ->toArray();
    }

    public function count(): int
    {
        return TenantGoodscat::where('tenant_id', 0)
            ->where($this->searchWhere)
            ->count();
    }
}
