<?php

namespace app\common\model\tenant;

use app\common\model\BaseModel;
use think\model\concern\SoftDelete;

class TenantRelation extends BaseModel
{
    use SoftDelete;

    protected $name = 'tenant_relation';
    protected $deleteTime = 'delete_time';
}
