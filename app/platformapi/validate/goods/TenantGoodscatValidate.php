<?php

namespace app\platformapi\validate\goods;

use app\common\validate\BaseValidate;

class TenantGoodscatValidate extends BaseValidate
{
    protected $rule = [
        'id' => 'require',
        'name' => 'require|max:64',
        'sort' => 'integer|egt:0',
        'is_show' => 'require|integer|in:0,1',
    ];

    protected $field = [
        'id' => '分类ID',
        'name' => '分类名称',
        'sort' => '排序',
        'is_show' => '状态',
    ];

    public function sceneAdd()
    {
        return $this->only(['name', 'sort', 'is_show']);
    }

    public function sceneEdit()
    {
        return $this->only(['id', 'name', 'sort', 'is_show']);
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
