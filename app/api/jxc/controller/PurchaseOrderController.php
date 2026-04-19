<?php

namespace app\api\jxc\controller;

use app\api\jxc\lists\PurchaseOrderLists;
use app\api\jxc\logic\PurchaseOrderLogic;
use app\api\jxc\validate\PurchaseOrderValidate;

class PurchaseOrderController extends BaseJxcController
{
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
        $params = (new PurchaseOrderValidate())->post()->goCheck('publish');
        $result = PurchaseOrderLogic::add($params);
        if ($result === false) {
            return $this->fail(PurchaseOrderLogic::getError());
        }
        return $this->success('添加成功', $result, 1, 1);
    }

    public function edit()
    {
        $params = (new PurchaseOrderValidate())->post()->goCheck('edit');
        $result = PurchaseOrderLogic::edit($params);
        if ($result === false) {
            return $this->fail(PurchaseOrderLogic::getError());
        }
        return $this->success('编辑成功', $result, 1, 1);
    }

    public function remove()
    {
        $params = (new PurchaseOrderValidate())->goCheck('remove');
        $result = PurchaseOrderLogic::remove($params);
        if ($result === false) {
            return $this->fail(PurchaseOrderLogic::getError());
        }
        return $this->success('删除成功', $result, 1, 1);
    }

    public function confirm()
    {
        $params = (new PurchaseOrderValidate())->post()->goCheck('confirm');
        $result = PurchaseOrderLogic::confirm($params);
        if ($result === false) {
            return $this->fail(PurchaseOrderLogic::getError());
        }
        return $this->success('操作成功', $result, 1, 1);
    }

    public function cancel()
    {
        $params = (new PurchaseOrderValidate())->post()->goCheck('cancel');
        $result = PurchaseOrderLogic::cancel($params);
        if ($result === false) {
            return $this->fail(PurchaseOrderLogic::getError());
        }
        return $this->success('取消成功', $result, 1, 1);
    }

    public function convertToSalesOrder()
    {
        $params = (new PurchaseOrderValidate())->post()->goCheck('convertToSalesOrder');
        $result = PurchaseOrderLogic::convertToSalesOrder($params);
        if ($result === false) {
            return $this->fail(PurchaseOrderLogic::getError());
        }
        return $this->success('转销售单成功', $result, 1, 1);
    }

    public function parsePastedText()
    {
        $params = (new PurchaseOrderValidate())->post()->goCheck('parsePastedText');
        $result = PurchaseOrderLogic::parsePastedText($params);
        return $this->data($result);
    }

    public function statistics()
    {
        $params = (new PurchaseOrderValidate())->get()->goCheck('statistics');
        return $this->data(PurchaseOrderLogic::statistics($params));
    }
}
