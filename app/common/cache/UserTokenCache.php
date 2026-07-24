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
        // Sessions are revocable. Never authorize a cache hit without first
        // confirming that the session is still live in the source of truth.
        $session = Db::table('la_user_session')
            ->where('token', $token)
            ->where('expire_time', '>', time())
            ->find();
        if (empty($session)) {
            $this->deleteUserInfo($token);
            return false;
        }

        $userInfo = $this->get($this->prefix . $token);
        if ($userInfo
            && (int)($userInfo['user_id'] ?? 0) === (int)$session['user_id']
            && (int)($userInfo['expire_time'] ?? 0) === (int)$session['expire_time']) {
            return $userInfo;
        }

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

    /**
     * Revoke every user-terminal session after membership authority is removed.
     * Database revocation is authoritative; cache deletion is only cleanup.
     */
    public static function revokeUserSessions(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $time = time();
        $tokens = Db::table('la_user_session')->where('user_id', $userId)->column('token');
        Db::table('la_user_session')->where('user_id', $userId)->update([
            'expire_time' => $time,
            'update_time' => $time,
        ]);

        $cache = new self();
        foreach ($tokens as $token) {
            $cache->deleteUserInfo((string)$token);
        }
    }
}
