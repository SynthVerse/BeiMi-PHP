<?php
declare(strict_types=1);

namespace app\common\model\jxc;

use app\common\model\BaseModel;

class AuditLog extends BaseModel
{
    protected $name = 'audit_log';

    // 不需要 update_time 和 delete_time
    protected $updateTime = false;
    protected $deleteTime = false;
}
