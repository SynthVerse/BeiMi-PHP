<?php

namespace app\common\service\pay;

use app\common\enum\PayEnum;
use app\common\logic\PayNotifyLogic;
use app\common\model\recharge\RechargeOrder;

/**
 * 支付成功回调
 * Class PaySuccessService
 * @package app\common\server
 */
class PayNoticeService extends BasePayService
{
    public function __construct()
    {

    }

    /**
     * 充值成功
     */
    public function notify($outTradeNo, $transactionId, $totalFee, $from)
    {
        if ($from == 'recharge') {
            $this->rechargeScuess($outTradeNo, $transactionId, $totalFee);
        }
    }

    /**
     * 企业充值成功
     */
    protected function rechargeScuess($outTradeNo, $transactionId, $totalFee)
    {
        $order = RechargeOrder::where(['pay_sn' => $outTradeNo])->findOrEmpty();
        if ($order->isEmpty() || $order->pay_status == PayEnum::ISPAID) {
            return true;
        }

        $extra = [];
        $extra['transaction_id'] = $transactionId;

        PayNotifyLogic::handle('recharge', $outTradeNo, $totalFee, $extra);
        return true;
    }
}

?>