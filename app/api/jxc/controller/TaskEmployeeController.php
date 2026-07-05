<?php

namespace app\api\jxc\controller;

use app\api\jxc\lists\TaskEmployeeLists;
use app\api\jxc\logic\TaskEmployeeLogic;
use app\api\jxc\validate\TaskEmployeeValidate;

class TaskEmployeeController extends BaseJxcController
{
    public function lists()
    {
        return $this->dataLists(TaskEmployeeLists::class);
    }

    public function detail()
    {
        $params = (new TaskEmployeeValidate())->get()->goCheck('detail');
        return $this->data(TaskEmployeeLogic::detail($params));
    }

    public function create()
    {
        $params = (new TaskEmployeeValidate())->post()->goCheck('create');
        return $this->writeResult(TaskEmployeeLogic::create($params), '创建成功');
    }

    public function edit()
    {
        $params = (new TaskEmployeeValidate())->post()->goCheck('edit');
        return $this->writeResult(TaskEmployeeLogic::edit($params), '编辑成功');
    }

    public function status()
    {
        $params = (new TaskEmployeeValidate())->post()->goCheck('status');
        return $this->writeResult(TaskEmployeeLogic::status($params), '状态更新成功');
    }

    private function writeResult(array|false $result, string $message)
    {
        if ($result === false) {
            return $this->fail(TaskEmployeeLogic::getError(), TaskEmployeeLogic::getReturnData() ?: []);
        }
        return $this->success($message, $result, 1, 1);
    }
}
