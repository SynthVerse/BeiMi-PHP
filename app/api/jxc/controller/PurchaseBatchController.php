<?php

namespace app\api\jxc\controller;

use app\api\jxc\lists\PurchaseBatchLists;
use app\api\jxc\logic\PurchaseBatchLogic;
use app\api\jxc\validate\PurchaseBatchValidate;

class PurchaseBatchController extends BaseJxcController
{
    public function lists()
    {
        return $this->dataLists(PurchaseBatchLists::class);
    }

    public function detail()
    {
        $params = (new PurchaseBatchValidate())->get()->goCheck('detail');
        $result = PurchaseBatchLogic::detail($params);
        return $result === false
            ? $this->fail(PurchaseBatchLogic::getError(), PurchaseBatchLogic::getErrors())
            : $this->data($result);
    }

    public function publish()
    {
        $params = (new PurchaseBatchValidate())->post()->goCheck('publish');
        $result = PurchaseBatchLogic::submit($params);
        return $result === false
            ? $this->fail(PurchaseBatchLogic::getError(), PurchaseBatchLogic::getErrors())
            : $this->success('采购批次创建成功', $result, 1, 1);
    }
}
