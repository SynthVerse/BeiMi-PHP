<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use app\common\model\jxc\Customer;
use app\common\model\jxc\Goods;
use app\common\model\jxc\GoodsSkuSpecValue;
use app\common\model\jxc\GoodsSpecValue;
use app\common\model\jxc\GoodsUnitsBinding;
use app\common\service\goods\GoodsSkuSelectionService;
use think\facade\Db;

/** 将客户端确认后的报货行重新校验、标准化并换算为库存基础单位。 */
class CustomerReportLineService extends BaseLogic
{
    private const SCALE = 2;

    /** @return array<int, array<string, mixed>>|false */
    public static function normalizeItems(array $items, int $mainCustomerId): array|false
    {
        if ($mainCustomerId <= 0 || $items === []) {
            self::setError('请至少提交一条客户报货行');
            return false;
        }
        $main = self::mainCustomer($mainCustomerId);
        if ($main === false) {
            return false;
        }
        $normalized = [];
        $actualReceivingCustomerId = null;
        foreach (array_values($items) as $index => $item) {
            if (!is_array($item)) {
                self::setError('报货行格式无效');
                return false;
            }
            $line = self::normalizeItem($item, $main, $index);
            if ($line === false) {
                return false;
            }
            if ($actualReceivingCustomerId === null) {
                $actualReceivingCustomerId = (int)$line['delivery_customer_id'];
            } elseif ($actualReceivingCustomerId !== (int)$line['delivery_customer_id']) {
                self::setError('一张报货单只能对应一个实际收货客户');
                return false;
            }
            $normalized[] = $line;
        }
        return $normalized;
    }

