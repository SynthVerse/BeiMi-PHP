<?php
namespace app\tenantapi\lists\goods;


use app\tenantapi\lists\BaseAdminDataLists;
use app\common\model\goods\TenantGoodscat;
use app\common\lists\ListsSearchInterface;


/**
 * TenantGoodscat列表
 * Class TenantGoodscatLists
 * @package app\tenantapi\listsgoods
 */
class TenantGoodscatLists extends BaseAdminDataLists implements ListsSearchInterface
{


    /**
     * @notes 设置搜索条件
     * @return \string[][]
     * @author likeadmin
     * @date 2025/12/24 09:09
     */
    public function setSearch(): array
    {
        return [
            '=' => ['name', 'is_show'],
        ];
    }


    /**
     * @notes 获取列表
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author likeadmin
     * @date 2025/12/24 09:09
     */
    public function lists(): array
    {
        return TenantGoodscat::where($this->searchWhere)
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
     * @date 2025/12/24 09:09
     */
    public function count(): int
    {
        return TenantGoodscat::where($this->searchWhere)->count();
    }

}