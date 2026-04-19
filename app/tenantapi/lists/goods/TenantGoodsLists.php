<?php

namespace app\tenantapi\lists\goods;


use app\common\model\goods\TenantGoods;
use app\common\lists\ListsSearchInterface;
use app\tenantapi\lists\BaseAdminDataLists;


/**
 * TenantGoods列表
 * Class TenantGoodsLists
 * @package app\platform\lists
 */
class TenantGoodsLists extends BaseAdminDataLists implements ListsSearchInterface
{

    protected $tenantId;

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * @notes 设置搜索条件
     * @return \string[][]
     * @author likeadmin
     * @date 2025/12/04 14:26
     */
    public function setSearch(): array
    {
        $allowSearch = ['name', 'cate_id', 'tenant_id'];
        return array_intersect(array_keys($this->params), $allowSearch);
    }


    /**
     * @notes 获取列表
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author likeadmin
     * @date 2025/12/04 14:26
     */
    public function lists(): array
    {
        return TenantGoods::withSearch($this->setSearch(), $this->params)
            ->with(['goodsCate'])
            ->append(['is_show_desc'])
            ->field(['id', 'cate_id', 'name', 'units', 'short_name', 'moneys', 'sort', 'sales_weight', 'sales_money', 'is_show'])
            ->limit($this->limitOffset, $this->limitLength)
            ->order(['id' => 'desc'])
            ->select()
            ->toArray();
    }


    /**
     * @notes 获取数量
     * @return int
     * @author likeadmin
     * @date 2025/12/04 14:26
     */
    public function count(): int
    {
        return TenantGoods::withSearch($this->setSearch(), $this->params)->count();
    }

}