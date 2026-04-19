<?php

namespace app\tenantapi\lists\supplier;


use app\tenantapi\lists\BaseAdminDataLists;
use app\common\model\supplier\UserSupplierMoney;
use app\common\lists\ListsSearchInterface;


/**
 * UserSupplierMoney列表
 * Class UserSupplierMoneyLists
 * @package app\tenantapi\listssupplier
 */
class UserSupplierMoneyLists extends BaseAdminDataLists implements ListsSearchInterface
{


    /**
     * @notes 设置搜索条件
     * @return \string[][]
     * @author likeadmin
     * @date 2026/01/06 16:10
     */
    public function setSearch(): array
    {
        return [
            '=' => ['admin_id', 'supplier_id'],
        ];
    }


    /**
     * @notes 获取列表
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author likeadmin
     * @date 2026/01/06 16:10
     */
    public function lists(): array
    {
        $list = UserSupplierMoney::where($this->searchWhere)->with(['supplier', 'admin'])
            ->field(['id', 'admin_id', 'supplier_id', 'money', 'remarks', 'order_ids', 'create_time'])
            ->limit($this->limitOffset, $this->limitLength)
            ->order(['id' => 'desc'])
            ->select()
            ->toArray();

        $moneyList = [];
        foreach ($list as $k => $val) {
            $temp = [
                "id" => $val["id"],
                "money" => $val["money"],
                "supplier_name" => $val["supplier"]["name"],
                "admin_name" => $val["admin"]["name"],
                "remarks" => $val["remarks"],
                "create_time" => $val["create_time"],
            ];
            array_push($moneyList, $temp);
        }
        return $moneyList;
    }


    /**
     * @notes 获取数量
     * @return int
     * @author likeadmin
     * @date 2026/01/06 16:10
     */
    public function count(): int
    {
        return UserSupplierMoney::where($this->searchWhere)->count();
    }

}