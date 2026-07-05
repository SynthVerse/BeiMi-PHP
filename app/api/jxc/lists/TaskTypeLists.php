<?php

namespace app\api\jxc\lists;

use app\api\jxc\logic\TaskTypeLogic;
use app\common\lists\BaseDataLists;

class TaskTypeLists extends BaseDataLists
{
    public function lists(): array
    {
        return TaskTypeLogic::lists($this->params)['lists'];
    }

    public function count(): int
    {
        return TaskTypeLogic::lists($this->params)['count'];
    }
}
