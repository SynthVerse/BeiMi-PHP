<?php

namespace app\api\jxc\validate;

use app\common\validate\BaseValidate;

final class DeliveryInventoryValidate extends BaseValidate
{
    protected $rule = [
        'id' => 'integer|gt:0',
        'task_id' => 'require|integer|gt:0',
        'event_type' => 'require|in:customer_handoff,vehicle_departed,third_party_driver_handoff',
        'driver_id' => 'require|integer|gt:0',
        'actual_handoff_time' => 'max:19',
        'items' => 'array|min:1',
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
        'attribution_id' => 'integer|gt:0',
        'version' => 'integer|gt:0',
        'name' => 'require|max:120',
        'mobile' => 'require|max:32',
        'platform' => 'require|max:80',
        'vehicle_no' => 'max:32',
        'is_enabled' => 'in:0,1',
    ];

    public function sceneConfirmSelf() { return $this->only(['task_id', 'event_type', 'items', 'handoff_note', 'exception_reason', 'second_confirmed', 'idempotency_key']); }
    public function sceneConfirmThirdParty() { return $this->only(['task_id', 'driver_id', 'actual_handoff_time', 'items', 'handoff_note', 'exception_reason', 'second_confirmed', 'idempotency_key']); }
    public function sceneDriverSave() { return $this->only(['id', 'version', 'name', 'mobile', 'platform', 'vehicle_no', 'is_enabled']); }
    public function sceneDriverLists() { return $this->only(['is_enabled']); }
    public function sceneDetail() { return $this->only(['id'])->append('id', 'require'); }
    public function sceneNegativeTodos() { return $this->only(['status', 'attribution_id']); }
    public function sceneResolveNegative()
    {
        return $this->only(['id', 'action', 'quantity', 'amount', 'reason', 'cost_status', 'idempotency_key'])
            ->append('id', 'require');
    }
}
