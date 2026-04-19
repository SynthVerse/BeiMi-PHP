<?php

namespace app\tenantapi\validate\user;


use app\common\validate\BaseValidate;


/**
 * UserOrder验证器
 * Class UserOrderValidate
 * @package app\tenantapi\validate\user
 */
class UserOrderValidate extends BaseValidate
{

    /**
     * 设置校验规则
     * @var string[]
     */
    protected $rule = [
        'id' => 'require',
        'user_id' => 'require',
        'order_money' => 'require',
        'order_pay_money' => 'require',
        'order_arrears_money' => 'require',
        'goods_number' => 'require',
        'status' => 'require',
        'pay_status' => 'require',
        'goods' => 'require|array',
        'name'  => 'require',
        'weight'  => 'require',
        'price'  => 'require',
        'units' => 'require',
        'pay_type' => 'require|in:1,2',
    ];


    /**
     * 参数描述
     * @var string[]
     */
    protected $field = [
        'id' => 'id',
        'user_id' => '用户',
        'order_money' => '订单金额',
        'order_pay_money' => '付款金额',
        'order_arrears_money' => '订单欠款金额',
        'goods_number' => '商品数量',
        'status' => '状态:1=未付款,2=部分付款,3=已付款',
        'pay_status' => '支付状态:1=未完成,2=已完成',
        'goods' => '商品信息',
        'name'  => '商品名称',
        'weight'  => '商品重量',
        'price'  => '商品单价',
        'units' => '商品',
        'pay_type' => '支付方式',
    ];


    /**
     * @notes 添加场景
     * @return UserOrderValidate
     * @author likeadmin
     * @date 2026/01/07 09:40
     */
    public function sceneAdd()
    {
        return $this->only(['user_id', 'goods', 'order_money', 'order_weight']);
    }

    /**
     * @notes 添加场景
     * @author likeadmin
     * @date 2025/12/22 16:53
     */
    public function sceneAddpar()
    {
        return $this->only(['id','name','weight','price','units']);
    }



    /**
     * @notes 编辑场景
     * @return UserOrderValidate
     * @author likeadmin
     * @date 2026/01/07 09:40
     */
    public function sceneEdit()
    {
        return $this->only(['id', 'user_id', 'order_money', 'order_pay_money', 'order_arrears_money', 'goods_number', 'status', 'pay_status']);
    }


    /**
     * @notes 删除场景
     * @return UserOrderValidate
     * @author likeadmin
     * @date 2026/01/07 09:40
     */
    public function sceneDelete()
    {
        return $this->only(['id']);
    }


    /**
     * @notes 详情场景
     * @return UserOrderValidate
     * @author likeadmin
     * @date 2026/01/07 09:40
     */
    public function sceneDetail()
    {
        return $this->only(['id']);
    }

    /**
     * @notes 付款场景
     * @author 金毛失望
     * @date 2025/12/22 16:53
     */
    public function scenePays()
    {
        return $this->only(['id','order_money','pay_type']);
    }

}