<?php

namespace app\api\jxc\validate;

use app\common\validate\BaseValidate;

class GoodsUnitValidate extends BaseValidate
{
    protected $rule = [
        'id' => 'require|integer|gt:0',
        'name' => 'require|max:10',
        'sort' => 'integer',
        'status' => 'integer|in:0,1',
    ];

    protected $field = [
        'id' => '单位ID',
        'name' => '单位名称',
        'sort' => '排序',
        'status' => '状态',
    ];

    public function sceneAdd()
    {
        return $this->only(['name', 'sort', 'status']);
    }

    public function sceneEdit()
    {
        return $this->only(['id', 'name', 'sort', 'status'])
            ->remove('name', 'require');
    }

    public function sceneDelete()
    {
        return $this->only(['id']);
    }

    public function sceneDetail()
    {
        return $this->only(['id']);
    }
}
