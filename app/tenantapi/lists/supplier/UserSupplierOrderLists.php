<?php
namespace app\tenantapi\lists\supplier;


use app\tenantapi\lists\BaseAdminDataLists;
use app\common\model\supplier\UserSupplierOrder;
use app\common\lists\ListsSearchInterface;


/**
 * UserSupplierOrder列表
 * Class UserSupplierOrderLists
 * @package app\tenantapi\listssupplier
 */
class UserSupplierOrderLists extends BaseAdminDataLists implements ListsSearchInterface
{


    /**
     * @notes 设置搜索条件
     * @return \string[][]
     * @author likeadmin
     * @date 2025/12/22 16:53
     */
    public function setSearch(): array
    {
        return [
            '=' => ['order_sn', 'supplier_id', 'status', 'pay_status', 'create_time'],
        ];
    }


    /**
     * @notes 获取列表
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author likeadmin
     * @date 2025/12/22 16:53
     */
    public function lists(): array
    {
        return UserSupplierOrder::where($this->searchWhere)
            ->field(['id', 'order_sn', 'tenant_id', 'supplier_id', 'supplier_name', 'order_number', 'order_money', 'order_pay_money', 'order_arrears_money', 'goods_number', 'status', 'pay_status', 'datetimesingle', 'remarks', 'create_time', 'update_time'])
            ->limit($this->limitOffset, $this->limitLength)
            ->order(['id' => 'desc'])
            ->select()
            ->toArray();
    }


    /**
     * @notes 获取数量
     * @return int
     * @author likeadmin
     * @date 2025/12/22 16:53
     */
    public function count(): int
    {
        return UserSupplierOrder::where($this->searchWhere)->count();
    }

}