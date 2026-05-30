<?php

namespace app\platformapi\logic\goods;

use app\common\logic\BaseLogic;
use app\common\service\cloud\CloudGoodsService;

class CloudGoodsLogic extends BaseLogic
{
    public static function add(array $params, int $adminId = 0): array|false
    {
        $result = CloudGoodsService::addPublic($params, $adminId);
        if ($result === false) {
            self::setError(CloudGoodsService::getError());
        }
        return $result;
    }

    public static function edit(array $params, int $adminId = 0): bool
    {
        $result = CloudGoodsService::editPublic($params, $adminId);
        if ($result === false) {
            self::setError(CloudGoodsService::getError());
        }
        return $result;
    }

    public static function delete(array $params): bool
    {
        $result = CloudGoodsService::deletePublic($params);
        if ($result === false) {
            self::setError(CloudGoodsService::getError());
        }
        return $result;
    }

    public static function detail(array $params): array
    {
        return CloudGoodsService::detailPublic((int)$params['id']);
    }
}
