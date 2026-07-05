<?php

namespace app\api\jxc\lists;

use app\api\jxc\logic\TaskRoleLogic;
use app\common\lists\BaseDataLists;

class TaskRoleLists extends BaseDataLists
{
    public function lists(): array
    {
        return TaskRoleLogic::lists($this->params)['lists'];
    }

    public function count(): int
    {
        return TaskRoleLogic::lists($this->params)['count'];
    }
}
