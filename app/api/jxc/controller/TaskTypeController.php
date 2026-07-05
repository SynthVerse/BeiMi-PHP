<?php

namespace app\api\jxc\controller;

use app\api\jxc\lists\TaskTypeLists;
use app\api\jxc\logic\TaskTypeLogic;
use app\api\jxc\validate\TaskTypeValidate;

class TaskTypeController extends BaseJxcController
{
    public function lists()
    {
        return $this->dataLists(TaskTypeLists::class);
    }

    public function detail()
    {
        $params = (new TaskTypeValidate())->get()->goCheck('detail');
        return $this->data(TaskTypeLogic::detail($params));
    }

    public function create()
    {
        $params = (new TaskTypeValidate())->post()->goCheck('create');
        return $this->writeResult(TaskTypeLogic::create($params), '创建成功');
    }

    public function edit()
    {
        $params = (new TaskTypeValidate())->post()->goCheck('edit');
        return $this->writeResult(TaskTypeLogic::edit($params), '编辑成功');
    }

    public function status()
    {
        $params = (new TaskTypeValidate())->post()->goCheck('status');
        return $this->writeResult(TaskTypeLogic::status($params), '状态更新成功');
    }

    private function writeResult(array|false $result, string $message)
    {
        if ($result === false) {
            return $this->fail(TaskTypeLogic::getError(), TaskTypeLogic::getReturnData() ?: []);
        }
        return $this->success($message, $result, 1, 1);
    }
}