    /** @param array<string, mixed> $item
     * @param array{id:int,name:string} $main
     * @return array<string, mixed>|false
     */
    private static function normalizeItem(array $item, array $main, int $index): array|false
    {
        $tenantId = self::tenantId();
        $goodsId = (int)($item['goods_id'] ?? 0);
        $warehouseId = (int)($item['warehouse_id'] ?? 0);
        $goods = Goods::where('tenant_id', $tenantId)->where('id', $goodsId)->findOrEmpty();
        if ($goods->isEmpty() || (int)($goods->is_disabled ?? 0) === 1) {
            self::setError('报货商品不存在或已停用');
            return false;
        }
        if ($warehouseId <= 0 || !Db::name('warehouse')->where('tenant_id', $tenantId)->where('id', $warehouseId)->find()) {
            self::setError('报货仓库不存在');
            return false;
        }
        $delivery = self::deliveryCustomer((int)($item['delivery_customer_id'] ?? 0), $main);
        if ($delivery === false) {
            return false;
        }
        $version = (int)($item['quantity_contract_version'] ?? 1);
        if (!in_array($version, [1, 2], true)) { self::setError('数量契约版本不受支持，请刷新客户端后重试'); return false; }
        try {
            $orderQuantity = $version === 2
                ? TradeQuantityFacts::decimal($item['order_qty'] ?? $item['quantity'] ?? $item['num'] ?? null)
                : self::positive($item['order_qty'] ?? $item['quantity'] ?? $item['num'] ?? null, 2);
        } catch (\DomainException $e) { self::setError($e->getMessage()); return false; }
        if ($orderQuantity === false) {
            self::setError('报货数量必须为最多两位小数的正数');
            return false;
        }
        $unit = self::orderUnit($goods, $item);
        if ($unit === false) {
            return false;
        }
        $attributes = self::attributes($goods, $item);
        if ($attributes === false) { return false; }
        $expected = $version === 2
            ? self::expectedQuantityV2($orderQuantity, $unit, $attributes, array_merge($item, ['main_customer_id' => $main['id']]))
            : self::expectedQuantity($orderQuantity, (string)$unit['name'], $item);
        if ($expected === false) {
            return false;
        }
        $price = self::price($item, $goods, $unit);
        if ($price === false) {
            return false;
        }
        $processingGroupsInput = (array)($item['processing_groups'] ?? []);
        if ($processingGroupsInput === [] && $version === 2) {
            $processingGroupsInput = [['group_key' => 'direct', 'name' => '直接配货', 'planned_qty' => $orderQuantity, 'process_ids' => []]];
        }
        if ($processingGroupsInput === []) {
            self::setError('每条报货明细必须至少添加一个加工分组');
            return false;
        }
        $processingGroups = self::normalizeProcessingGroups($processingGroupsInput, $orderQuantity, $version === 2);
        if ($processingGroups === false) {
            return false;
        }
        return ($version === 2 ? ['quantity_contract_version' => 2, 'size_check_stage' => $expected['size_check_stage'], 'estimate_status' => $expected['estimate_status'],
            'estimate_source' => $expected['estimate_source'], 'estimate_reference_snapshot' => json_encode($expected['reference'] ?? null, JSON_UNESCAPED_UNICODE)] : []) + [
            'client_line_id' => (int)($item['id'] ?? 0),
            'warehouse_id' => $warehouseId,
            'goods_id' => (int)$goods->id,
            'goods_name' => (string)$goods->name,
            'goods_code' => (string)($goods->product_code ?? ''),
            'sku_id' => $attributes['sku_id'],
            'sku_name' => $attributes['sku_name'],
            'quality_id' => $attributes['quality_id'],
            'spec_id' => $attributes['spec_id'],
            'main_customer_id' => $main['id'],
            'main_customer_name' => $main['name'],
            'delivery_customer_id' => $delivery['id'],
            'delivery_customer_name' => $delivery['name'],
            'unit_id' => $unit['id'],
            'unit_name' => $unit['name'],
            'base_unit_id' => $attributes['base_unit_id'],
            'base_unit_name' => $attributes['base_unit_name'],
            'order_qty' => $orderQuantity,
            'expected_base_qty' => $expected['base_qty'],
            'acceptable_base_qty_min' => $expected['acceptable_min'],
            'acceptable_base_qty_max' => $expected['acceptable_max'],
            'piece_weight_min' => $expected['min'],
            'piece_weight_max' => $expected['max'],
            'piece_weight_confirmed' => $expected['confirmed'],
            'specification_verification_status' => $expected['confirmed'] === 1 ? 'pending' : 'not_required',
            'verified_piece_count' => 0,
            'verified_piece_weight_min' => '0.00',
            'verified_piece_weight_max' => '0.00',
            'specification_verification_note' => '',
            'specification_verified_by' => 0,
            'specification_verified_time' => 0,
            'quality_snapshot' => $attributes['quality_snapshot'],
            'specification_snapshot' => $attributes['specification_snapshot'],
            'processing_requirement' => trim((string)($item['processing_requirement'] ?? $item['processing'] ?? '')),
            'processing_groups' => $processingGroups,
            'price_status' => $price['status'],
            'price' => $price['price'],
            'pricing_unit_id' => $price['unit_id'],
            'pricing_unit_name' => $price['unit_name'],
            'line_remark' => trim((string)($item['remark'] ?? $item['line_remark'] ?? '')),
            'sort' => $index,
        ];
    }

    /** @return array{id:int,name:string}|false */
    private static function mainCustomer(int $id): array|false
    {
        $customer = Customer::where('tenant_id', self::tenantId())->where('id', $id)->findOrEmpty();
        if ($customer->isEmpty() || (int)($customer->is_disabled ?? 0) === 1) {
            self::setError('主客户不存在或已停用');
            return false;
        }
        if ((int)($customer->parent_id ?? 0) > 0) {
            $customer = Customer::where('tenant_id', self::tenantId())->where('id', (int)$customer->parent_id)->findOrEmpty();
            if ($customer->isEmpty() || (int)($customer->parent_id ?? 0) > 0 || (int)($customer->is_disabled ?? 0) === 1) {
                self::setError('子客户所属主客户无效');
                return false;
            }
        }
        return ['id' => (int)$customer->id, 'name' => (string)$customer->customer_name];
    }

