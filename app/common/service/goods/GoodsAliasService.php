<?php

declare(strict_types=1);

namespace app\common\service\goods;

use app\common\model\cloud\CloudGoods;
use app\common\model\jxc\Goods;
use think\facade\Db;

/**
 * 商品别名是商品主数据的一部分：云端标准别名与租户本地别名共用规范化、唯一性和读取规则。
 */
class GoodsAliasService
{
    private const NORMALIZED_WHITESPACE = [
        "\u{0009}", "\u{000A}", "\u{000B}", "\u{000C}", "\u{000D}", "\u{0020}",
        "\u{0085}", "\u{00A0}", "\u{1680}",
        "\u{2000}", "\u{2001}", "\u{2002}", "\u{2003}", "\u{2004}", "\u{2005}",
        "\u{2006}", "\u{2007}", "\u{2008}", "\u{2009}", "\u{200A}",
        "\u{2028}", "\u{2029}", "\u{202F}", "\u{205F}", "\u{3000}",
    ];

    /** @return array<int,string> */
    public static function normalizeInput(mixed $value): array
    {
        $values = is_array($value) ? $value : preg_split('/[\r\n,，、;；]+/u', (string)$value);
        $aliases = [];
        foreach ($values ?: [] as $item) {
            $alias = trim((string)(is_array($item) ? ($item['alias'] ?? $item['name'] ?? '') : $item));
            $normalized = self::normalize($alias);
            if ($normalized === '' || isset($aliases[$normalized])) {
                continue;
            }
            if (mb_strlen($alias) > 200) {
                throw new \InvalidArgumentException('商品别名不能超过 200 个字符');
            }
            $aliases[$normalized] = $alias;
        }
        return array_values($aliases);
    }

    public static function normalize(string $value): string
    {
        $value = str_replace(self::NORMALIZED_WHITESPACE, '', $value);
        return $value === '' ? '' : mb_strtolower($value);
    }

    /** @return array<int,string> */
    public static function tenantAliases(int $tenantId, int $goodsId): array
    {
        if ($tenantId <= 0 || $goodsId <= 0) {
            return [];
        }
        return Db::name('goods_alias')->where('tenant_id', $tenantId)->where('goods_id', $goodsId)
            ->order(['id' => 'asc'])->column('alias');
    }

    /** @return array<int,string> */
    public static function cloudAliases(int $cloudGoodsId): array
    {
        if ($cloudGoodsId <= 0) {
            return [];
        }
        return Db::name('goods_alias')->where('tenant_id', 0)->where('cloud_goods_id', $cloudGoodsId)
            ->order(['id' => 'asc'])->column('alias');
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
    public static function attachTenantAliases(array $rows): array
    {
        $goodsIds = array_values(array_filter(array_unique(array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $rows))));
        if ($goodsIds === []) {
            return $rows;
        }
        $grouped = [];
        foreach (Db::name('goods_alias')->where('tenant_id', self::tenantIdFromRows($rows))->whereIn('goods_id', $goodsIds)->order(['id' => 'asc'])->select()->toArray() as $row) {
            $grouped[(int)$row['goods_id']][] = (string)$row['alias'];
        }
        foreach ($rows as &$row) {
            $row['aliases'] = $grouped[(int)($row['id'] ?? 0)] ?? [];
        }
        unset($row);
        return $rows;
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
    public static function attachCloudAliases(array $rows): array
    {
        $ids = array_values(array_filter(array_unique(array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $rows))));
        if ($ids === []) {
            return $rows;
        }
        $grouped = [];
        foreach (Db::name('goods_alias')->where('tenant_id', 0)->whereIn('cloud_goods_id', $ids)->order(['id' => 'asc'])->select()->toArray() as $row) {
            $grouped[(int)$row['cloud_goods_id']][] = (string)$row['alias'];
        }
        foreach ($rows as &$row) {
            $row['aliases'] = $grouped[(int)($row['id'] ?? 0)] ?? [];
        }
        unset($row);
        return $rows;
    }

    /** @param array<int,string> $aliases */
    public static function validateCloud(int $cloudGoodsId, string $canonicalName, array $aliases): ?string
    {
        $tokens = self::tokens($canonicalName, $aliases);
        if ($tokens === []) {
            return '商品名称不能为空';
        }
        foreach (CloudGoods::where('scope', CloudGoods::SCOPE_PUBLIC)->where('tenant_id', 0)
            ->where('status', '<>', CloudGoods::STATUS_ARCHIVED)->field(['id', 'name'])->select()->toArray() as $goods) {
            if ((int)$goods['id'] !== $cloudGoodsId && isset($tokens[self::normalize((string)$goods['name'])])) {
                return '云端商品名称或别名与“' . (string)$goods['name'] . '”冲突';
            }
        }
        $query = Db::name('goods_alias')->where('tenant_id', 0)->whereIn('normalized_alias', array_keys($tokens));
        if ($cloudGoodsId > 0) {
            $query->where('cloud_goods_id', '<>', $cloudGoodsId);
        }
        $conflict = $query->find();
        if ($conflict !== null) {
            return '云端商品名称或别名与现有别名“' . (string)$conflict['alias'] . '”冲突';
        }
        return null;
    }

