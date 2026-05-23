<?php

namespace app\common\model\tenant;

use app\common\model\BaseModel;
use think\model\concern\SoftDelete;

class TenantInvite extends BaseModel
{
    use SoftDelete;

    protected $name = 'tenant_invite';
    protected $deleteTime = 'delete_time';
}