    /** @param array{id:int,name:string} $main
     * @return array{id:int,name:string}|false
     */
    private static function deliveryCustomer(int $id, array $main): array|false
    {
        if ($id <= 0 || $id === $main['id']) {
            return $main;
        }
        $customer = Customer::where('tenant_id', self::tenantId())->where('id', $id)->findOrEmpty();
        if ($customer->isEmpty() || (int)($customer->is_disabled ?? 0) === 1 || (int)($customer->parent_id ?? 0) !== $main['id']) {
            self::setError('配送客户必须是该主客户或其一级子客户');
            return false;
        }
        return ['id' => (int)$customer->id, 'name' => (string)$customer->customer_name];
    }

    /** @param Goods $goods
     * @param array<string, mixed> $item
     * @return array{id:int,name:string}|false
     */
    private static function orderUnit(Goods $goods, array $item): array|false
    {
        $unitId = (int)($item['unit_id'] ?? $item['order_unit_id'] ?? 0);
        $unitName = trim((string)($item['unit_name'] ?? $item['unit'] ?? ''));
        if ($unitId <= 0 && $unitName === '') {
            $unitId = (int)($goods->unit_id ?? 0);
            $unitName = (string)($goods->units ?? '');
        }
        $binding = null;
        if ($unitId > 0) {
            $binding = GoodsUnitsBinding::where('tenant_id', self::tenantId())->where('goods_id', (int)$goods->id)
                ->where('unit_id', $unitId)->where('status', 1)->findOrEmpty();
        }
        if (($binding === null || $binding->isEmpty()) && $unitName !== '') {
            $binding = GoodsUnitsBinding::where('tenant_id', self::tenantId())->where('goods_id', (int)$goods->id)
                ->where('unit_name', $unitName)->where('status', 1)->findOrEmpty();
        }
        if ($binding && !$binding->isEmpty()) {
            return ['id' => (int)$binding->unit_id, 'name' => (string)$binding->unit_name];
        }
        if ($unitId === (int)($goods->unit_id ?? 0) && $unitName === (string)($goods->units ?? '')) {
            return ['id' => $unitId, 'name' => $unitName];
        }
        self::setError('报货单位不属于该商品');
        return false;
    }

    /** @param array<string, mixed> $item
     * @return array{base_qty:string,acceptable_min:string,acceptable_max:string,min:string,max:string,confirmed:int}|false
     */
    private static function expectedQuantity(string $orderQuantity, string $unitName, array $item): array|false
    {
        $unit = self::canonicalUnit($unitName);
        $factors = ['斤' => '1.00', '公斤' => '2.00', '两' => '0.10'];
        if (isset($factors[$unit])) {
            $baseQuantity = bcmul($orderQuantity, $factors[$unit], self::SCALE);
            $range = self::pieceWeightRange($item, false);
            if ($range === false) {
                return false;
            }
            $acceptable = self::acceptableTotalRange($item, $baseQuantity);
            if ($acceptable === false) {
                return false;
            }
            if ($range['confirmed'] === 1 && !self::hasPossiblePieceCount($acceptable['min'], $acceptable['max'], $range['min'], $range['max'])) {
                self::setError('可接受总重量范围与单条重量要求无法同时满足');
                return false;
            }
            return [
                'base_qty' => $baseQuantity,
                'acceptable_min' => $acceptable['min'],
                'acceptable_max' => $acceptable['max'],
                'min' => $range['min'],
                'max' => $range['max'],
                'confirmed' => $range['confirmed'],
            ];
        }
        if (!in_array($unit, ['条', '个', '只', '盒', '件'], true) || !preg_match('/^\d+\.00$/', $orderQuantity)) {
            self::setError('计数单位数量必须是正整数');
            return false;
        }
        $range = self::pieceWeightRange($item, true);
        if ($range === false) {
            return false;
        }
        $physicalMin = bcmul($orderQuantity, $range['min'], self::SCALE);
        $physicalMax = bcmul($orderQuantity, $range['max'], self::SCALE);
        $requested = self::positive($item['expected_base_qty'] ?? null, 2);
        $baseQuantity = $requested === false ? $physicalMax : $requested;
        if (bccomp($baseQuantity, $physicalMin, self::SCALE) < 0 || bccomp($baseQuantity, $physicalMax, self::SCALE) > 0) {
            self::setError('预计总重量必须落在数量与单件重量范围可组成的区间内');
            return false;
        }
        $acceptable = self::acceptableTotalRange($item, $baseQuantity, $physicalMin, $physicalMax);
        if ($acceptable === false) {
            return false;
        }
        return [
            'base_qty' => $baseQuantity,
            'acceptable_min' => $acceptable['min'],
            'acceptable_max' => $acceptable['max'],
            'min' => $range['min'],
            'max' => $range['max'],
            'confirmed' => 1,
        ];
    }

