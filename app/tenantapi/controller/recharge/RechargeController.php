<?php

namespace app\tenantapi\controller\recharge;

use app\tenantapi\controller\BaseAdminController;
use app\tenantapi\lists\recharge\RechargeLists;
use app\tenantapi\logic\recharge\RechargeLogic;
use app\tenantapi\validate\recharge\RechargeOrderValidate;
use app\tenantapi\validate\recharge\RechargeRefundValidate;
use app\tenantapi\logic\recharge\PaymentLogic;

/**
 * 充值控制器
 * Class RechargeController
 * @package app\tenantapi\controller\recharge
 */
class RechargeController extends BaseAdminController
{

    /**
     * @notes 获取充值设置
     * @return \think\response\Json
     * @author 段誉
     * @date 2023/2/22 16:48
     */
    public function getConfig()
    {
        $result = RechargeLogic::getConfig();
        return $this->data($result);
    }


    /**
     * @notes 充值设置
     * @return \think\response\Json
     * @author 段誉
     * @date 2023/2/22 16:48
     */
    public function setConfig()
    {
        $params = $this->request->post();
        $result = RechargeLogic::setConfig($params);
        if ($result) {
            return $this->success('操作成功', [], 1, 1);
        }
        return $this->fail(RechargeLogic::getError());
    }


    /**
     * @notes 充值记录
     * @return \think\response\Json
     * @author 段誉
     * @date 2023/2/24 16:01
     */
    public function lists()
    {
        return $this->dataLists(new RechargeLists());
    }

    /**
     * @notes 添加用户信息
     * @return \think\response\Json
     * @author 段誉
     * @date 2022/9/22 16:34
     */
    public function add()
    {
        $params = (new RechargeOrderValidate())->post()->goCheck('add');
        $params['tenant_id'] = $this->tenantId;
        $result = RechargeLogic::recharge($params);
        if (!$result) {
            return $this->fail('操作失败');
        }
        return $this->success('操作成功', [], 1, 1);
    }

    /**
     * @notes 取消订单
     * @return \think\response\Json
     * @author 乔峰
     * @date 2022/9/22 16:34
     */
    public function cancel()
    {
        $params = (new RechargeOrderValidate())->post()->goCheck('delete');
        $result = RechargeLogic::cancel($params, $this->tenantId);
        if (!$result) {
            return $this->fail('操作失败');
        }
        return $this->success('操作成功', [], 1, 1);
    }

    /**
     * @notes 支付订单
     * @return \think\response\Json
     * @author 乔峰
     * @date 2022/9/22 16:34
     */
    public function pay()
    {
        $params = (new RechargeOrderValidate())->post()->goCheck('pay');
        //订单信息
        $order = PaymentLogic::getPayOrderInfo($params);
        if (false === $order) {
            return $this->fail(PaymentLogic::getError(), $params);
        }

        if ($order['code_url'] != "") {
            return $this->success('操作成功', ['code_url' => $order["code_url"]]);
        }

        //支付流程
        $redirectUrl = $params['redirect'] ?? '/pages/payment/payment';
        $result = PaymentLogic::pay($params['from'], $order, 3, $redirectUrl);
        if (count($result) == 0) {
            return $this->fail(PaymentLogic::getError(), $params);
        }

        return $this->success('操作成功', $result);
    }

    /**
     * @notes 订单状态
     * @return \think\response\Json
     * @author 乔峰
     * @date 2022/9/22 16:34
     */
    public function status()
    {
        $params = (new RechargeOrderValidate())->post()->goCheck('pay');
        //订单信息
        $order = PaymentLogic::getPayOrderDetails($params);
        if (null === $order) {
            return $this->fail(PaymentLogic::getError(), $params);
        }

        return $this->success('', ['id' => $order['id'], 'pay_status' => $order['pay_status']], 1, 1);
    }

    /**
     * @notes 退款
     * @return \think\response\Json
     * @author 段誉
     * @date 2023/2/28 17:29
     */
    public function refund()
    {
        $params = (new RechargeRefundValidate())->post()->goCheck('refund');
        $result = RechargeLogic::refund($params, $this->adminId);
        list($flag, $msg) = $result;
        if (false === $flag) {
            return $this->fail($msg);
        }
        return $this->success($msg, [], 1, 1);
    }


    /**
     * @notes 重新退款
     * @return \think\response\Json
     * @author 段誉
     * @date 2023/2/28 19:17
     */
    public function refundAgain()
    {
        $params = (new RechargeRefundValidate())->post()->goCheck('again');
        $result = RechargeLogic::refundAgain($params, $this->adminId);
        list($flag, $msg) = $result;
        if (false === $flag) {
            return $this->fail($msg);
        }
        return $this->success($msg, [], 1, 1);
    }


    /**
     * @notes 获取支付方式
     * @return \think\response\Json
     * @author 段誉
     * @date 2023/2/28 19:17
     */
    public function payWay()
    {
        $result = PaymentLogic::getPayWayList(3, $this->tenantId, 0, 'recharge');
        if ($result === false) {
            return $this->fail(PaymentLogic::getError());
        }
        return $this->data($result);
    }
}