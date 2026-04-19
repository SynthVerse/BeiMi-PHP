<?php

namespace app\common\service\pay;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\RequestOptions;

/**
 * 汇旺财
 * Class WeChatPayService
 * @package app\common\server
 */
class HwcService extends BasePayService implements PayInterface
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

    protected $urls = 'https://pay.hstypay.com/v2/pay/gateway';

    /**
     * 初始化设置
     * HwcService constructor.
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
     * @notes 扫码支付
     * @return Config
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author 段誉
     * @date 2021/7/28 17:43
     */
    public function qrcodePay(string $out_trade_no, string $subject, float $total_amount, string $extra_common_param)
    {
        $parameters = [];
        $parameters['service'] = 'unified.trade.native';
        $parameters['mch_id'] = $this->config['mch_id'];
        $parameters['version'] = '2.0';
        $parameters['sign_type'] = 'MD5';
        $parameters['body'] = $subject;
        $parameters['attach'] = $extra_common_param;
        $parameters['mch_create_ip'] = '124.71.109.133';
        $parameters['total_fee'] = ($total_amount * 100);
        $parameters['out_trade_no'] = $out_trade_no;
        $parameters['sub_openid'] = '';
        $parameters['sub_appid'] = 'wx4f3cb0c82261edba';
        $parameters['notify_url'] = (string)url('pay/hwcNotify', [], false, true);
        $parameters['is_raw'] = 1;
        $parameters['nonce_str'] = mt_rand(time(), time() + rand());
        $sign = $this->createSign($parameters);
        $parameters['sign'] = $sign;
        $data = $this->toXml($parameters);
        $client = new Client();

        try {
            $response = $client->post($this->urls, [
                RequestOptions::HEADERS => ['Content-Type' => 'application/xml', 'Accept' => 'application/xml'],
                RequestOptions::BODY => $data,
            ]);
            $body = $response->getBody();
            if ($body == "") {
                return ['code' => 1, 'msg' => "请求失败"];
            }

            $result = $this->parseXML($body);

            if ($result['status'] != 0) {
                return ['code' => 1, 'msg' => $result['message']];
            }

            return ['code' => 0, 'msg' => '操作成功', 'code_url' => $result['code_url'], 'out_trade_no' => ''];

        } catch (ClientException $e) {
            return ['code' => 1, 'msg' => $e->getMessage()];
        }

    }

    public function jsPay(string $out_trade_no, string $subject, float $total_amount, string $extra_common_param, int $platform, int $isMinipg, string $openid, string $appId)
    {
        // TODO: Implement jsPay() method.
    }

    public function refund(string $out_trade_no, float $refund_amount, string $refund_reason, string $out_request_no)
    {
        // TODO: Implement refund() method.
    }

    public function queryOrder(string $out_trade_no, string $shopdate)
    {
        // TODO: Implement queryOrder() method.
    }

    /**
     *获取带参数的请求URL
     */
    public function getRequestURL($parameters)
    {
        $sign = $this->createSign($parameters);
        $parameters['sign'] = $sign;
        $reqPar = "";
        ksort($parameters);
        foreach ($parameters as $k => $v) {
            $reqPar .= $k . "=" . urlencode($v) . "&";
        }

        return "https://pay.hstypay.com/v2/pay/gateway" . "?" . $reqPar;
    }

    //创建加密
    public function createSign($parameters)
    {
        $signPars = "";
        ksort($parameters);
        foreach ($parameters as $k => $v) {
            if ("" != $v && "sign" != $k) {
                $signPars .= $k . "=" . $v . "&";
            }
        }
        $signPars .= "key=" . $this->config['pay_sign_key'];
        return strtoupper(md5($signPars));
    }

    //解析xml
    public function parseXML($xmlSrc)
    {
        if (empty($xmlSrc)) {
            return false;
        }
        $array = array();
        $xml = simplexml_load_string($xmlSrc);
        $encode = $this->getXmlEncode($xmlSrc);

        if ($xml && $xml->children()) {
            foreach ($xml->children() as $node) {
                //有子节点
                if ($node->children()) {
                    $k = $node->getName();
                    $nodeXml = $node->asXML();
                    $v = substr($nodeXml, strlen($k) + 2, strlen($nodeXml) - 2 * strlen($k) - 5);

                } else {
                    $k = $node->getName();
                    $v = (string)$node;
                }

                if ($encode != "" && $encode != "UTF-8") {
                    $k = iconv("UTF-8", $encode, $k);
                    $v = iconv("UTF-8", $encode, $v);
                }
                $array[$k] = $v;
            }
        }
        return $array;
    }

    public static function toXml($array)
    {
        $xml = '<xml>';
        foreach ($array as $k => $v) {
            $xml .= '<' . $k . '><![CDATA[' . $v . ']]></' . $k . '>';
        }
        $xml .= '</xml>';
        return $xml;
    }

    //获取xml编码
    function getXmlEncode($xml)
    {
        $ret = preg_match("/<?xml[^>]* encoding=\"(.*)\"[^>]* ?>/i", $xml, $arr);
        if ($ret) {
            return strtoupper($arr[1]);
        } else {
            return "";
        }
    }
}

?>