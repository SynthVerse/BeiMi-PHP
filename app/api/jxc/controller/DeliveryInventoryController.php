<?php

namespace app\api\jxc\controller;

use app\api\jxc\logic\DeliveryInventoryLogic;
use app\api\jxc\logic\NegativeInventoryLogic;
use app\api\jxc\logic\ThirdPartyDriverLogic;
use app\api\jxc\validate\DeliveryInventoryValidate;

class DeliveryInventoryController extends BaseJxcController
{
    public function confirmSelf()
    {
        $params = (new DeliveryInventoryValidate())->post()->goCheck('confirmSelf');
        $result = DeliveryInventoryLogic::confirmSelfDelivery($params);
        return $result === false
            ? $this->fail(DeliveryInventoryLogic::getError())
            : $this->success('交付确认成功', $result, 1, 1);
    }

    public function confirmThirdParty()
    {
        $params = (new DeliveryInventoryValidate())->post()->goCheck('confirmThirdParty');
        $result = DeliveryInventoryLogic::confirmThirdPartyDelivery($params);
        return $result === false
            ? $this->fail(DeliveryInventoryLogic::getError())
            : $this->success('第三方司机交接成功', $result, 1, 1);
    }

    public function confirmCustomerVehicle()
    {
        $params = (new DeliveryInventoryValidate())->post()->goCheck('confirmCustomerVehicle');
        $result = DeliveryInventoryLogic::confirmCustomerVehicleDelivery($params);
        return $result === false
            ? $this->fail(DeliveryInventoryLogic::getError())
            : $this->success('客户车辆交付确认成功', $result, 1, 1);
    }

    public function driverSave()
    {
        $params = (new DeliveryInventoryValidate())->post()->goCheck('driverSave');
        $result = ThirdPartyDriverLogic::save($params);
        return $result === false ? $this->fail(ThirdPartyDriverLogic::getError()) : $this->data($result);
    }

    public function drivers()
    {
        $params = (new DeliveryInventoryValidate())->get()->goCheck('driverLists');
        $result = ThirdPartyDriverLogic::lists($params);
        return $result === false ? $this->fail(ThirdPartyDriverLogic::getError()) : $this->data($result);
    }

    public function detail()
    {
        $params = (new DeliveryInventoryValidate())->get()->goCheck('detail');
        $result = DeliveryInventoryLogic::detail($params);
        return $result === false ? $this->fail(DeliveryInventoryLogic::getError()) : $this->data($result);
    }

    public function negativeTodos()
    {
        $params = (new DeliveryInventoryValidate())->get()->goCheck('negativeTodos');
        $result = NegativeInventoryLogic::todos($params);
        return $result === false ? $this->fail(NegativeInventoryLogic::getError()) : $this->data($result);
    }

    public function resolveNegative()
    {
        $params = (new DeliveryInventoryValidate())->post()->goCheck('resolveNegative');
        $result = NegativeInventoryLogic::resolve($params);
        return $result === false
            ? $this->fail(NegativeInventoryLogic::getError())
            : $this->success('负库存处理成功', $result, 1, 1);
    }
}
