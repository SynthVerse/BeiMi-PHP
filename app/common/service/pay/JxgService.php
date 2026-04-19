<?php

namespace app\common\service\pay;

use Alipay\EasySDK\Kernel\Config;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\RequestOptions;

/**
 * 吉祥格支付
 * Class WeChatPayService
 * @package app\common\server
 */
class JxgService extends BasePayService implements PayInterface
{
    /**
     * 用户客户端
     * @var
     */
    protected $terminal;

    /**
     * 支付实例
     * @var
     */
    protected $pay;

    /**
     * 配置
     * @var
     */
    protected $config;

    protected $noticeUrl;

    /**
     * 初始化设置
     * JxgService constructor.
     * @throws \Exception
     */
    public function __construct($terminal = null)
    {
        //设置用户终端
        $this->terminal = $terminal;
    }

    /**
     * 初始化支付配置
     * @param $config
     */
    public function getOptions($config)
    {
        $this->config = $config;
    }


    /**
     * 二维码接口
     * @param $config
     */
    public function qrcodePay(string $out_trade_no, string $subject, float $total_amount, string $extra_common_param)
    {
        $param = [
            "out_trade_no" => $out_trade_no,
            "shopdate" => date("Ymd"),
            "subject" => $subject,
            "total_amount" => $total_amount,
            "timeout_express" => "96h",
            "extra_common_param" => $extra_common_param
        ];

        $reqParam = $this->getRequest($param);
        $client = new Client();
        $url = "https://gpay.jxg114.com/api/pay/unified";
        try {
            $response = $client->post($url, [
                RequestOptions::JSON => $reqParam,
                RequestOptions::HEADERS => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            ]);

            $body = $response->getBody();
            if ($body == "") {
                return ['code' => 1, 'msg' => "请求失败"];
            }

            $reqData = json_decode($body, true);
            if ($reqData['code'] != "10000") {
                return ['code' => 1, 'msg' => $reqData['msg']];
            }

            return ['code' => 0, 'msg' => $reqData['msg'], 'code_url' => $reqData['data']['qr_code_url'], 'out_trade_no' => $reqData['data']['out_trade_no']];
        } catch (ClientException $e) {
            return ['code' => 1, 'msg' => $e->getMessage()];
        }
    }


    /**
     * js支付
     * @param $platform 平台 1 微信 2 支付宝 3 银联支付 4 QQ支付
     * @param $isMinipg 平台  0=未知,1=小程序,2=公众号
     */
    public function jsPay(string $out_trade_no, string $subject, float $total_amount, string $extra_common_param, int $platform, int $isMinipg, string $openid, string $appId)
    {
        $param = [
            "out_trade_no" => $out_trade_no,
            "shopdate" => date("Ymd"),
            "subject" => $subject,
            "total_amount" => $total_amount,
            "timeout_express" => "96h",
            "extra_common_param" => $extra_common_param,
            "platform" => $platform,
            "open_id" => $openid,
            "is_minipg" => $isMinipg,
            "appid" => $appId,
        ];

        $reqParam = $this->getRequest($param);
        $client = new Client();
        $url = "https://gpay.jxg114.com/api/pay/js";
        try {
            $response = $client->post($url, [
                RequestOptions::JSON => $reqParam,
                RequestOptions::HEADERS => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            ]);

            $body = $response->getBody();
            if ($body == "") {
                return ['code' => 1, 'msg' => "请求失败"];
            }

            $reqData = json_decode($body, true);
            if ($reqData['code'] != "10000") {
                return ['code' => 1, 'msg' => $reqData['msg']];
            }

            return ['code' => 0, 'msg' => $reqData['msg'], 'data' => $reqData['data']];

        } catch (ClientException $e) {
            return ['code' => 1, 'msg' => $e->getMessage()];
        }
    }

    /**
     * 退款订单
     * @param $config
     */
    public function refund(string $out_trade_no, float $refund_amount, string $refund_reason, string $out_request_no)
    {
        $param = [
            "out_trade_no" => $out_trade_no,
            "shopdate" => date("Ymd"),
            "refund_amount" => $refund_amount,
            "refund_reason" => $refund_reason,
            "out_request_no" => $out_request_no,
        ];

        $reqParam = $this->getRequest($param);
        $client = new Client();
        $url = "https://gpay.jxg114.com/api/order/refund";
        try {
            $response = $client->post($url, [
                RequestOptions::JSON => $reqParam,
                RequestOptions::HEADERS => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            ]);

            $body = $response->getBody();
            if ($body == "") {
                return ['code' => 1, 'msg' => "请求失败"];
            }

            $reqData = json_decode($body, true);
            if ($reqData['code'] != "10000") {
                return ['code' => 1, 'msg' => $reqData['msg']];
            }

            return ['code' => 0, 'msg' => $reqData['msg'], 'data' => $reqData['data']];

        } catch (ClientException $e) {
            return ['code' => 1, 'msg' => $e->getMessage()];
        }
    }

    //查询接口
    public function queryOrder(string $out_trade_no, string $shopdate)
    {
        $param = [
            "out_trade_no" => $out_trade_no,
            "shopdate" => "",
        ];

        $reqParam = $this->getRequest($param);
        $client = new Client();
        $url = "https://gpay.jxg114.com/api/pay/query";
        try {
            $response = $client->post($url, [
                RequestOptions::JSON => $reqParam,
                RequestOptions::HEADERS => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            ]);

            $body = $response->getBody();
            if ($body == "") {
                return ['code' => 1, 'msg' => "请求失败"];
            }

            $reqData = json_decode($body, true);
            if ($reqData['code'] != "10000") {
                return ['code' => 1, 'msg' => $reqData['msg']];
            }


            return ['code' => 0, 'msg' => $reqData['msg'], 'data' => $reqData['data']];

        } catch (ClientException $e) {
            return ['code' => 1, 'msg' => $e->getMessage()];
        }
    }

    /**
     * @notes 公共请求
     * @return Config
     * @author 令狐冲
     * @date 2025/7/28 17:43
     */
    public function getRequest(array $param)
    {
        $commonReq = $this->commonReq();
        $bizContent = json_encode($param);
        $commonReq['biz_content'] = $bizContent;
        $signStr = Sprintf("%d%s%s%s%s%s%s", $this->config['mch_id'], $commonReq["timestamp"], $commonReq["sign_type"], $commonReq["version"], $commonReq["charset"], $commonReq["notify_url"], $commonReq["biz_content"]);
        $sign = md5(md5($signStr) . $this->config['pay_sign_key']);
        $commonReq['sign'] = $sign;
        return $commonReq;
    }

    /**
     * @notes 公共请求
     * @return Config
     * @author 令狐冲
     * @date 2025/7/28 17:43
     */
    public function commonReq()
    {
        return [
            "partner_id" => intval($this->config['mch_id']),
            "timestamp" => date("Y-m-d H:i:s"),
            "charset" => "UTF-8",
            "sign_type" => "MD5",
            "version" => "1.0",
            "notify_url" => (string)url('api/pay/jxgNotify', [], false, true),
        ];
    }
}

?>