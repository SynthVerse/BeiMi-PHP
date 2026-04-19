<?php
namespace app\tenantapi\validate\recharge;


use app\common\validate\BaseValidate;


/**
 * RechargeOrder验证器
 * Class RechargeOrderValidate
 * @package app\platform\validate
 */
class RechargeOrderValidate extends BaseValidate
{

    /**
     * 设置校验规则
     * @var string[]
     */
    protected $rule = [
        'id' => 'require',
        'user_id' => 'require',
        'pay_way' => 'require',
        'pay_status' => 'require',
        'order_amount' => 'require',
        'from' => 'require',
    ];

    /**
     * 参数描述
     * @var string[]
     */
    protected $field = [
        'id' => 'id',
        'user_id' => '用户id',
        'pay_way' => '支付方式 2-微信支付 3-支付宝支付',
        'pay_status' => '支付状态：0-待支付；1-已支付,2-取消支付',
        'order_amount' => '充值金额',
        'from'  => '来源',
    ];


    /**
     * @notes 添加场景
     * @return RechargeOrderValidate
     * @author likeadmin
     * @date 2025/12/08 14:01
     */
    public function sceneAdd()
    {
        return $this->only(['user_id','pay_way','order_amount']);
    }


    /**
     * @notes 编辑场景
     * @return RechargeOrderValidate
     * @author likeadmin
     * @date 2025/12/08 14:01
     */
    public function sceneEdit()
    {
        return $this->only(['id','tenant_id','sn','user_id','pay_way','pay_status','order_amount']);
    }



    /**
     * @notes 删除场景
     * @return RechargeOrderValidate
     * @author likeadmin
     * @date 2025/12/08 14:01
     */
    public function sceneDelete()
    {
        return $this->only(['id']);
    }

    /**
     * @notes 支付场景
     * @return RechargeOrderValidate
     * @author likeadmin
     * @date 2025/12/08 14:01
     */
    public function scenePay()
    {
        return $this->only(['id','from']);
    }

    /**
     * @notes 详情场景
     * @return RechargeOrderValidate
     * @author likeadmin
     * @date 2025/12/08 14:01
     */
    public function sceneDetail()
    {
        return $this->only(['id']);
    }

}