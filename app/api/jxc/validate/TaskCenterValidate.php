<?php

namespace app\api\jxc\validate;

use app\common\validate\BaseValidate;

class TaskCenterValidate extends BaseValidate
{
    protected $rule = [
        'id' => 'integer|gt:0',
        'task_ids' => 'array',
        'ids' => 'array',
        'reservation_ids' => 'array',
        'reservation_item_ids' => 'array',
        'item_ids' => 'array',
        'assignments' => 'array',
        'task_date' => 'max:20',
        'task_kind' => 'max:32',
        'role_code' => 'max:50',
        'status' => 'max:32',
        'progress_num' => 'float|egt:0',
        'status_reason' => 'max:500',
        'reason' => 'max:500',
        'priority' => 'max:32',
        'assignee_employee_id' => 'integer|egt:0',
        'employee_id' => 'integer|egt:0',
        'device_id' => 'max:100',
        'device_name' => 'max:100',
        'error_code' => 'max:64',
        'error_message' => 'max:255',
        'scope' => 'max:32',
    ];

    public function scenePreview()
    {
        return $this->only(['reservation_ids', 'reservation_item_ids', 'item_ids']);
    }

    public function sceneAssignmentSave()
    {
        return $this->only(['assignments', 'reservation_ids', 'reservation_item_ids', 'item_ids', 'task_date', 'priority']);
    }

    public function scenePrintData()
    {
        return $this->only(['task_ids', 'ids', 'task_date', 'task_kind', 'role_code', 'status', 'employee_id', 'device_id', 'device_name', 'scope']);
    }

    public function sceneStatus()
    {
        return $this->only(['id', 'task_ids', 'ids', 'status', 'progress_num', 'status_reason', 'reason', 'task_date', 'employee_id', 'role_code', 'device_id', 'device_name', 'error_code', 'error_message', 'scope']);
    }
}
