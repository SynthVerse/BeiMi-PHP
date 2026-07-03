<?php

namespace app\api\jxc\controller;

use app\api\jxc\lists\SalesReservationLists;
use app\api\jxc\logic\SalesReservationLogic;
use app\api\jxc\validate\SalesReservationValidate;

class SalesReservationController extends BaseJxcController
{
    public function lists()
    {
        return $this->dataLists(SalesReservationLists::class);
    }

    public function detail()
    {
        $params = (new SalesReservationValidate())->get()->goCheck('detail');
        return $this->data(SalesReservationLogic::detail($params));
    }

    public function submit()
    {
        $params = (new SalesReservationValidate())->post()->goCheck('submit');
        $result = SalesReservationLogic::submit($params);
        if ($result === false) {
            return $this->fail(SalesReservationLogic::getError(), SalesReservationLogic::getReturnData() ?: []);
        }
        return $this->success('提交成功', $result, 1, 1);
    }

    public function cancel()
    {
        $params = (new SalesReservationValidate())->post()->goCheck('cancel');
        $result = SalesReservationLogic::cancel($params);
        if ($result === false) {
            return $this->fail(SalesReservationLogic::getError(), SalesReservationLogic::getReturnData() ?: []);
        }
        return $this->success('取消成功', $result, 1, 1);
    }

    public function convertSales()
    {
        $params = (new SalesReservationValidate())->post()->goCheck('convertSales');
        $result = SalesReservationLogic::convertSales($params);
        if ($result === false) {
            return $this->fail(SalesReservationLogic::getError(), SalesReservationLogic::getReturnData() ?: []);
        }
        return $this->success('转换成功', $result, 1, 1);
    }
}
