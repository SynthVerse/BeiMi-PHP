<?php

declare(strict_types=1);

namespace app\api\jxc\middleware;

use app\api\service\UserTokenService;
use app\common\service\auth\TenantSessionAuthorityService;
use app\common\service\JsonService;
use app\tenantapi\service\TenantTokenService;
use think\facade\Config;
use think\facade\Db;

class JxcLoginMiddleware
{
    public function handle($request, \Closure $next)
    {
        $token = $request->header('token');

        if (empty($token)) {
            return JsonService::fail('请求参数缺token', [], 0, 0);
        }

        // Both identities are resolved from the database. A token collision is
        // ambiguous and must never inherit either identity's authority.
        $adminInfo = TenantSessionAuthorityService::resolve((string)$token);
        $userInfo = self::resolveUserAuthority((string)$token);
        if (!empty($adminInfo) && !empty($userInfo)) {
            return JsonService::fail('登录身份冲突，请重新登录', [], -1, 0);
        }
        if (empty($adminInfo) && empty($userInfo)) {
            return JsonService::fail('登录超时，请重新登录', [], -1, 0);
        }

        if (!empty($adminInfo)) {
            if (($adminInfo['login_ip'] ?? '') !== (string)$request->ip()) {
                return JsonService::fail('ip地址发生变化，请重新登录', [], -1, 0);
            }

            $beExpireDuration = (int)Config::get('project.tenant_token.be_expire_duration');
            if (time() > ((int)($adminInfo['expire_time'] ?? 0) - $beExpireDuration)) {
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

        $beExpireDuration = (int)Config::get('project.user_token.be_expire_duration');
        if (time() > ((int)$userInfo['expire_time'] - $beExpireDuration)) {
            if (empty(UserTokenService::overtimeToken((string)$token))) {
                return JsonService::fail('登录过期', [], -1, 0);
            }
            $userInfo = self::resolveUserAuthority((string)$token);
            if (empty($userInfo)) {
                return JsonService::fail('登录状态无效，请重新登录', [], -1, 0);
            }
        }

        if (empty($userInfo['store_available'])) {
            return json([
                'code' => 0,
                'msg' => '请先创建或加入店铺',
                'error_code' => 'STORE_REQUIRED',
                'data' => ['requires_store' => true],
            ], 200);
        }

        $userId = (int)$userInfo['user_id'];
        $tenantId = (int)$userInfo['tenant_id'];
        $request->tenantId = $tenantId;
        $request->adminId = $userId;
        $request->userId = $userId;
        $request->jxcFromUserToken = true;
        $request->userInfo = $userInfo;
        $request->adminInfo = [
            'admin_id' => $userId,
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'root' => 0,
            'name' => (string)($userInfo['nickname'] ?? ''),
            'account' => (string)($userInfo['mobile'] ?? ''),
            'role_name' => (string)($userInfo['member_role'] ?? ''),
            'role_id' => [],
            'token' => (string)$token,
            'terminal' => $userInfo['terminal'],
            'expire_time' => (int)$userInfo['expire_time'],
        ];

        return $next($request);
    }

    /** @return array<string, mixed>|false */
    private static function resolveUserAuthority(string $token): array|false
    {
        $session = Db::name('user_session')
            ->where('token', $token)
            ->where('expire_time', '>', time())
            ->find();
        if (empty($session)) {
            return false;
        }

        $user = Db::name('user')
            ->where('id', (int)$session['user_id'])
            ->whereNull('delete_time')
            ->find();
        if (empty($user)) {
            return false;
        }

        $tenantId = (int)($user['tenant_id'] ?? 0);
        $tenant = $tenantId > 0
            ? Db::name('tenant')
                ->where('id', $tenantId)
                ->where('disable', 0)
                ->whereNull('delete_time')
                ->find()
            : null;
        $member = !empty($tenant)
            ? Db::name('tenant_member')
                ->where('tenant_id', $tenantId)
                ->where('user_id', (int)$user['id'])
                ->where('status', 1)
                ->whereNull('delete_time')
                ->find()
            : null;

        return [
            'user_id' => (int)$user['id'],
            'tenant_id' => $tenantId,
            'nickname' => (string)($user['nickname'] ?? ''),
            'mobile' => (string)($user['mobile'] ?? ''),
            'terminal' => $session['terminal'],
            'expire_time' => (int)$session['expire_time'],
            'member_role' => (string)($member['role'] ?? ''),
            'store_available' => !empty($tenant) && !empty($member),
        ];
    }
}
