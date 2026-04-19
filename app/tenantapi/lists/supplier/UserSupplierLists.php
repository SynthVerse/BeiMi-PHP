<?php

namespace app\tenantapi\lists\supplier;


use app\tenantapi\lists\BaseAdminDataLists;
use app\common\model\supplier\UserSupplier;
use app\common\lists\ListsSearchInterface;


/**
 * UserSupplier列表
 * Class UserSupplierLists
 * @package app\tenantapi\listssupplier
 */
class UserSupplierLists extends BaseAdminDataLists implements ListsSearchInterface
{


    /**
     * @notes 设置搜索条件
     * @return \string[][]
     * @author likeadmin
     * @date 2025/12/22 16:14
     */
    public function setSearch(): array
    {
        $allowSearch = ['keyword', 'name'];
        return array_intersect(array_keys($this->params), $allowSearch);
    }


    /**
     * @notes 获取列表
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author likeadmin
     * @date 2025/12/22 16:14
     */
    public function lists(): array
    {
        return UserSupplier::withSearch($this->setSearch(), $this->params)
            ->field(['id', 'name', 'order_money', 'order_arrears_money', 'create_time'])
            ->limit($this->limitOffset, $this->limitLength)
            ->order(['id' => 'desc'])
            ->select()
            ->toArray();
    }


    /**
     * @notes 获取数量
     * @return int
     * @author likeadmin
     * @date 2025/12/22 16:14
     */
    public function count(): int
    {
        return UserSupplier::withSearch($this->setSearch(), $this->params)->count();
    }

}