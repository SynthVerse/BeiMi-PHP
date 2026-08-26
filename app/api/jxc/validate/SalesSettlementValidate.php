<?php

declare(strict_types=1);

namespace app\api\jxc\validate;

use app\common\validate\BaseValidate;

final class SalesSettlementValidate extends BaseValidate
{
    protected $rule = [
        'id' => 'integer|gt:0',
        'order_id' => 'require|integer|gt:0',
        'expected_version' => 'require|integer|egt:0',
        'idempotency_key' => 'require|max:96',
        'lines' => 'require|array|min:1',
        'rounding_amount' => 'max:20',
        'rounding_reason' => 'max:500',
        'second_confirmed' => 'in:0,1',
        'show_cumulative_debt' => 'in:0,1',
        'edit_reason' => 'max:500',
        'inventory_exception_reason' => 'max:500',
        'inventory_second_confirmed' => 'in:0,1',
        'status' => 'max:32',
        'keyword' => 'max:100',
        'decision' => 'require|in:approve,reject',
        'reason' => 'require|max:500',
        'print_log_id' => 'require|integer|gt:0',
        'success' => 'require|in:0,1',
        'error_message' => 'max:255',
    ];

    public function sceneLists() { return $this->only(['status', 'keyword']); }
    public function sceneDetail() { return $this->only(['id'])->append('id', 'require'); }
    public function sceneSubmit()
    {
        return $this->only([
            'order_id', 'expected_version', 'idempotency_key', 'lines', 'rounding_amount',
            'rounding_reason', 'second_confirmed', 'show_cumulative_debt', 'edit_reason',
            'inventory_exception_reason', 'inventory_second_confirmed',
        ]);
    }
    public function scenePreparePrint()
    {
        return $this->only(['id', 'expected_version', 'idempotency_key'])
            ->append('id', 'require')->append('expected_version', 'require|integer|gt:0');
    }
    public function scenePrintResult()
    {
        return $this->only(['id', 'print_log_id', 'success', 'error_message'])->append('id', 'require');
    }
    public function sceneWeightTodos() { return $this->only(['status']); }
    public function sceneResolveWeight()
    {
        return $this->only([
            'id', 'decision', 'reason', 'idempotency_key',
            'inventory_exception_reason', 'inventory_second_confirmed',
        ])->append('id', 'require');
    }
}
