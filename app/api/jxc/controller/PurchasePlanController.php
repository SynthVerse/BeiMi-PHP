<?php
namespace app\api\jxc\controller;
use app\api\jxc\logic\PurchasePlanLogic;
class PurchasePlanController extends BaseJxcController {
    public function demands()
    {
        return $this->respond('demands', 'get');
    }
    public function lists()
    {
        return $this->respond('lists', 'get');
    }
    public function detail()
    {
        return $this->respond('detail', 'get');
    }
    public function preview()
    {
        return $this->respond('preview', 'get');
    }
    public function arrivalPreview()
    {
        return $this->respond('arrivalPreview', 'get');
    }
    public function verifyStock()
    {
        return $this->respond('verifyStock');
    }
    public function create()
    {
        return $this->respond('create');
    }
    public function preparePrint()
    {
        return $this->respond('preparePrint');
    }
    public function printResult()
    {
        return $this->respond('printResult');
    }
    public function recordBought()
    {
        return $this->respond('recordBought');
    }
    public function revise()
    {
        return $this->respond('revise');
    }
    public function attachArrival()
    {
        return $this->respond('attachArrival');
    }
    private function respond(string $action, string $method = 'post')
    {
        $params = $method === 'get'?$this->request->get():$this->request->post();
        $result = PurchasePlanLogic::$action($params);
        return $result === false?$this->fail(PurchasePlanLogic::getError()):($method === 'get'?$this->data($result):$this->success('操作成功', $result, 1, 1));
    }
}
