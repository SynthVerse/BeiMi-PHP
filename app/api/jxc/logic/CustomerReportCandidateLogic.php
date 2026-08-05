<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use app\common\model\jxc\Goods;
use app\common\model\jxc\GoodsUnit;
use app\common\service\goods\GoodsAliasService;
use think\facade\Db;

/** 仅把自然语言变为待确认候选；绝不建单、绝不预留。 */
class CustomerReportCandidateLogic extends BaseLogic
{
    private const LIMIT = 20;

    /** @return array{lines:array<int,array<string,mixed>>} */
    public static function recognize(string $text): array
    {
        $sourceLines = self::sourceLines($text);
        $headerCustomer = self::firstLineCustomer($sourceLines);
        if ($headerCustomer !== null) {
            array_shift($sourceLines);
        }
        return ['lines' => array_map(static fn(string $line, int $index): array => self::line($line, $index, $headerCustomer), $sourceLines, array_keys($sourceLines))];
    }

    /** @return array{goods_id:int,goods:array<string,mixed>}|false */
    public static function quickCreateGoods(array $params): array|false
    {
        $tenantId = self::tenantId();
        $name = trim((string)($params['name'] ?? ''));
        $categoryId = (int)($params['category_id'] ?? 0);
        $unitId = (int)($params['unit_id'] ?? 0);
        if ($tenantId <= 0 || $name === '' || $categoryId <= 0 || $unitId <= 0) {
            self::setError('快速建商品需填写名称、分类和基础单位');
            return false;
        }
        if (!self::canManageGoods()) {
            self::setError('当前账号没有商品维护权限');
            return false;
        }
        $unit = GoodsUnit::where('tenant_id', $tenantId)->where('id', $unitId)->findOrEmpty();
        if ($unit->isEmpty()) {
            self::setError('基础单位不存在');
            return false;
        }
        $created = GoodsLogic::add([
            'name' => $name,
            'category_id' => $categoryId,
            'unit_id' => $unitId,
            'units' => (string)$unit->name,
            'product_code' => 'CR' . date('YmdHis') . random_int(100, 999),
            'price' => '0.00', 'cost' => '0.00', 'is_disabled' => 0,
            'bound_units' => [['unit_id' => $unitId, 'unit_name' => (string)$unit->name, 'is_base_unit' => 1, 'status' => 1]],
        ]);
        if ($created === false || (int)($created['id'] ?? 0) <= 0) {
            self::setError(GoodsLogic::getError());
            return false;
        }
        $goods = Goods::where('tenant_id', $tenantId)->where('id', (int)$created['id'])->findOrEmpty();
        if ($goods->isEmpty()) {
            self::setError('商品创建后无法读取');
            return false;
        }
        return ['goods_id' => (int)$goods->id, 'goods' => self::goods($goods->toArray())];
    }

    /** @return array<string,mixed> */
    private static function line(string $source, int $index, ?array $headerCustomer = null): array
    {
        $quantity = self::quantity($source);
        $goods = self::goodsCandidates($source);
        $customers = self::customerCandidates($source, $goods['candidates']);
        if (($customers['status'] ?? '') === 'missing' && $headerCustomer !== null) {
            $customers = ['status' => 'unique', 'selected' => $headerCustomer, 'candidates' => [$headerCustomer]];
        }
        $attributes = self::attributes($source);
        $selectedGoods = $goods['selected'];
        $preference = $selectedGoods && $customers['selected']
            ? CustomerReportPreferenceService::suggestion((int)$customers['selected']['id'], (int)$selectedGoods['id']) : [];
        $missing = [];
        foreach (['customer' => $customers, 'goods' => $goods, 'quantity' => $quantity] as $name => $value) {
            if (($value['status'] ?? '') !== 'unique') { $missing[] = $name; }
        }
        return [
            'index' => $index, 'source_text' => $source,
            'goods_needle' => self::goodsNeedle($source),
            'status' => $missing === [] ? 'ready' : (($goods['status'] ?? '') === 'none' ? 'no_goods_candidate' : 'needs_confirmation'),
            'customer' => $customers, 'goods' => $goods, 'quantity' => $quantity,
            'attributes' => $attributes, 'preference' => $preference,
            'missing_fields' => $missing, 'can_submit' => $missing === [],
            'quick_create_allowed' => self::canManageGoods(),
        ];
    }

    /** @return array<int,string> */
    private static function sourceLines(string $text): array
    {
        $lines = [];
        foreach (preg_split('/\r?\n/u', trim($text)) ?: [] as $sourceLine) {
            $sourceLine = trim($sourceLine);
            if ($sourceLine === '') {
                continue;
            }
            $items = array_values(array_filter(array_map('trim', preg_split('/[、；;]+/u', $sourceLine) ?: [])));
            if (count($items) <= 1) {
                $lines[] = $sourceLine;
                continue;
            }
            $customerPrefix = self::customerPrefix($items[0]);
            foreach ($items as $index => $item) {
                if ($index > 0 && $customerPrefix !== '' && !preg_match('/^(?:客户|客戶|给|給)/u', $item)) {
                    $item = $customerPrefix . ' ' . $item;
                }
                $lines[] = $item;
            }
        }
        return $lines;
    }

