<?php
namespace app\platformapi\logic\tenant;

use app\common\cache\TenantAdminAuthCache;
use app\common\cache\TenantAdminTokenCache;
use app\common\enum\user\UserTerminalEnum;
use app\common\logic\BaseLogic;
use app\common\model\tenant\Tenant;
use app\common\service\auth\TenantSessionAuthorityService;
use Exception;
use think\facade\Db;

/**
 * 用户逻辑层
 * Class TenantLogic
 * @package app\platformapi\logic\user
 */
class TenantLogic extends BaseLogic
{
    public static function confirmClosureBackupPurged(
        string $publicId,
        int $adminId,
        bool $confirmed
    ): array|false {
        try {
            if (!$confirmed) {
                throw new Exception('请先确认外部备份已实际清理');
            }
            if (trim($publicId) === '' || $adminId <= 0) {
                throw new Exception('注销凭据或平台管理员身份无效');
            }
            return Db::transaction(function () use ($publicId, $adminId) {
                $receipt = Db::name('tenant_closure_receipt')
                    ->where('public_id', trim($publicId))
                    ->lock(true)
                    ->find();
                if (!$receipt) {
                    throw new Exception('注销凭据不存在');
                }
                if (!empty($receipt['backup_deleted_at'])) {
                    return self::formatClosureReceipt((array)$receipt);
                }
                if (empty($receipt['online_deleted_at']) || empty($receipt['attachments_deleted_at'])) {
                    throw new Exception('在线数据或专属附件尚未清理完成');
                }

                $time = time();
                Db::name('tenant_closure_receipt')->where('id', (int)$receipt['id'])->update([
                    'status' => 'completed',
                    'phase' => 'completed',
                    'backup_deleted_at' => $time,
                    'backup_purged_by_admin_id' => $adminId,
                    'last_error_code' => '',
                    'last_error_message' => '',
                    'update_time' => $time,
                ]);
                $receipt = Db::name('tenant_closure_receipt')->where('id', (int)$receipt['id'])->find();
                return self::formatClosureReceipt((array)$receipt);
            });
        } catch (\Throwable $e) {
            self::setError($e->getMessage());
            return false;
        }
    }

    private static function formatClosureReceipt(array $receipt): array
    {
        return [
            'public_id' => (string)$receipt['public_id'],
            'tenant_id' => (int)$receipt['tenant_id'],
            'status' => (string)$receipt['status'],
            'phase' => (string)$receipt['phase'],
            'effective_at' => (int)$receipt['effective_at'],
            'online_deleted_at' => $receipt['online_deleted_at'] === null ? null : (int)$receipt['online_deleted_at'],
            'attachments_deleted_at' => $receipt['attachments_deleted_at'] === null ? null : (int)$receipt['attachments_deleted_at'],
            'backup_purge_due_at' => (int)$receipt['backup_purge_due_at'],
            'backup_deleted_at' => $receipt['backup_deleted_at'] === null ? null : (int)$receipt['backup_deleted_at'],
            'backup_purged_by_admin_id' => (int)$receipt['backup_purged_by_admin_id'],
        ];
    }

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
        $transactionStarted = false;
        try {
            $expiredTokens = [];
            $domain_alias = self::formatDomainAlias((string)($params['domain_alias'] ?? ''));
            $expiredTime = empty($params['expired_time']) ? time() : strtotime((string)$params['expired_time']);
            if (false === $expiredTime) {
                throw new Exception('有效期格式错误');
            }
            $params["expired_time"] = $expiredTime;
            Db::startTrans();
            $transactionStarted = true;
            $tenant = Tenant::where('id', (int)$params['id'])->lock(true)->findOrEmpty();
            if ($tenant->isEmpty()) {
                throw new Exception('店铺不存在');
            }
            $authorizationChanged = (int)$tenant['disable'] !== (int)($params['disable'] ?? 0)
                || (int)$tenant['expired_time'] !== (int)$params['expired_time'];
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
            if ($authorizationChanged) {
                $adminIds = Db::name('tenant_admin')
                    ->where('tenant_id', (int)$params['id'])
                    ->whereNull('delete_time')
                    ->column('id');
                $expiredTokens = TenantSessionAuthorityService::expireAdminSessionsByIds($adminIds);
            }
            Db::commit();
            $transactionStarted = false;
            TenantSessionAuthorityService::clearTokenCaches($expiredTokens);
            if ($authorizationChanged) {
                TenantSessionAuthorityService::clearTenantAuthorizationCache((int)$params['id']);
            }
            return true;
        } catch (\Exception $e) {
            self::setError($e->getMessage());
            if ($transactionStarted) {
                Db::rollback();
            }
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
            $tokens = [];
            $adminIds = [];
            Db::transaction(function () use ($params, &$tokens, &$adminIds) {
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

            });
            TenantSessionAuthorityService::clearTokenCaches($tokens);
            foreach ($adminIds as $adminId) {
                TenantSessionAuthorityService::clearAuthorizationCache((int)$adminId, (int)$params['id']);
            }
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
            $tokens = [];
            $adminIds = [];
            Db::transaction(function () use ($params, &$tokens, &$adminIds) {
                $tenantId = (int)$params['id'];
                $permanentClosure = Db::name('tenant_closure_receipt')
                    ->where('tenant_id', $tenantId)
                    ->count();
                if ($permanentClosure > 0) {
                    throw new Exception('店铺已永久注销，不能恢复');
                }
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

                $tokens = TenantSessionAuthorityService::expireAdminSessionsByIds($adminIds);
            });
            TenantSessionAuthorityService::clearTokenCaches($tokens);
            foreach ($adminIds as $adminId) {
                TenantSessionAuthorityService::clearAuthorizationCache((int)$adminId, (int)$params['id']);
            }
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
