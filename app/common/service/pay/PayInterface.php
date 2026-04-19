<?php
namespace app\common\service\pay;
interface PayInterface
{
    //二维码支付
   public function qrcodePay(string $out_trade_no, string $subject, float $total_amount, string $extra_common_param);
   //js支付
   public function jsPay(string $out_trade_no, string $subject, float $total_amount, string $extra_common_param, int $platform, int $isMinipg, string $openid, string $appId);
   //退款
   public function refund(string $out_trade_no, float $refund_amount, string $refund_reason, string $out_request_no);
   //查询订单
   public function queryOrder(string $out_trade_no, string $shopdate);
}


?>