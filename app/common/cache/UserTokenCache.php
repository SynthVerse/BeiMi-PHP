<?php
// +----------------------------------------------------------------------
// | likeadmin快速开发前后端分离管理后台（PHP版）
// +----------------------------------------------------------------------
// | 欢迎阅读学习系统程序代码，建议反馈是我们前进的动力
// | 开源版本可自由商用，可去除界面版权logo
// | gitee下载：https://gitee.com/likeshop_gitee/likeadmin
// | github下载：https://github.com/likeshop-github/likeadmin
// | 访问官网：https://www.likeadmin.cn
// | likeadmin团队 版权所有 拥有最终解释权
// +----------------------------------------------------------------------
// | author: likeadminTeam
// +----------------------------------------------------------------------


namespace app\common\cache;

use app\common\cache\BaseCache;
use app\common\service\FileService;
use think\facade\Db;
use think\facade\Log;

class UserTokenCache extends BaseCache
{

    private $prefix = 'token_user_';


    /**
     * @notes 通过token获取缓存用户信息
     * @param $token
     * @return array|false|mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author 段誉
     * @date 2022/9/16 10:11
     */
    public function getUserInfo($token)
    {
        //直接从缓存获取
        $userInfo = $this->get($this->prefix . $token);
        if ($userInfo) {
            return $userInfo;
        }

        //从数据获取信息被设置缓存(可能后台清除缓存）
        $userInfo = $this->setUserInfo($token);
        if ($userInfo) {
            return $userInfo;
        }

        return false;
    }


    /**
     * @notes 通过有效token设置用户信息缓存
     * @param $token
     * @return array|false|mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author 段誉
     * @date 2022/9/16 10:11
     */
    public function setUserInfo($token)
    {
        try {
            // 使用 Db::table 直接查询，完全绕过 Model 层（scopes/events/SoftDelete）
            $userSession = Db::table('la_user_session')
                ->where('token', $token)
                ->where('expire_time', '>', time())
                ->find();

            if (empty($userSession)) {
                Log::info('[UserTokenCache] session not found', ['token_prefix' => substr($token, 0, 8)]);
                return [];
            }

            $user = Db::table('la_user')
                ->where('id', $userSession['user_id'])
                ->whereNull('delete_time')
                ->find();

            if (empty($user)) {
                Log::info('[UserTokenCache] user not found', ['user_id' => $userSession['user_id']]);
                return [];
            }

            $userInfo = [
                'user_id'     => (int)$user['id'],
                'tenant_id'   => (int)($user['tenant_id'] ?? 0),
                'nickname'    => $user['nickname'] ?? '',
                'token'       => $token,
                'sn'          => $user['sn'] ?? '',
                'mobile'      => $user['mobile'] ?? '',
                'avatar'      => trim($user['avatar'] ?? '') ? FileService::getFileUrl($user['avatar']) : '',
                'terminal'    => (int)$userSession['terminal'],
                'expire_time' => (int)$userSession['expire_time'],
            ];

            $ttl = max((int)$userSession['expire_time'] - time(), 60);
            $this->set($this->prefix . $token, $userInfo, $ttl);

            return $userInfo;
        } catch (\Throwable $e) {
            Log::error('[UserTokenCache] setUserInfo exception', [
                'token_prefix' => substr($token, 0, 8),
                'error' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);
            return [];
        }
    }


    /**
     * @notes 删除缓存
     * @param $token
     * @return bool
     * @author 段誉
     * @date 2022/9/16 10:13
     */
    public function deleteUserInfo($token)
    {
        return $this->delete($this->prefix . $token);
    }
}