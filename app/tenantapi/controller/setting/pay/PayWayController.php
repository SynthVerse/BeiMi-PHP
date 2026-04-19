<?php
namespace app\tenantapi\controller\setting\pay;

use app\tenantapi\controller\BaseAdminController;
use app\tenantapi\logic\setting\pay\PayWayLogic;


/**
 * 支付方式
 * Class PayWayController
 * @package app\tenantapi\controller\setting\pay
 */
class PayWayController extends BaseAdminController
{

    /**
     * @notes 获取支付方式
     * @return \think\response\Json
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author 段誉
     * @date 2023/2/23 16:27
     */
    public function getPayWay()
    {
        $result = PayWayLogic::getPayWay();
        return $this->success('获取成功',$result);
    }


    /**
     * @notes 设置支付方式
     * @return \think\response\Json
     * @throws \Exception
     * @author 段誉
     * @date 2023/2/23 16:27
     */
    public function setPayWay()
    {
        $params = $this->request->post();
        $result = (new PayWayLogic())->setPayWay($params);
        if (true !== $result) {
            return $this->fail($result);
        }
        return $this->success('操作成功',[],1, 1);
    }
}