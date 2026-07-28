<?php

namespace app\api\jxc\controller;

use app\api\jxc\lists\PurchaseOrderLists;
use app\api\jxc\logic\PurchaseOrderLogic;
use app\api\jxc\validate\PurchaseOrderValidate;

class PurchaseOrderController extends BaseJxcController
{
    private function legacyReservationRetired()
    {
        return $this->fail('旧销售预定写入口已退役', ['error_code' => 'JXC_LEGACY_PURCHASE_RESERVATION_RETIRED']);
    }

    public function lists()
    {
        return $this->dataLists(PurchaseOrderLists::class);
    }

    public function detail()
    {
        $params = (new PurchaseOrderValidate())->get()->goCheck('detail');
        return $this->data(PurchaseOrderLogic::detail($params));
    }

    public function add()
    {
        return $this->legacyReservationRetired();
    }

    public function edit()
    {
        return $this->legacyReservationRetired();
    }

    public function remove()
    {
        return $this->legacyReservationRetired();
    }

    public function confirm()
    {
        return $this->legacyReservationRetired();
    }

    public function cancel()
    {
        return $this->legacyReservationRetired();
    }

    public function convertToSalesOrder()
    {
        return $this->legacyReservationRetired();
    }

    public function parsePastedText()
    {
        return $this->legacyReservationRetired();
    }

    public function statistics()
    {
        $params = (new PurchaseOrderValidate())->get()->goCheck('statistics');
        return $this->data(PurchaseOrderLogic::statistics($params));
    }
}
