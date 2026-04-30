<?php

namespace app\api\jxc\controller;

use app\api\jxc\lists\SupplierLists;
use app\api\jxc\logic\SupplierLogic;
use app\api\jxc\validate\SupplierValidate;

class SupplierController extends BaseJxcController
{
    public function lists()
    {
        return $this->dataLists(SupplierLists::class);
    }

    public function add()
    {
        $params = (new SupplierValidate())->post()->goCheck('add');
        $result = SupplierLogic::add($params);
        if ($result === false) {
            return $this->fail(SupplierLogic::getError());
        }
        return $this->success('添加成功', $result, 1, 1);
    }

    public function edit()
    {
        $params = (new SupplierValidate())->post()->goCheck('edit');
        $result = SupplierLogic::edit($params);
        if ($result === false) {
            return $this->fail(SupplierLogic::getError());
        }
        return $this->success('编辑成功', [], 1, 1);
    }

    public function delete()
    {
        $params = (new SupplierValidate())->goCheck('delete');
        $result = SupplierLogic::delete($params);
        if ($result === false) {
            return $this->fail(SupplierLogic::getError());
        }
        return $this->success('删除成功', [], 1, 1);
    }

    public function detail()
    {
        $params = (new SupplierValidate())->goCheck('detail');
        return $this->data(SupplierLogic::detail($params));
    }

    public function paymoney()
    {
        $params = (new SupplierValidate())->post()->goCheck('paymoney');
        $result = SupplierLogic::paymoney($params);
        if ($result === false) {
            return $this->fail(SupplierLogic::getError());
        }
        return $this->success('付款成功', $result, 1, 1);
    }
}
