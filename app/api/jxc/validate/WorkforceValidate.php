<?php

namespace app\api\jxc\validate;

use app\common\validate\BaseValidate;

final class WorkforceValidate extends BaseValidate
{
    protected $rule = [
        'id' => 'require|integer|gt:0',
        'name' => 'require|max:60',
        'mobile' => 'require|max:30',
        'bind_user_id' => 'integer|egt:0',
        'is_enabled' => 'in:0,1',
        'process_ids' => 'array',
        'permission_keys' => 'array',
        'keyword' => 'max:60',
        'trigger_type' => 'in:remark,shortage,group_ready,ticket_recovered,manual',
        'keywords' => 'array',
        'sort' => 'integer',
        'ids' => 'require|array',
    ];

    public function sceneEmployees() { return $this->only(['keyword', 'is_enabled']); }
    public function sceneEmployee() { return $this->only(['id']); }
    public function sceneSaveEmployee() { return $this->only(['id', 'name', 'mobile', 'bind_user_id', 'is_enabled', 'process_ids', 'permission_keys'])->remove('id', 'require|gt')->append('id', 'egt:0'); }
    public function sceneStatusEmployee() { return $this->only(['id', 'is_enabled'])->append('is_enabled', 'require'); }
    public function sceneProcesses() { return $this->only(['keyword', 'is_enabled']); }
    public function sceneSaveProcess() { return $this->only(['id', 'name', 'trigger_type', 'keywords', 'sort', 'is_enabled'])->remove('id', 'require'); }
    public function sceneStatusProcess() { return $this->only(['id', 'is_enabled'])->append('is_enabled', 'require'); }
    public function sceneReorderProcesses() { return $this->only(['ids']); }
    public function sceneDeleteProcess() { return $this->only(['id']); }
}
