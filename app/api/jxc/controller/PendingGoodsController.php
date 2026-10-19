<?php
namespace app\api\jxc\controller;
use app\api\jxc\logic\PendingGoodsLogic;
use app\api\jxc\validate\PendingGoodsValidate;
class PendingGoodsController extends BaseJxcController
{
    public function lists() { return $this->respond('lists', true); }
    public function detail() { return $this->respond('detail', true); }
    public function recoveryPreview() { return $this->respond('recoveryPreview', true); }
    public function action() { return $this->respond('action'); }
    public function recover() { return $this->respond('recover'); }
    private function respond(string $action, bool $get = false)
    {
        $validator = new PendingGoodsValidate();
        $params = $get ? $validator->get()->goCheck($action) : $validator->post()->goCheck($action);
        $result = PendingGoodsLogic::$action($params);
        return $result === false ? $this->fail(PendingGoodsLogic::getError()) : ($get ? $this->data($result) : $this->success('处理成功', $result, 1, 1));
    }
}
