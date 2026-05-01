<?php
// +----------------------------------------------------------------------
// | likeadmin快速开发前后端分离管理后台（PHP版）
// +----------------------------------------------------------------------
// | 欢迎阅读学习系统程序代码，建议反馈是我们前进的动力
// | 开源版本可自由商用，可去除界面版权logo
// | gitee下载：https://gitee.com/likeshop_gitee/likeadmin
// | github下载：https://github.com/likeshop-github/likeadmin
// | 访问官网：https://www.likeadmin.cn
// | likeadmin团队 版权所有 拥有最终解释权
// +----------------------------------------------------------------------
// | author: likeadminTeam
// +----------------------------------------------------------------------
namespace app\common\service\wechat;


use app\common\model\Config;
use EasyWeChat\Kernel\Exceptions\Exception;
use EasyWeChat\MiniApp\Application;


/**
 * 微信功能类
 * Class WeChatMnpService
 * @package app\common\service
 */
class WeChatMnpService
{

    protected $app;

    protected $config;

    public function __construct()
    {
        $this->config = $this->getConfig();
        $this->app = new Application($this->config);
    }


    /**
     * @notes 配置加载：登录阶段不依赖租户上下文
     * 优先级：.env 环境变量 > 平台级 la_config 表
     * @return array
     * @throws \Exception
     */
    protected function getConfig()
    {
        // 登录阶段优先从 .env 读取（[PROJECT] section 下的键以 project. 前缀访问），
        // 同时兼容顶层无 section 写法，避免多租户 tenantId 为空导致查询失败
        $appId = env('project.wechat_mnp_app_id', env('WECHAT_MNP_APP_ID', ''));
        $secret = env('project.wechat_mnp_app_secret', env('WECHAT_MNP_APP_SECRET', ''));

        // .env 为空时尝试从平台级配置表查询（绕过租户隔离）
        if (empty($appId)) {
            $appId = Config::where(['type' => 'mnp_setting', 'name' => 'app_id'])->value('value', '');
        }
        if (empty($secret)) {
            $secret = Config::where(['type' => 'mnp_setting', 'name' => 'app_secret'])->value('value', '');
        }

        if (empty($appId) || empty($secret)) {
            throw new \Exception('请先设置小程序配置（AppID/AppSecret）');
        }

        return [
            'app_id' => $appId,
            'secret' => $secret,
            'response_type' => 'array',
            'log' => [
                'level' => 'debug',
                'file' => app()->getRootPath() . 'runtime/wechat/' . date('Ym') . '/' . date('d') . '.log'
            ],
        ];
    }


    /**
     * @notes 小程序-根据code获取微信信息
     * @param string $code
     * @return array
     * @throws Exception
     * @throws \EasyWeChat\Kernel\Exceptions\HttpException
     * @throws \EasyWeChat\Kernel\Exceptions\InvalidArgumentException
     * @throws \Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     * @author 段誉
     * @date 2023/2/27 11:03
     */
    public function getMnpResByCode(string $code)
    {
        $utils = $this->app->getUtils();
        $response = $utils->codeToSession($code);

        if (!isset($response['openid']) || empty($response['openid'])) {
            throw new Exception('获取openID失败');
        }

        return $response;
    }


    /**
     * @notes 获取手机号
     * @param string $code
     * @return \EasyWeChat\Kernel\HttpClient\Response|\Symfony\Contracts\HttpClient\ResponseInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     * @author 段誉
     * @date 2023/2/27 11:46
     */
    public function getUserPhoneNumber(string $code)
    {
        return $this->app->getClient()->postJson('wxa/business/getuserphonenumber', [
            'code' => $code,
        ]);
    }


}