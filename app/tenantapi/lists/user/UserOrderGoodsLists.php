<?php
// +----------------------------------------------------------------------
// | likeadmin快速开发前后端分离管理后台（PHP版）
// +----------------------------------------------------------------------
// | 欢迎阅读学习系统程序代码，建议反馈是我们前进的动力
// | 开源版本可自由商用，可去除界面版权logo
// | gitee下载：https://gitee.com/likeshop_gitee/likeadmin
// | github下载：https://github.com/likeshop-github/likeadmin
// | 访问官网：https://www.likeadmin.cn
// | likeadmin团队 版权所有 拥有最终解释权
// +----------------------------------------------------------------------
// | author: likeadminTeam
// +----------------------------------------------------------------------

namespace app\tenantapi\lists\user;


use app\tenantapi\lists\BaseAdminDataLists;
use app\common\model\user\UserOrderGoods;
use app\common\lists\ListsSearchInterface;


/**
 * UserOrderGoods列表
 * Class UserOrderGoodsLists
 * @package app\tenantapi\listsuser
 */
class UserOrderGoodsLists extends BaseAdminDataLists implements ListsSearchInterface
{


    /**
     * @notes 设置搜索条件
     * @return \string[][]
     * @author likeadmin
     * @date 2026/01/07 09:41
     */
    public function setSearch(): array
    {
        return [
            '=' => ['user_id', 'order_id', 'goods_id'],
        ];
    }


    /**
     * @notes 获取列表
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author likeadmin
     * @date 2026/01/07 09:41
     */
    public function lists(): array
    {
        return UserOrderGoods::where($this->searchWhere)
            ->field(['id', 'tenant_id', 'user_id', 'order_id', 'goods_id', 'name', 'number', 'units', 'units_money', 'price', 'amount'])
            ->limit($this->limitOffset, $this->limitLength)
            ->order(['id' => 'desc'])
            ->select()
            ->toArray();
    }


    /**
     * @notes 获取数量
     * @return int
     * @author likeadmin
     * @date 2026/01/07 09:41
     */
    public function count(): int
    {
        return UserOrderGoods::where($this->searchWhere)->count();
    }

}