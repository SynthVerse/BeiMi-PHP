<?php
namespace app\tenantapi\validate\supplier;


use app\common\validate\BaseValidate;


/**
 * UserSupplierMoney验证器
 * Class UserSupplierMoneyValidate
 * @package app\tenantapi\validate\supplier
 */
class UserSupplierMoneyValidate extends BaseValidate
{

    /**
     * 设置校验规则
     * @var string[]
     */
    protected $rule = [
        'id' => 'require',
        'tenant_id' => 'require',
        'supplier_id' => 'require',
        'money' => 'require',
        'order_ids' => 'require',
        'createtime' => 'require',
    ];


    /**
     * 参数描述
     * @var string[]
     */
    protected $field = [
        'id' => 'id',
        'tenant_id' => '店铺id',
        'supplier_id' => '供应商',
        'money' => '金额',
        'order_ids' => '订单id',
        'createtime' => '创建时间',
    ];


    /**
     * @notes 添加场景
     * @return UserSupplierMoneyValidate
     * @author likeadmin
     * @date 2026/01/06 16:10
     */
    public function sceneAdd()
    {
        return $this->only(['tenant_id','supplier_id','money','order_ids','createtime']);
    }


    /**
     * @notes 编辑场景
     * @return UserSupplierMoneyValidate
     * @author likeadmin
     * @date 2026/01/06 16:10
     */
    public function sceneEdit()
    {
        return $this->only(['id','tenant_id','supplier_id','money','order_ids','createtime']);
    }


    /**
     * @notes 删除场景
     * @return UserSupplierMoneyValidate
     * @author likeadmin
     * @date 2026/01/06 16:10
     */
    public function sceneDelete()
    {
        return $this->only(['id']);
    }


    /**
     * @notes 详情场景
     * @return UserSupplierMoneyValidate
     * @author likeadmin
     * @date 2026/01/06 16:10
     */
    public function sceneDetail()
    {
        return $this->only(['id']);
    }

}