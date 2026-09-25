<?php

namespace app\api\jxc\controller;

use app\api\jxc\logic\PurchasePlanLogic;
use app\api\jxc\validate\PurchasePlanValidate;

class PurchasePlanController extends BaseJxcController
{
    public function lists() { return $this->respond('lists', 'get'); }
    public function detail() { return $this->respond('detail', 'get'); }
    public function arrivalPreview() { return $this->respond('arrivalPreview', 'get'); }
    public function create() { return $this->respond('create'); }
    public function attachArrival() { return $this->respond('attachArrival'); }
    public function terminate() { return $this->respond('terminate'); }

    private function respond(string $action, string $method = 'post')
    {
        $validator = new PurchasePlanValidate();
        $params = $method === 'get' ? $validator->get()->goCheck($action) : $validator->post()->goCheck($action);
        $result = PurchasePlanLogic::$action($params);
        return $result === false
            ? $this->fail(PurchasePlanLogic::getError())
            : ($method === 'get' ? $this->data($result) : $this->success('操作成功', $result, 1, 1));
    }
}
