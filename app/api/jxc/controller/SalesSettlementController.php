<?php

declare(strict_types=1);

namespace app\api\jxc\controller;

use app\api\jxc\logic\SalesSettlementLogic;
use app\api\jxc\validate\SalesSettlementValidate;

class SalesSettlementController extends BaseJxcController
{
    public function lists()
    {
        $params = (new SalesSettlementValidate())->get()->goCheck('lists');
        $result = SalesSettlementLogic::lists($params);
        return $result === false ? $this->fail(SalesSettlementLogic::getError()) : $this->data($result);
    }

    public function detail()
    {
        $params = (new SalesSettlementValidate())->get()->goCheck('detail');
        $result = SalesSettlementLogic::detail($params);
        return $result === false ? $this->fail(SalesSettlementLogic::getError()) : $this->data($result);
    }

    public function submit()
    {
        $params = (new SalesSettlementValidate())->post()->goCheck('submit');
        $result = SalesSettlementLogic::submit($params);
        return $result === false
            ? $this->fail(SalesSettlementLogic::getError())
            : $this->success('销售结算保存成功', $result, 1, 1);
    }

    public function preparePrint()
    {
        $params = (new SalesSettlementValidate())->post()->goCheck('preparePrint');
        $result = SalesSettlementLogic::preparePrint($params);
        return $result === false
            ? $this->fail(SalesSettlementLogic::getError())
            : $this->success('销售单打印准备成功', $result, 1, 1);
    }

    public function printResult()
    {
        $params = (new SalesSettlementValidate())->post()->goCheck('printResult');
        $result = SalesSettlementLogic::printResult($params);
        return $result === false
            ? $this->fail(SalesSettlementLogic::getError())
            : $this->success('销售单打印回执保存成功', $result, 1, 1);
    }

    public function weightTodos()
    {
        $params = (new SalesSettlementValidate())->get()->goCheck('weightTodos');
        $result = SalesSettlementLogic::weightDifferenceTodos($params);
        return $result === false ? $this->fail(SalesSettlementLogic::getError()) : $this->data($result);
    }

    public function resolveWeight()
    {
        $params = (new SalesSettlementValidate())->post()->goCheck('resolveWeight');
        $result = SalesSettlementLogic::resolveWeightDifference($params);
        return $result === false
            ? $this->fail(SalesSettlementLogic::getError())
            : $this->success('计费重量差处理成功', $result, 1, 1);
    }
}
