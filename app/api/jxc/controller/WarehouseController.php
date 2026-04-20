<?php

namespace app\api\jxc\controller;

use app\api\jxc\lists\WarehouseLists;
use app\api\jxc\logic\WarehouseLogic;
use app\api\jxc\validate\WarehouseValidate;

class WarehouseController extends BaseJxcController
{
    public function lists()
    {
        return $this->dataLists(WarehouseLists::class);
    }

    public function detail()
    {
        $params = (new WarehouseValidate())->goCheck('detail');
        return $this->data(WarehouseLogic::detail($params));
    }

    public function add()
    {
        $params = (new WarehouseValidate())->post()->goCheck('add');
        $result = WarehouseLogic::add($params);
        if ($result === false) {
            return $this->fail(WarehouseLogic::getError());
        }
        return $this->success('添加成功', $result, 1, 1);
    }

    public function edit()
    {
        $params = (new WarehouseValidate())->post()->goCheck('edit');
        $result = WarehouseLogic::edit($params);
        if ($result === false) {
            return $this->fail(WarehouseLogic::getError());
        }
        return $this->success('编辑成功', [], 1, 1);
    }

    public function delete()
    {
        $params = (new WarehouseValidate())->post()->goCheck('delete');
        $result = WarehouseLogic::delete($params);
        if ($result === false) {
            return $this->fail(WarehouseLogic::getError());
        }
        return $this->success('删除成功', [], 1, 1);
    }

    public function enable()
    {
        $params = (new WarehouseValidate())->post()->goCheck('status');
        $params['status'] = 1;
        $result = WarehouseLogic::changeStatus($params);
        if ($result === false) {
            return $this->fail(WarehouseLogic::getError());
        }
        return $this->success('启用成功', [], 1, 1);
    }

    public function disable()
    {
        $params = (new WarehouseValidate())->post()->goCheck('status');
        $params['status'] = 0;
        $result = WarehouseLogic::changeStatus($params);
        if ($result === false) {
            return $this->fail(WarehouseLogic::getError());
        }
        return $this->success('停用成功', [], 1, 1);
    }
}
