<?php

namespace app\common\model\cloud;

use think\Model;

class CloudGoods extends Model
{
    public const SCOPE_PUBLIC = 1;
    public const SCOPE_PRIVATE = 2;

    public const STATUS_DISABLED = 0;
    public const STATUS_ENABLED = 1;

    protected $name = 'cloud_goods';

    public function getScopeDescAttr($value, $data): string
    {
        return (int)($data['scope'] ?? 0) === self::SCOPE_PUBLIC ? '公共库' : '私有库';
    }

    public function getStatusDescAttr($value, $data): string
    {
        return (int)($data['status'] ?? 0) === self::STATUS_ENABLED ? '启用' : '停用';
    }
}
