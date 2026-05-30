<?php
namespace app\platformapi\logic\tenant;

use app\common\cache\TenantAdminAuthCache;
use app\common\cache\TenantAdminTokenCache;
use app\common\enum\user\UserTerminalEnum;
use app\common\logic\BaseLogic;
use app\common\model\tenant\Tenant;
use Exception;
use think\facade\Db;

/**
 * 用户逻辑层
 * Class TenantLogic
 * @package app\platformapi\logic\user
 */
class TenantLogic extends BaseLogic
{
    /**
     * @notes 新增租户
     * @param array $params
     * @return Tenant|\think\Model
     * @throws Exception
     * @author JXDN
     * @date 2024/09/03 14:42
     */
    public static function add(array $params)
    {
        $domain_alias = self::formatDomainAlias((string)($params['domain_alias'] ?? ''));
        $hostName = trim((string)($params['host_name'] ?? $params['sn'] ?? ''));
        $sn = $hostName === '' ? Tenant::createUserSn() : $hostName;
        $exists = (new Tenant())->where('sn', $sn)->find();
        if (!empty($exists)) {
            throw new Exception('主机名已被占用，请更换');
        }
        return Tenant::create([
            'sn'                  => $sn,
            'name'                => $params['name'],
            'avatar'              => $params['avatar'] ?? '',
            'tel'                 => $params['tel'] ?? '',
            'domain_alias'        => $domain_alias,
            'domain_alias_enable' => (int)($params['domain_alias_enable'] ?? 1),
            'disable'             => $params['disable'] ?? 0,
            'notes'               => $params['notes'] ?? '',
            'tactics'             => $params['tactics'] ?? 0,
            'expired_time'        => (int)($params['expired_time'] ?? time()),
        ]);
    }

    /**
     * @notes 用户详情
     * @param int $userId
     * @return array|false
     * @author JXDN
     * @date 2024/09/11 15:48
     */
    public static function detail(int $userId)
    {
        try {
            $field = "id,sn,name,avatar,tel,domain_alias,domain_alias_enable,disable,expired_time,create_time,notes";

            $user = Tenant::where(['id' => $userId])->field($field)->findOrEmpty();
            $user['user_total'] = Db::name('tenant_member')
                ->where('tenant_id', $userId)
                ->where('status', 1)
                ->whereNull('delete_time')
                ->count();

            $http_prefix = self::checkHttp() ? 'https://' : 'http://';
            $domain = self::getRootDmain(request()->domain());
            $user['default_domain'] = $http_prefix . $user['sn'] . '.' . $domain . '/admin/';
            $user['domain'] = (int)$user['domain_alias_enable'] === 0 && !empty($user['domain_alias'])
                ? $http_prefix . $user['domain_alias'] . '/admin/'
                : $user['default_domain'];
            $user['expired_time'] = date("Y-m-d",$user['expired_time']);
            return $user->toArray();
        } catch (\Exception $e) {
            self::setError($e->getMessage());
            return false;
        }

    }

    /**
     * @notes 更新租户信息
     * @param array $params
     * @return bool
     * @author JXDN
     * @date 2024/09/03 14:28
     */
    public static function edit(array $params)
    {
        try {
            $domain_alias = self::formatDomainAlias((string)($params['domain_alias'] ?? ''));
            $expiredTime = empty($params['expired_time']) ? time() : strtotime((string)$params['expired_time']);
            if (false === $expiredTime) {
                throw new Exception('有效期格式错误');
            }
            $params["expired_time"] = $expiredTime;
            Tenant::update([
                'name'                => $params['name'],
                'avatar'              => $params['avatar'] ?? '',
                'disable'             => $params['disable'] ?? 0,
                'tel'                 => $params['tel'] ?? '',
                'expired_time'        => $params['expired_time'],
                'domain_alias'        => $domain_alias,
                'domain_alias_enable' => (int)($params['domain_alias_enable'] ?? 1),
                'notes'               => $params['notes'] ?? '',
            ], ['id' => $params['id']]);
            return true;
        } catch (\Exception $e) {
            self::setError($e->getMessage());
            return false;
        }
    }

