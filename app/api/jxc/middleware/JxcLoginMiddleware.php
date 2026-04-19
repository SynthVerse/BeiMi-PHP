<?php

declare(strict_types=1);

namespace app\api\jxc\middleware;

use app\common\cache\TenantAdminTokenCache;
use app\common\service\JsonService;
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
        if (empty($adminInfo)) {
            return JsonService::fail('登录超时，请重新登录', [], -1, 0);
        }

        $beExpireDuration = Config::get('project.admin_token.be_expire_duration');
        if (time() > (($adminInfo['expire_time'] ?? 0) - $beExpireDuration)) {
            $result = TenantTokenService::overtimeToken($token);
            if (empty($result)) {
                return JsonService::fail('登录过期', [], -1, 0);
            }
            $adminInfo = (new TenantAdminTokenCache())->getAdminInfo($token);
        }

        $request->tenantId = (int)($adminInfo['tenant_id'] ?? 0);
        $request->adminInfo = $adminInfo;
        $request->adminId = (int)($adminInfo['admin_id'] ?? 0);

        return $next($request);
    }
}
