<?php

namespace app\api\jxc\controller;

use app\api\jxc\lists\SalesReturnOrderLists;
use app\api\jxc\logic\SalesReturnOrderLogic;
use app\api\jxc\validate\SalesReturnOrderValidate;

class SalesReturnOrderController extends BaseJxcController
{
    public function lists()
    {
        return $this->dataLists(SalesReturnOrderLists::class);
    }

    public function detail()
    {
        $params = (new SalesReturnOrderValidate())->get()->goCheck('detail');
        return $this->data(SalesReturnOrderLogic::detail($params));
    }

    public function publish()
    {
        $params = (new SalesReturnOrderValidate())->post()->goCheck('publish');
        $result = SalesReturnOrderLogic::publish($params);
        if ($result === false) {
            return $this->fail(SalesReturnOrderLogic::getError(), SalesReturnOrderLogic::getReturnData() ?: []);
        }
        return $this->success('添加成功', $result, 1, 1);
    }

    public function edit()
    {
        $params = (new SalesReturnOrderValidate())->post()->goCheck('edit');
        $result = SalesReturnOrderLogic::edit($params);
        if ($result === false) {
            return $this->fail(SalesReturnOrderLogic::getError(), SalesReturnOrderLogic::getReturnData() ?: []);
        }
        return $this->success('编辑成功', $result, 1, 1);
    }

    public function remove()
    {
        $params = (new SalesReturnOrderValidate())->goCheck('remove');
        $result = SalesReturnOrderLogic::remove($params);
        if ($result === false) {
            return $this->fail(SalesReturnOrderLogic::getError(), SalesReturnOrderLogic::getReturnData() ?: []);
        }
        return $this->success('删除成功', $result, 1, 1);
    }

    public function statistics()
    {
        $params = (new SalesReturnOrderValidate())->get()->goCheck('statistics');
        return $this->data(SalesReturnOrderLogic::statistics($params));
    }
}
