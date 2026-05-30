<?php

namespace app\tenantapi\lists\goods;

use app\common\service\cloud\CloudGoodsService;
use app\tenantapi\lists\BaseAdminDataLists;

class CloudGoodsLists extends BaseAdminDataLists
{
    public function lists(): array
    {
        return CloudGoodsService::listPrivateWithPublic(
            $this->params,
            $this->tenantId(),
            $this->limitOffset,
            $this->limitLength
        );
    }

    public function count(): int
    {
        return CloudGoodsService::countPrivateWithPublic($this->params, $this->tenantId());
    }

    protected function tenantId(): int
    {
        return (int)($this->request->tenantId ?? ($this->adminInfo['tenant_id'] ?? 0));
    }
}
