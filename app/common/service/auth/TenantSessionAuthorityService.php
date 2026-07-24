<?php

declare(strict_types=1);

namespace app\common\service\auth;

use app\common\cache\TenantAdminAuthCache;
use app\common\cache\TenantAdminTokenCache;
use app\common\model\auth\TenantAdmin;
use app\common\model\auth\TenantAdminSession;
use app\common\model\tenant\Tenant;

/**
 * Server-side authority for tenant-admin sessions.
 *
 * Token cache entries are only an optimization.  A protected request must
 * always pass this database-backed check before it is treated as authenticated.
 */
final class TenantSessionAuthorityService
{
    /** @return array<string, mixed>|false */
    public static function resolve(string $token): array|false
    {
        if ($token === '') {
            return false;
        }

        $session = TenantAdminSession::where('token', $token)
            ->where('expire_time', '>', time())
            ->findOrEmpty();
        if ($session->isEmpty()) {
            return false;
        }

        $admin = TenantAdmin::where('id', (int)$session['admin_id'])
            ->where('disable', 0)
            ->findOrEmpty();
        if ($admin->isEmpty()) {
            return false;
        }

        $tenant = Tenant::where('id', (int)$admin['tenant_id'])
            ->where('disable', 0)
            ->where('expired_time', '>', time())
            ->findOrEmpty();
        if ($tenant->isEmpty()) {
            return false;
        }

        return [
            'admin_id' => (int)$admin['id'],
            'tenant_id' => (int)$admin['tenant_id'],
            'root' => (int)$admin['root'],
            'name' => (string)$admin['name'],
            'account' => (string)$admin['account'],
            'token' => $token,
            'terminal' => $session['terminal'],
            'expire_time' => (int)$session['expire_time'],
            'login_ip' => (string)$admin['login_ip'],
        ];
    }

    /** @return list<string> */
    public static function expireAdminSessions(int $adminId): array
    {
        return self::expireAdminSessionsByIds([$adminId]);
    }

    /** @param list<int> $adminIds @return list<string> */
    public static function expireAdminSessionsByIds(array $adminIds): array
    {
        $adminIds = array_values(array_filter(array_unique(array_map('intval', $adminIds))));
        if ($adminIds === []) {
            return [];
        }

        $tokens = TenantAdminSession::whereIn('admin_id', $adminIds)->column('token');
        $time = time();
        TenantAdminSession::whereIn('admin_id', $adminIds)->update([
            'expire_time' => $time,
            'update_time' => $time,
        ]);

        return array_values(array_filter(array_map('strval', $tokens)));
    }

    /** @param list<string> $tokens */
    public static function clearTokenCaches(array $tokens): void
    {
        try {
            $cache = new TenantAdminTokenCache();
        } catch (\Throwable) {
            // Post-commit cleanup is intentionally best effort.
            return;
        }

        foreach ($tokens as $token) {
            try {
                $cache->deleteAdminInfo($token);
            } catch (\Throwable) {
                // Post-commit cleanup is intentionally best effort.
            }
        }
    }

    public static function clearAuthorizationCache(int $adminId, int $tenantId): void
    {
        try {
            (new TenantAdminAuthCache($adminId, $tenantId))->clearAuthCache();
        } catch (\Throwable) {
            // Post-commit cleanup is intentionally best effort.
        }
    }

    public static function clearTenantAuthorizationCache(int $tenantId): void
    {
        try {
            (new TenantAdminAuthCache('', $tenantId))->deleteTag();
        } catch (\Throwable) {
            // Post-commit cleanup is intentionally best effort.
        }
    }
}
