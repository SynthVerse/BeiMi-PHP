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
        return $this->data(AuthLogic::info($this->adminInfo));
    }

    public function logout()
    {
        AuthLogic::logout($this->adminInfo);
        return $this->success();
    }
}
