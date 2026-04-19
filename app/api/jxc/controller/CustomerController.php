<?php

namespace app\api\jxc\controller;

use app\api\jxc\lists\CustomerLists;
use app\api\jxc\logic\CustomerLogic;
use app\api\jxc\validate\CustomerValidate;

class CustomerController extends BaseJxcController
{
    public function lists()
    {
        return $this->dataLists(CustomerLists::class);
    }

    public function detail()
    {
        $params = (new CustomerValidate())->get()->goCheck('detail');
        return $this->data(CustomerLogic::detail($params));
    }

    public function add()
    {
        $params = (new CustomerValidate())->post()->goCheck('add');
        $result = CustomerLogic::add($params);
        if ($result === false) {
            return $this->fail(CustomerLogic::getError());
        }
        return $this->success('添加成功', $result, 1, 1);
    }

    public function edit()
    {
        $params = (new CustomerValidate())->post()->goCheck('edit');
        $result = CustomerLogic::edit($params);
        if ($result === false) {
            return $this->fail(CustomerLogic::getError());
        }
        return $this->success('更新成功', $result, 1, 1);
    }

    public function delete()
    {
        $params = (new CustomerValidate())->goCheck('delete');
        $result = CustomerLogic::delete($params);
        if ($result === false) {
            return $this->fail(CustomerLogic::getError());
        }
        return $this->success('删除成功', $result, 1, 1);
    }

    public function children()
    {
        $params = (new CustomerValidate())->get()->goCheck('children');
        return $this->success('', CustomerLogic::children($params));
    }

    public function summary()
    {
        $params = (new CustomerValidate())->get()->goCheck('summary');
        return $this->data(CustomerLogic::summary($params));
    }

    public function search()
    {
        return $this->dataLists(CustomerLists::class);
    }

    public function bindStore()
    {
        $params = (new CustomerValidate())->post()->goCheck('bindStore');
        $result = CustomerLogic::bindStore($params);
        if ($result === false) {
            return $this->fail(CustomerLogic::getError());
        }
        return $this->success('绑定成功', $result, 1, 1);
    }

    public function unbindStore()
    {
        $params = (new CustomerValidate())->post()->goCheck('unbindStore');
        $result = CustomerLogic::unbindStore($params);
        if ($result === false) {
            return $this->fail(CustomerLogic::getError());
        }
        return $this->success('解绑成功', $result, 1, 1);
    }

    public function assignGroup()
    {
        $params = (new CustomerValidate())->post()->goCheck('assignGroup');
        $result = CustomerLogic::assignGroup($params);
        if ($result === false) {
            return $this->fail(CustomerLogic::getError());
        }
        return $this->success('调整成功', $result, 1, 1);
    }

    public function status()
    {
        $params = (new CustomerValidate())->post()->goCheck('status');
        $result = CustomerLogic::setStatus($params);
        if ($result === false) {
            return $this->fail(CustomerLogic::getError());
        }
        return $this->success('状态更新成功', $result, 1, 1);
    }

    public function paymoney()
    {
        $params = (new CustomerValidate())->post()->goCheck('paymoney');
        $result = CustomerLogic::paymoney($params);
        if ($result === false) {
            return $this->fail(CustomerLogic::getError());
        }
        return $this->success('付款成功', $result, 1, 1);
    }

    public function salesHistory()
    {
        $params = (new CustomerValidate())->get()->goCheck('salesHistory');
        return $this->data(CustomerLogic::salesHistory($params));
    }

    public function receivableSummary()
    {
        $params = (new CustomerValidate())->get()->goCheck('receivableSummary');
        return $this->data(CustomerLogic::receivableSummary($params));
    }
}
