<?php

namespace app\api\jxc\controller;

use app\api\jxc\logic\WorkforceLogic;
use app\api\jxc\validate\WorkforceValidate;

class WorkforceController extends BaseJxcController
{
    public function permissionCatalog()
    {
        $result = WorkforceLogic::permissionCatalog();
        return $result === false ? $this->fail(WorkforceLogic::getError()) : $this->data($result);
    }

    public function employees() { return $this->read('employees'); }
    public function currentPermissions() { return $this->data(WorkforceLogic::currentPermissions()); }
    public function employee() { return $this->read('employee'); }
    public function saveEmployee() { return $this->write('saveEmployee'); }
    public function statusEmployee() { return $this->write('statusEmployee'); }
    public function processes() { return $this->read('processes'); }
    public function saveProcess() { return $this->write('saveProcess'); }
    public function statusProcess() { return $this->write('statusProcess'); }
    public function reorderProcesses() { return $this->write('reorderProcesses'); }
    public function deleteProcess() { return $this->write('deleteProcess'); }

    private function read(string $action)
    {
        $params = (new WorkforceValidate())->get()->goCheck($action);
        $result = WorkforceLogic::$action($params);
        return $result === false ? $this->fail(WorkforceLogic::getError()) : $this->data($result);
    }

    private function write(string $action)
    {
        $params = (new WorkforceValidate())->post()->goCheck($action);
        $result = WorkforceLogic::$action($params);
        return $result === false ? $this->fail(WorkforceLogic::getError()) : $this->success('操作成功', $result, 1, 1);
    }
}
