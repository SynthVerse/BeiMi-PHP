<?php

namespace app\common\model\jxc;

use app\common\model\BaseModel;

class SupplyOrder extends BaseModel
{
    protected $name = 'supply_order';

    public const RETURN_STATUS_NONE = 0;
    public const RETURN_STATUS_PARTIAL = 1;
    public const RETURN_STATUS_FULL = 2;

    public static function returnStatusLabel(int $status): string
    {
        return match ($status) {
            self::RETURN_STATUS_PARTIAL => '部分退货',
            self::RETURN_STATUS_FULL => '已退货',
            default => '未退货',
        };
    }
}
