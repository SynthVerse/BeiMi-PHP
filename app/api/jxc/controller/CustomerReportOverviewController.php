<?php
declare(strict_types=1);

namespace app\api\jxc\controller;

use app\api\jxc\logic\CustomerReportOverviewLogic;

class CustomerReportOverviewController extends BaseJxcController
{
    public function overview()
    {
        $result = CustomerReportOverviewLogic::overview($this->request->get());
        return $result === false ? $this->fail(CustomerReportOverviewLogic::getError()) : $this->data($result);
    }

    public function exportWord()
    {
        $result = CustomerReportOverviewLogic::exportWord($this->request->post());
        return $result === false ? $this->fail(CustomerReportOverviewLogic::getError()) : $this->data($result);
    }
}