    private static function customerPrefix(string $source): string
    {
        if (!preg_match('/^((?:客户|客戶|给|給)\s*[:：]?\s*[^\s，,、；;]+(?:\s*报货|\s*報貨)?)/u', $source, $match)) {
            return '';
        }
        return trim((string)$match[1]);
    }

    /** @param array<int,string> $sourceLines
     * @return ?array<string,mixed>
     */
    private static function firstLineCustomer(array $sourceLines): ?array
    {
        if (count($sourceLines) < 2) {
            return null;
        }
        $source = trim((string)$sourceLines[0]);
        if ($source === '' || (self::quantity($source)['status'] ?? '') !== 'missing') {
            return null;
        }
        $customers = self::customerCandidatesForNeedle($source);
        $selected = $customers['selected'] ?? null;
        if ($selected === null || mb_strtolower((string)($selected['name'] ?? '')) !== mb_strtolower($source)) {
            return null;
        }
        return $selected;
    }

    /** @return array{status:string,value:?string,unit:?string} */
    private static function quantity(string $text): array
    {
        if (!preg_match('/(?<![\d.])(\d+(?:\.\d{1,2})?)\s*(公斤|千克|kg|斤|两|条|个|只|盒|件)/iu', $text, $m)) {
            return ['status' => 'missing', 'value' => null, 'unit' => null];
        }
        $unit = self::unit((string)$m[2]);
        if (bccomp(bcadd((string)$m[1], '0', 2), '0.00', 2) <= 0 || (in_array($unit, ['条','个','只','盒','件'], true) && str_contains((string)$m[1], '.'))) {
            return ['status' => 'invalid', 'value' => null, 'unit' => $unit];
        }
        return ['status' => 'unique', 'value' => bcadd((string)$m[1], '0', 2), 'unit' => $unit];
    }

    /** @return array{status:string,selected:?array<string,mixed>,candidates:array<int,array<string,mixed>>} */
    private static function goodsCandidates(string $text): array
    {
        $needle = self::goodsNeedle($text);
        if (self::tenantId() <= 0 || $needle === '') { return ['status' => 'none', 'selected' => null, 'candidates' => []]; }
        $normalizedNeedle = GoodsAliasService::normalize($needle);
        $rows = Db::name('goods')->alias('goods')
            ->leftJoin('goods_alias alias', 'alias.tenant_id = goods.tenant_id AND alias.goods_id = goods.id')
            ->where('goods.tenant_id', self::tenantId())->where('goods.is_disabled', 0)
            ->where(function ($query) use ($needle, $normalizedNeedle) {
                $query->whereLike('goods.name', '%' . $needle . '%')
                    ->whereOr('goods.product_code', 'like', '%' . $needle . '%')
                    ->whereOr('alias.alias', 'like', '%' . $needle . '%')
                    ->whereOr('alias.normalized_alias', $normalizedNeedle);
            })
            ->field(['goods.id','goods.name','goods.product_code','goods.unit_id','goods.units','goods.category_id'])
            ->group('goods.id')->order('goods.id asc')->limit(self::LIMIT)->select()->toArray();
        $candidates = array_map(static fn(array $row): array => self::goods($row), $rows);
        $exactIds = Db::name('goods_alias')->where('tenant_id', self::tenantId())->where('normalized_alias', $normalizedNeedle)->column('goods_id');
        $exact = array_values(array_filter($candidates, static fn(array $one): bool => GoodsAliasService::normalize((string)$one['name']) === $normalizedNeedle
            || GoodsAliasService::normalize((string)$one['product_code']) === $normalizedNeedle
            || in_array((int)$one['id'], array_map('intval', $exactIds), true)));
        // 别名识别只允许规范化后的完整匹配；模糊候选只用于校对页人工确认，绝不自动回填。
        $selected = count($exact) === 1 ? $exact[0] : null;
        if ($selected !== null) {
            foreach ($candidates as &$candidate) {
                if ((int)$candidate['id'] === (int)$selected['id']) {
                    $candidate['matched_name'] = $needle;
                    $selected = $candidate;
                    break;
                }
            }
            unset($candidate);
        }
        return ['status' => $selected ? 'unique' : ($candidates === [] ? 'none' : 'ambiguous'), 'selected' => $selected, 'candidates' => $candidates];
    }

