<?php

namespace app\api\jxc\controller;

use app\api\jxc\logic\FinanceSetupLogic;

class FinanceSetupController extends BaseJxcController
{
    public function workbench() { return $this->respond(FinanceSetupLogic::workbench($this->request->get())); }
    public function preparation() { return $this->respond(FinanceSetupLogic::preparation()); }
    public function accounts() { return $this->respond(FinanceSetupLogic::accounts()); }
    public function savePreparation() { return $this->respond(FinanceSetupLogic::savePreparation($this->request->post())); }
    public function saveAccount() { return $this->respond(FinanceSetupLogic::saveAccount($this->request->post())); }
    public function opening() { return $this->respond(FinanceSetupLogic::opening()); }
    public function openingSubjects() { return $this->respond(FinanceSetupLogic::opening($this->request->get(), true)); }
    public function saveOpeningItem() { return $this->respond(FinanceSetupLogic::openingAction('item', $this->request->post())); }
    public function removeOpeningItem() { return $this->respond(FinanceSetupLogic::openingAction('remove', $this->request->post())); }
    public function saveOpeningReview() { return $this->respond(FinanceSetupLogic::openingAction('review', $this->request->post())); }
    public function submitOpening() { return $this->respond(FinanceSetupLogic::openingAction('submit', $this->request->post())); }
    public function reopenOpening() { return $this->respond(FinanceSetupLogic::openingAction('reopen', $this->request->post())); }
    public function confirmOpening() { return $this->respond(FinanceSetupLogic::openingAction('confirm', $this->request->post())); }

    private function respond(array|false $result)
    {
        return $result === false ? $this->fail(FinanceSetupLogic::getError()) : $this->data($result);
    }
}
