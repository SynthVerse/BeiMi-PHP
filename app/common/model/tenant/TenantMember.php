<?php

namespace app\common\model\tenant;

use app\common\model\BaseModel;
use think\model\concern\SoftDelete;

class TenantMember extends BaseModel
{
    use SoftDelete;

    protected $name = 'tenant_member';
    protected $deleteTime = 'delete_time';
}
