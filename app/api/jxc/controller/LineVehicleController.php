<?php

namespace app\api\jxc\controller;

use app\api\jxc\logic\LineVehicleLogic;
use app\api\jxc\validate\LineVehicleValidate;

class LineVehicleController extends BaseJxcController
{
    public function scheduleSave()
    {
        $params = (new LineVehicleValidate())->post()->goCheck('scheduleSave');
        $result = LineVehicleLogic::saveSchedule($params);
        return $result === false ? $this->fail(LineVehicleLogic::getError()) : $this->data($result);
    }

    public function schedules()
    {
        $params = (new LineVehicleValidate())->get()->goCheck('schedules');
        $result = LineVehicleLogic::schedules($params);
        return $result === false ? $this->fail(LineVehicleLogic::getError()) : $this->data($result);
    }

    public function tripCreate()
    {
        $params = (new LineVehicleValidate())->post()->goCheck('tripCreate');
        $result = LineVehicleLogic::createTrip($params);
        return $result === false
            ? $this->fail(LineVehicleLogic::getError())
            : $this->success('送站趟次创建成功', $result, 1, 1);
    }

    public function trips()
    {
        $params = (new LineVehicleValidate())->get()->goCheck('trips');
        $result = LineVehicleLogic::trips($params);
        return $result === false ? $this->fail(LineVehicleLogic::getError()) : $this->data($result);
    }

    public function tripDetail()
    {
        $params = (new LineVehicleValidate())->get()->goCheck('tripDetail');
        $result = LineVehicleLogic::tripDetail($params);
        return $result === false ? $this->fail(LineVehicleLogic::getError()) : $this->data($result);
    }

    public function packageRecord()
    {
        $params = (new LineVehicleValidate())->post()->goCheck('packageRecord');
        $result = LineVehicleLogic::recordPackages($params);
        return $result === false ? $this->fail(LineVehicleLogic::getError()) : $this->data($result);
    }

    public function tripDepart()
    {
        $params = (new LineVehicleValidate())->post()->goCheck('tripDepart');
        $result = LineVehicleLogic::departTrip($params);
        return $result === false ? $this->fail(LineVehicleLogic::getError()) : $this->data($result);
    }

    public function reroute()
    {
        $params = (new LineVehicleValidate())->post()->goCheck('reroute');
        $result = LineVehicleLogic::reroute($params);
        return $result === false ? $this->fail(LineVehicleLogic::getError()) : $this->data($result);
    }

    public function returnPending()
    {
        $params = (new LineVehicleValidate())->post()->goCheck('returnPending');
        $result = LineVehicleLogic::returnReroutedToPending($params);
        return $result === false ? $this->fail(LineVehicleLogic::getError()) : $this->data($result);
    }

    public function handoffConfirm()
    {
        $params = (new LineVehicleValidate())->post()->goCheck('handoffConfirm');
        $result = LineVehicleLogic::confirmHandoff($params);
        return $result === false
            ? $this->fail(LineVehicleLogic::getError())
            : $this->success('线车交接成功', $result, 1, 1);
    }

    public function manifest()
    {
        $params = (new LineVehicleValidate())->get()->goCheck('manifest');
        $result = LineVehicleLogic::loadingManifest($params);
        return $result === false ? $this->fail(LineVehicleLogic::getError()) : $this->data($result);
    }
}
