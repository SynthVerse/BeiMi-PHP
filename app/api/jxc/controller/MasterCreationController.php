<?php

namespace app\api\jxc\controller;

use app\api\jxc\logic\MasterCreationReceipt;

class MasterCreationController extends BaseJxcController
{
    public function result()
    {
        $result = MasterCreationReceipt::lookup($this->request->get());
        return $result === false ? $this->fail(MasterCreationReceipt::getError()) : $this->data($result);
    }
}
