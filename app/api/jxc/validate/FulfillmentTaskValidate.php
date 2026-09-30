<?php

namespace app\api\jxc\validate;

use app\common\validate\BaseValidate;

final class FulfillmentTaskValidate extends BaseValidate
{
    protected $rule = [
        'id' => 'require|integer|gt:0',
        'process_id' => 'require|integer|gt:0',
        'print_log_id' => 'integer|gt:0',
        'control_id' => 'integer|gt:0',
        'report_item_id' => 'integer|gt:0',
        'success' => 'require|in:0,1',
        'error_message' => 'max:255',
        'actual_weight' => 'max:20',
        'actual_price' => 'max:20',
        'recovery_note' => 'max:500',
        'specification_result' => 'in:confirmed',
        'verified_piece_count' => 'integer|gt:0',
        'verified_piece_weight_min' => 'max:20',
        'verified_piece_weight_max' => 'max:20',
        'specification_note' => 'max:500',
        'requirement' => 'max:500',
        'delivery_date' => 'dateFormat:Y-m-d',
        'status_scope' => 'in:printable,working,recovered,exception',
        'exception_reason' => 'in:lost,damaged,illegible',
        'exception_note' => 'max:500',
        'resolution' => 'in:recovered,unrecoverable',
        'note' => 'max:500',
        'new_expected_base_qty' => 'max:20',
        'processed_reduction_qty' => 'max:20',
        'processed_disposition' => 'in:return_to_stock,internal_loss,other',
        'other_inventory_action' => 'in:release,consume',
        'reason_code' => 'in:shortage,damage,customer_cancel',
        'reason' => 'max:500',
        'idempotency_key' => 'max:96',
        'version' => 'integer|gt:0',
        'delivery_arrangement' => 'array',
    ];

    public function sceneDashboard() { return $this->only(['delivery_date']); }
    public function sceneLists() { return $this->only(['status_scope']); }
    public function sceneDetail() { return $this->only(['id']); }
    public function sceneResolve() { return $this->only(['id', 'process_id', 'requirement']); }
    public function scenePrintData() { return $this->only(['id']); }
    public function scenePrintResult() { return $this->only(['id', 'print_log_id', 'success', 'error_message'])->append('print_log_id', 'require'); }
    public function sceneRecover() { return $this->only(['id', 'print_log_id', 'actual_weight', 'actual_price', 'recovery_note', 'specification_result', 'verified_piece_count', 'verified_piece_weight_min', 'verified_piece_weight_max', 'specification_note']); }
    public function sceneSpecificationShortage() { return $this->only(['id', 'print_log_id', 'specification_note'])->append('specification_note', 'require'); }
    public function sceneRecoverException() { return $this->only(['id', 'print_log_id', 'actual_weight', 'actual_price', 'exception_reason', 'exception_note'])->append('print_log_id', 'require'); }
    public function scenePaperControl() { return $this->only(['control_id', 'resolution', 'note'])->append('control_id', 'require'); }
    public function sceneControlPrintData() { return $this->only(['control_id'])->append('control_id', 'require'); }
    public function sceneControlPrintResult() { return $this->only(['control_id', 'print_log_id', 'success', 'error_message'])->append('control_id', 'require')->append('print_log_id', 'require'); }
    public function sceneChangeDeliveryArrangement() { return $this->only(['id', 'version', 'delivery_date', 'delivery_arrangement', 'reason'])->append('version', 'require')->append('delivery_date', 'require')->append('delivery_arrangement', 'require')->append('reason', 'require'); }
    public function sceneReduceItem() { return $this->only(['report_item_id', 'new_expected_base_qty', 'processed_reduction_qty', 'processed_disposition', 'other_inventory_action', 'reason', 'idempotency_key'])->append('report_item_id', 'require'); }
    public function sceneMarkUndelivered() { return $this->only(['report_item_id', 'reason_code', 'reason', 'idempotency_key'])->append('report_item_id', 'require'); }
    public function sceneBill() { return $this->only(['id']); }
}
