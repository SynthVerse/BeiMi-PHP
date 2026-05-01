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

namespace app\api\logic;

use app\common\logic\BaseLogic;
use app\common\enum\LoginEnum;
use app\common\enum\user\UserTerminalEnum;
use app\common\model\user\User;
use app\common\service\ConfigService;
use app\common\service\FileService;
use app\api\service\{UserTokenService, WechatUserService};
use app\common\enum\{YesNoEnum};
use app\common\service\{
    wechat\WeChatMnpService
};
use think\facade\{Db, Config, Log};

/**
 * 登录逻辑
 * Class LoginLogic
 * @package app\api\logic
 */
class LoginLogic extends BaseLogic
{

    /**
     * @notes 账号密码注册
     * @param array $params
     * @return bool
     * @author 段誉
     * @date 2022/9/7 15:37
     */
    public static function register(array $params)
    {
        try {
            $userSn = User::createUserSn();
            $passwordSalt = Config::get('project.unique_identification');
            $password = create_password($params['password'], $passwordSalt);
            $avatar = ConfigService::get('default_image', 'user_avatar');

            User::create([
                'sn' => $userSn,
                'tenant_id' => request()->tenantId,
                'avatar' => $avatar,
                'nickname' => '用户' . $userSn,
                'account' => $params['account'],
                'password' => $password,
                'channel' => $params['channel'],
            ]);

            return true;
        } catch (\Exception $e) {
            self::setError($e->getMessage());
            return false;
        }
    }


    /**
     * @notes 账号/手机号登录，手机号验证码
     * @param $params
     * @return array|false
     * @author 段誉
     * @date 2022/9/6 19:26
     */
    public static function login($params)
    {
        try {
            // 账号/手机号 密码登录
            $where = ['account|mobile' => $params['account']];
            if ($params['scene'] == LoginEnum::MOBILE_CAPTCHA) {
                //手机验证码登录
                $where = ['mobile' => $params['account']];
            }

            $user = User::where($where)->findOrEmpty();
            if ($user->isEmpty()) {
                throw new \Exception('用户不存在');
            }

            //更新登录信息
            $user->login_time = time();
            $user->login_ip = request()->ip();
            $user->save();

            //设置token
            $userInfo = UserTokenService::setToken($user, $params['terminal']);

            //返回登录信息
            $avatar = $user->avatar ?: Config::get('project.default_image.user_avatar');
            $avatar = FileService::getFileUrl($avatar);

            return [
                'nickname' => $userInfo['nickname'],
                'sn' => $userInfo['sn'],
                'mobile' => $userInfo['mobile'],
                'avatar' => $avatar,
                'token' => $userInfo['token'],
            ];
        } catch (\Exception $e) {
            self::setError($e->getMessage());
            return false;
        }
    }


    /**
     * @notes 退出登录
     * @param $userInfo
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author 段誉
     * @date 2022/9/16 17:56
     */
    public static function logout($userInfo)
    {
        //token不存在，不注销
        if (!isset($userInfo['token'])) {
            return false;
        }

        //设置token过期
        return UserTokenService::expireToken($userInfo['token']);
    }


    /**
     * @notes 小程序-授权登录
     * @param array $params
     * @return array|false
     * @author 段誉
     * @date 2022/9/20 19:47
     */
    public static function mnpLogin(array $params)
    {
        $ip = request()->ip();
        Db::startTrans();
        try {
            //通过code获取微信 openid
            $response = (new WeChatMnpService())->getMnpResByCode($params['code']);
            $userServer = new WechatUserService($response, UserTerminalEnum::WECHAT_MMP);
            $userInfo = $userServer->getResopnseByUserInfo()->authUserLogin()->getUserInfo();

            // 更新登录信息
            self::updateLoginInfo($userInfo['id']);

            Db::commit();

            // 登录审计日志
            Log::info('[mnpLogin] success', [
                'user_id' => $userInfo['id'] ?? null,
                'openid' => $response['openid'] ?? null,
                'ip' => $ip,
            ]);

            return $userInfo;
        } catch (\Exception  $e) {
            Db::rollback();
            Log::error('[mnpLogin] fail', [
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);
            self::$error = $e->getMessage();
            return false;
        }
    }


    /**
     * @notes 更新登录信息
     * @param $userId
     * @throws \Exception
     * @author 段誉
     * @date 2022/9/20 19:46
     */
    public static function updateLoginInfo($userId)
    {
        $user = User::findOrEmpty($userId);
        if ($user->isEmpty()) {
            throw new \Exception('用户不存在');
        }

        $time = time();
        $user->login_time = $time;
        $user->login_ip = request()->ip();
        $user->update_time = $time;
        $user->save();
    }


    /**
     * @notes 更新用户信息
     * @param $params
     * @param $userId
     * @return User
     * @author 段誉
     * @date 2023/2/22 11:19
     */
    public static function updateUser($params, $userId)
    {
        return User::where(['id' => $userId])->update([
            'nickname' => $params['nickname'],
            'avatar' => FileService::setFileUrl($params['avatar']),
            'is_new_user' => YesNoEnum::NO
        ]);
    }
}