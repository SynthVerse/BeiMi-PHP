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

namespace app\tenantapi\logic\auth;


use app\common\enum\YesNoEnum;
use app\common\logic\BaseLogic;
use app\common\model\auth\TenantAdmin;
use app\common\model\auth\TenantAdminRole;
use app\common\model\auth\TenantSystemMenu;
use app\common\model\auth\TenantSystemRole;
use app\common\model\auth\TenantSystemRoleMenu;


/**
 * 系统菜单
 * Class MenuLogic
 * @package app\tenantapi\logic\auth
 */
class MenuLogic extends BaseLogic
{


    /**
     * @notes 获取管理员对应的角色菜单
     * @param $adminId
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author 段誉
     * @date 2022/7/1 10:50
     */
    public static function getMenuByAdminId($adminId)
    {
        $admin = TenantAdmin::field('id,tenant_id,root')->findOrEmpty($adminId);
        if ($admin->isEmpty()) {
            return [];
        }

        $tenantId = (int)$admin['tenant_id'];
        if ($tenantId <= 0) {
            return [];
        }

        $where = [];
        $where[] = ['type', 'in', ['M', 'C']];
        $where[] = ['is_disable', '=', 0];
        $where[] = ['tenant_id', '=', $tenantId];

        if ($admin['root'] != 1) {
            $roleIds = array_map('intval', TenantAdminRole::where('admin_id', $admin['id'])->column('role_id'));
            if ($roleIds === []) {
                return [];
            }

            $tenantRoleIds = array_map('intval', TenantSystemRole::where('tenant_id', $tenantId)
                ->whereIn('id', $roleIds)
                ->column('id'));
            sort($roleIds, SORT_NUMERIC);
            sort($tenantRoleIds, SORT_NUMERIC);
            if ($roleIds !== $tenantRoleIds) {
                return [];
            }

            $roleMenu = TenantSystemRoleMenu::whereIn('role_id', $roleIds)->column('menu_id');
            $where[] = ['id', 'in', $roleMenu];
        }

        $menu = TenantSystemMenu::where($where)
            ->order(['sort' => 'desc', 'id' => 'asc'])
            ->select();

        return linear_to_tree($menu, 'children');
    }


    /**
     * @notes 全部数据
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author 段誉
     * @date 2022/10/13 11:03
     */
    public static function getAllData(int $tenantId): array
    {
        if ($tenantId <= 0) {
            return [];
        }

        $data = TenantSystemMenu::where(['is_disable' => YesNoEnum::NO])
            ->where('tenant_id', $tenantId)
            ->field('id,pid,name')
            ->order(['sort' => 'desc', 'id' => 'desc'])
            ->select()
            ->toArray();

        return linear_to_tree($data, 'children');
    }

}
