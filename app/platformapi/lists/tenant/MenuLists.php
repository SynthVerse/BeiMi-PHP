<?php
namespace app\platformapi\lists\tenant;

use app\tenantapi\lists\BaseAdminDataLists;
use app\common\model\auth\TenantSystemMenu;
use think\db\exception\DbException;


/**
 *  菜单列表
 * Class MenuLists
 * @package app\tenantapi\lists\auth
 */
class MenuLists extends BaseAdminDataLists
{

    /**
     * @notes 获取菜单列表
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author 段誉
     * @date 2022/6/29 16:41
     */
    public function lists(): array
    {
        $lists = TenantSystemMenu::order(['sort' => 'desc', 'id' => 'asc'])
            ->select()
            ->toArray();
        return linear_to_tree($lists, 'children');
    }


    /**
     * @notes 获取菜单数量
     * @return int
     * @throws DbException
     * @author 段誉
     * @date 2022/6/29 16:41
     */
    public function count(): int
    {
        return TenantSystemMenu::count();
    }

}