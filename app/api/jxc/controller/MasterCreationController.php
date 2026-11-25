<?php

namespace app\api\jxc\controller;

use app\api\jxc\logic\MasterCreationReceipt;
use app\api\jxc\logic\FinanceAccess;

class MasterCreationController extends BaseJxcController
{
    public function result()
    {
        $params = $this->request->get();
        if (FinanceAccess::userIdentity() && ($params['kind'] ?? '') === 'employee') {
            return $this->fail('本版尚未开放员工档案');
        }
        $result = MasterCreationReceipt::lookup($params);
        return $result === false ? $this->fail(MasterCreationReceipt::getError()) : $this->data($result);
    }
}
