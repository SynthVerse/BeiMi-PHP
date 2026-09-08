<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 期初截点和成本承接共用实物发生时点；晚录交付不能改成录入日。 */
final class FinanceStockFactTime
{
    public static function resolve(int $tenant, array $flow, bool $lock = false): int
    {
        if ((int)$flow['tenant_id'] !== $tenant) { throw new \DomainException('库存事实不属于当前门店'); }
        $eventId = 0;
        if ($flow['order_type'] === 'delivery_transport_loss') { $eventId = (int)$flow['order_id']; }
        if ($flow['order_type'] === 'sales_delivery') {
            if (!preg_match('/^实际交付出库-事件([1-9][0-9]*)$/uD', $flow['remark'], $match)) {
                throw new \DomainException('交付流水缺少可核对的原交付事件，尚未核实实际日期');
            }
            $eventId = (int)$match[1];
        }
        if (in_array($flow['order_type'], ['sales_delivery', 'delivery_transport_loss'], true)) {
            $event = Db::name('fulfillment_delivery_event')->where('tenant_id', $tenant)->where('id', $eventId)->lock($lock)->find();
            if (!$event) { throw new \DomainException('库存对应的原交付事件不存在，尚未核实实际日期'); }
            $time = (int)(($event['actual_handoff_time'] ?? 0) ?: (($event['delivered_time'] ?? 0) ?: $event['create_time']));
        } else { $time = (int)$flow['create_time']; }
        if ($time <= 0 || $time > time()) { throw new \DomainException('库存来源实际日期尚未核实或晚于当前时间'); }
        return $time;
    }
}
