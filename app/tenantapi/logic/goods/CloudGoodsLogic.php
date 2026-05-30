<?php

namespace app\tenantapi\logic\goods;

use app\common\logic\BaseLogic;
use app\common\service\cloud\CloudGoodsService;

class CloudGoodsLogic extends BaseLogic
{
    public static function add(array $params, int $tenantId, int $adminId = 0): array|false
    {
        $result = CloudGoodsService::addPrivate($params, $tenantId, $adminId);
        if ($result === false) {
            self::setError(CloudGoodsService::getError());
        }
        return $result;
    }

    public static function edit(array $params, int $tenantId, int $adminId = 0): bool
    {
        $result = CloudGoodsService::editPrivate($params, $tenantId, $adminId);
        if ($result === false) {
            self::setError(CloudGoodsService::getError());
        }
        return $result;
    }

    public static function delete(array $params, int $tenantId): bool
    {
        $result = CloudGoodsService::deletePrivate($params, $tenantId);
        if ($result === false) {
            self::setError(CloudGoodsService::getError());
        }
        return $result;
    }

    public static function detail(array $params, int $tenantId): array
    {
        return CloudGoodsService::detailPrivateOrPublic((int)$params['id'], $tenantId);
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
