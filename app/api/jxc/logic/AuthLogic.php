<?php

namespace app\api\jxc\logic;

use app\common\cache\TenantAdminTokenCache;
use app\common\logic\BaseLogic;
use app\tenantapi\logic\LoginLogic as TenantLoginLogic;

class AuthLogic extends BaseLogic
{
    public static function login(array $params): array
    {
        $result = (new TenantLoginLogic())->login($params);
        $adminInfo = (new TenantAdminTokenCache())->getAdminInfo($result['token'] ?? '');

        $userInfo = [
            'id' => (int)($adminInfo['admin_id'] ?? 0),
            'admin_id' => (int)($adminInfo['admin_id'] ?? 0),
            'tenant_id' => (int)($adminInfo['tenant_id'] ?? 0),
            'name' => $result['name'] ?? ($adminInfo['name'] ?? ''),
            'avatar' => $result['avatar'] ?? '',
            'role_name' => $result['role_name'] ?? ($adminInfo['role_name'] ?? ''),
            'token' => $result['token'] ?? '',
        ];

        return [
            'token' => $userInfo['token'],
            'user_info' => $userInfo,
            'userinfo' => $userInfo,
        ];
    }

    public static function info(array $adminInfo): array
    {
        $userInfo = [
            'id' => (int)($adminInfo['admin_id'] ?? 0),
            'admin_id' => (int)($adminInfo['admin_id'] ?? 0),
            'tenant_id' => (int)($adminInfo['tenant_id'] ?? 0),
            'name' => $adminInfo['name'] ?? '',
            'avatar' => '',
            'role_name' => $adminInfo['role_name'] ?? '',
            'token' => $adminInfo['token'] ?? '',
        ];

        return [
            'token' => $userInfo['token'],
            'user_info' => $userInfo,
            'userinfo' => $userInfo,
        ];
    }

    public static function logout(array $adminInfo): bool
    {
        return (new TenantLoginLogic())->logout($adminInfo);
    }
}
