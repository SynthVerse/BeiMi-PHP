<?php

namespace app\platformapi\lists\goods;

use app\common\service\cloud\CloudGoodsService;
use app\platformapi\lists\BaseAdminDataLists;

class CloudGoodsLists extends BaseAdminDataLists
{
    public function lists(): array
    {
        return CloudGoodsService::listPublic($this->params, $this->limitOffset, $this->limitLength);
    }

    public function count(): int
    {
        return CloudGoodsService::countPublic($this->params);
    }
}
