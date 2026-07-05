<?php

namespace app\api\jxc\controller;

use app\api\jxc\lists\TaskRoleLists;
use app\api\jxc\logic\TaskRoleLogic;
use app\api\jxc\validate\TaskRoleValidate;

class TaskRoleController extends BaseJxcController
{
    public function lists()
    {
        return $this->dataLists(TaskRoleLists::class);
    }

    public function detail()
    {
        $params = (new TaskRoleValidate())->get()->goCheck('detail');
        return $this->data(TaskRoleLogic::detail($params));
    }

    public function create()
    {
        $params = (new TaskRoleValidate())->post()->goCheck('create');
        return $this->writeResult(TaskRoleLogic::create($params), '创建成功');
    }

    public function edit()
    {
        $params = (new TaskRoleValidate())->post()->goCheck('edit');
        return $this->writeResult(TaskRoleLogic::edit($params), '编辑成功');
    }

    public function status()
    {
        $params = (new TaskRoleValidate())->post()->goCheck('status');
        return $this->writeResult(TaskRoleLogic::status($params), '状态更新成功');
    }

    private function writeResult(array|false $result, string $message)
    {
        if ($result === false) {
            return $this->fail(TaskRoleLogic::getError(), TaskRoleLogic::getReturnData() ?: []);
        }
        return $this->success($message, $result, 1, 1);
    }
}
