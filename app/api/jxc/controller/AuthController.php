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
