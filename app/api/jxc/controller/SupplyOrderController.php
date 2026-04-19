<?php

namespace app\api\jxc\controller;

use app\api\jxc\lists\SupplyOrderLists;
use app\api\jxc\logic\SupplyOrderLogic;
use app\api\jxc\validate\SupplyOrderValidate;

class SupplyOrderController extends BaseJxcController
{
    public function lists()
    {
        return $this->dataLists(SupplyOrderLists::class);
    }

    public function detail()
    {
        $params = (new SupplyOrderValidate())->get()->goCheck('detail');
        return $this->data(SupplyOrderLogic::detail($params));
    }

    public function publish()
    {
        $params = (new SupplyOrderValidate())->post()->goCheck('publish');
        $result = SupplyOrderLogic::publish($params);
        if ($result === false) {
            return $this->fail(SupplyOrderLogic::getError());
        }
        return $this->success('添加成功', $result, 1, 1);
    }

    public function edit()
    {
        $params = (new SupplyOrderValidate())->post()->goCheck('edit');
        $result = SupplyOrderLogic::edit($params);
        if ($result === false) {
            return $this->fail(SupplyOrderLogic::getError());
        }
        return $this->success('编辑成功', $result, 1, 1);
    }

    public function remove()
    {
        $params = (new SupplyOrderValidate())->goCheck('remove');
        $result = SupplyOrderLogic::remove($params);
        if ($result === false) {
            return $this->fail(SupplyOrderLogic::getError());
        }
        return $this->success('删除成功', $result, 1, 1);
    }

    public function statistics()
    {
        $params = (new SupplyOrderValidate())->get()->goCheck('statistics');
        return $this->data(SupplyOrderLogic::statistics($params));
    }
}
