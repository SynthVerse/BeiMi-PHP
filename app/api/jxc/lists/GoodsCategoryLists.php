<?php

namespace app\api\jxc\lists;

use app\common\lists\BaseDataLists;
use app\common\lists\ListsSearchInterface;
use app\common\model\goods\TenantGoodscat;

class GoodsCategoryLists extends BaseDataLists implements ListsSearchInterface
{
    public function setSearch(): array
    {
        return [
            '%like%' => ['name'],
        ];
    }

    protected function baseQuery()
    {
        return TenantGoodscat::where($this->searchWhere)
            ->where('tenant_id', (int)(request()->tenantId ?? 0))
            ->where('is_show', 0)
            ->field(['id', 'name'])
            ->order(['sort' => 'desc', 'id' => 'desc']);
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
