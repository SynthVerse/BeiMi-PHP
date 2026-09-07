<?php

namespace app\api\jxc\controller;

use app\api\jxc\logic\FinanceSetupLogic;

class FinanceSetupController extends BaseJxcController
{
    public function workbench() { return $this->respond(FinanceSetupLogic::workbench()); }
    public function preparation() { return $this->respond(FinanceSetupLogic::preparation()); }
    public function accounts() { return $this->respond(FinanceSetupLogic::accounts()); }
    public function savePreparation() { return $this->respond(FinanceSetupLogic::savePreparation($this->request->post())); }
    public function saveAccount() { return $this->respond(FinanceSetupLogic::saveAccount($this->request->post())); }

    private function respond(array|false $result)
    {
        return $result === false ? $this->fail(FinanceSetupLogic::getError()) : $this->data($result);
    }
}
