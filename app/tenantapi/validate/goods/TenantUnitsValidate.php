<?php
namespace app\tenantapi\validate\goods;


use app\common\validate\BaseValidate;


/**
 * TenantUnits验证器
 * Class TenantUnitsValidate
 * @package app\platform\validate
 */
class TenantUnitsValidate extends BaseValidate
{

    /**
     * 设置校验规则
     * @var string[]
     */
    protected $rule = [
        'id' => 'require',
        'name' => 'require',
        'sort' => 'require',
        'is_show' => 'require',
    ];


    /**
     * 参数描述
     * @var string[]
     */
    protected $field = [
        'id' => 'id',
        'name' => '单位名称',
        'sort' => '排序',
        'is_show' => '是否显示',
    ];


    /**
     * @notes 添加场景
     * @return TenantUnitsValidate
     * @author likeadmin
     * @date 2025/12/04 14:20
     */
    public function sceneAdd()
    {
        return $this->only(['name','sort','is_show']);
    }


    /**
     * @notes 编辑场景
     * @return TenantUnitsValidate
     * @author likeadmin
     * @date 2025/12/04 14:20
     */
    public function sceneEdit()
    {
        return $this->only(['id','name','sort','is_show']);
    }


    /**
     * @notes 删除场景
     * @return TenantUnitsValidate
     * @author likeadmin
     * @date 2025/12/04 14:20
     */
    public function sceneDelete()
    {
        return $this->only(['id']);
    }


    /**
     * @notes 详情场景
     * @return TenantUnitsValidate
     * @author likeadmin
     * @date 2025/12/04 14:20
     */
    public function sceneDetail()
    {
        return $this->only(['id']);
    }

}