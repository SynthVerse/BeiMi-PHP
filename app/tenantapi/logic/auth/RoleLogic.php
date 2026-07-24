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

use app\common\logic\BaseLogic;
use app\common\model\auth\TenantSystemRole;
use app\common\model\auth\TenantSystemRoleMenu;
use app\common\model\auth\TenantSystemMenu;
use app\common\model\auth\TenantAdminRole;
use app\common\service\auth\TenantSessionAuthorityService;
use think\facade\Db;


/**
 * 角色逻辑层
 * Class RoleLogic
 * @package app\tenantapi\logic\auth
 */
class RoleLogic extends BaseLogic
{

    /**
     * @notes 添加角色
     * @param array $params
     * @return bool
     * @author 段誉
     * @date 2021/12/29 11:50
     */
    public static function add(array $params): bool
    {
        $tenantId = self::currentTenantId();
        if ($tenantId <= 0) {
            self::$error = '租户上下文不可用';
            return false;
        }
        Db::startTrans();
        try {
            $menuId = self::tenantMenuIds($tenantId, $params['menu_id'] ?? []);

            $role = TenantSystemRole::create([
                'tenant_id' => $tenantId,
                'name' => $params['name'],
                'desc' => $params['desc'] ?? '',
                'sort' => $params['sort'] ?? 0,
            ]);

            $data = [];
            foreach ($menuId as $item) {
                if (empty($item)) {
                    continue;
                }
                $data[] = [
                    'role_id' => $role['id'],
                    'menu_id' => $item,
                ];
            }
            (new TenantSystemRoleMenu)->insertAll($data);

            Db::commit();
            return true;
        } catch (\Exception $e) {
            Db::rollback();
            self::$error = $e->getMessage();
            return false;
        }
    }


    /**
     * @notes 编辑角色
     * @param array $params
     * @return bool
     * @author 段誉
     * @date 2021/12/29 14:16
     */
    public static function edit(array $params): bool
    {
        $tenantId = self::currentTenantId();
        if ($tenantId <= 0) {
            self::$error = '租户上下文不可用';
            return false;
        }
        $expiredTokens = [];
        Db::startTrans();
        $roleId = (int)$params['id'];
        $menuId = $params['menu_id'] ?? [];
        $result = self::runEditCallbacks(
            static function () use ($params, $tenantId, $roleId, $menuId, &$expiredTokens): void {
                $role = TenantSystemRole::where('id', $roleId)
                    ->where('tenant_id', $tenantId)
                    ->lock(true)
                    ->findOrEmpty();
                if ($role->isEmpty()) {
                    throw new \RuntimeException('角色不存在');
                }
                $scopedMenuIds = self::tenantMenuIds($tenantId, $menuId);
                $role->save([
                    'name' => $params['name'],
                    'desc' => $params['desc'] ?? '',
                    'sort' => $params['sort'] ?? 0,
                ]);

                $adminIds = TenantAdminRole::where('role_id', $roleId)->column('admin_id');
                $expiredTokens = TenantSessionAuthorityService::expireAdminSessionsByIds($adminIds);

                // A role update is a complete replacement. Deleting first is
                // required for an empty menu_id to revoke every prior permission.
                TenantSystemRoleMenu::where(['role_id' => $roleId])->delete();
                $data = self::roleMenuRows($roleId, $scopedMenuIds);
                if ($data !== []) {
                    (new TenantSystemRoleMenu)->insertAll($data);
                }
            },
            static function (): void {
                Db::commit();
            },
            static function (): void {
                Db::rollback();
            },
            static function () use ($tenantId, &$expiredTokens): void {
                TenantSessionAuthorityService::clearTokenCaches($expiredTokens);
                TenantSessionAuthorityService::clearTenantAuthorizationCache($tenantId);
            }
        );
        if (!$result['success']) {
            self::$error = $result['message'];
            return false;
        }
        return true;
    }

    /**
     * Runs the database write/commit boundary before the cache boundary.
     * Cache cleanup runs only after commit and must never change business success.
     *
     * @return array{success: bool, committed: bool, message: string, exception_class?: string}
     */
    private static function runEditCallbacks(
        callable $write,
        callable $commit,
        callable $rollback,
        callable $invalidateCache
    ): array {
        try {
            $write();
            $commit();
        } catch (\Throwable $e) {
            try {
                $rollback();
            } catch (\Throwable) {
                // Preserve the write failure as the caller-visible outcome.
            }
            return ['success' => false, 'committed' => false, 'message' => $e->getMessage()];
        }

        try {
            $invalidateCache();
        } catch (\Throwable) {
            // Defensive boundary: committed authorization changes remain successful.
        }

        return ['success' => true, 'committed' => true, 'message' => ''];
    }

