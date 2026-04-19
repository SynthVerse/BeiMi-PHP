<?php
namespace app\common\service\pay;

use Alipay\EasySDK\Kernel\Config;
use app\common\enum\PayEnum;
use app\common\model\pay\TenantPayConfig;

/**
 * 银盛支付
 * Class WeChatPayService
 * @package app\common\server
 */
class YshengService extends BasePayService
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

    /**
     * 初始化设置
     * YshengService constructor.
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
    public function scanPay($paySn,$money){
      $this->getOptions();
    }
}

?>