    /** @return array<int,array<string,mixed>>|false */
    private static function expectedQuantityV2(string $quantity, array $unit, array $attributes, array $item): array|false
    {
        try {
            $metadata = TradeQuantityService::unit((int)$unit['id']);
            $base = TradeQuantityService::unit((int)$attributes['base_unit_id']);
            if (!$metadata || !$base) { throw new \DomainException('商品单位配置缺失'); }
            if (!in_array($metadata['measure_type'], ['weight', 'count'], true) || !in_array($base['measure_type'], ['weight', 'count'], true)
                || ($base['measure_type'] === 'weight' && empty($base['jin_factor']))) { throw new \DomainException('单位类型或重量关系尚未核实，请先维护真实计量配置'); }
            TradeQuantityFacts::decimal($quantity, (int)$metadata['quantity_precision']);
            $range = self::pieceWeightRange($item, false);
            if ($range === false) { return false; }
            $stage = (string)($item['size_check_stage'] ?? 'delivery');
            if (!in_array($stage, ['before_processing', 'delivery'], true)) { throw new \DomainException('客户大小核验阶段无效'); }
            $expected = '0.0000'; $source = 'unknown'; $reference = null;
            if ((int)$unit['id'] === (int)$attributes['base_unit_id']) {
                $expected = bcadd($quantity, '0', 4); $source = 'demand_base_unit';
            } elseif ($metadata['measure_type'] === 'weight' && $base['measure_type'] === 'weight') {
                $expected = TradeQuantityFacts::exactDivide(TradeQuantityFacts::weightToJin($quantity, $metadata['unit_name']), $base['jin_factor'], (int)$base['quantity_precision']);
                $source = 'weight_conversion';
            } elseif ($metadata['measure_type'] === 'count' && $base['measure_type'] === 'weight' && $range['confirmed'] === 1) {
                $mean = bcdiv(bcadd($range['min'], $range['max'], 4), '2', 4);
                $expected = bcdiv(bcmul($quantity, $mean, 4), $base['jin_factor'], 4); $source = 'customer_size_range';
            } elseif ($metadata['measure_type'] === 'count' && $base['measure_type'] === 'weight') {
                $reference = self::historicalWeightReference((int)$item['goods_id'], (int)$attributes['sku_id'], (int)$unit['id'], (int)$item['main_customer_id']);
                if ($reference) {
                    // An estimate may be rounded; it never creates an actual count, weight, or conversion rule.
                    $expected = bcdiv(bcmul($quantity, $reference['weight_jin'], 8), bcmul($reference['count'], $base['jin_factor'], 8), 4);
                    $source = 'completed_delivery_reference';
                }
            }
            // Explicit total constraints are distinct from an estimate. Absent bounds remain 0/unset.
            $min = trim((string)($item['acceptable_base_qty_min'] ?? ''));
            $max = trim((string)($item['acceptable_base_qty_max'] ?? ''));
            if ($min !== '' && is_numeric($min) && bccomp($min, '0', 4) === 0) { $min = ''; }
            if ($max !== '' && is_numeric($max) && bccomp($max, '0', 4) === 0) { $max = ''; }
            if ($min !== '' || $max !== '') {
                $min = TradeQuantityFacts::decimal($min); $max = TradeQuantityFacts::decimal($max);
                if (bccomp($min, $max, 4) > 0) { throw new \DomainException('客户总重范围无效'); }
                if ($range['confirmed'] === 1 && $metadata['measure_type'] === 'count') {
                    $physicalMin = bcmul($quantity, $range['min'], 4); $physicalMax = bcmul($quantity, $range['max'], 4);
                    if (bccomp($max, $physicalMin, 4) < 0 || bccomp($min, $physicalMax, 4) > 0) {
                        throw new \DomainException('数量与每件大小无法满足客户总重范围');
                    }
                    if (bccomp($expected, $min, 4) < 0) { $expected = $min; }
                    if (bccomp($expected, $max, 4) > 0) { $expected = $max; }
                }
            }
            return ['base_qty' => $expected, 'acceptable_min' => $min === '' ? '0.0000' : $min,
                'acceptable_max' => $max === '' ? '0.0000' : $max, 'min' => $range['min'], 'max' => $range['max'],
                'confirmed' => $range['confirmed'], 'size_check_stage' => $stage, 'estimate_status' => $source === 'unknown' ? 'unknown' : 'known', 'estimate_source' => $source, 'reference' => $reference];
        } catch (\DomainException $e) { self::setError($e->getMessage()); return false; }
    }

