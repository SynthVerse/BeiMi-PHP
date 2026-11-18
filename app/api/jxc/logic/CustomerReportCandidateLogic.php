<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use app\common\model\jxc\Goods;
use app\common\model\jxc\GoodsUnit;
use app\common\service\goods\GoodsAliasService;
use app\common\service\goods\GoodsMaintenancePermissionService;
use think\facade\Db;

/** 仅把自然语言变为待确认候选；绝不建单、绝不预留。 */
class CustomerReportCandidateLogic extends BaseLogic
{
    private const LIMIT = 20;
    private const LINE_LIMIT = 100;

    /** @return array<string,mixed> */
    public static function recognize(string $text): array
    {
        $sourceLines = self::sourceLines($text);
        $context = self::recognitionContext();
        $header = self::firstLineHeader($sourceLines, $context);
        $customer = $header['customer'] ?? null;
        if ($header !== null) {
            array_shift($sourceLines);
        }
        $totalLines = count($sourceLines);
        $sourceLines = array_slice($sourceLines, 0, self::LINE_LIMIT);
        $context['main_customer_id'] = (int)($customer['selected']['main_customer']['id'] ?? 0);
        $segmentId = null;
        $lines = [];
        $segments = [];
        foreach ($sourceLines as $index => $source) {
            $sourceIndex = $index + ($header !== null ? 1 : 0);
            $boundary = self::recognitionBoundary($source, $context['unit_pattern']);
            $goods = self::goodsCandidates($source, $boundary, $context);
            $segment = self::sectionTitle($source, $sourceIndex, $goods, $context['main_customer_id'], $context['unit_pattern']);
            if ($segment === null || $segment['kind'] === 'undecided') {
                $line = self::line($source, $index, $customer, $segmentId, $boundary, $goods, $context, $segment !== null);
                $line['source_index'] = $sourceIndex;
                if ($segment === null) {
                    $lines[] = $line;
                    continue;
                }
                $segment['product_line'] = $line;
            }
            $customer = $segment['customer'];
            if ($context['main_customer_id'] === 0 && $customer['selected'] !== null) {
                $context['main_customer_id'] = (int)$customer['selected']['main_customer']['id'];
            }
            $segmentId = $segment['id'];
            $segments[] = $segment;
        }
        return [
            'header' => $header,
            'segments' => $segments,
            'lines' => $lines,
            'total_lines' => $totalLines,
            'processed_lines' => count($sourceLines),
            'line_limit' => self::LINE_LIMIT,
            'truncated' => $totalLines > self::LINE_LIMIT,
        ];
    }

