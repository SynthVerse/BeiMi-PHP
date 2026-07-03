<?php

namespace app\api\jxc\controller;

use app\api\jxc\lists\ProcurementTaskLists;
use app\api\jxc\logic\ProcurementTaskLogic;
use app\api\jxc\validate\ProcurementTaskValidate;

class ProcurementTaskController extends BaseJxcController
{
    public function lists()
    {
        return $this->dataLists(ProcurementTaskLists::class);
    }

    public function detail()
    {
        $params = (new ProcurementTaskValidate())->get()->goCheck('detail');
        return $this->data(ProcurementTaskLogic::detail($params));
    }

    public function manualCreate()
    {
        $params = (new ProcurementTaskValidate())->post()->goCheck('manualCreate');
        $result = ProcurementTaskLogic::manualCreate($params);
        if ($result === false) {
            return $this->fail(ProcurementTaskLogic::getError(), ProcurementTaskLogic::getReturnData() ?: []);
        }
        return $this->success('创建成功', $result, 1, 1);
    }

    public function start()
    {
        $params = (new ProcurementTaskValidate())->post()->goCheck('start');
        $result = ProcurementTaskLogic::start($params);
        if ($result === false) {
            return $this->fail(ProcurementTaskLogic::getError(), ProcurementTaskLogic::getReturnData() ?: []);
        }
        return $this->success('开始成功', $result, 1, 1);
    }

    public function close()
    {
        $params = (new ProcurementTaskValidate())->post()->goCheck('close');
        $result = ProcurementTaskLogic::close($params);
        if ($result === false) {
            return $this->fail(ProcurementTaskLogic::getError(), ProcurementTaskLogic::getReturnData() ?: []);
        }
        return $this->success('关闭成功', $result, 1, 1);
    }

    public function cancel()
    {
        $params = (new ProcurementTaskValidate())->post()->goCheck('cancel');
        $result = ProcurementTaskLogic::cancel($params);
        if ($result === false) {
            return $this->fail(ProcurementTaskLogic::getError(), ProcurementTaskLogic::getReturnData() ?: []);
        }
        return $this->success('取消成功', $result, 1, 1);
    }
}