    private static function historicalWeightReference(int $goods, int $sku, int $unit, int $customer): ?array
    {
        $rows = Db::name('fulfillment_delivery_item')->alias('d')
            ->join('fulfillment_delivery_event e', "e.tenant_id=d.tenant_id AND e.id=d.delivery_event_id AND e.status='completed'")
            ->join('customer_report_item i', 'i.tenant_id=d.tenant_id AND i.id=d.report_item_id')
            ->leftJoin('sales_delivery_correction c', 'c.tenant_id=d.tenant_id AND c.order_id=d.sales_order_id')
            ->where('d.tenant_id', self::tenantId())->where('d.goods_id', $goods)->where('d.sku_id', $sku)
            ->where('i.main_customer_id', $customer)->where('d.fact_version', 2)->whereNull('c.id')
            ->field('d.id,d.delivery_event_id,d.quantity_facts_snapshot,e.delivered_time')->order('e.delivered_time desc,d.id desc')->limit(20)->select()->toArray();
        foreach ($rows as $row) {
            $facts = TradeQuantityService::snapshot($row);
            if (!$facts || ($facts['weight_source'] ?? '') !== 'measured' || !empty($facts['processing']) || bccomp((string)($facts['actual_delivery_weight'] ?? '0'), '0', 4) <= 0) { continue; }
            foreach ($facts['quantity_facts'] as $count) {
                if ((int)$count['unit_id'] === $unit && ($count['source'] ?? '') === 'counted' && bccomp($count['quantity'], '0', 4) > 0) {
                    return ['strategy_version' => 'same_customer_sku_direct_measurement_v1', 'source_delivery_item_id' => (int)$row['id'], 'source_event_id' => (int)$row['delivery_event_id'],
                        'source_version' => (int)$facts['version'], 'recorded_at' => (int)$row['delivered_time'],
                        'unit_id' => $unit, 'count' => $count['quantity'], 'weight_jin' => $facts['actual_delivery_weight']];
                }
            }
        }
        return null;
    }

