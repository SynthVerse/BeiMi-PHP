<?php

namespace app\api\jxc\controller;

use app\api\jxc\logic\TaskCenterService;
use app\api\jxc\validate\TaskCenterValidate;

class TaskController extends BaseJxcController
{
    public function dashboard()
    {
        return $this->data(TaskCenterService::dashboard($this->request->get()));
    }

    public function reservationsSelect()
    {
        return $this->data(TaskCenterService::reservationsSelect($this->request->get()));
    }

    public function reservationsPreview()
    {
        $params = (new TaskCenterValidate())->post()->goCheck('preview');
        return $this->data(TaskCenterService::preview($params));
    }

    public function items()
    {
        return $this->data(TaskCenterService::items($this->request->get()));
    }

    public function assignmentSave()
    {
        $params = (new TaskCenterValidate())->post()->goCheck('assignmentSave');
        return $this->success('保存成功', TaskCenterService::saveAssignment($params), 1, 1);
    }

    public function employeeBoard()
    {
        return $this->data(TaskCenterService::employeeBoard($this->request->get()));
    }

    public function procurementShortage()
    {
        return $this->data(TaskCenterService::procurementShortage($this->request->get()));
    }

    public function printData()
    {
        $params = (new TaskCenterValidate())->post()->goCheck('printData');
        return $this->data(TaskCenterService::printData($params));
    }

    public function status()
    {
        $params = (new TaskCenterValidate())->post()->goCheck('status');
        $result = TaskCenterService::status($params);
        if ($result === false) {
            return $this->fail('任务状态不允许操作', ['error_code' => 'TASK_STATUS_INVALID']);
        }
        return $this->success('更新成功', $result, 1, 1);
    }
}
