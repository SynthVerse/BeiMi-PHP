<?php

namespace app\api\jxc\controller;

use app\api\jxc\logic\StoreLogic;

class StoreController extends BaseJxcController
{
    public function detail()
    {
        $params = $this->request->get();
        return $this->data(StoreLogic::getStoreInfo($params));
    }

    public function setStore()
    {
        $params = $this->request->post();
        $result = StoreLogic::setStore($params);
        if ($result === false) {
            return $this->fail(StoreLogic::getError());
        }
        return $this->success('更新成功', $result, 1, 1);
    }

    public function createStore()
    {
        $params = $this->request->post();
        $result = StoreLogic::createStore($params);
        if ($result === false) {
            return $this->fail(StoreLogic::getError());
        }
        return $this->success('创建成功', $result, 1, 1);
    }
}
