<?php
namespace app\platformapi\logic\tenant;

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
        $domain_alias = preg_replace('/^https?:\/\/|\/$/', '', $params['domain_alias']);
        $sn = $params['host_name'] ?? Tenant::createUserSn();
        $exists = (new Tenant())->where('sn', $sn)->find();
        if (!empty($exists)) {
            throw new Exception('主机名已被占用，请更换');
        }
        return Tenant::create([
            'sn'                  => $sn,
            'name'                => $params['name'],
            'avatar'              => $params['avatar'],
            'tel'                 => $params['tel'],
            'domain_alias'        => $domain_alias,
            'domain_alias_enable' => $params['domain_alias_enable'],
            'disable'             => $params['disable'] ?? 0,
            'notes'               => $params['notes'] ?? '',
            'tactics'             => $params['tactics'] ?? 0,
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

            $domain = request()->domain();
            $user['default_domain'] = $domain . '/admin/';
            $user['domain'] = $domain . '/admin/';
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
            $domain_alias = preg_replace('/^https?:\/\/|\/$/', '', $params['domain_alias']);
            $params["expired_time"] = strtotime($params["expired_time"]);
            Tenant::update([
                'name'                => $params['name'],
                'avatar'              => $params['avatar'],
                'disable'             => $params['disable'] ?? 0,
                'tel'                 => $params['tel'],
                'expired_time'        => $params['expired_time'],
                'domain_alias'        => $domain_alias,
                'domain_alias_enable' => $params['domain_alias_enable'],
                'notes'               => $params['notes'] ?? '',
            ], ['id' => $params['id']]);
            return true;
        } catch (\Exception $e) {
            self::setError($e->getMessage());
            return false;
        }
    }

    /**
     * @notes 删除租户
     * @param array $params
     * @return bool
     * @author JXDN
     * @date 2024/09/03 17:04
     */
    public static function delete(array $params)
    {
        try {
            Tenant::destroy($params['id']);
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
}
