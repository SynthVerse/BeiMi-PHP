<?php

namespace app\api\jxc\lists;

use app\api\jxc\logic\WorkTaskLogic;
use app\common\lists\BaseDataLists;

class WorkTaskLists extends BaseDataLists
{
    public function lists(): array
    {
        return WorkTaskLogic::lists($this->params)['lists'];
    }

    public function count(): int
    {
        return WorkTaskLogic::lists($this->params)['count'];
    }
}
