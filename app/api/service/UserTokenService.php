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


namespace app\api\service;

use app\common\cache\UserTokenCache;
use think\facade\Config;
use think\facade\Db;

class UserTokenService
{

    /**
     * @notes 设置或更新用户token
     * @param $userId
     * @param $terminal
     * @return array|false|mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author 段誉
     * @date 2022/9/16 10:10
     */
    public static function setToken($user, $terminal)
    {
        $time = time();
        $userSession = Db::table('la_user_session')
            ->where('user_id', $user->id)
            ->where('terminal', $terminal)
            ->find();

        $expireTime = $time + Config::get('project.user_token.expire_duration');
        $userTokenCache = new UserTokenCache();

        if ($userSession) {
            $userTokenCache->deleteUserInfo($userSession['token']);
            $token = ((int)$userSession['expire_time'] <= $time)
                ? create_token($user->id)
                : $userSession['token'];

            Db::table('la_user_session')
                ->where('id', $userSession['id'])
                ->update([
                    'token' => $token,
                    'expire_time' => $expireTime,
                    'update_time' => $time,
                ]);
        } else {
            $token = create_token($user->id);
            Db::table('la_user_session')->insert([
                'user_id' => $user->id,
                'terminal' => $terminal,
                'token' => $token,
                'expire_time' => $expireTime,
                'update_time' => $time,
                'create_time' => $time,
                'tenant_id' => 0,
            ]);
        }

        return $userTokenCache->setUserInfo($token);
    }


    /**
     * @notes 延长token过期时间
     * @param $token
     * @return array|false|mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author 段誉
     * @date 2022/9/16 10:10
     */
    public static function overtimeToken($token)
    {
        $time = time();
        $userSession = Db::table('la_user_session')->where('token', $token)->find();
        if (empty($userSession)) {
            return false;
        }

        $expireTime = $time + Config::get('project.user_token.expire_duration');
        Db::table('la_user_session')
            ->where('id', $userSession['id'])
            ->update([
                'expire_time' => $expireTime,
                'update_time' => $time,
            ]);

        return (new UserTokenCache())->setUserInfo($token);
    }


    /**
     * @notes 设置token为过期
     * @param $token
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author 段誉
     * @date 2022/9/16 10:10
     */
    public static function expireToken($token)
    {
        $userSession = Db::table('la_user_session')->where('token', $token)->find();
        if (empty($userSession)) {
            return false;
        }

        $time = time();
        Db::table('la_user_session')
            ->where('id', $userSession['id'])
            ->update([
                'expire_time' => $time,
                'update_time' => $time,
            ]);

        return (new UserTokenCache())->deleteUserInfo($token);
    }

}
