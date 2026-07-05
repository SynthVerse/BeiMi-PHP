<?php

namespace app\api\jxc\validate;

use app\common\validate\BaseValidate;

class WorkTaskValidate extends BaseValidate
{
    protected $rule = [
        'id' => 'require|integer|gt:0',
        'type_code' => 'require|max:50',
        'title' => 'require|max:200',
        'content' => 'max:2000',
        'assignee_employee_id' => 'integer|egt:0',
        'source_type' => 'max:50',
        'source_id' => 'integer|egt:0',
        'source_sn' => 'max:64',
        'reservation_id' => 'integer|egt:0',
        'reservation_sn' => 'max:64',
        'progress_num' => 'float|egt:0',
        'target_num' => 'float|egt:0',
    ];

    public function sceneCreate()
    {
        return $this->only(['type_code', 'title', 'content', 'assignee_employee_id', 'source_type', 'source_id', 'source_sn', 'reservation_id', 'reservation_sn', 'progress_num', 'target_num']);
    }

    public function sceneEdit()
    {
        return $this->only(['id', 'title', 'content', 'target_num']);
    }

    public function sceneAssign()
    {
        return $this->only(['id', 'assignee_employee_id'])->append('assignee_employee_id', 'require');
    }

    public function sceneAction()
    {
        return $this->only(['id']);
    }

    public function sceneDetail()
    {
        return $this->only(['id']);
    }
}
