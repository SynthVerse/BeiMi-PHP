<?php

namespace app\api\jxc\controller;

use app\api\jxc\lists\CustomerGroupLists;
use app\api\jxc\logic\CustomerGroupLogic;
use app\api\jxc\validate\CustomerGroupValidate;

class CustomerGroupController extends BaseJxcController
{
    public function lists()
    {
        return $this->dataLists(CustomerGroupLists::class);
    }

    public function add()
    {
        $params = (new CustomerGroupValidate())->post()->goCheck('add');
        $result = CustomerGroupLogic::add($params);
        if ($result === false) {
            return $this->fail(CustomerGroupLogic::getError());
        }
        return $this->success('创建成功', $result, 1, 1);
    }

    public function detail()
    {
        $params = (new CustomerGroupValidate())->get()->goCheck('detail');
        $result = CustomerGroupLogic::detail($params);
        return $this->success('', $result);
    }

    public function rename()
    {
        $params = (new CustomerGroupValidate())->post()->goCheck('rename');
        $result = CustomerGroupLogic::rename($params);
        if ($result === false) {
            return $this->fail(CustomerGroupLogic::getError());
        }
        return $this->success('重命名成功', $result, 1, 1);
    }

    public function delete()
    {
        $params = (new CustomerGroupValidate())->post()->goCheck('delete');
        $result = CustomerGroupLogic::delete($params);
        if ($result === false) {
            return $this->fail(CustomerGroupLogic::getError());
        }
        return $this->success('删除成功', $result, 1, 1);
    }
}
