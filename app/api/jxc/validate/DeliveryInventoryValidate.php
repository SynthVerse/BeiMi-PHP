<?php

namespace app\api\jxc\validate;

use app\common\validate\BaseValidate;

final class DeliveryInventoryValidate extends BaseValidate
{
    protected $rule = [
        'id' => 'require|integer|gt:0',
        'task_id' => 'require|integer|gt:0',
        'event_type' => 'require|in:customer_handoff,vehicle_departed',
        'handoff_note' => 'max:500',
        'exception_reason' => 'max:500',
        'second_confirmed' => 'in:0,1',
        'idempotency_key' => 'require|max:96',
        'action' => 'require|in:wait_inbound,record_missing_inbound,inventory_writeoff,retain',
        'quantity' => 'max:20',
        'amount' => 'max:20',
        'reason' => 'max:500',
        'cost_status' => 'in:confirmed,pending',
        'status' => 'in:open,closed,all',
    ];

    public function sceneConfirmSelf() { return $this->only(['task_id', 'event_type', 'handoff_note', 'exception_reason', 'second_confirmed', 'idempotency_key']); }
    public function sceneDetail() { return $this->only(['id']); }
    public function sceneNegativeTodos() { return $this->only(['status']); }
    public function sceneResolveNegative() { return $this->only(['id', 'action', 'quantity', 'amount', 'reason', 'cost_status', 'idempotency_key']); }
}
