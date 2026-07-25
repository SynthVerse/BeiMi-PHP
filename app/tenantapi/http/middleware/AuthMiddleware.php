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

declare (strict_types=1);

namespace app\tenantapi\http\middleware;

use app\common\model\auth\TenantAdminRole;
use app\common\model\auth\TenantSystemMenu;
use app\common\model\auth\TenantSystemRole;
use app\common\model\auth\TenantSystemRoleMenu;
use app\common\service\auth\TenantSessionAuthorityService;
use app\common\service\JsonService;
use think\helper\Str;

/**
 * 权限验证中间件
 * Class AuthMiddleware
 * @package app\tenantapi\http\middleware
 */
class AuthMiddleware
{
    /**
     * @notes 权限验证
     * @param $request
     * @param \Closure $next
     * @return mixed
     * @author 令狐冲
     * @date 2021/7/2 19:29
     */
    public function handle($request, \Closure $next)
    {
        //不登录访问，无需权限验证
        if ($request->controllerObject->isNotNeedLogin()) {
            return $next($request);
        }

        $adminInfo = $request->adminInfo ?? null;
        $token = is_array($adminInfo) ? (string)($adminInfo['token'] ?? '') : '';
        $adminInfo = TenantSessionAuthorityService::resolve($token);
        if (empty($adminInfo)) {
            return JsonService::fail('登录状态无效，请重新登录', [], -1);
        }
        $request->adminInfo = $adminInfo;
        $tenantId = (int)($adminInfo['tenant_id'] ?? 0);
        $adminId = (int)($adminInfo['admin_id'] ?? 0);
        if ($tenantId <= 0 || $adminId <= 0) {
            return JsonService::fail('登录状态无效，请重新登录', [], -1);
        }

        if (($adminInfo['login_ip'] ?? '') != request()->ip()) {
            return JsonService::fail('ip地址发生变化，请重新登录', [], -1);
        }

        //系统默认超级管理员，无需权限验证
        if (1 === ($adminInfo['root'] ?? 0)) {
            return $next($request);
        }

        // 当前访问路径
        $accessUri = strtolower($request->controller() . '/' . $request->action());
        $allMenu = TenantSystemMenu::where('tenant_id', $tenantId)
            ->where('is_disable', 0)
            ->where('perms', '<>', '')
            ->field('id,perms')
            ->select()
            ->toArray();
        $registeredMenuIds = [];
        foreach ($allMenu as $menu) {
            if ($this->formatUrl([(string)$menu['perms']])[0] === $accessUri) {
                $registeredMenuIds[] = (int)$menu['id'];
            }
        }
        if ($registeredMenuIds === []) {
            return JsonService::fail('权限不足，无法访问或操作');
        }

        $roleIds = array_map('intval', TenantAdminRole::where('admin_id', $adminId)->column('role_id'));
        if ($roleIds === []) {
            return JsonService::fail('权限不足，无法访问或操作');
        }
        $tenantRoleIds = array_map('intval', TenantSystemRole::where('tenant_id', $tenantId)
            ->whereIn('id', $roleIds)
            ->column('id'));
        sort($roleIds, SORT_NUMERIC);
        sort($tenantRoleIds, SORT_NUMERIC);
        if ($roleIds !== $tenantRoleIds) {
            return JsonService::fail('权限不足，无法访问或操作');
        }

        $authorized = TenantSystemRoleMenu::whereIn('role_id', $roleIds)
            ->whereIn('menu_id', $registeredMenuIds)
            ->count() > 0;
        if ($authorized) {
            return $next($request);
        }
        return JsonService::fail('权限不足，无法访问或操作');
    }


    /**
     * @notes 格式化URL
     * @param array $data
     * @return array|string[]
     * @author 段誉
     * @date 2022/7/7 15:39
     */
    public function formatUrl(array $data)
    {
        return array_map(function ($item) {
            return strtolower(Str::camel($item));
        }, $data);
    }

}
