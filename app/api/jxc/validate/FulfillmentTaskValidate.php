<?php

namespace app\api\jxc\validate;

use app\common\validate\BaseValidate;

final class FulfillmentTaskValidate extends BaseValidate
{
    protected $rule = [
        'id' => 'require|integer|gt:0',
        'employee_id' => 'require|integer|gt:0',
        'process_id' => 'require|integer|gt:0',
        'print_log_id' => 'require|integer|gt:0',
        'success' => 'require|in:0,1',
        'error_message' => 'max:255',
        'actual_weight' => 'max:20',
        'actual_price' => 'max:20',
        'recovery_note' => 'max:500',
        'requirement' => 'max:500',
        'delivery_date' => 'dateFormat:Y-m-d',
        'status_scope' => 'in:printable,working,recovered,exception',
    ];

    public function sceneDashboard() { return $this->only(['delivery_date']); }
    public function sceneLists() { return $this->only(['status_scope']); }
    public function sceneDetail() { return $this->only(['id']); }
    public function sceneCandidates() { return $this->only(['id']); }
    public function sceneAssign() { return $this->only(['id', 'employee_id']); }
    public function sceneResolve() { return $this->only(['id', 'process_id', 'requirement']); }
    public function scenePrintData() { return $this->only(['id']); }
    public function scenePrintResult() { return $this->only(['id', 'print_log_id', 'success', 'error_message']); }
    public function sceneRecover() { return $this->only(['id', 'actual_weight', 'actual_price', 'recovery_note']); }
    public function sceneBill() { return $this->only(['id']); }
}
