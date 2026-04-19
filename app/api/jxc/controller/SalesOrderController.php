<?php

namespace app\api\jxc\controller;

use app\api\jxc\lists\SalesOrderLists;
use app\api\jxc\logic\SalesOrderLogic;
use app\api\jxc\validate\SalesOrderValidate;

class SalesOrderController extends BaseJxcController
{
    public function lists()
    {
        return $this->dataLists(SalesOrderLists::class);
    }

    public function detail()
    {
        $params = (new SalesOrderValidate())->get()->goCheck('detail');
        return $this->data(SalesOrderLogic::detail($params));
    }

    public function publish()
    {
        $params = (new SalesOrderValidate())->post()->goCheck('publish');
        $result = SalesOrderLogic::publish($params);
        if ($result === false) {
            return $this->fail(SalesOrderLogic::getError());
        }
        return $this->success('添加成功', $result, 1, 1);
    }

    public function edit()
    {
        $params = (new SalesOrderValidate())->post()->goCheck('edit');
        $result = SalesOrderLogic::edit($params);
        if ($result === false) {
            return $this->fail(SalesOrderLogic::getError());
        }
        return $this->success('编辑成功', $result, 1, 1);
    }

    public function remove()
    {
        $params = (new SalesOrderValidate())->goCheck('remove');
        $result = SalesOrderLogic::remove($params);
        if ($result === false) {
            return $this->fail(SalesOrderLogic::getError());
        }
        return $this->success('删除成功', $result, 1, 1);
    }

    public function statistics()
    {
        $params = (new SalesOrderValidate())->get()->goCheck('statistics');
        return $this->data(SalesOrderLogic::statistics($params));
    }
}