    /** @param array<int,string> $aliases */
    public static function replaceTenantAliases(int $tenantId, int $goodsId, array $aliases, string $source = 'tenant'): void
    {
        Db::name('goods_alias')->where('tenant_id', $tenantId)->where('goods_id', $goodsId)->delete();
        self::insert($tenantId, $goodsId, 0, $aliases, $source);
    }

    /** @param array<int,string> $aliases */
    public static function replaceCloudAliases(int $cloudGoodsId, array $aliases): void
    {
        Db::name('goods_alias')->where('tenant_id', 0)->where('cloud_goods_id', $cloudGoodsId)->delete();
        self::insert(0, 0, $cloudGoodsId, $aliases, 'cloud');
    }

    /** @return array<int,int> */
    public static function matchingTenantGoodsIds(int $tenantId, string $keyword): array
    {
        if ($tenantId <= 0 || trim($keyword) === '') {
            return [];
        }
        return array_map('intval', Db::name('goods_alias')->where('tenant_id', $tenantId)
            ->whereLike('alias', '%' . trim($keyword) . '%')->column('goods_id'));
    }

    /**
     * @param array<int,string> $aliases
     * @return array{status:string,goods:?array<string,mixed>,goods_ids:array<int,int>}
     */
    public static function resolveTenantCreateConflict(
        int $tenantId,
        string $canonicalName,
        array $aliases,
        int $ignoreGoodsId = 0
    ): array {
        $tokens = self::tokens($canonicalName, $aliases);
        if ($tenantId <= 0 || $tokens === []) {
            return ['status' => 'none', 'goods' => null, 'goods_ids' => []];
        }

        $goodsIds = [];
        foreach (Goods::where('tenant_id', $tenantId)
            ->whereIn('normalized_name', array_keys($tokens))
            ->field(['id'])
            ->select()
            ->toArray() as $goods
        ) {
            $goodsId = (int)($goods['id'] ?? 0);
            if ($goodsId > 0 && $goodsId !== $ignoreGoodsId) {
                $goodsIds[$goodsId] = true;
            }
        }
        $aliasQuery = Db::name('goods_alias')
            ->where('tenant_id', $tenantId)
            ->whereIn('normalized_alias', array_keys($tokens));
        if ($ignoreGoodsId > 0) {
            $aliasQuery->where('goods_id', '<>', $ignoreGoodsId);
        }
        foreach ($aliasQuery->column('goods_id') as $goodsId) {
            if ((int)$goodsId > 0) {
                $goodsIds[(int)$goodsId] = true;
            }
        }

        $ids = array_keys($goodsIds);
        sort($ids, SORT_NUMERIC);
        if (count($ids) !== 1) {
            return [
                'status' => $ids === [] ? 'none' : 'ambiguous',
                'goods' => null,
                'goods_ids' => $ids,
            ];
        }

        $goods = Goods::where('tenant_id', $tenantId)
            ->where('id', $ids[0])
            ->findOrEmpty();
        if ($goods->isEmpty()) {
            return ['status' => 'none', 'goods' => null, 'goods_ids' => []];
        }
        return [
            'status' => 'unique',
            'goods' => $goods->toArray(),
            'goods_ids' => $ids,
        ];
    }

    /** @return array<int,int> */
    public static function matchingCloudGoodsIds(string $keyword): array
    {
        if (trim($keyword) === '') {
            return [];
        }
        return array_map('intval', Db::name('goods_alias')->where('tenant_id', 0)
            ->whereLike('alias', '%' . trim($keyword) . '%')->column('cloud_goods_id'));
    }

    /** @param array<int,string> $aliases */
    private static function insert(int $tenantId, int $goodsId, int $cloudGoodsId, array $aliases, string $source): void
    {
        $now = time();
        foreach ($aliases as $alias) {
            $normalized = self::normalize($alias);
            if ($normalized === '') {
                continue;
            }
            Db::name('goods_alias')->insert([
                'tenant_id' => $tenantId,
                'goods_id' => $goodsId,
                'cloud_goods_id' => $cloudGoodsId,
                'alias' => $alias,
                'normalized_alias' => $normalized,
                'source' => $source,
                'create_time' => $now,
                'update_time' => $now,
            ]);
        }
    }

    /** @param array<int,string> $aliases @return array<string,true> */
    private static function tokens(string $canonicalName, array $aliases): array
    {
        $canonical = self::normalize($canonicalName);
        $tokens = $canonical === '' ? [] : [$canonical => true];
        foreach ($aliases as $alias) {
            $normalized = self::normalize($alias);
            if ($normalized !== '') {
                $tokens[$normalized] = true;
            }
        }
        return $tokens;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function tenantIdFromRows(array $rows): int
    {
        foreach ($rows as $row) {
            if ((int)($row['tenant_id'] ?? 0) > 0) {
                return (int)$row['tenant_id'];
            }
        }
        return (int)(request()->tenantId ?? 0);
    }
}
