<?php
namespace app\tenantapi\validate\supplier;


use app\common\validate\BaseValidate;


/**
 * UserSupplierOrder验证器
 * Class UserSupplierOrderValidate
 * @package app\tenantapi\validate\supplier
 */
class UserSupplierOrderValidate extends BaseValidate
{

    /**
     * 设置校验规则
     * @var string[]
     */
    protected $rule = [
        'id' => 'require',
        'supplier_id' => 'require',
        'order_money' => 'require',
        'order_weight' => 'require',
        'order_pay_money' => 'require',
        'order_arrears_money' => 'require',
        'goods_number' => 'require',
        'status' => 'require',
        'pay_status' => 'require',
        'goods' => 'require|array',
        'createtime' => 'require',
        'updatetime' => 'require',
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
        'order_sn' => '订单流水号',
        'tenant_id' => '店铺',
        'supplier_id' => '供应商',
        'order_money' => '订单金额',
        'order_weight' => '订单重量',
        'order_pay_money' => '付款金额',
        'order_arrears_money' => '订单欠款金额',
        'goods_number' => '商品数量',
        'status' => '状态:1=未付款,2=部分付款,3=已付款',
        'pay_status' => '支付状态:1=未完成,2=已完成',
        'goods' => '商品信息',
        'createtime' => '创建时间',
        'updatetime' => '更新时间',
        'name'  => '商品名称',
        'weight'  => '商品重量',
        'price'  => '商品单价',
        'units' => '商品',
        'pay_type' => '支付方式',
    ];


    /**
     * @notes 添加场景
     * @return UserSupplierOrderValidate
     * @author likeadmin
     * @date 2025/12/22 16:53
     */
    public function sceneAdd()
    {
        return $this->only(['supplier_id','goods','order_money','order_weight']);
    }


    /**
     * @notes 添加场景
     * @return UserSupplierOrderValidate
     * @author likeadmin
     * @date 2025/12/22 16:53
     */
    public function sceneAddpar()
    {
        return $this->only(['id','name','weight','price','units']);
    }


    /**
     * @notes 编辑场景
     * @return UserSupplierOrderValidate
     * @author likeadmin
     * @date 2025/12/22 16:53
     */
    public function sceneEdit()
    {
        return $this->only(['id','order_sn','tenant_id','supplier_id','customer','order_money','order_pay_money','order_arrears_money','goods_number','status','pay_status','createtime','updatetime']);
    }


    /**
     * @notes 删除场景
     * @return UserSupplierOrderValidate
     * @author likeadmin
     * @date 2025/12/22 16:53
     */
    public function sceneDelete()
    {
        return $this->only(['id']);
    }


    /**
     * @notes 详情场景
     * @return UserSupplierOrderValidate
     * @author likeadmin
     * @date 2025/12/22 16:53
     */
    public function sceneDetail()
    {
        return $this->only(['id']);
    }

    /**
     * @notes 付款场景
     * @return UserSupplierOrderValidate
     * @author 金毛失望
     * @date 2025/12/22 16:53
     */
    public function scenePays()
    {
        return $this->only(['id','order_money','pay_type']);
    }

}