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

use app\common\model\auth\TenantAdminRole;
use app\common\model\auth\TenantSystemRole;
use app\common\model\auth\TenantSystemMenu;
use app\common\model\auth\TenantSystemRoleMenu;


/**
 * 权限功能类
 * Class AuthLogic
 * @package app\tenantapi\logic\auth
 */
class AuthLogic
{

    /**
     * @notes 获取全部权限
     * @return mixed
     * @author 段誉
     * @date 2022/7/1 11:55
     */
    public static function getAllAuth(int $tenantId): array
    {
        if ($tenantId <= 0) {
            return [];
        }

        return TenantSystemMenu::distinct(true)
            ->where('tenant_id', $tenantId)
            ->where([
                ['is_disable', '=', 0],
                ['perms', '<>', '']
            ])
            ->column('perms');
    }


    /**
     * @notes 获取当前管理员角色按钮权限
     * @param $roleId
     * @return mixed
     * @author 段誉
     * @date 2022/7/1 16:10
     */
    public static function getBtnAuthByRoleId($admin)
    {
        if ($admin['root']) {
            return ['*'];
        }

        $tenantId = (int)($admin['tenant_id'] ?? 0);
        if ($tenantId <= 0) {
            return [];
        }
        $menuId = TenantSystemRoleMenu::whereIn('role_id', self::tenantRoleIdsByAdminId((int)$admin['id'], $tenantId))
            ->column('menu_id');

        $where[] = ['is_disable', '=', 0];
        $where[] = ['perms', '<>', ''];

        $roleAuth = TenantSystemMenu::distinct(true)
            ->where('id', 'in', $menuId)
            ->where('tenant_id', $tenantId)
            ->where($where)
            ->column('perms');

        $allAuth = TenantSystemMenu::distinct(true)
            ->where('tenant_id', $tenantId)
            ->where($where)
            ->column('perms');

        $hasAllAuth = array_diff($allAuth, $roleAuth);
        if (empty($hasAllAuth)) {
            return ['*'];
        }

        return $roleAuth;
    }


    /**
     * @notes 获取管理员角色关联的菜单id(菜单，权限)
     * @param int $adminId
     * @return array
     * @author 段誉
     * @date 2022/7/1 15:56
     */
    public static function getAuthByAdminId(int $adminId): array
    {
        $admin = \app\common\model\auth\TenantAdmin::field('id,tenant_id')->findOrEmpty($adminId);
        if ($admin->isEmpty()) {
            return [];
        }
        $tenantId = (int)$admin['tenant_id'];
        if ($tenantId <= 0) {
            return [];
        }
        $roleIds = self::tenantRoleIdsByAdminId($adminId, $tenantId);
        $menuId = TenantSystemRoleMenu::whereIn('role_id', $roleIds)->column('menu_id');

        return TenantSystemMenu::distinct(true)
            ->where([
                ['is_disable', '=', 0],
                ['perms', '<>', ''],
                ['id', 'in', array_unique($menuId)],
            ])
            ->where('tenant_id', $tenantId)
            ->column('perms');
    }

    /** @return list<int> */
    private static function tenantRoleIdsByAdminId(int $adminId, int $tenantId): array
    {
        $roleIds = array_map('intval', TenantAdminRole::where('admin_id', $adminId)->column('role_id'));
        if ($roleIds === []) {
            return [];
        }

        $tenantRoleIds = array_map('intval', TenantSystemRole::where('tenant_id', $tenantId)
            ->whereIn('id', $roleIds)
            ->column('id'));
        sort($roleIds, SORT_NUMERIC);
        sort($tenantRoleIds, SORT_NUMERIC);

        // Historical association rows can outlive a tenant-bound role change.
        // Treat any such mismatch as invalid rather than granting partial access.
        return $roleIds === $tenantRoleIds ? $roleIds : [];
    }
}