    private static function normalizeProcessingGroups(array $groups, string $orderQuantity, bool $allowDirect = false): array|false
    {
        if ($groups === []) {
            self::setError('每条报货明细必须至少添加一个加工分组');
            return false;
        }
        $normalized = [];
        $keys = [];
        $total = '0.00';
        foreach (array_values($groups) as $index => $group) {
            if (!is_array($group)) {
                self::setError('加工分组格式无效');
                return false;
            }
            $key = trim((string)($group['group_key'] ?? $group['client_group_id'] ?? 'group-' . ($index + 1)));
            if ($key === '' || strlen($key) > 64 || isset($keys[$key])) {
                self::setError('加工分组标识无效或重复');
                return false;
            }
            $keys[$key] = true;
            try { $quantity = $allowDirect ? TradeQuantityFacts::decimal($group['planned_qty'] ?? null) : self::positive($group['planned_qty'] ?? null, 2); }
            catch (\DomainException $e) { self::setError($e->getMessage()); return false; }
            if ($quantity === false) {
                self::setError('加工分组计划数量必须为最多两位小数的正数');
                return false;
            }
            $processIds = array_values(array_unique(array_filter(array_map('intval', (array)($group['process_ids'] ?? [])), static fn(int $id): bool => $id > 0)));
            if ($processIds === [] && !$allowDirect) {
                self::setError('每个加工分组必须选择至少一个执行工序');
                return false;
            }
            $processes = $processIds === [] ? [] : Db::name('work_process')->where('tenant_id', self::tenantId())->whereIn('id', $processIds)
                ->where('trigger_type', 'report_selection')->where('is_enabled', 1)->whereNull('delete_time')
                ->field('id,name,sort')->select()->toArray();
            if (count($processes) !== count($processIds)) {
                self::setError('加工分组包含不可用的报货选择工序');
                return false;
            }
            usort($processes, static fn(array $left, array $right): int => [(int)$left['sort'], (int)$left['id']] <=> [(int)$right['sort'], (int)$right['id']]);
            $normalized[] = [
                'group_key' => $key,
                'name' => mb_substr(trim((string)($group['name'] ?? '')) ?: '加工分组' . ($index + 1), 0, 100),
                'planned_qty' => $quantity,
                'processes' => array_values($processes),
                'sort' => $index,
            ];
            $total = bcadd($total, $quantity, $allowDirect ? 4 : self::SCALE);
        }
        if (bccomp($total, $orderQuantity, $allowDirect ? 4 : self::SCALE) !== 0) {
            self::setError('加工分组计划数量之和必须等于报货数量');
            return false;
        }
        return $normalized;
    }

    /** @param array<string,mixed> $item @return array{min:string,max:string,confirmed:int}|false */
    private static function pieceWeightRange(array $item, bool $required): array|false
    {
        $confirmed = self::truthy($item['piece_weight_confirmed'] ?? false);
        if (!$confirmed) {
            if ($required) {
                self::setError('计数单位必须在本次报货确认单件重量或区间');
                return false;
            }
            return ['min' => '0.00', 'max' => '0.00', 'confirmed' => 0];
        }
        $min = self::positive($item['piece_weight_min'] ?? $item['piece_weight'] ?? null, 2);
        $max = self::positive($item['piece_weight_max'] ?? $item['piece_weight'] ?? null, 2);
        if ($min === false || $max === false || bccomp($max, $min, self::SCALE) < 0) {
            self::setError('单件重量区间无效');
            return false;
        }
        return ['min' => $min, 'max' => $max, 'confirmed' => 1];
    }

    /** @param array<string,mixed> $item @return array{min:string,max:string}|false */
    private static function acceptableTotalRange(array $item, string $target, ?string $physicalMin = null, ?string $physicalMax = null): array|false
    {
        $minInput = trim((string)($item['acceptable_base_qty_min'] ?? ''));
        $maxInput = trim((string)($item['acceptable_base_qty_max'] ?? ''));
        $min = $minInput === '' ? $target : self::positive($minInput, 2);
        $max = $maxInput === '' ? $target : self::positive($maxInput, 2);
        if ($min === false || $max === false || bccomp($max, $min, self::SCALE) < 0
            || bccomp($target, $min, self::SCALE) < 0 || bccomp($target, $max, self::SCALE) > 0) {
            self::setError('可接受总重量范围必须包含本次预计总重量');
            return false;
        }
        if (($physicalMin !== null && bccomp($max, $physicalMin, self::SCALE) < 0)
            || ($physicalMax !== null && bccomp($min, $physicalMax, self::SCALE) > 0)) {
            self::setError('可接受总重量范围与数量及单件重量要求无法同时满足');
            return false;
        }
        return ['min' => $min, 'max' => $max];
    }

    private static function hasPossiblePieceCount(string $totalMin, string $totalMax, string $pieceMin, string $pieceMax): bool
    {
        $minimumCount = (int)bcdiv($totalMin, $pieceMax, 0);
        if (bccomp(bcmul((string)$minimumCount, $pieceMax, self::SCALE), $totalMin, self::SCALE) < 0) {
            $minimumCount++;
        }
        $maximumCount = (int)bcdiv($totalMax, $pieceMin, 0);
        return $minimumCount > 0 && $minimumCount <= $maximumCount;
    }

