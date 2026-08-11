<?php

namespace app\api\jxc\controller;

use app\api\jxc\logic\FulfillmentTaskLogic;
use app\api\jxc\validate\FulfillmentTaskValidate;

class FulfillmentTaskController extends BaseJxcController
{
    public function dashboard() { return $this->read('dashboard'); }
    public function lists() { return $this->read('lists'); }
    public function detail() { return $this->read('detail'); }
    public function candidates() { return $this->read('candidates'); }
    public function assign() { return $this->write('assign'); }
    public function resolve() { return $this->write('resolveException', 'resolve'); }
    public function printData() { return $this->write('printData'); }
    public function printResult() { return $this->write('printResult'); }
    public function recover() { return $this->write('recover'); }
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
}
