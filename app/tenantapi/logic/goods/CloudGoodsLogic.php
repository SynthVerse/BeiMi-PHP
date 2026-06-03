<?php

namespace app\tenantapi\logic\goods;

use app\common\logic\BaseLogic;
use app\common\service\cloud\CloudGoodsService;

class CloudGoodsLogic extends BaseLogic
{
    public static function add(array $params, int $tenantId, int $adminId = 0): array|false
    {
        self::setError('云端商品库仅支持公共库');
        return false;
    }

    public static function edit(array $params, int $tenantId, int $adminId = 0): bool
    {
        self::setError('云端商品库仅支持公共库');
        return false;
    }

    public static function delete(array $params, int $tenantId): bool
    {
        self::setError('云端商品库仅支持公共库');
        return false;
    }

    public static function detail(array $params, int $tenantId): array
    {
        return CloudGoodsService::detailVisible((int)$params['id'], $tenantId);
    }

    public static function load(array $params, int $tenantId, int $adminId = 0): array|false
    {
        $result = CloudGoodsService::loadToTenant($params, $tenantId, 0, $adminId);
        if ($result === false) {
            self::setError(CloudGoodsService::getError());
        }
        return $result;
    }
}
