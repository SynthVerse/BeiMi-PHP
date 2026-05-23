<?php

declare(strict_types=1);

namespace app\api\jxc\middleware;

use app\common\cache\TenantAdminTokenCache;
use app\common\cache\UserTokenCache;
use app\api\jxc\logic\StoreLogic;
use app\common\service\JsonService;
use app\common\service\jxc\StoreMembershipService;
use app\tenantapi\service\TenantTokenService;
use think\facade\Config;

class JxcLoginMiddleware
{
    public function handle($request, \Closure $next)
    {
        $token = $request->header('token');

        if (empty($token)) {
            return JsonService::fail('请求参数缺token', [], 0, 0);
        }

        $adminInfo = (new TenantAdminTokenCache())->getAdminInfo($token);

        // 回退查询：当 TenantAdminTokenCache 无记录时，尝试从 UserTokenCache 获取（微信登录用户）
        $fromUserToken = false;
        if (empty($adminInfo)) {
            $userInfo = (new UserTokenCache())->getUserInfo($token);
            if (empty($userInfo)) {
                return JsonService::fail('登录超时，请重新登录', [], -1, 0);
            }
            $adminInfo = [
                'admin_id'    => $userInfo['user_id'],
                'user_id'     => $userInfo['user_id'],
                'tenant_id'   => $userInfo['tenant_id'] ?? 0,
                'root'        => 0,
                'name'        => $userInfo['nickname'] ?? '',
                'account'     => $userInfo['mobile'] ?? '',
                'role_name'   => '',
                'role_id'     => [],
                'token'       => $userInfo['token'],
                'terminal'    => $userInfo['terminal'] ?? '',
                'expire_time' => $userInfo['expire_time'] ?? 0,
            ];
            $fromUserToken = true;
        }

        // Token 续期逻辑仅对 TenantAdmin 体系生效
        if (!$fromUserToken) {
            $beExpireDuration = Config::get('project.admin_token.be_expire_duration');
            if (time() > (($adminInfo['expire_time'] ?? 0) - $beExpireDuration)) {
                $result = TenantTokenService::overtimeToken($token);
                if (empty($result)) {
                    return JsonService::fail('登录过期', [], -1, 0);
                }
                $adminInfo = (new TenantAdminTokenCache())->getAdminInfo($token);
            }
        }

        $tenantId = (int)($adminInfo['tenant_id'] ?? 0);
        $request->tenantId = $tenantId;
        $request->adminInfo = $adminInfo;
        $request->adminId = (int)($adminInfo['admin_id'] ?? 0);
        $request->userId = (int)($adminInfo['user_id'] ?? 0);
        $request->jxcFromUserToken = $fromUserToken;

        if ($fromUserToken) {
            $userId = (int)($adminInfo['user_id'] ?? $adminInfo['admin_id'] ?? 0);
            $action = strtolower((string)$request->action());
            $controller = strtolower((string)$request->controller());
            $storeEntryActions = ['status', 'createstore', 'join', 'acceptmemberinvite', 'lists', 'switchstore'];
            $isStoreEntryAction = str_ends_with($controller, 'store') && in_array($action, $storeEntryActions, true);

            if (!$isStoreEntryAction && !StoreMembershipService::requireCurrentMembership($userId, $tenantId)) {
                return JsonService::fail('请先创建或切换到有效店铺', StoreLogic::status(), 0, 0);
            }
        }

        return $next($request);
    }
}