    /** @param array<string, mixed> $item
     * @param Goods $goods
     * @param array{id:int,name:string} $orderUnit
     * @return array{status:string,price:string,unit_id:int,unit_name:string}|false
     */
    private static function price(array $item, Goods $goods, array $orderUnit): array|false
    {
        $status = (string)($item['price_status'] ?? 'unpriced');
        if ($status === '' || $status === 'unpriced') {
            if ((int)($item['quantity_contract_version'] ?? 1) === 2) {
                $id = (int)($item['pricing_unit_id'] ?? $goods->default_pricing_unit_id ?? $goods->unit_id ?? 0);
                $metadata = $id > 0 ? TradeQuantityService::unit($id) : null;
                if ($metadata) {
                    $pricing = self::orderUnit($goods, ['unit_id' => $id, 'unit_name' => $metadata['unit_name']]);
                    if ($pricing === false) { return false; }
                    return ['status' => 'unpriced', 'price' => '0.00', 'unit_id' => $pricing['id'], 'unit_name' => $pricing['name']];
                }
            }
            return ['status' => 'unpriced', 'price' => '0.00', 'unit_id' => 0, 'unit_name' => ''];
        }
        if ($status !== 'priced') {
            self::setError('价格状态无效');
            return false;
        }
        $price = self::positive($item['price'] ?? $item['unit_price'] ?? null, 2, true);
        if ($price === false) {
            self::setError('确认单价必须为合法非负数');
            return false;
        }
        $unitId = (int)($item['pricing_unit_id'] ?? $item['price_unit_id'] ?? 0);
        $unitName = trim((string)($item['pricing_unit_name'] ?? $item['price_unit_name'] ?? ''));
        if ($unitId <= 0 || $unitName === '') {
            self::setError('确认单价时必须同时填写计价单位');
            return false;
        }
        $pricing = self::orderUnit($goods, ['unit_id' => $unitId, 'unit_name' => $unitName]);
        if ($pricing === false) {
            self::setError('计价单位不属于该商品');
            return false;
        }
        return ['status' => 'priced', 'price' => $price, 'unit_id' => $pricing['id'], 'unit_name' => $pricing['name']];
    }

    /** @param array<string, mixed> $item
     * @return array{sku_id:int,sku_name:string,base_unit_id:int,base_unit_name:string,quality_id:int,spec_id:int,quality_snapshot:string,specification_snapshot:string}|false
     */
    private static function attributes(Goods $goods, array $item): array|false
    {
        $tenantId = self::tenantId();
        $skuId = (int)($item['sku_id'] ?? 0);
        $skuName = '';
        $qualityId = (int)($item['quality_id'] ?? 0);
        $qualitySnapshot = trim((string)($item['quality'] ?? $item['quality_snapshot'] ?? ''));
        $specificationId = (int)($item['spec_id'] ?? $item['specification_id'] ?? 0);
        $specificationSnapshot = trim((string)($item['specification'] ?? $item['specification_snapshot'] ?? ''));
        try {
            $sku = GoodsSkuSelectionService::forSale($tenantId, (int)$goods->id, $skuId);
        } catch (\InvalidArgumentException $exception) {
            self::setError($exception->getMessage());
            return false;
        }
        $skuId = (int)$sku->id;
        $skuName = (string)$sku->sku_name;
        $qualityRelation = self::skuAttribute($skuId, 'quality_status');
        if ($qualityId <= 0 && $qualitySnapshot === '' && $qualityRelation !== null) {
            $qualityId = $qualityRelation['id'];
            $qualitySnapshot = $qualityRelation['name'];
        }
        $specificationRelation = self::skuAttribute($skuId, 'weight_grade');
        if ($specificationId <= 0 && $specificationSnapshot === '' && $specificationRelation !== null) {
            $specificationId = $specificationRelation['id'];
            $specificationSnapshot = $specificationRelation['name'];
        }
        $quality = self::attributeValue($goods, $qualityId, $qualitySnapshot, '品质', 'quality_status', $skuId);
        if ($quality === false) {
            return false;
        }
        $specification = self::attributeValue($goods, $specificationId, $specificationSnapshot, '规格', 'weight_grade', $skuId);
        if ($specification === false) {
            return false;
        }
        return [
            'sku_id' => $skuId, 'sku_name' => $skuName,
            'base_unit_id' => (int)$sku->base_unit_id > 0 ? (int)$sku->base_unit_id : (int)($goods->unit_id ?? 0),
            'base_unit_name' => trim((string)($sku->base_unit_name ?? '')) !== ''
                ? (string)$sku->base_unit_name
                : (string)($goods->units ?? ''),
            'quality_id' => $quality['id'], 'spec_id' => $specification['id'],
            'quality_snapshot' => $quality['name'], 'specification_snapshot' => $specification['name'],
        ];
    }

