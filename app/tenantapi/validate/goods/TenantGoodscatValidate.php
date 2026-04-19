<?php
namespace app\tenantapi\validate\goods;


use app\common\validate\BaseValidate;


/**
 * TenantGoodscat验证器
 * Class TenantGoodscatValidate
 * @package app\tenantapi\validate\goods
 */
class TenantGoodscatValidate extends BaseValidate
{

    /**
     * 设置校验规则
     * @var string[]
     */
    protected $rule = [
        'id' => 'require',
        'name' => 'require',
        'is_show' => 'require',
    ];


    /**
     * 参数描述
     * @var string[]
     */
    protected $field = [
        'id' => 'id',
        'name' => '分类名称',
        'is_show' => '是否显示: 0=否, 1=是',
    ];


    /**
     * @notes 添加场景
     * @return TenantGoodscatValidate
     * @author likeadmin
     * @date 2025/12/24 09:09
     */
    public function sceneAdd()
    {
        return $this->only(['name','is_show']);
    }


    /**
     * @notes 编辑场景
     * @return TenantGoodscatValidate
     * @author likeadmin
     * @date 2025/12/24 09:09
     */
    public function sceneEdit()
    {
        return $this->only(['id','name','is_show']);
    }


    /**
     * @notes 删除场景
     * @return TenantGoodscatValidate
     * @author likeadmin
     * @date 2025/12/24 09:09
     */
    public function sceneDelete()
    {
        return $this->only(['id']);
    }


    /**
     * @notes 详情场景
     * @return TenantGoodscatValidate
     * @author likeadmin
     * @date 2025/12/24 09:09
     */
    public function sceneDetail()
    {
        return $this->only(['id']);
    }

}