    /**
     * @notes 放入回收站
     * @param array $params
     * @return bool
     * @author JXDN
     * @date 2024/09/03 17:04
     */
    public static function delete(array $params)
    {
        try {
            Db::transaction(function () use ($params) {
                $tenantId = (int)$params['id'];
                Tenant::destroy($tenantId);

                $adminIds = Db::name('tenant_admin')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('delete_time')
                    ->column('id');

                if (!$adminIds) {
                    return;
                }

                $time = time();
                Db::name('tenant_admin')
                    ->whereIn('id', $adminIds)
                    ->update([
                        'disable' => 1,
                        'update_time' => $time,
                    ]);

                $tokens = Db::name('tenant_admin_session')
                    ->whereIn('admin_id', $adminIds)
                    ->column('token');
                Db::name('tenant_admin_session')
                    ->whereIn('admin_id', $adminIds)
                    ->update([
                        'expire_time' => $time,
                        'update_time' => $time,
                    ]);

                $tokenCache = new TenantAdminTokenCache();
                foreach ($tokens as $token) {
                    $tokenCache->deleteAdminInfo($token);
                }
                foreach ($adminIds as $adminId) {
                    (new TenantAdminAuthCache($adminId))->clearAuthCache();
                }
            });
            return true;
        } catch (\Exception $e) {
            self::setError($e->getMessage());
            return false;
        }
    }

    /**
     * @notes 恢复回收站店铺
     * @param array $params
     * @return bool
     */
    public static function restore(array $params)
    {
        try {
            Db::transaction(function () use ($params) {
                $tenantId = (int)$params['id'];
                $tenant = Tenant::onlyTrashed()->where('id', $tenantId)->findOrEmpty();
                if ($tenant->isEmpty()) {
                    throw new Exception('回收站店铺不存在');
                }

                $snExists = Tenant::where('sn', $tenant['sn'])
                    ->where('id', '<>', $tenantId)
                    ->findOrEmpty();
                if (!$snExists->isEmpty()) {
                    throw new Exception('店铺编号已被占用，无法恢复');
                }

                $domainAlias = self::formatDomainAlias((string)($tenant['domain_alias'] ?? ''));
                if ((int)$tenant['domain_alias_enable'] === 0 && $domainAlias !== '') {
                    $domainExists = Tenant::where('domain_alias', $domainAlias)
                        ->where('id', '<>', $tenantId)
                        ->findOrEmpty();
                    if (!$domainExists->isEmpty()) {
                        throw new Exception('域名别名已被占用，无法恢复');
                    }
                }

                if (false === $tenant->restore()) {
                    throw new Exception('恢复失败');
                }

                $adminIds = Db::name('tenant_admin')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('delete_time')
                    ->column('id');

                if (!$adminIds) {
                    return;
                }

                Db::name('tenant_admin')
                    ->whereIn('id', $adminIds)
                    ->update([
                        'disable' => 0,
                        'update_time' => time(),
                    ]);

                foreach ($adminIds as $adminId) {
                    (new TenantAdminAuthCache($adminId))->clearAuthCache();
                }
            });
            return true;
        } catch (\Exception $e) {
            self::setError($e->getMessage());
            return false;
        }
    }

    /**
     * @notes 检查是否为https
     * @return bool
     * @author JXDN
     * @date 2024/09/11 14:39
     */
    public static function checkHttp()
    {
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            return true;
        } else {
            return false;
        }
    }

    /**
     * @notes 获取根域名
     * @param $url
     * @return array|int|string|null
     * @author JXDN
     * @date 2024/09/11 14:49
     */
    public static function getRootDmain($url)
    {
        // 解析 URL 获取主机名
        $host = parse_url($url, PHP_URL_HOST);

        // 如果主机名为空，返回 null
        if (!$host) {
            return null;
        }

        // 拆分域名
        $parts = explode('.', $host);

        // 检查域名的级数
        $numParts = count($parts);

        // 针对常见的两级或三级域名进行处理
        if ($numParts >= 2) {
            // 获取最后两部分，例如 qq.com 或 co.uk
            return $parts[$numParts - 2] . '.' . $parts[$numParts - 1];
        }

        return $host; // 当域名本身就是根域名时，直接返回
    }

    private static function formatDomainAlias(string $domainAlias): string
    {
        return preg_replace('/^https?:\/\//i', '', rtrim(trim($domainAlias), '/'));
    }
}
