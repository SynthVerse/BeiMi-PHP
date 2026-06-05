<?php

namespace app\api\jxc\controller;

use app\api\jxc\logic\AuthLogic;
use app\common\enum\AdminTerminalEnum;
use app\tenantapi\validate\LoginValidate;

class AuthController extends BaseJxcController
{
    public array $notNeedLogin = ['login'];

    public function login()
    {
        $params = array_merge($this->request->post(), [
            'terminal' => AdminTerminalEnum::MOBILE,
        ]);
        $this->request->withPost($params);
        $params = (new LoginValidate())->post()->goCheck();
        $result = AuthLogic::login($params);
        return $this->success('登录成功', $result, 1, 0);
    }

    public function info()
    {
        $this->refreshIdentityContext();

        // 如果中间件未能设置身份，尝试直接从 token 重新验证
        if ($this->adminId <= 0 && (int)($this->adminInfo['user_id'] ?? 0) <= 0) {
            $token = $this->request->header('token');
            if (!empty($token)) {
                $userInfo = (new \app\common\cache\UserTokenCache())->getUserInfo($token);
                if (!empty($userInfo) && !empty($userInfo['user_id'])) {
                    $this->adminInfo = [
                        'admin_id'    => $userInfo['user_id'],
                        'user_id'     => $userInfo['user_id'],
                        'tenant_id'   => $userInfo['tenant_id'] ?? 0,
                        'root'        => 0,
                        'name'        => $userInfo['nickname'] ?? '',
                        'account'     => $userInfo['mobile'] ?? '',
                        'role_name'   => '',
                        'role_id'     => [],
                        'token'       => $token,
                        'terminal'    => $userInfo['terminal'] ?? '',
                        'expire_time' => $userInfo['expire_time'] ?? 0,
                    ];
                    $this->adminId = (int)$userInfo['user_id'];
                }
            }
        }

        if ($this->adminId <= 0 && (int)($this->adminInfo['user_id'] ?? 0) <= 0) {
            return $this->fail('登录超时，请重新登录', [], -1, 0);
        }

        return $this->data(AuthLogic::info($this->adminInfo));
    }

    public function logout()
    {
        $this->refreshIdentityContext();
        AuthLogic::logout($this->adminInfo);
        return $this->success();
    }
}
