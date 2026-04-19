<?php

namespace app\tenantapi\lists\user;


use app\tenantapi\lists\BaseAdminDataLists;
use app\common\model\user\UserOrder;
use app\common\lists\ListsSearchInterface;


/**
 * UserOrder列表
 * Class UserOrderLists
 * @package app\tenantapi\listsuser
 */
class UserOrderLists extends BaseAdminDataLists implements ListsSearchInterface
{


    /**
     * @notes 设置搜索条件
     * @return \string[][]
     * @author likeadmin
     * @date 2026/01/07 09:40
     */
    public function setSearch(): array
    {
        return [
            '=' => ['order_sn', 'user_id', 'status', 'pay_status', 'create_time', 'order_status'],
        ];
    }


    /**
     * @notes 获取列表
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author likeadmin
     * @date 2026/01/07 09:40
     */
    public function lists(): array
    {
        return UserOrder::where($this->searchWhere)->with(["user", "admin"])
            ->field(['id', 'order_sn', 'user_id', 'order_number', 'order_money', 'order_pay_money', 'order_arrears_money', 'goods_number', 'status', 'pay_status', 'create_time', 'remarks', 'order_status'])
            ->limit($this->limitOffset, $this->limitLength)
            ->order(['id' => 'desc'])
            ->select()
            ->toArray();
    }


    /**
     * @notes 获取数量
     * @return int
     * @author likeadmin
     * @date 2026/01/07 09:40
     */
    public function count(): int
    {
        return UserOrder::where($this->searchWhere)->count();
    }

}