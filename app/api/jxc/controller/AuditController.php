<?php
declare(strict_types=1);

namespace app\api\jxc\controller;

use app\api\jxc\lists\AuditLogLists;

class AuditController extends BaseJxcController
{
    /**
     * 审计日志列表
     */
    public function lists()
    {
        return $this->dataLists(new AuditLogLists());
    }
}
