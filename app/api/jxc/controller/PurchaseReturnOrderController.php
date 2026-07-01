<?php

namespace app\api\jxc\controller;

use app\api\jxc\lists\PurchaseReturnOrderLists;
use app\api\jxc\logic\PurchaseReturnOrderLogic;
use app\api\jxc\validate\PurchaseReturnOrderValidate;

class PurchaseReturnOrderController extends BaseJxcController
{
    public function lists()
    {
        return $this->dataLists(PurchaseReturnOrderLists::class);
    }

    public function detail()
    {
        $params = (new PurchaseReturnOrderValidate())->get()->goCheck('detail');
        $result = PurchaseReturnOrderLogic::detail($params);
        if ($result === false) {
            return $this->fail(PurchaseReturnOrderLogic::getError(), PurchaseReturnOrderLogic::getReturnData() ?: []);
        }
        return $this->data($result);
    }

    public function publish()
    {
        $params = (new PurchaseReturnOrderValidate())->post()->goCheck('publish');
        $result = PurchaseReturnOrderLogic::publish($params);
        if ($result === false) {
            return $this->fail(PurchaseReturnOrderLogic::getError(), PurchaseReturnOrderLogic::getReturnData() ?: []);
        }
        return $this->success('添加成功', $result, 1, 1);
    }

    public function edit()
    {
        $params = (new PurchaseReturnOrderValidate())->post()->goCheck('edit');
        $result = PurchaseReturnOrderLogic::edit($params);
        if ($result === false) {
            return $this->fail(PurchaseReturnOrderLogic::getError(), PurchaseReturnOrderLogic::getReturnData() ?: []);
        }
        return $this->success('编辑成功', $result, 1, 1);
    }

    public function remove()
    {
        $params = (new PurchaseReturnOrderValidate())->goCheck('remove');
        $result = PurchaseReturnOrderLogic::remove($params);
        if ($result === false) {
            return $this->fail(PurchaseReturnOrderLogic::getError(), PurchaseReturnOrderLogic::getReturnData() ?: []);
        }
        return $this->success('删除成功', $result, 1, 1);
    }
}
