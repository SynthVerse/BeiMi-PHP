<?php

namespace app\api\jxc\controller;

use app\api\jxc\lists\WorkTaskLists;
use app\api\jxc\logic\WorkTaskLogic;
use app\api\jxc\validate\WorkTaskValidate;

class TaskController extends BaseJxcController
{
    public function lists()
    {
        return $this->dataLists(WorkTaskLists::class);
    }

    public function detail()
    {
        $params = (new WorkTaskValidate())->get()->goCheck('detail');
        return $this->data(WorkTaskLogic::detail($params));
    }

    public function create()
    {
        $params = (new WorkTaskValidate())->post()->goCheck('create');
        $result = WorkTaskLogic::create($params);
        if ($result === false) {
            return $this->fail(WorkTaskLogic::getError(), WorkTaskLogic::getReturnData() ?: []);
        }
        return $this->success('创建成功', $result, 1, 1);
    }

    public function edit()
    {
        $params = (new WorkTaskValidate())->post()->goCheck('edit');
        return $this->writeResult(WorkTaskLogic::edit($params), '编辑成功');
    }

    public function assign()
    {
        $params = (new WorkTaskValidate())->post()->goCheck('assign');
        return $this->writeResult(WorkTaskLogic::assign($params), '分配成功');
    }

    public function start()
    {
        $params = (new WorkTaskValidate())->post()->goCheck('action');
        return $this->writeResult(WorkTaskLogic::start($params), '开始成功');
    }

    public function complete()
    {
        $params = (new WorkTaskValidate())->post()->goCheck('action');
        return $this->writeResult(WorkTaskLogic::complete($params), '完成成功');
    }

    public function cancel()
    {
        $params = (new WorkTaskValidate())->post()->goCheck('action');
        return $this->writeResult(WorkTaskLogic::cancel($params), '取消成功');
    }

    private function writeResult(array|false $result, string $message)
    {
        if ($result === false) {
            return $this->fail(WorkTaskLogic::getError(), WorkTaskLogic::getReturnData() ?: []);
        }
        return $this->success($message, $result, 1, 1);
    }
}
