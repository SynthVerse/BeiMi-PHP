<?php

namespace app\api\jxc\controller;

use app\api\jxc\logic\FinanceSetupLogic;

class FinanceSetupController extends BaseJxcController
{
    public function workbench() { return $this->respond(FinanceSetupLogic::workbench($this->request->get())); }
    public function preparation() { return $this->respond(FinanceSetupLogic::preparation()); }
    public function openingGuide() { return $this->respond(FinanceSetupLogic::openingGuide()); }
    public function startOpening() { return $this->respond(FinanceSetupLogic::startOpening($this->request->post())); }
    public function saveGuideCard() { return $this->respond(FinanceSetupLogic::saveGuideCard($this->request->post())); }
    public function accounts() { return $this->respond(FinanceSetupLogic::accounts()); }
    public function savePreparation() { return $this->respond(FinanceSetupLogic::savePreparation($this->request->post())); }
    public function saveAccount() { return $this->respond(FinanceSetupLogic::saveAccount($this->request->post())); }
    public function removeAccount()
    {
        $params = $this->request->post();
        $raw = $this->request->post(false);
        // 保留明确的 JSON 布尔选择；默认 trim 会把 false 变成空字符串。
        if (array_key_exists('remove_opening_draft', $raw)) { $params['remove_opening_draft'] = $raw['remove_opening_draft']; }
        return $this->respond(FinanceSetupLogic::removeAccount($params));
    }
    public function opening() { return $this->respond(FinanceSetupLogic::opening()); }
    public function openingSubjects() { return $this->respond(FinanceSetupLogic::opening($this->request->get(), true)); }
    public function saveOpeningItem()
    {
        $params = $this->request->post();
        $raw = $this->request->post(false);
        // trim 会把 JSON 整数转成字符串；仅保留契约标记的原始类型，其余输入仍走项目过滤器。
        if (array_key_exists('entry_contract', $raw)) { $params['entry_contract'] = $raw['entry_contract']; }
        if (is_array($raw['form'] ?? null) && array_key_exists('entry_contract', $raw['form'])) {
            $params['form']['entry_contract'] = $raw['form']['entry_contract'];
        }
        $editingDraft = in_array($params['editing_draft'] ?? false, [true, 1, '1', 'true'], true);
        return $this->respond(FinanceSetupLogic::openingAction($editingDraft ? 'editor' : 'item', $params));
    }
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