    /** @param array<int,array<string,mixed>> $goods
     * @return array{status:string,selected:?array<string,mixed>,candidates:array<int,array<string,mixed>>}
     */
    private static function customerCandidates(string $text, array $goods): array
    {
        $needle = '';
        if (preg_match('/(?:客户|客戶|给|給)\s*[:：]?\s*([^\s，,、；;]+?)(?:报货|報貨)?(?=\s|$)/u', $text, $m)) { $needle = trim($m[1]); }
        if ($needle === '' && $goods !== []) {
            foreach ($goods as $goodsCandidate) {
                $matchedName = (string)($goodsCandidate['matched_name'] ?? $goodsCandidate['name'] ?? '');
                $pos = $matchedName === '' ? false : mb_strpos($text, $matchedName);
                if ($pos !== false) {
                    $needle = trim(mb_substr($text, 0, $pos), " \t，,、；;：:");
                    break;
                }
            }
        }
        return self::customerCandidatesForNeedle($needle);
    }

    /** @return array{status:string,selected:?array<string,mixed>,candidates:array<int,array<string,mixed>>} */
    private static function customerCandidatesForNeedle(string $needle): array
    {
        if (self::tenantId() <= 0 || $needle === '') { return ['status' => 'missing', 'selected' => null, 'candidates' => []]; }
        $rows = Db::name('customer')->alias('delivery')
            ->leftJoin('customer main', 'main.id = delivery.parent_id AND main.tenant_id = delivery.tenant_id')
            ->where('delivery.tenant_id', self::tenantId())->where('delivery.is_disabled', 0)
            ->whereLike('delivery.customer_name', '%' . $needle . '%')
            ->field([
                'delivery.id', 'delivery.customer_name', 'delivery.parent_id',
                'main.id' => 'main_id', 'main.customer_name' => 'main_name', 'main.is_disabled' => 'main_disabled',
            ])->order('delivery.id asc')->limit(self::LIMIT)->select()->toArray();
        $candidates = array_values(array_filter(array_map(static function (array $row): ?array {
            $deliveryId = (int)$row['id'];
            $parentId = (int)$row['parent_id'];
            $mainId = $parentId > 0 ? (int)$row['main_id'] : $deliveryId;
            $mainName = $parentId > 0 ? (string)$row['main_name'] : (string)$row['customer_name'];
            if ($mainId <= 0 || $mainName === '' || ($parentId > 0 && (int)$row['main_disabled'] === 1)) {
                return null;
            }
            return [
                'id' => $deliveryId, 'name' => (string)$row['customer_name'], 'parent_id' => $parentId,
                'main_customer' => ['id' => $mainId, 'name' => $mainName],
                'delivery_customer' => ['id' => $deliveryId, 'name' => (string)$row['customer_name']],
            ];
        }, $rows)));
        $exact = array_values(array_filter($candidates, static fn(array $one): bool => mb_strtolower($one['name']) === mb_strtolower($needle)));
        $selected = count($exact) === 1 ? $exact[0] : (count($candidates) === 1 ? $candidates[0] : null);
        return ['status' => $selected ? 'unique' : ($candidates === [] ? 'none' : 'ambiguous'), 'selected' => $selected, 'candidates' => $candidates];
    }

    /** @return array<string,mixed> */
    private static function attributes(string $text): array
    {
        preg_match('/(?<!\d)(\d+\s*头)/u', $text, $head);
        $processing = array_values(array_filter(['去鳞','开背','切段','切片','去内脏'], static fn(string $word): bool => mb_strpos($text, $word) !== false));
        return ['specification' => isset($head[1]) ? preg_replace('/\s+/u', '', $head[1]) : null, 'processing' => $processing];
    }

    private static function goodsNeedle(string $text): string
    {
        $text = preg_replace('/(?:客户|客戶|给|給)\s*[:：]?\s*[^\s，,、；;]+/u', '', $text) ?? $text;
        $text = preg_replace('/(?:每\s*)?(?:约|約)?\s*\d+(?:\.\d{1,2})?\s*(?:公斤|千克|kg|斤|两|条|个|只|盒|件|头)/iu', '', $text) ?? $text;
        $text = str_replace(['去鳞','开背','切段','切片','去内脏','约','約','左右'], '', $text);
        return trim(preg_replace('/[^\p{Han}A-Za-z0-9_-]+/u', '', $text) ?? '');
    }
    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function goods(array $row): array { return ['id'=>(int)($row['id']??0),'name'=>(string)($row['name']??''),'product_code'=>(string)($row['product_code']??''),'unit_id'=>(int)($row['unit_id']??0),'units'=>(string)($row['units']??''),'category_id'=>(int)($row['category_id']??0)]; }
    private static function unit(string $name): string { return match (mb_strtolower(trim($name))) { 'kg','公斤','千克' => '公斤', default => trim($name) }; }
    private static function tenantId(): int { return (int)(request()->tenantId ?? 0); }
    private static function canManageGoods(): bool { return (int)(request()->adminId ?? 0) > 0; }
}
