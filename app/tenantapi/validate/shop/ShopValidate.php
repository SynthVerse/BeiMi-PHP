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

namespace app\tenantapi\validate\shop;

use app\common\model\dept\TenantDept;
use app\common\validate\BaseValidate;


/**
 * 店铺验证器
 * Class ShopValidate
 * @package app\tenantapi\validate\shop
 */
class ShopValidate extends BaseValidate
{

    protected $rule = [
        'id' => 'require|checkShop',
        'name' => 'require|length:1,30',
        'mobile' => 'mobile',
        'address' => 'max:200',
        'status' => 'in:0,1',
    ];


    protected $message = [
        'id.require' => '参数缺失',
        'name.require' => '请填写店铺名称',
        'name.length' => '店铺名称长度须在1-30位字符',
        'mobile.mobile' => '联系电话格式不正确',
        'address.max' => '地址长度不能超过200个字符',
        'status.in' => '状态值不正确',
    ];


    /**
     * @notes 添加场景
     * @return ShopValidate
     */
    public function sceneAdd()
    {
        return $this->only(['name', 'mobile', 'address', 'status']);
    }


    /**
     * @notes 编辑场景
     * @return ShopValidate
     */
    public function sceneEdit()
    {
        return $this->only(['id', 'name', 'mobile', 'address', 'status']);
    }


    /**
     * @notes 详情场景
     * @return ShopValidate
     */
    public function sceneDetail()
    {
        return $this->only(['id']);
    }


    /**
     * @notes 删除场景
     * @return ShopValidate
     */
    public function sceneDelete()
    {
        return $this->only(['id']);
    }


    /**
     * @notes 校验店铺是否存在
     * @param $value
     * @return bool|string
     */
    public function checkShop($value)
    {
        $shop = TenantDept::findOrEmpty($value);
        if ($shop->isEmpty()) {
            return '店铺不存在';
        }
        return true;
    }

}
