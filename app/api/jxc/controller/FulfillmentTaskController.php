<?php

namespace app\api\jxc\controller;

use app\api\jxc\logic\FulfillmentChangeLogic;
use app\api\jxc\logic\FulfillmentTaskLogic;
use app\api\jxc\validate\FulfillmentTaskValidate;

class FulfillmentTaskController extends BaseJxcController
{
    public function dashboard() { return $this->read('dashboard'); }
    public function lists() { return $this->read('lists'); }
    public function detail() { return $this->read('detail'); }
    public function resolve() { return $this->write('resolveException', 'resolve'); }
    public function printData() { return $this->write('printData'); }
    public function printResult() { return $this->write('printResult'); }
    public function paperControl() { return $this->write('paperControl'); }
    public function controlPrintData() { return $this->write('controlPrintData'); }
    public function controlPrintResult() { return $this->write('controlPrintResult'); }
    public function changeDeliveryArrangement() { return $this->write('changeDeliveryArrangement'); }
    public function reduceItem() { return $this->writeChange('reduceItem'); }
    public function markUndelivered() { return $this->writeChange('markUndelivered'); }
    public function bill() { return $this->write('bill'); }

    private function read(string $action)
    {
        $params = (new FulfillmentTaskValidate())->get()->goCheck($action);
        $result = FulfillmentTaskLogic::$action($params);
        return $result === false ? $this->fail(FulfillmentTaskLogic::getError()) : $this->data($result);
    }

    private function write(string $logicAction, ?string $scene = null)
    {
        $params = (new FulfillmentTaskValidate())->post()->goCheck($scene ?? $logicAction);
        $result = FulfillmentTaskLogic::$logicAction($params);
        return $result === false ? $this->fail(FulfillmentTaskLogic::getError()) : $this->success('操作成功', $result, 1, 1);
    }

    private function writeChange(string $action)
    {
        $params = (new FulfillmentTaskValidate())->post()->goCheck($action);
        $result = FulfillmentChangeLogic::$action($params);
        return $result === false ? $this->fail(FulfillmentChangeLogic::getError()) : $this->success('操作成功', $result, 1, 1);
    }
}
