<?php

namespace app\api\jxc\lists;

use app\common\lists\BaseDataLists;
use app\common\service\cloud\CloudGoodsService;

class CloudGoodsLists extends BaseDataLists
{
    public function lists(): array
    {
        return CloudGoodsService::listVisible(
            $this->params,
            $this->tenantId(),
            $this->limitOffset,
            $this->limitLength
        );
    }

    public function count(): int
    {
        return CloudGoodsService::countVisible($this->params, $this->tenantId());
    }

    protected function tenantId(): int
    {
        return (int)(request()->tenantId ?? 0);
    }
}
