<?php
namespace app\tenantapi\validate\user;


use app\common\validate\BaseValidate;


/**
 * UserMoney验证器
 * Class UserMoneyValidate
 * @package app\tenantapi\validate\user
 */
class UserMoneyValidate extends BaseValidate
{

    /**
     * 设置校验规则
     * @var string[]
     */
    protected $rule = [
        'id' => 'require',
        'user_id' => 'require',
        'money' => 'require',
        'order_ids' => 'require',
    ];


    /**
     * 参数描述
     * @var string[]
     */
    protected $field = [
        'id' => 'id',
        'user_id' => '客户',
        'money' => '金额',
        'order_ids' => '订单id',
    ];


    /**
     * @notes 添加场景
     * @return UserMoneyValidate
     * @author likeadmin
     * @date 2026/01/07 09:41
     */
    public function sceneAdd()
    {
        return $this->only(['user_id','money','order_ids']);
    }


    /**
     * @notes 编辑场景
     * @return UserMoneyValidate
     * @author likeadmin
     * @date 2026/01/07 09:41
     */
    public function sceneEdit()
    {
        return $this->only(['id','user_id','money','order_ids']);
    }


    /**
     * @notes 删除场景
     * @return UserMoneyValidate
     * @author likeadmin
     * @date 2026/01/07 09:41
     */
    public function sceneDelete()
    {
        return $this->only(['id']);
    }


    /**
     * @notes 详情场景
     * @return UserMoneyValidate
     * @author likeadmin
     * @date 2026/01/07 09:41
     */
    public function sceneDetail()
    {
        return $this->only(['id']);
    }

}