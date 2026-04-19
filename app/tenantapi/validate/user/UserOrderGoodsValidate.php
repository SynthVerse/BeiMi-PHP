<?php
namespace app\tenantapi\validate\user;


use app\common\validate\BaseValidate;


/**
 * UserOrderGoods验证器
 * Class UserOrderGoodsValidate
 * @package app\tenantapi\validate\user
 */
class UserOrderGoodsValidate extends BaseValidate
{

    /**
     * 设置校验规则
     * @var string[]
     */
    protected $rule = [
        'id' => 'require',
    ];


    /**
     * 参数描述
     * @var string[]
     */
    protected $field = [
        'id' => 'id',
    ];


    /**
     * @notes 添加场景
     * @return UserOrderGoodsValidate
     * @author likeadmin
     * @date 2026/01/07 09:41
     */
    public function sceneAdd()
    {
        return $this->remove('id', true);
    }


    /**
     * @notes 编辑场景
     * @return UserOrderGoodsValidate
     * @author likeadmin
     * @date 2026/01/07 09:41
     */
    public function sceneEdit()
    {
        return $this->only(['id']);
    }


    /**
     * @notes 删除场景
     * @return UserOrderGoodsValidate
     * @author likeadmin
     * @date 2026/01/07 09:41
     */
    public function sceneDelete()
    {
        return $this->only(['id']);
    }


    /**
     * @notes 详情场景
     * @return UserOrderGoodsValidate
     * @author likeadmin
     * @date 2026/01/07 09:41
     */
    public function sceneDetail()
    {
        return $this->only(['id']);
    }

}