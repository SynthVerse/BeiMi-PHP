<?php

namespace app\api\jxc\lists;

use app\api\jxc\logic\TaskEmployeeLogic;
use app\common\lists\BaseDataLists;

class TaskEmployeeLists extends BaseDataLists
{
    public function lists(): array
    {
        return TaskEmployeeLogic::lists($this->params)['lists'];
    }

    public function count(): int
    {
        return TaskEmployeeLogic::lists($this->params)['count'];
    }
}
