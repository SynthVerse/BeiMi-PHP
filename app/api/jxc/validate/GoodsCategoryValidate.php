<?php

declare(strict_types=1);

namespace app\api\jxc\validate;

use app\common\validate\BaseValidate;

final class GoodsCategoryValidate extends BaseValidate
{
    protected $rule = [
        'name' => 'require|max:64',
    ];

    protected $field = [
        'name' => '分类名称',
    ];

    public function sceneAdd(): self
    {
        return $this->only(['name']);
    }
}
