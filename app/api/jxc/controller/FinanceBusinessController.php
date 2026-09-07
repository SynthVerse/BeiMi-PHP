<?php

namespace app\api\jxc\controller;

use app\api\jxc\logic\{FinanceBusinessLogic, FinanceEvidence, FinanceValue};

class FinanceBusinessController extends BaseJxcController
{
    public function catalog() { return $this->data(FinanceBusinessLogic::catalog()); }
    public function lists() { return $this->respond(FinanceBusinessLogic::lists($this->request->get())); }
    public function detail() { return $this->respond(FinanceBusinessLogic::detail($this->request->get())); }
    public function options() { return $this->respond(FinanceBusinessLogic::options($this->request->get())); }
    public function subjects() { return $this->respond(FinanceBusinessLogic::subjects($this->request->get())); }
    public function action() { return $this->respond(FinanceBusinessLogic::action((string)$this->request->post('action', ''), $this->request->post())); }
    public function evidence()
    {
        try {
            return $this->data(FinanceEvidence::save(FinanceValue::text($this->request->post('type', ''), 40), $this->request->file('file')));
        } catch (\Exception $error) { return $this->fail($error->getMessage()); }
    }
    public function evidenceContent()
    {
        try { return $this->data(FinanceEvidence::content(FinanceValue::id($this->request->get('id', 0))))->header(['Cache-Control' => 'private, no-store', 'Pragma' => 'no-cache']); }
        catch (\DomainException $error) { return $this->fail($error->getMessage()); }
    }
    private function respond(array|false $result) { return $result === false ? $this->fail(FinanceBusinessLogic::getError()) : $this->data($result); }
}
