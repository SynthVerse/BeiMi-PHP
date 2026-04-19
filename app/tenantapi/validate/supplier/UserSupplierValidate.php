<?php
namespace app\tenantapi\validate\supplier;


use app\common\validate\BaseValidate;


/**
 * UserSupplier验证器
 * Class UserSupplierValidate
 * @package app\tenantapi\validate\supplier
 */
class UserSupplierValidate extends BaseValidate
{

    /**
     * 设置校验规则
     * @var string[]
     */
    protected $rule = [
        'id' => 'require',
        'name' => 'require',
        'order_money' => 'require',
        'pay_type' => 'require|in:1,2',
    ];


    /**
     * 参数描述
     * @var string[]
     */
    protected $field = [
        'id' => 'id',
        'name' => '供应商名称',
        'order_money' => '订单金额',
        'pay_type' => '支付方式',
    ];


    /**
     * @notes 添加场景
     * @return UserSupplierValidate
     * @author likeadmin
     * @date 2025/12/22 16:14
     */
    public function sceneAdd()
    {
        return $this->only(['name']);
    }


    /**
     * @notes 编辑场景
     * @return UserSupplierValidate
     * @author likeadmin
     * @date 2025/12/22 16:14
     */
    public function sceneEdit()
    {
        return $this->only(['id','name']);
    }


    /**
     * @notes 删除场景
     * @return UserSupplierValidate
     * @author likeadmin
     * @date 2025/12/22 16:14
     */
    public function sceneDelete()
    {
        return $this->only(['id']);
    }


    /**
     * @notes 详情场景
     * @return UserSupplierValidate
     * @author likeadmin
     * @date 2025/12/22 16:14
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