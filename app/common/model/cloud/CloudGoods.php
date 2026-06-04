<?php

namespace app\common\model\cloud;

use think\Model;

class CloudGoods extends Model
{
    public const SCOPE_PUBLIC = 1;

    public const STATUS_DISABLED = 0;
    public const STATUS_ENABLED = 1;
    public const STATUS_ARCHIVED = 2;

    protected $name = 'cloud_goods';

    public function getScopeDescAttr($value, $data): string
    {
        return '公共库';
    }

    public function getStatusDescAttr($value, $data): string
    {
        return match ((int)($data['status'] ?? 0)) {
            self::STATUS_ENABLED => '启用',
            self::STATUS_ARCHIVED => '已归档',
            default => '停用',
        };
    }
}
