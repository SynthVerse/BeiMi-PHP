<?php

namespace app\tenantapi\logic\recharge;

use app\common\enum\PayEnum;
use app\common\enum\YesNoEnum;
use app\common\logic\BaseLogic;
use app\common\model\pay\TenantPayConfig;
use app\common\model\pay\TenantPayWay;
use app\common\model\recharge\RechargeOrder;
use app\common\model\user\User;
use app\common\service\pay\AliPayService;
use app\common\service\pay\HwcService;
use app\common\service\pay\JxgService;
use app\common\service\pay\OtherService;
use app\common\service\pay\WeChatPayService;
use app\common\service\pay\YshengService;

/**
 * 充值逻辑层
 * Class RechargeLogic
 * @package app\tenantapi\logic\recharge
 */
class PaymentLogic extends BaseLogic
{
    /**
     * @notes 支付方式
     * @param $userId
     * @param $terminal
     * @param $params
     * @return array|false
     * @author 段誉
     * @date 2023/2/24 17:53
     */
    public static function getPayWayList($scan, $terminal, $userId = 0, $from = '')
    {

        try {
            //获取支付场景
            $pay_way = TenantPayWay::alias('pw')
                ->join('tenant_pay_config dp', 'pw.pay_config_id = dp.id')
                ->where(['pw.scene' => $scan, 'dp.tenant_id' => $terminal, 'pw.status' => YesNoEnum::YES])
                ->field('dp.id,dp.name,pw.pay_config_id,dp.pay_way')
                ->order('pw.is_default desc,dp.sort desc,id asc')
                ->select()
                ->toArray();

            foreach ($pay_way as $k => &$item) {
                if ($item['pay_way'] == PayEnum::WECHAT_PAY) {
                    $item['extra'] = '微信快捷支付';
                }

                if ($item['pay_way'] == PayEnum::ALI_PAY) {
                    $item['extra'] = '支付宝快捷支付';
                }

                if ($item['pay_way'] == PayEnum::JXG_PAY) {
                    $item['extra'] = '吉祥格快捷支付';
                }

                if ($item['pay_way'] == PayEnum::HWC_PAY) {
                    $item['extra'] = '汇旺财快捷支付';
                }

                if ($item['pay_way'] == PayEnum::YS_PAY) {
                    $item['extra'] = '银盛快捷支付';
                }

                if ($item['pay_way'] == PayEnum::OTHER_PAY) {
                    $item['extra'] = '其他快捷支付';
                }

                if ($item['pay_way'] == PayEnum::BALANCE_PAY) {
                    $user_money = User::where(['id' => $userId])->value('user_money');
                    $item['extra'] = '可用余额:' . $user_money;
                }
                // 充值时去除余额支付
                if ($from == 'recharge' && $item['pay_way'] == PayEnum::BALANCE_PAY) {
                    unset($pay_way[$k]);
                }
            }

            return array_values($pay_way);
        } catch (\Exception $e) {
            self::setError($e->getMessage());
            return false;
        }
    }

    /*
     * 获取订单支付状态
     * */
    public static function getPayOrderDetails($params){
        if($params['from'] == 'recharge') {
            $order = RechargeOrder::findOrEmpty($params['id']);
            if ($order->isEmpty()) {
                return null;
            }

            return $order;
        }

        return null;
    }

    /**
     * @notes 获取预支付订单信息
     * @param $params
     * @return RechargeOrder|array|false|\think\Model
     * @author 段誉
     * @date 2023/2/27 15:19
     */
    public static function getPayOrderInfo($params)
    {
        try {
            switch ($params['from']) {
                case 'recharge':
                    $order = RechargeOrder::findOrEmpty($params['id']);
                    if ($order->isEmpty()) {
                        throw new \Exception('充值订单不存在');
                    }
                    break;
            }

            if ($order['pay_status'] == PayEnum::ISPAID) {
                throw new \Exception('订单已支付');
            }
            return $order;
        } catch (\Exception $e) {
            self::$error = $e->getMessage();
            return false;
        }
    }

    /**
     * @notes 支付
     * @param $payWay
     * @param $from
     * @param $order
     * @param $terminal
     * @param $redirectUrl
     * @return array|false|mixed|string|string[]
     * @throws \Exception
     * @author mjf
     * @date 2024/3/18 16:49
     */
    public static function pay($from, $order, $terminal, $redirectUrl)
    {
        $paySn = $order['pay_sn'];
        if ($order['order_amount'] == 0) {
            return [];
        }

        $payWay = (new TenantPayConfig())->field(["pay_way", "config"])->where(['id' => $order['pay_way']])->find();
        $payService = null;

        if ($payWay['pay_way'] == PayEnum::WECHAT_PAY) {
            $payService = (new WeChatPayService($terminal, $order['user_id'] ?? null));
        } else if ($payWay['pay_way'] == PayEnum::ALI_PAY) {
            $payService = (new AliPayService($terminal));
        } else if ($payWay['pay_way'] == PayEnum::JXG_PAY) {
            $payService = (new JxgService($terminal));
        } else if ($payWay['pay_way'] == PayEnum::HWC_PAY) {
            $payService = (new HwcService($terminal));
        } else if ($payWay['pay_way'] == PayEnum::YS_PAY) {
            $payService = (new YshengService($terminal));
        } else if ($payWay['pay_way'] == PayEnum::OTHER_PAY) {
            $payService = (new OtherService($terminal));
        }

        $payService->getOptions($payWay['config']);
        $result = $payService->qrcodePay($paySn, "充值", floatval($order['order_amount']), 'recharge');
        if ($result['code'] == 1) {
            self::$error = $result['msg'];
            return [];
        }

        RechargeOrder::where(['id' => $order['id']])->update(['transaction_id' => $result['out_trade_no'], 'code_url' => $result['code_url']]);
        return ['code_url' => $result['code_url']];

    }


    /**
     * @notes 设置订单号 支付回调时截取前面的单号 18个
     * @param $orderSn
     * @param $terminal
     * @return string
     * @author 段誉
     * @date 2023/3/1 16:31
     * @remark 回调时使用了不同的回调地址,导致跨客户端支付时(例如小程序,公众号)可能出现201,商户订单号重复错误
     */
    public static function formatOrderSn($orderSn, $terminal)
    {
        $suffix = mb_substr(time(), -4);
        return $orderSn . $terminal . $suffix;
    }

}

?>