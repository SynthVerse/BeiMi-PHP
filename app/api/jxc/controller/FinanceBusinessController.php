<?php

namespace app\api\jxc\controller;

use app\api\jxc\logic\{FinanceBusinessLogic, FinanceEvidence, FinanceValue};

class FinanceBusinessController extends BaseJxcController
{
    public function catalog() { return $this->data(FinanceBusinessLogic::catalog()); }
    public function lists() { return $this->respond(FinanceBusinessLogic::lists($this->request->get())); }
    public function detail() { return $this->respond(FinanceBusinessLogic::detail($this->request->get())); }
    public function options() { return $this->respond(FinanceBusinessLogic::options($this->request->get())); }
    public function closingChecklist() { return $this->respond(FinanceBusinessLogic::closingChecklist($this->request->get())); }
    public function monthlyReport() { return $this->respond(FinanceBusinessLogic::monthlyReport($this->request->get())); }
    public function periodPreview() { return $this->respond(FinanceBusinessLogic::periodAction('preview', $this->request->get())); }
    public function closePeriod() { return $this->respond(FinanceBusinessLogic::periodAction('close', $this->request->post())); }
    public function periodHistory() { return $this->respond(FinanceBusinessLogic::periodAction('history', $this->request->get())); }
    public function periodDetail() { return $this->respond(FinanceBusinessLogic::periodAction('detail', $this->request->get())); }
    public function subjects() { return $this->respond(FinanceBusinessLogic::subjects($this->request->get())); }
    public function salesOutput() { return $this->salesOutputAction('document', $this->request->get()); }
    public function prepareSalesPrint() { return $this->salesOutputAction('prepare', $this->request->post()); }
    public function salesPrintReceipt() { return $this->salesOutputAction('receipt', $this->request->post()); }
    public function salesPrintStatus() { return $this->salesOutputAction('status', $this->request->get()); }
    private function salesOutputAction(string $method, array $params)
    {
        try { return $this->data(\app\api\jxc\logic\FinanceSalesOutput::$method($params)); }
        catch (\DomainException $error) { return $this->fail($error->getMessage()); }
    }
    public function salesSources()
    {
        try { return $this->data(\app\api\jxc\logic\FinanceCustomers::salesSources($this->request->get())); }
        catch (\DomainException $error) { return $this->fail($error->getMessage()); }
    }
    public function saveSalesTerms()
    {
        try { return $this->data(\app\api\jxc\logic\FinanceSalesRules::save($this->request->post())); }
        catch (\DomainException $error) { return $this->fail($error->getMessage()); }
    }
    public function saveSalesPrecision()
    {
        try { return $this->data(\app\api\jxc\logic\FinanceSalesPrecision::save($this->request->post())); }
        catch (\DomainException $error) { return $this->fail($error->getMessage()); }
    }
    public function action() { return $this->respond(FinanceBusinessLogic::action((string)$this->request->post('action', ''), $this->request->post())); }
    public function preview()
    {
        try { return $this->data(\app\api\jxc\logic\FinancePreview::calculate($this->request->post())); }
        catch (\DomainException $error) { return $this->fail($error->getMessage()); }
    }
    public function customerBalances() { return $this->customerRead('lists'); }
    public function customerBalanceDetail() { return $this->customerRead('detail'); }
    public function customerSource() { return $this->customerRead('source'); }
    public function statements() { return $this->statementRead('lists'); }
    public function supplierBalances() { return $this->supplierRead('lists'); }
    public function supplierBalanceDetail() { return $this->supplierRead('detail'); }
    private function supplierRead(string $method)
    {
        try { return $this->data(\app\api\jxc\logic\FinanceSupplierBalances::$method($this->request->get())); }
        catch (\DomainException $error) { return $this->fail($error->getMessage()); }
    }
    public function statementDetail() { return $this->statementRead('detail'); }
    public function overdueTodos() { return $this->overdueRead('lists'); }
    public function overdueHistory() { return $this->overdueRead('history'); }
    private function overdueRead(string $method)
    {
        try { return $this->data(\app\api\jxc\logic\FinanceOverdue::$method($this->request->get())); }
        catch (\DomainException $error) { return $this->fail($error->getMessage()); }
    }
    public function statementAction()
    {
        try { return $this->data(\app\api\jxc\logic\FinanceStatements::action((string)$this->request->post('action', ''), $this->request->post())); }
        catch (\DomainException $error) { return $this->fail($error->getMessage()); }
    }
    private function statementRead(string $method)
    {
        try { return $this->data(\app\api\jxc\logic\FinanceStatements::$method($this->request->get())); }
        catch (\DomainException $error) { return $this->fail($error->getMessage()); }
    }
    private function customerRead(string $method)
    {
        try { return $this->data(\app\api\jxc\logic\FinanceCustomerBalances::$method($this->request->get())); }
        catch (\DomainException $error) { return $this->fail($error->getMessage()); }
    }
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
