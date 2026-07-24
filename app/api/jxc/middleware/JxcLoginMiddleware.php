<?php

declare(strict_types=1);

namespace app\api\jxc\middleware;

use app\common\service\auth\TenantSessionAuthorityService;
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

        // Cache entries are not an authority boundary. Resolve the session,
        // administrator, and tenant from the database for every request.
        $adminInfo = TenantSessionAuthorityService::resolve((string)$token);
        if (empty($adminInfo)) {
            return JsonService::fail('登录超时，请重新登录', [], -1, 0);
        }

        if (($adminInfo['login_ip'] ?? '') !== (string)$request->ip()) {
            return JsonService::fail('ip地址发生变化，请重新登录', [], -1, 0);
        }

        $beExpireDuration = Config::get('project.tenant_token.be_expire_duration');
        if (time() > (($adminInfo['expire_time'] ?? 0) - $beExpireDuration)) {
            $result = TenantTokenService::overtimeToken($token);
            if (empty($result)) {
                return JsonService::fail('登录过期', [], -1, 0);
            }
            $adminInfo = TenantSessionAuthorityService::resolve((string)$token);
            if (empty($adminInfo) || ($adminInfo['login_ip'] ?? '') !== (string)$request->ip()) {
                return JsonService::fail('登录状态无效，请重新登录', [], -1, 0);
            }
        }

        $tenantId = (int)($adminInfo['tenant_id'] ?? 0);
        $request->tenantId = $tenantId;
        $request->adminInfo = $adminInfo;
        $request->adminId = (int)($adminInfo['admin_id'] ?? 0);
        $request->userId = (int)$adminInfo['admin_id'];

        return $next($request);
    }
}
