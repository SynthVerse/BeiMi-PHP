<?php

namespace app\api\jxc\validate;

use app\common\validate\BaseValidate;

class CustomerGroupValidate extends BaseValidate
{
    protected $rule = [
        'id' => 'integer|gt:0',
        'group_name' => 'max:100',
        'name' => 'max:100',
        'old_group_name' => 'max:100',
        'new_group_name' => 'max:100',
        'sort' => 'integer',
    ];

    protected $field = [
        'id' => '分组ID',
        'group_name' => '分组名称',
        'name' => '分组名称',
        'old_group_name' => '旧分组名称',
        'new_group_name' => '新分组名称',
        'sort' => '排序',
    ];

    public function sceneAdd()
    {
        return $this->only(['group_name', 'name', 'sort']);
    }

    public function sceneRename()
    {
        return $this->only(['id', 'group_name', 'old_group_name', 'new_group_name', 'name', 'sort']);
    }

    public function sceneDelete()
    {
        return $this->only(['id', 'group_name', 'name']);
    }

    public function sceneDetail()
    {
        return $this->only(['id'])
            ->append('id', 'require');
    }
}
