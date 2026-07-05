<?php

namespace app\api\jxc\validate;

use app\common\validate\BaseValidate;

class TaskRoleValidate extends BaseValidate
{
    protected $rule = [
        'id' => 'require|integer|gt:0',
        'code' => 'require|alphaDash|max:50',
        'name' => 'require|max:100',
        'is_enabled' => 'integer|in:0,1',
        'status' => 'integer|in:0,1',
    ];

    public function sceneCreate()
    {
        return $this->only(['code', 'name', 'is_enabled']);
    }

    public function sceneEdit()
    {
        return $this->only(['id', 'name', 'is_enabled']);
    }

    public function sceneStatus()
    {
        return $this->only(['id', 'is_enabled', 'status']);
    }

    public function sceneDetail()
    {
        return $this->only(['id']);
    }
}
