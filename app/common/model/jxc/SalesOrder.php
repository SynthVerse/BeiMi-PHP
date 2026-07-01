<?php

namespace app\common\model\jxc;

use app\common\model\BaseModel;

class SalesOrder extends BaseModel
{
    protected $name = 'sales_order';

    public const STATUS_SOLD = 1;
    public const STATUS_PART_RETURNED = 2;
    public const STATUS_RETURNED = 3;

    public static function statusLabel(int $status): string
    {
        return match ($status) {
            self::STATUS_PART_RETURNED => '部分退货',
            self::STATUS_RETURNED => '已退货',
            default => '已销售',
        };
    }
}
