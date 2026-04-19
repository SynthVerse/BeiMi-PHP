<?php
namespace app\tenantapi\validate\user;


use app\common\validate\BaseValidate;


/**
 * UserGroup验证器
 * Class UserGroupValidate
 * @package app\platform\validate
 */
class UserGroupValidate extends BaseValidate
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
        'name' => '分类名称',
        'sort' => '排序',
        'is_show' => '是否显示',
    ];


    /**
     * @notes 添加场景
     * @return UserGroupValidate
     * @author likeadmin
     * @date 2025/12/08 09:46
     */
    public function sceneAdd()
    {
        return $this->only(['name','sort','is_show','tenant_id']);
    }


    /**
     * @notes 编辑场景
     * @return UserGroupValidate
     * @author likeadmin
     * @date 2025/12/08 09:46
     */
    public function sceneEdit()
    {
        return $this->only(['id','name','sort','is_show','tenant_id']);
    }


    /**
     * @notes 删除场景
     * @return UserGroupValidate
     * @author likeadmin
     * @date 2025/12/08 09:46
     */
    public function sceneDelete()
    {
        return $this->only(['id']);
    }


    /**
     * @notes 详情场景
     * @return UserGroupValidate
     * @author likeadmin
     * @date 2025/12/08 09:46
     */
    public function sceneDetail()
    {
        return $this->only(['id']);
    }

}