    /** @return array{goods_id:int,existing:bool,reusable:bool,goods:array<string,mixed>}|false */
    public static function quickCreateGoods(array $params): array|false
    {
        self::setReturnData(null);
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
            'dimensions' => $params['dimensions'] ?? [],
            'combinations' => $params['combinations'] ?? null,
        ]);
        if ($created === false || (int)($created['id'] ?? 0) <= 0) {
            $error = GoodsLogic::getError();
            self::setError(str_contains($error, 'SKU维度')
                ? '请前往完整新建商品页配置至少一个SKU维度'
                : $error);
            return false;
        }
        if (($created['reusable'] ?? true) !== true) {
            $state = (string)($created['existing_state'] ?? '');
            self::setError($state === 'archived' ? '同名商品已归档，请先恢复' : '同名商品已停用，请先启用');
            self::setReturnData([
                'goods_id' => (int)$created['id'],
                'existing' => (bool)($created['existing'] ?? true),
                'reusable' => false,
                'requires_activation' => true,
                'existing_state' => $state,
                'goods' => (array)($created['goods'] ?? []),
            ]);
            return false;
        }
        $goods = Goods::where('tenant_id', $tenantId)->where('id', (int)$created['id'])->findOrEmpty();
        if ($goods->isEmpty()) {
            self::setError('商品创建后无法读取');
            return false;
        }
        return [
            'goods_id' => (int)$goods->id,
            'existing' => (bool)($created['existing'] ?? false),
            'reusable' => true,
            'goods' => self::goods($goods->toArray()),
        ];
    }

    /** @return array<string,mixed> */
    private static function line(
        string $source, int $index, ?array $defaultCustomer, ?int $segmentId,
        array $boundary, array $goods, array &$context, bool $standaloneProduct
    ): array {
        $quantity = self::quantity($source, $goods['selected'], $context);
        $specification = self::specification($source, $goods['selected'], $context);
        $customers = $standaloneProduct
            ? ['status' => 'missing', 'selected' => null, 'candidates' => []]
            : self::customerCandidates($source, $goods['candidates'], $specification['customer_prefix'] ?? null, $context['main_customer_id']);
        $inherits = $customers['status'] === 'missing';
        if ($inherits && $defaultCustomer !== null) {
            $customers = $defaultCustomer;
        }
        $selectedGoods = $goods['selected'];
        $preference = $selectedGoods && $customers['selected']
            ? CustomerReportPreferenceService::suggestion((int)$customers['selected']['id'], (int)$selectedGoods['id']) : [];
        $matchedName = trim((string)(
            $selectedGoods['matched_name']
            ?? self::commonMatchedName($goods['candidates'])
        ));
        $suggestedGoodsName = ($goods['status'] ?? '') === 'none'
            ? $boundary['suggested_name']
            : null;
        $lineRemark = self::recognizedRemark($source, $matchedName, $boundary);
        $missing = [];
        foreach (['customer' => $customers, 'goods' => $goods, 'quantity' => $quantity] as $name => $value) {
            if (($value['status'] ?? '') !== 'unique') { $missing[] = $name; }
        }
        if ($selectedGoods && !in_array($specification['status'], ['unique', 'missing'], true)) $missing[] = 'specification';
        unset($specification['customer_prefix'], $specification['suffix_remaining']);
        return [
            'index' => $index, 'source_text' => $source,
            ...($inherits && $segmentId !== null ? ['segment_id' => $segmentId] : []),
            'goods_needle' => $matchedName !== '' ? $matchedName : (string)($boundary['suggested_name'] ?? ''),
            'suggested_goods_name' => $suggestedGoodsName,
            'line_remark' => $lineRemark,
            'status' => $missing === [] ? 'ready' : (($goods['status'] ?? '') === 'none' ? 'no_goods_candidate' : 'needs_confirmation'),
            'customer' => $customers, 'goods' => $goods, 'quantity' => $quantity,
            'attributes' => ['specification' => $specification, 'processing' => []], 'preference' => $preference,
            'sku_id' => (int)($specification['selected']['id'] ?? 0),
            'sku_name' => (string)($specification['selected']['sku_name'] ?? ''),
            'missing_fields' => $missing, 'can_submit' => $missing === [],
            'quick_create_allowed' => (bool)($context['can_manage_goods'] ?? false),
        ];
    }

    /** Keep product words next to the matched goods separate from customer text. */
    private static function specification(string $source, ?array $goods, array &$context): array
    {
        $result = ['status' => 'missing', 'matched_terms' => [], 'candidates' => [], 'selected' => null];
        if (!$goods) return $result;
        $goodsId = (int)$goods['id'];
        if (!isset($context['sku_configs'][$goodsId])) {
            $context['sku_configs'][$goodsId] = GoodsDimensionLogic::productDimensions(['goods_id' => $goodsId]);
        }
        $config = $context['sku_configs'][$goodsId];
        // These words express a preservation requirement even when that value is unavailable.
        $terms = ['鲜活' => '鲜活', '冰鲜' => '冰鲜', '冷冻' => '冷冻', '活的' => '鲜活', '活' => '鲜活'];
        $valueIds = [];
        foreach ($config['dimensions'] as $dimension) {
            if (($dimension['usage_mode'] ?? $dimension['dimension_type']) !== 'sku' || (int)$dimension['status'] !== 1) continue;
            foreach ($dimension['values'] as $value) {
                $name = trim((string)$value['name']);
                if ($name === '') continue;
                $terms[$name] = $name;
                $valueIds[(int)$value['id']] = true;
            }
        }
        uksort($terms, static fn(string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));
        $matchedName = (string)($goods['matched_name'] ?? $goods['name']);
        $characters = preg_split('//u', $matchedName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $pattern = '/' . implode('\s*', array_map(static fn(string $part): string => preg_quote($part, '/'), $characters)) . '/iu';
        if ($characters === [] || !preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE)) return $result;
        $position = $match[0][1];
        $prefix = self::trimBusinessSeparators(substr($source, 0, $position));
        $suffix = self::trimBusinessSeparators(substr($source, $position + strlen($match[0][0])));
        $constraints = [];
        $consume = static function (string &$text, bool $fromEnd) use ($terms, &$constraints, &$result): void {
            while ($text !== '') {
                $found = false;
                foreach ($terms as $term => $value) {
                    if (!($fromEnd ? str_ends_with($text, $term) : str_starts_with($text, $term))) continue;
                    $text = $fromEnd ? substr($text, 0, -strlen($term)) : substr($text, strlen($term));
                    $text = self::trimBusinessSeparators($text);
                    $result['matched_terms'][] = $term;
                    $constraints[$value] = true;
                    $found = true;
                    break;
                }
                if (!$found) break;
            }
        };
        // A complete known customer name wins over words contained in that name.
        $prefixCustomer = self::customerCandidatesForNeedle($prefix, (int)($context['main_customer_id'] ?? 0));
        $exactCustomer = array_filter($prefixCustomer['candidates'], static fn(array $customer): bool => mb_strtolower($customer['name']) === mb_strtolower($prefix));
        if ($exactCustomer === []) $consume($prefix, true);
        $result['customer_prefix'] = $prefix;
        $consume($suffix, false);
        if ($context['unit_pattern'] !== null) {
            $suffix = preg_replace('/^\d+(?:\.\d+)?\s*(?:' . $context['unit_pattern'] . ')\s*/iu', '', $suffix) ?? $suffix;
            $consume($suffix, false);
        }
        $result['suffix_remaining'] = $suffix;
        $result['matched_terms'] = array_values(array_unique($result['matched_terms']));
        if ($constraints === []) return $result;
        $candidates = [];
        foreach ($config['skus'] as $sku) {
            if ((int)$sku['status'] !== 1 || (int)$sku['sale_status'] !== 1 || (int)($sku['dimension_disabled_snapshot'] ?? 0) !== 0) continue;
            $values = [];
            $valid = true;
            foreach ($sku['dimensions'] as $dimension) {
                if (!isset($valueIds[(int)$dimension['value_id']])) { $valid = false; break; }
                $values[(string)$dimension['value_name']] = true;
            }
            if ($valid && array_diff_key($constraints, $values) === []) $candidates[] = $sku;
        }
        $result['status'] = count($candidates) === 1 ? 'unique' : ($candidates === [] ? 'none' : 'ambiguous');
        $result['candidates'] = $candidates;
        $result['selected'] = count($candidates) === 1 ? $candidates[0] : null;
        return $result;
    }

    private static function quantity(string $source, ?array $goods, array $context): array
    {
        $missing = ['status' => 'missing', 'value' => null, 'unit' => null, 'unit_id' => 0];
        $text = self::goodsSourceText($source);
        if ($context['unit_pattern'] === null || preg_match_all('/\d+(?:\.\d+)?/u', $text) !== 1
            || preg_match('/每|单价|元|约|左右|[~～至—+\-]/u', $text)
            || !preg_match('/(?<![\d.])(\d+(?:\.\d{1,4})?)\s*(' . $context['unit_pattern'] . ')\s*$/iu', $text, $match)
            || (float)$match[1] <= 0) return $missing;
        $unit = (string)$match[2];
        $unitId = (int)($context['unit_ids'][mb_strtolower($unit)] ?? 0);
        $bindings = $goods && $unitId > 0 ? Db::name('goods_units_binding')->where('tenant_id', self::tenantId())
            ->where('goods_id', (int)$goods['id'])->where('unit_id', $unitId)->where('status', 1)->select()->toArray() : [];
        $bound = $goods && $unitId > 0 && ((int)$goods['unit_id'] === $unitId
            || array_filter($bindings, static fn(array $binding): bool => (int)($binding['delete_time'] ?? 0) === 0) !== []);
        return ['status' => $bound ? 'unique' : 'unverified', 'value' => $match[1], 'unit' => $unit, 'unit_id' => $unitId];
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

    /** A quantity or an explicit inline addressee makes this a product row. */
    private static function sectionTitle(string $source, int $sourceIndex, array $goods, int $mainId, ?string $unitPattern): ?array
    {
        if ($sourceIndex === 0 || self::hasQuantityBoundary($source, $unitPattern)) {
            return null;
        }
        $customer = self::customerCandidatesForNeedle($source, $mainId);
        $prefix = self::customerPrefix($source);
        if ($prefix !== '' && $prefix !== $source) {
            $exact = array_filter($customer['candidates'], static fn(array $candidate): bool => mb_strtolower($candidate['name']) === mb_strtolower($source));
            if ($exact === []) {
                return null;
            }
        }
        $hasCustomer = $customer['candidates'] !== [];
        $hasGoods = $goods['candidates'] !== [];
        if ($hasGoods && !$hasCustomer) {
            return null;
        }
        $kind = $hasCustomer && !$hasGoods ? 'customer' : 'undecided';
        if ($kind === 'undecided' && $hasCustomer) {
            $customer['status'] = 'ambiguous';
            $customer['selected'] = null;
        }
        return ['id' => $sourceIndex, 'source_text' => $source, 'kind' => $kind, 'customer' => $customer];
    }

    /** @param array<int,string> $sourceLines @return ?array<string,mixed> */
    private static function firstLineHeader(array $sourceLines, array $context): ?array
    {
        if (count($sourceLines) < 2) {
            return null;
        }
        $source = trim((string)$sourceLines[0]);
        if ($source === '' || self::hasQuantityBoundary($source, $context['unit_pattern'])) {
            return null;
        }

        $hasQuantityLine = false;
        foreach (array_slice($sourceLines, 1) as $line) {
            if (self::hasQuantityBoundary($line, $context['unit_pattern'])) {
                $hasQuantityLine = true;
                break;
            }
        }
        $boundary = self::recognitionBoundary($source, $context['unit_pattern']);
        if (!$hasQuantityLine
            || (self::goodsCandidates($source, $boundary, $context)['status'] ?? '') !== 'none'
        ) {
            return null;
        }

        $customers = self::customerCandidatesForNeedle($source);
        $status = (string)($customers['status'] ?? 'none');
        return [
            'type' => 'customer_header', 'index' => 0, 'source_text' => $source,
            'status' => $status, 'customer' => $customers,
            'handling' => $status === 'unique' ? 'applied_to_lines' : 'needs_customer_confirmation',
        ];
    }

    /** @return array{status:string,selected:?array<string,mixed>,candidates:array<int,array<string,mixed>>} */
    private static function goodsCandidates(string $text, array $boundary, array &$context): array
    {
        $needle = (string)($boundary['suggested_name'] ?? self::goodsSourceText($text));
        $normalizedNeedle = GoodsAliasService::normalize($needle);
        $exactBoundary = $boundary['suggested_name'] !== null;
        if ($normalizedNeedle === '') {
            return ['status' => 'none', 'selected' => null, 'candidates' => []];
        }

        $exactSingleCharacterNames = [];
        if ($exactBoundary && preg_match('/^\p{Han}$/u', $normalizedNeedle)) {
            // A single-character name or alias takes priority over a coinciding product code.
            $exactSingleCharacterNames = $context['name_index'][$normalizedNeedle] ?? [];
            if ($exactSingleCharacterNames === []) {
                $singleCharacterMatch = self::singleCharacterGoodsMatch($needle, $normalizedNeedle, $context);
                if ($singleCharacterMatch !== null) {
                    return $singleCharacterMatch;
                }
            }
        }

        $matchingTokens = $exactBoundary
            ? self::normalizedSuffixes($normalizedNeedle, $context['max_token_length'])
            : self::normalizedPrefixes($normalizedNeedle, $context['max_token_length']);
        $matchedNames = [];
        foreach ($matchingTokens as $normalizedToken) {
            $matchedThisToken = false;
            $goodsNames = $context['token_index'][$normalizedToken] ?? [];
            if ($exactSingleCharacterNames !== []) {
                $goodsNames = array_intersect_key($goodsNames, $exactSingleCharacterNames);
            }
            foreach ($goodsNames as $goodsId => $displayName) {
                $matchedThisToken = true;
                if (!isset($matchedNames[$goodsId])
                    || mb_strlen($displayName) > mb_strlen($matchedNames[$goodsId])
                ) {
                    $matchedNames[$goodsId] = $displayName;
                }
            }
            if ($exactBoundary && $matchedThisToken) {
                break;
            }
        }
        ksort($matchedNames, SORT_NUMERIC);

        $candidates = [];
        foreach ($matchedNames as $goodsId => $matchedName) {
            $candidate = $context['goods_catalog'][$goodsId]['goods'];
            $candidate['matched_name'] = $matchedName;
            $candidate['match_type'] = GoodsAliasService::normalize($matchedName) === GoodsAliasService::normalize($candidate['name']) ? 'canonical' : 'alias';
            $candidates[] = $candidate;
            if (count($candidates) >= self::LIMIT) {
                break;
            }
        }

        if ($candidates !== []) {
            $selected = count($candidates) === 1 ? $candidates[0] : null;
            return [
                'status' => $selected !== null ? 'unique' : 'ambiguous',
                'selected' => $selected,
                'candidates' => $candidates,
                'match_type' => $selected['match_type'] ?? null,
            ];
        }

        if ($exactBoundary) {
            $withSpecification = [];
            $longest = 0;
            foreach ($context['token_index'] as $token => $goodsNames) {
                if (!str_contains($normalizedNeedle, $token) || str_ends_with($normalizedNeedle, $token)) continue;
                foreach ($goodsNames as $goodsId => $displayName) {
                    $candidate = $context['goods_catalog'][$goodsId]['goods'] + ['matched_name' => $displayName];
                    $specification = self::specification($text, $candidate, $context);
                    if (($specification['suffix_remaining'] ?? null) !== '' || $specification['matched_terms'] === []) continue;
                    $length = mb_strlen($token);
                    if ($length < $longest) continue;
                    if ($length > $longest) { $withSpecification = []; $longest = $length; }
                    $candidate['match_type'] = GoodsAliasService::normalize($displayName) === GoodsAliasService::normalize($candidate['name']) ? 'canonical' : 'alias';
                    $withSpecification[$goodsId] = $candidate;
                }
            }
            if ($withSpecification !== []) {
                $selected = count($withSpecification) === 1 ? reset($withSpecification) : null;
                return ['status' => $selected ? 'unique' : 'ambiguous', 'selected' => $selected,
                    'candidates' => array_slice(array_values($withSpecification), 0, self::LIMIT), 'match_type' => $selected['match_type'] ?? null];
            }
        }
        $fuzzy = self::fuzzyGoodsCandidates((string)($boundary['suggested_name'] ?? ''), $context);
        $prefixes = [];
        if (preg_match('/^\p{Han}{2,}$/u', $normalizedNeedle)) {
            // Count all matching goods before applying the display limit.
            foreach ($context['token_index'] as $token => $goodsNames) {
                if (!str_starts_with($token, $normalizedNeedle)) continue;
                foreach ($goodsNames as $goodsId => $_name) {
                    $prefixes[$goodsId] = $context['goods_catalog'][$goodsId]['goods'] + [
                        'matched_name' => $needle, 'match_type' => 'prefix',
                    ];
                }
            }
        }
        if ($prefixes !== []) {
            $selected = count($prefixes) === 1 ? reset($prefixes) : null;
            return ['status' => $selected ? 'unique' : 'ambiguous', 'selected' => $selected,
                'candidates' => array_slice(array_values($prefixes), 0, self::LIMIT), 'match_type' => 'prefix'];
        }
        return [
            'status' => $fuzzy === [] ? 'none' : 'ambiguous',
            'selected' => null,
            'candidates' => $fuzzy,
            'match_type' => $fuzzy === [] ? null : 'fuzzy',
        ];
    }

    /** @param array<int,array<string,mixed>> $goods
     * @return array{status:string,selected:?array<string,mixed>,candidates:array<int,array<string,mixed>>}
     */
    private static function customerCandidates(string $text, array $goods, ?string $productPrefix, int $mainId): array
    {
        $needle = '';
        if (preg_match('/(?:客户|客戶|给|給)\s*[:：]?\s*([^\s，,、；;]+?)(?:报货|報貨)?(?=\s|$)/u', $text, $m)) { $needle = trim($m[1]); }
        if ($needle === '' && $productPrefix !== null) return self::customerCandidatesForNeedle($productPrefix, $mainId);
        if ($needle === '' && $goods !== []) {
            foreach ($goods as $goodsCandidate) {
                $matchedName = (string)($goodsCandidate['matched_name'] ?? $goodsCandidate['name'] ?? '');
                $pos = $matchedName === '' ? false : mb_strpos($text, $matchedName);
                if ($pos !== false) {
                    $needle = self::trimBusinessSeparators(mb_substr($text, 0, $pos));
                    break;
                }
            }
        }
        return self::customerCandidatesForNeedle($needle, $mainId);
    }

    /** @return array{status:string,selected:?array<string,mixed>,candidates:array<int,array<string,mixed>>} */
    private static function customerCandidatesForNeedle(string $needle, int $mainId = 0): array
    {
        if (self::tenantId() <= 0 || $needle === '') { return ['status' => 'missing', 'selected' => null, 'candidates' => []]; }
        $query = Db::name('customer')->alias('delivery')
            ->leftJoin('customer main', 'main.id = delivery.parent_id AND main.tenant_id = delivery.tenant_id')
            ->where('delivery.tenant_id', self::tenantId())->where('delivery.is_disabled', 0)
            ->where(function ($valid) {
                $valid->where('delivery.parent_id', 0)->whereOr(function ($parent) {
                    $parent->where('main.parent_id', 0)->where('main.is_disabled', 0);
                });
            })
            ->whereRaw("delivery.customer_name LIKE ? ESCAPE '!'", [
                '%' . strtr($needle, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%',
            ]);
        if ($mainId > 0) {
            $query->where(function ($scope) use ($mainId) {
                $scope->where('delivery.id', $mainId)->whereOr('delivery.parent_id', $mainId);
            });
        }
        $rows = $query
            ->field([
                'delivery.id', 'delivery.customer_name', 'delivery.parent_id', 'delivery.phone', 'delivery.address',
                'main.id' => 'main_id', 'main.customer_name' => 'main_name', 'main.phone' => 'main_phone',
                'main.address' => 'main_address', 'main.is_disabled' => 'main_disabled',
            ])->orderRaw('CASE WHEN delivery.customer_name = ? THEN 0 ELSE 1 END', [$needle])
            ->order('delivery.id asc')->limit(self::LIMIT)->select()->toArray();
        $candidates = array_values(array_filter(array_map(static function (array $row): ?array {
            $deliveryId = (int)$row['id'];
            $parentId = (int)$row['parent_id'];
            $mainId = $parentId > 0 ? (int)$row['main_id'] : $deliveryId;
            $mainName = $parentId > 0 ? (string)$row['main_name'] : (string)$row['customer_name'];
            if ($mainId <= 0 || $mainName === '' || ($parentId > 0 && (int)$row['main_disabled'] === 1)) {
                return null;
            }
            $deliveryPhone = (string)($row['phone'] ?? '');
            $deliveryAddress = (string)($row['address'] ?? '');
            $mainPhone = $parentId > 0 ? (string)($row['main_phone'] ?? '') : $deliveryPhone;
            $mainAddress = $parentId > 0 ? (string)($row['main_address'] ?? '') : $deliveryAddress;
            return [
                'id' => $deliveryId, 'name' => (string)$row['customer_name'], 'parent_id' => $parentId,
                'customer_no' => self::customerNo($deliveryId), 'phone' => $deliveryPhone, 'address' => $deliveryAddress,
                'display_name' => $parentId > 0
                    ? $mainName . ' / ' . (string)$row['customer_name']
                    : (string)$row['customer_name'],
                'main_customer' => [
                    'id' => $mainId, 'name' => $mainName, 'customer_no' => self::customerNo($mainId),
                    'phone' => $mainPhone, 'address' => $mainAddress,
                ],
                'delivery_customer' => [
                    'id' => $deliveryId, 'name' => (string)$row['customer_name'], 'customer_no' => self::customerNo($deliveryId),
                    'phone' => $deliveryPhone, 'address' => $deliveryAddress,
                ],
            ];
        }, $rows)));
        $exact = array_values(array_filter($candidates, static fn(array $one): bool => mb_strtolower($one['name']) === mb_strtolower($needle)));
        $selected = count($exact) === 1 ? $exact[0] : (count($candidates) === 1 ? $candidates[0] : null);
        return ['status' => $selected ? 'unique' : ($candidates === [] ? 'none' : 'ambiguous'), 'selected' => $selected, 'candidates' => $candidates];
    }

    private static function customerNo(int $customerId): string
    {
        return 'C' . str_pad((string)$customerId, 8, '0', STR_PAD_LEFT);
    }

    /** @return ?array{status:string,selected:?array<string,mixed>,candidates:array<int,array<string,mixed>>,match_type:string} */
    private static function singleCharacterGoodsMatch(string $needle, string $normalizedNeedle, array $context): ?array
    {
        $goodsIds = [];
        $hasPrefix = false;
        foreach (array_keys($context['fuzzy_index'][$normalizedNeedle] ?? []) as $token) {
            $nameMatches = $context['name_index'][$token] ?? [];
            if ($nameMatches === []) {
                continue;
            }
            $goodsIds += $nameMatches;
            $hasPrefix = $hasPrefix || str_starts_with($token, $normalizedNeedle);
        }
        if ($goodsIds === []) {
            return null;
        }

        // Decide uniqueness across the eligible catalog before limiting the displayed candidates.
        $goodsIds = array_keys($goodsIds);
        sort($goodsIds, SORT_NUMERIC);
        $candidates = array_map(
            static fn(int $id): array => $context['goods_catalog'][$id]['goods'],
            array_slice($goodsIds, 0, self::LIMIT)
        );
        $selected = null;
        if ($hasPrefix && count($goodsIds) === 1) {
            $selected = $candidates[0] + ['matched_name' => $needle, 'match_type' => 'prefix'];
            $candidates = [$selected];
        }
        return [
            'status' => $selected !== null ? 'unique' : 'ambiguous',
            'selected' => $selected,
            'candidates' => $candidates,
            'match_type' => $selected['match_type'] ?? 'fuzzy',
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private static function fuzzyGoodsCandidates(string $needle, array $context): array
    {
        $normalizedNeedle = GoodsAliasService::normalize($needle);
        $needleCharacters = preg_split('//u', $normalizedNeedle, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($needleCharacters === []) {
            return [];
        }

        $firstGram = count($needleCharacters) === 1
            ? $needleCharacters[0]
            : $needleCharacters[0] . $needleCharacters[1];
        $goodsIds = [];
        foreach (array_keys($context['fuzzy_index'][$firstGram] ?? []) as $normalizedToken) {
            if (!str_contains($normalizedToken, $normalizedNeedle)) {
                continue;
            }
            foreach ($context['token_index'][$normalizedToken] ?? [] as $goodsId => $_displayName) {
                $goodsIds[(int)$goodsId] = true;
                if (count($goodsIds) >= self::LIMIT) {
                    break 2;
                }
            }
        }
        ksort($goodsIds, SORT_NUMERIC);

        $candidates = [];
        foreach (array_keys($goodsIds) as $goodsId) {
            $candidates[] = $context['goods_catalog'][$goodsId]['goods'];
            if (count($candidates) >= self::LIMIT) {
                break;
            }
        }
        return $candidates;
    }

    /** @return array{suggested_name:?string,remark:string} */
    private static function recognitionBoundary(string $text, ?string $unitPattern): array
    {
        $goodsText = self::goodsSourceText($text);
        if ($goodsText === '' || $unitPattern === null
            || !preg_match(
                '/^(.*?)(\d+(?:\.\d{1,4})?\s*(?:' . $unitPattern . '))(.*)$/iu',
                $goodsText,
                $match
            )
        ) {
            return ['suggested_name' => null, 'remark' => ''];
        }
        $suggestedName = self::trimBusinessSeparators((string)$match[1]);
        if ($suggestedName === '') {
            return ['suggested_name' => null, 'remark' => ''];
        }
        return [
            'suggested_name' => $suggestedName,
            'remark' => self::trimBusinessSeparators((string)$match[2] . (string)$match[3]),
        ];
    }

    private static function goodsSourceText(string $text): string
    {
        $withoutCustomer = preg_replace(
            '/^(?:客户|客戶|给|給)\s*[:：]?\s*[^\s，,、；;]+(?:\s*(?:报货|報貨))?\s*/u',
            '',
            trim($text)
        );
        return self::trimBusinessSeparators((string)$withoutCustomer);
    }

    /** @param array{suggested_name:?string,remark:string} $boundary */
    private static function recognizedRemark(string $source, string $matchedName, array $boundary): string
    {
        if ($boundary['suggested_name'] !== null) {
            return $boundary['remark'];
        }
        if ($matchedName === '') {
            return '';
        }
        $goodsText = self::goodsSourceText($source);
        $matchedCharacters = preg_split(
            '//u',
            GoodsAliasService::normalize($matchedName),
            -1,
            PREG_SPLIT_NO_EMPTY
        ) ?: [];
        if ($matchedCharacters === []) {
            return '';
        }
        $pattern = '/^' . implode(
            '\s*',
            array_map(static fn(string $character): string => preg_quote($character, '/'), $matchedCharacters)
        ) . '\s*/iu';
        if (preg_match($pattern, $goodsText, $match) !== 1) {
            return '';
        }
        return self::trimBusinessSeparators(substr($goodsText, strlen((string)$match[0])));
    }

    /**
     * @return array{
     *   unit_pattern:?string,
     *   goods_catalog:array<int,array{goods:array<string,mixed>,tokens:array<string,string>}>,
     *   token_index:array<string,array<int,string>>,
     *   name_index:array<string,array<int,true>>,
     *   fuzzy_index:array<string,array<string,true>>,
     *   max_token_length:int,
     *   can_manage_goods:bool
     * }
     */
    private static function recognitionContext(): array
    {
        $tenantId = self::tenantId();
        if ($tenantId <= 0) {
            return [
                'unit_pattern' => null,
                'unit_ids' => [],
                'goods_catalog' => [],
                'token_index' => [],
                'name_index' => [],
                'fuzzy_index' => [],
                'max_token_length' => 0,
                'can_manage_goods' => false,
            ];
        }

        $unitNames = array_map(
            'strval',
            Db::name('goods_unit')
                ->where('tenant_id', $tenantId)
                ->where('status', 1)
                ->column('name')
        );
        $rows = Db::name('goods')
            ->where('tenant_id', $tenantId)
            ->where('is_disabled', 0)
            ->where('is_archived', 0)
            ->field(['id', 'name', 'product_code', 'unit_id', 'units', 'category_id'])
            ->order('id asc')
            ->select()
            ->toArray();

        $catalog = [];
        $nameIndex = [];
        foreach ($rows as $row) {
            $goods = self::goods($row);
            $goodsId = (int)$goods['id'];
            $tokens = [];
            foreach (['name', 'product_code'] as $field) {
                $displayName = (string)$goods[$field];
                $normalized = GoodsAliasService::normalize($displayName);
                if ($normalized !== '') {
                    $tokens[$normalized] = $displayName;
                    if ($field === 'name') {
                        $nameIndex[$normalized][$goodsId] = true;
                    }
                }
            }
            $catalog[$goodsId] = ['goods' => $goods, 'tokens' => $tokens];
        }

        if ($catalog !== []) {
            $aliases = Db::name('goods_alias')
                ->where('tenant_id', $tenantId)
                ->whereIn('goods_id', array_keys($catalog))
                ->field(['goods_id', 'alias'])
                ->order('id asc')
                ->select()
                ->toArray();
            foreach ($aliases as $alias) {
                $goodsId = (int)($alias['goods_id'] ?? 0);
                $displayName = trim((string)($alias['alias'] ?? ''));
                $normalized = GoodsAliasService::normalize($displayName);
                if ($normalized !== '' && isset($catalog[$goodsId])) {
                    $catalog[$goodsId]['tokens'][$normalized] = $displayName;
                    $nameIndex[$normalized][$goodsId] = true;
                }
            }
        }

        $tokenIndex = [];
        $fuzzyIndex = [];
        $maxTokenLength = 0;
        foreach ($catalog as $goodsId => $entry) {
            foreach ($entry['tokens'] as $normalizedToken => $displayName) {
                $tokenIndex[$normalizedToken][$goodsId] = $displayName;
                $characters = preg_split('//u', $normalizedToken, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $maxTokenLength = max($maxTokenLength, count($characters));
                foreach (array_unique($characters) as $character) {
                    $fuzzyIndex[$character][$normalizedToken] = true;
                }
                for ($index = 0, $last = count($characters) - 1; $index < $last; $index++) {
                    $gram = $characters[$index] . $characters[$index + 1];
                    $fuzzyIndex[$gram][$normalizedToken] = true;
                }
            }
        }

        return [
            'unit_pattern' => self::unitPattern($unitNames),
            'unit_ids' => array_change_key_case(Db::name('goods_unit')->where('tenant_id', $tenantId)->where('status', 1)->column('id', 'name'), CASE_LOWER),
            'goods_catalog' => $catalog,
            'token_index' => $tokenIndex,
            'name_index' => $nameIndex,
            'fuzzy_index' => $fuzzyIndex,
            'max_token_length' => $maxTokenLength,
            'can_manage_goods' => self::canManageGoods(),
        ];
    }

    /** @return array<int,string> */
    private static function normalizedPrefixes(string $normalized, int $maximumLength): array
    {
        $characters = preg_split('//u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $prefixes = [];
        $prefix = '';
        foreach (array_slice($characters, 0, $maximumLength) as $character) {
            $prefix .= $character;
            $prefixes[] = $prefix;
        }
        return $prefixes;
    }

    /** @return array<int,string> */
    private static function normalizedSuffixes(string $normalized, int $maximumLength): array
    {
        $characters = preg_split('//u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $maximumLength = min(count($characters), max(0, $maximumLength));
        $suffixes = [];
        for ($length = $maximumLength; $length >= 1; $length--) {
            $suffixes[] = implode('', array_slice($characters, -$length));
        }
        return $suffixes;
    }

    private static function trimBusinessSeparators(string $value): string
    {
        return preg_replace('/^[\s，,、；;：:]+|[\s，,、；;：:]+$/u', '', $value) ?? trim($value);
    }

    /** @param array<int,string> $units */
    private static function unitPattern(array $units): ?string
    {
        $deduplicated = [];
        foreach ($units as $unit) {
            $unit = trim($unit);
            if ($unit !== '') {
                $deduplicated[mb_strtolower($unit)] = $unit;
            }
        }
        $units = array_values($deduplicated);
        if ($units === []) {
            return null;
        }
        usort($units, static fn(string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));
        return implode('|', array_map(static fn(string $unit): string => preg_quote($unit, '/'), $units));
    }

    private static function hasQuantityBoundary(string $text, ?string $unitPattern): bool
    {
        return $unitPattern !== null
            && preg_match(
                '/(?<![\d.])\d+(?:\.\d{1,4})?\s*(?:' . $unitPattern . ')/iu',
                self::goodsSourceText($text)
            ) === 1;
    }

    /** @param array<int,array<string,mixed>> $candidates */
    private static function commonMatchedName(array $candidates): string
    {
        $normalizedName = null;
        $displayName = '';
        foreach ($candidates as $candidate) {
            $matchedName = trim((string)($candidate['matched_name'] ?? ''));
            $normalized = GoodsAliasService::normalize($matchedName);
            if ($normalized === '') {
                return '';
            }
            if ($normalizedName === null) {
                $normalizedName = $normalized;
                $displayName = $matchedName;
                continue;
            }
            if ($normalized !== $normalizedName) {
                return '';
            }
        }
        return $displayName;
    }
    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function goods(array $row): array { return ['id'=>(int)($row['id']??0),'name'=>(string)($row['name']??''),'product_code'=>(string)($row['product_code']??''),'unit_id'=>(int)($row['unit_id']??0),'units'=>(string)($row['units']??''),'category_id'=>(int)($row['category_id']??0)]; }
    private static function tenantId(): int { return (int)(request()->tenantId ?? 0); }
    private static function canManageGoods(): bool { return GoodsMaintenancePermissionService::canMaintain(); }
}
