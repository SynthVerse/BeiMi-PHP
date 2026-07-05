<?php

namespace app\api\jxc\validate;

use app\common\validate\BaseValidate;

class TaskTypeValidate extends BaseValidate
{
    protected $rule = [
        'id' => 'require|integer|gt:0',
        'code' => 'require|max:50',
        'name' => 'require|max:100',
        'role_codes' => 'array',
        'is_enabled' => 'integer|in:0,1',
        'status' => 'integer|in:0,1',
    ];

    public function sceneCreate()
    {
        return $this->only(['code', 'name', 'role_codes', 'is_enabled']);
    }

    public function sceneEdit()
    {
        return $this->only(['id', 'name', 'role_codes', 'is_enabled']);
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
