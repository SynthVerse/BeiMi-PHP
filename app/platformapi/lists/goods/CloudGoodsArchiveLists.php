<?php

namespace app\platformapi\lists\goods;

use app\common\service\cloud\CloudGoodsService;
use app\platformapi\lists\BaseAdminDataLists;

class CloudGoodsArchiveLists extends BaseAdminDataLists
{
    public function lists(): array
    {
        return CloudGoodsService::listArchivedPublic($this->params, $this->limitOffset, $this->limitLength);
    }

    public function count(): int
    {
        return CloudGoodsService::countArchivedPublic($this->params);
    }
}
