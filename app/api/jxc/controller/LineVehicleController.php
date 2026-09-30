<?php

namespace app\api\jxc\controller;

use app\api\jxc\logic\LineVehicleLogic;
use app\api\jxc\validate\LineVehicleValidate;

class LineVehicleController extends BaseJxcController
{
    public function scheduleSave()
    {
        return $this->legacyCreationRetired();
    }

    public function schedules()
    {
        $params = (new LineVehicleValidate())->get()->goCheck('schedules');
        $result = LineVehicleLogic::schedules($params);
        return $result === false ? $this->fail(LineVehicleLogic::getError()) : $this->data($result);
    }

    public function tripCreate()
    {
        return $this->legacyCreationRetired();
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

    private function legacyCreationRetired()
    {
        return json([
            'code' => 0,
            'show' => 1,
            'msg' => '旧固定线车调度已退役，不能新建或修改班次和送站趟次',
            'data' => [
                'retirement_marker' => 'LEGACY_LINE_VEHICLE_RETIRED',
                'allowed_actions' => [
                    'history_view', 'package_record', 'trip_depart',
                    'reroute', 'return_pending', 'handoff_confirm', 'manifest_view',
                ],
            ],
        ], 410);
    }
}