    /** @return list<array{role_id:int, menu_id:mixed}> */
    private static function roleMenuRows(int $roleId, array $menuId): array
    {
        $data = [];
        foreach ($menuId as $item) {
            $data[] = [
                'role_id' => $roleId,
                'menu_id' => $item,
            ];
        }

        return $data;
    }

    /** @return list<int> */
    private static function tenantMenuIds(int $tenantId, mixed $menuId): array
    {
        if (!is_array($menuId)) {
            throw new \RuntimeException('权限菜单格式错误');
        }
        $menuIds = [];
        foreach ($menuId as $menu) {
            if (!is_int($menu) || $menu <= 0) {
                throw new \RuntimeException('权限菜单不存在');
            }
            $menuIds[] = $menu;
        }
        $menuIds = array_values(array_unique($menuIds));
        if ($menuIds === []) {
            return [];
        }
        $scopedIds = TenantSystemMenu::where('tenant_id', $tenantId)
            ->whereIn('id', $menuIds)
            ->column('id');
        $scopedIds = self::normalizeAuthoritativeMenuIds($scopedIds);
        sort($menuIds, SORT_NUMERIC);
        sort($scopedIds, SORT_NUMERIC);
        if ($scopedIds !== $menuIds) {
            throw new \RuntimeException('权限菜单不存在');
        }

        return $menuIds;
    }

    /** @return list<int> */
    private static function normalizeAuthoritativeMenuIds(array $menuIds): array
    {
        return array_map('intval', $menuIds);
    }

    private static function currentTenantId(): int
    {
        $adminInfo = request()->adminInfo ?? null;
        if (!is_array($adminInfo)) {
            return 0;
        }

        $tenantId = (int)($adminInfo['tenant_id'] ?? 0);
        return $tenantId > 0 ? $tenantId : 0;
    }

    /**
     * @notes 删除角色
     * @param int $id
     * @return bool
     * @author 段誉
     * @date 2021/12/29 14:16
     */
    public static function delete(int $id)
    {
        $tenantId = self::currentTenantId();
        if ($tenantId <= 0) {
            self::$error = '租户上下文不可用';
            return false;
        }
        $expiredTokens = [];
        Db::startTrans();
        $result = self::runEditCallbacks(
            static function () use ($id, $tenantId, &$expiredTokens): void {
                $role = TenantSystemRole::where('id', $id)
                    ->where('tenant_id', $tenantId)
                    ->lock(true)
                    ->findOrEmpty();
                if ($role->isEmpty()) {
                    throw new \RuntimeException('角色不存在');
                }
                $role->delete();
                TenantSystemRoleMenu::where('role_id', $id)->delete();
                $adminIds = Db::name('tenant_admin_role')->where('role_id', $id)->column('admin_id');
                $expiredTokens = TenantSessionAuthorityService::expireAdminSessionsByIds($adminIds);
            },
            static function (): void {
                Db::commit();
            },
            static function (): void {
                Db::rollback();
            },
            static function () use ($tenantId, &$expiredTokens): void {
                TenantSessionAuthorityService::clearTokenCaches($expiredTokens);
                TenantSessionAuthorityService::clearTenantAuthorizationCache($tenantId);
            }
        );
        if (!$result['success']) {
            self::$error = $result['message'];
            return false;
        }
        return true;
    }


    /**
     * @notes 角色详情
     * @param int $id
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author 段誉
     * @date 2021/12/29 14:17
     */
    public static function detail(int $id): array
    {
        $tenantId = self::currentTenantId();
        if ($tenantId <= 0) {
            return [];
        }
        $detail = TenantSystemRole::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->field('id,name,desc,sort')
            ->findOrEmpty();
        if ($detail->isEmpty()) {
            return [];
        }
        $authList = $detail->roleMenuIndex()->select()->toArray();
        $menuId = array_column($authList, 'menu_id');
        $detail['menu_id'] = $menuId;
        return $detail->toArray();
    }


    /**
     * @notes 角色数据
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author 段誉
     * @date 2022/10/13 10:39
     */
    public static function getAllData()
    {
        $tenantId = self::currentTenantId();
        if ($tenantId <= 0) {
            return [];
        }
        return TenantSystemRole::where('tenant_id', $tenantId)->order(['sort' => 'desc', 'id' => 'desc'])
            ->select()
            ->toArray();
    }


}
