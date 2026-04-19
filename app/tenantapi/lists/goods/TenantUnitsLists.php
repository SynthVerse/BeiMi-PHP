<?php

namespace app\tenantapi\lists\goods;

use app\common\lists\BaseDataLists;
use app\common\model\goods\TenantUnits;
use app\common\lists\ListsSearchInterface;


/**
 * TenantUnits列表
 * Class TenantUnitsLists
 * @package app\platform\lists
 */
class TenantUnitsLists extends BaseDataLists implements ListsSearchInterface
{

    protected $tenantId;

    public function __construct($tenantId)
    {
        $this->tenantId = $tenantId;
        parent::__construct();
    }

    /**
     * @notes 设置搜索条件
     * @return \string[][]
     * @author likeadmin
     * @date 2025/12/04 14:24
     */
    public function setSearch(): array
    {
        return [
            '=' => ['name', 'sort', 'is_show'],
        ];
    }


    /**
     * @notes 获取列表
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author likeadmin
     * @date 2025/12/04 14:24
     */
    public function lists(): array
    {
        array_push($this->searchWhere, ["tenant_id", "=", $this->tenantId]);
        return TenantUnits::where($this->searchWhere)
            ->append(['is_show_desc'])
            ->field(['id', 'name', 'sort', 'is_show'])
            ->limit($this->limitOffset, $this->limitLength)
            ->order(['id' => 'desc'])
            ->select()
            ->toArray();
    }


    /**
     * @notes 获取数量
     * @return int
     * @author likeadmin
     * @date 2025/12/04 14:24
     */
    public function count(): int
    {
        return TenantUnits::where($this->searchWhere)->count();
    }

}