<?php

namespace app\common\cache;

class CloudGoodsCache extends BaseCache
{
    public function listKey(array $params): string
    {
        ksort($params);
        return 'cloud_goods:list:' . md5(json_encode($params, JSON_UNESCAPED_UNICODE));
    }

    public function detailKey(int $id, int $tenantId = 0): string
    {
        return 'cloud_goods:detail:' . $tenantId . ':' . $id;
    }

    public function rememberValue(string $key, callable $callback, int $ttl = 300): mixed
    {
        $cached = $this->get($key);
        if ($cached !== null) {
            return $cached;
        }
        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }

    public function clearAll(): bool
    {
        return $this->deleteTag();
    }
}