    /** @return array{id:int,name:string}|false */
    private static function attributeValue(Goods $goods, int $valueId, string $snapshot, string $label, string $dimensionCode, int $skuId = 0): array|false
    {
        if ($valueId <= 0 && $snapshot === '') {
            return ['id' => 0, 'name' => ''];
        }
        if ($valueId <= 0) {
            self::setError($label . '必须从当前商品已维护的属性中选择');
            return false;
        }
        $dimension = Db::name('goods_spec')
            ->where('tenant_id', self::tenantId())
            ->where('code', $dimensionCode)
            ->field('id,status')
            ->find();
        $dimensionId = (int)($dimension['id'] ?? 0);
        if ($dimensionId <= 0) {
            self::setError($label . '维度未维护，不能使用该属性');
            return false;
        }
        if ((int)($dimension['status'] ?? 0) === 0) {
            $belongsToSelectedSku = $skuId > 0 && GoodsSkuSpecValue::where('tenant_id', self::tenantId())
                ->where('sku_id', $skuId)
                ->where('spec_id', $dimensionId)
                ->where('spec_value_id', $valueId)
                ->count() > 0;
            if (!$belongsToSelectedSku) {
                self::setError($label . '维度已停用，只能沿用既有SKU快照');
                return false;
            }
        }
        $value = GoodsSpecValue::where('tenant_id', self::tenantId())->where('goods_id', (int)$goods->id)
            ->where('spec_id', $dimensionId)->where('id', $valueId)->where('status', 1)->findOrEmpty();
        if ($value->isEmpty()) {
            self::setError($label . '不属于当前商品或属性类型不匹配');
            return false;
        }
        return ['id' => (int)$value->id, 'name' => (string)$value->name];
    }

    /** @return array{id:int,name:string}|null */
    private static function skuAttribute(int $skuId, string $dimensionCode): ?array
    {
        $row = Db::name('goods_sku_spec_value')->alias('relation')
            ->join('goods_spec spec', 'spec.id=relation.spec_id AND spec.tenant_id=relation.tenant_id')
            ->where('relation.tenant_id', self::tenantId())
            ->where('relation.sku_id', $skuId)
            ->where('spec.code', $dimensionCode)
            ->field('relation.spec_value_id,relation.spec_value_name')
            ->find();
        return $row ? ['id' => (int)$row['spec_value_id'], 'name' => (string)$row['spec_value_name']] : null;
    }

    private static function positive(mixed $value, int $scale, bool $allowZero = false): string|false
    {
        $value = trim((string)$value);
        $pattern = '/^\d+(?:\.\d{1,2})?$/';
        if ($value === '' || preg_match($pattern, $value) !== 1) {
            return false;
        }
        $normalized = self::decimal($value);
        return bccomp($normalized, '0.00', self::SCALE) > 0 || ($allowZero && bccomp($normalized, '0.00', self::SCALE) === 0) ? $normalized : false;
    }
    private static function canonicalUnit(string $unit): string { return match (mb_strtolower(trim($unit))) { 'kg', '公斤', '千克' => '公斤', default => trim($unit) }; }
    private static function truthy(mixed $value): bool { return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true); }
    private static function decimal(string $value): string { return bcadd($value, '0', self::SCALE); }
    private static function tenantId(): int { return (int)(request()->tenantId ?? 0); }
}
