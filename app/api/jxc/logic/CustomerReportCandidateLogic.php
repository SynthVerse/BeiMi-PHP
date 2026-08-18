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

    /** @return array{header:?array<string,mixed>,lines:array<int,array<string,mixed>>} */
    public static function recognize(string $text): array
    {
        $sourceLines = self::sourceLines($text);
        $context = self::recognitionContext();
        $header = self::firstLineHeader($sourceLines, $context);
        $headerCustomer = $header['customer']['selected'] ?? null;
        if ($header !== null) {
            array_shift($sourceLines);
        }
        $totalLines = count($sourceLines);
        $sourceLines = array_slice($sourceLines, 0, self::LINE_LIMIT);
        return [
            'header' => $header,
            'lines' => array_map(
                static fn(string $line, int $index): array => self::line($line, $index, $headerCustomer, $context),
                $sourceLines,
                array_keys($sourceLines)
            ),
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
    private static function line(string $source, int $index, ?array $headerCustomer, array $context): array
    {
        $boundary = self::recognitionBoundary($source, $context['unit_pattern']);
        $quantity = ['status' => 'missing', 'value' => null, 'unit' => null];
        $goods = self::goodsCandidates($source, $boundary, $context);
        $customers = self::customerCandidates($source, $goods['candidates']);
        if (($customers['status'] ?? '') === 'missing' && $headerCustomer !== null) {
            $customers = ['status' => 'unique', 'selected' => $headerCustomer, 'candidates' => [$headerCustomer]];
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
        return [
            'index' => $index, 'source_text' => $source,
            'goods_needle' => $matchedName !== '' ? $matchedName : (string)($boundary['suggested_name'] ?? ''),
            'suggested_goods_name' => $suggestedGoodsName,
            'line_remark' => $lineRemark,
            'status' => $missing === [] ? 'ready' : (($goods['status'] ?? '') === 'none' ? 'no_goods_candidate' : 'needs_confirmation'),
            'customer' => $customers, 'goods' => $goods, 'quantity' => $quantity,
            'attributes' => ['specification' => null, 'processing' => []], 'preference' => $preference,
            'missing_fields' => $missing, 'can_submit' => $missing === [],
            'quick_create_allowed' => (bool)($context['can_manage_goods'] ?? false),
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
    private static function goodsCandidates(string $text, array $boundary, array $context): array
    {
        $needle = (string)($boundary['suggested_name'] ?? self::goodsSourceText($text));
        $normalizedNeedle = GoodsAliasService::normalize($needle);
        $exactBoundary = $boundary['suggested_name'] !== null;
        if ($normalizedNeedle === '') {
            return ['status' => 'none', 'selected' => null, 'candidates' => []];
        }

        $matchingTokens = $exactBoundary
            ? self::normalizedSuffixes($normalizedNeedle, $context['max_token_length'])
            : self::normalizedPrefixes($normalizedNeedle, $context['max_token_length']);
        $matchedNames = [];
        foreach ($matchingTokens as $normalizedToken) {
            $matchedThisToken = false;
            foreach ($context['token_index'][$normalizedToken] ?? [] as $goodsId => $displayName) {
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
            ];
        }

        return [
            'status' => 'none',
            'selected' => null,
            'candidates' => self::fuzzyGoodsCandidates(
                (string)($boundary['suggested_name'] ?? ''),
                $context
            ),
        ];
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
                    $needle = self::trimBusinessSeparators(mb_substr($text, 0, $pos));
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
                'delivery.id', 'delivery.customer_name', 'delivery.parent_id', 'delivery.phone', 'delivery.address',
                'main.id' => 'main_id', 'main.customer_name' => 'main_name', 'main.phone' => 'main_phone',
                'main.address' => 'main_address', 'main.is_disabled' => 'main_disabled',
            ])->order('delivery.id asc')->limit(self::LIMIT)->select()->toArray();
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
                '/^(.*?)(\d+(?:\.\d{1,2})?\s*(?:' . $unitPattern . '))(.*)$/iu',
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
                'goods_catalog' => [],
                'token_index' => [],
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
        foreach ($rows as $row) {
            $goods = self::goods($row);
            $tokens = [];
            foreach ([(string)$goods['name'], (string)$goods['product_code']] as $displayName) {
                $normalized = GoodsAliasService::normalize($displayName);
                if ($normalized !== '') {
                    $tokens[$normalized] = $displayName;
                }
            }
            $catalog[(int)$goods['id']] = ['goods' => $goods, 'tokens' => $tokens];
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
            'goods_catalog' => $catalog,
            'token_index' => $tokenIndex,
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
                '/(?<![\d.])\d+(?:\.\d{1,2})?\s*(?:' . $unitPattern . ')/iu',
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
