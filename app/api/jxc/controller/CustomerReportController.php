<?php

namespace app\api\jxc\controller;

use app\api\jxc\logic\CustomerReportCandidateLogic;
use app\api\jxc\logic\CustomerReportLogic;
use app\api\jxc\validate\CustomerReportValidate;

class CustomerReportController extends BaseJxcController
{
    public function recognize()
    {
        $params = (new CustomerReportValidate())->post()->goCheck('recognize');
        return $this->data(CustomerReportCandidateLogic::recognize((string)$params['text']));
    }

    public function quickCreateGoods()
    {
        $params = (new CustomerReportValidate())->post()->goCheck('quickCreateGoods');
        $result = CustomerReportCandidateLogic::quickCreateGoods($params);
        return $result === false ? $this->fail(CustomerReportCandidateLogic::getError()) : $this->success('商品创建成功', $result, 1, 1);
    }

    public function submit() { return $this->respond('submit'); }
    public function edit() { return $this->respond('edit'); }
    public function retry() { return $this->respond('retry'); }
    public function convert() { return $this->respond('convert'); }
    public function cancel() { return $this->respond('cancel'); }

    public function lists()
    {
        $params = (new CustomerReportValidate())->get()->goCheck('lists');
        return $this->data(CustomerReportLogic::lists($params));
    }

    public function availability()
    {
        $params = (new CustomerReportValidate())->get()->goCheck('availability');
        return $this->data(CustomerReportLogic::availability($params));
    }

    public function detail()
    {
        $params = (new CustomerReportValidate())->get()->goCheck('detail');
        $result = CustomerReportLogic::detail($params);
        return $result === false ? $this->fail(CustomerReportLogic::getError()) : $this->data($result);
    }

    private function respond(string $action)
    {
        $params = (new CustomerReportValidate())->post()->goCheck($action);
        $result = CustomerReportLogic::$action($params);
        return $result === false ? $this->fail(CustomerReportLogic::getError(), CustomerReportLogic::getReturnData() ?: []) : $this->success('操作成功', $result, 1, 1);
    }
}
