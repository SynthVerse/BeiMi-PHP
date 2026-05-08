<?php

declare(strict_types=1);

namespace app\common\service\jxc;

use app\common\model\tenant\Tenant;
use app\common\model\user\User;
use think\facade\Config;
use think\facade\Db;
use think\facade\Log;

/**
 * 租户预置服务
 *
 * 场景：
 *  - 微信小程序新用户首次登录时，为其自动创建独立租户（一人一租户）并初始化默认数据
 *  - CLI 批量为存量微信用户补建租户
 *
 * 注意：
 *  - 本服务中的所有写操作不假设存在外层事务，但建议由调用方包裹在事务中（mnpLogin 已有 Db::startTrans）
 *  - 对已有 tenant_id > 0 的用户直接返回现有 tenantId，仅补默认数据，幂等安全
 */
class TenantProvisionService
{
    /**
     * 为微信小程序用户预置租户 + 默认数据
     *
     * 流程：
     *   1. 若 $user->tenant_id > 0，直接返回，并补齐默认数据（幂等）
     *   2. 否则：新建 la_tenant -> 新建 la_tenant_admin(root,disable=1) -> 回写 user.tenant_id
     *   3. 调用 DefaultDataInitService::initForTenant()
     *
     * @param User $user 小程序端 la_user 模型（已落库，含 id/sn/nickname 等）
     * @param string|null $openid 微信 openid（用于租户名称展示，可为空）
     * @return int 最终使用的 tenantId（>0）
     */
    public static function provisionForWechatUser(User $user, ?string $openid = null): int
    {
        $userId = (int)$user->id;
        $existingTenantId = (int)($user->tenant_id ?? 0);

        if ($existingTenantId > 0) {
            DefaultDataInitService::initForTenant($existingTenantId);
            return $existingTenantId;
        }

        // 1. 创建租户
        $sn   = Tenant::createUserSn();
        $name = self::buildTenantName($openid, $user);
        $time = time();

        $tenantId = (int)Db::name('tenant')->insertGetId([
            'sn'                  => $sn,
            'name'                => $name,
            'avatar'              => '',
            'tel'                 => '',
            'domain_alias'        => '',
            'domain_alias_enable' => 0,
            'disable'             => 0,
            'notes'               => '微信小程序用户自动创建',
            'tactics'             => 0,
            'expired_time'        => $time,
            'create_time'         => $time,
            'update_time'         => $time,
        ]);

        if ($tenantId <= 0) {
            throw new \RuntimeException('创建租户失败');
        }

        // 2. 创建租户超管账号（仅占位，disable=1 禁用后台登录；微信用户通过 JWT/小程序 token 访问）
        $salt = (string)Config::get('project.unique_identification');
        $randomPwd = bin2hex(random_bytes(8));
        $hashedPwd = md5($salt . md5($randomPwd . $salt));

        Db::name('tenant_admin')->insert([
            'tenant_id'        => $tenantId,
            'root'             => 1,
            'name'             => $name,
            'avatar'           => '',
            'account'          => $sn,
            'password'         => $hashedPwd,
            'login_time'       => 0,
            'login_ip'         => '',
            'multipoint_login' => 1,
            'disable'          => 1,
            'create_time'      => $time,
            'update_time'      => $time,
            'delete_time'      => null,
        ]);

        // 3. 回写 la_user.tenant_id
        //    直接用 Db facade 绕过 BaseModel 钩子，确保 tenant_id 写入指定值
        Db::name('user')->where('id', $userId)->update([
            'tenant_id'   => $tenantId,
            'update_time' => $time,
        ]);
        $user->tenant_id = $tenantId;

        // 4. 初始化 JXC 默认数据
        DefaultDataInitService::initForTenant($tenantId);

        Log::info('[TenantProvision] wechat user provisioned', [
            'user_id'   => $userId,
            'openid'    => $openid,
            'tenant_id' => $tenantId,
            'tenant_sn' => $sn,
        ]);

        return $tenantId;
    }

    /**
     * 构造租户展示名
     */
    private static function buildTenantName(?string $openid, User $user): string
    {
        if (!empty($openid)) {
            return 'WX_' . substr($openid, -8);
        }
        if (!empty($user->nickname)) {
            return (string)$user->nickname;
        }
        return 'WX_' . (string)$user->sn;
    }
}
