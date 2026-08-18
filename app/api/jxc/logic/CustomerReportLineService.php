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
        $orderQuantity = self::positive($item['order_qty'] ?? $item['quantity'] ?? $item['num'] ?? null, 2);
        if ($orderQuantity === false) {
            self::setError('报货数量必须为最多两位小数的正数');
            return false;
        }
        $unit = self::orderUnit($goods, $item);
        if ($unit === false) {
            return false;
        }
        $expected = self::expectedQuantity($orderQuantity, (string)$unit['name'], $item);
        if ($expected === false) {
            return false;
        }
        $price = self::price($item, $goods, $unit);
        if ($price === false) {
            return false;
        }
        $attributes = self::attributes($goods, $item);
        if ($attributes === false) {
            return false;
        }
        return [
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
            'piece_weight_min' => $expected['min'],
            'piece_weight_max' => $expected['max'],
            'piece_weight_confirmed' => $expected['confirmed'],
            'quality_snapshot' => $attributes['quality_snapshot'],
            'specification_snapshot' => $attributes['specification_snapshot'],
            'processing_requirement' => trim((string)($item['processing_requirement'] ?? $item['processing'] ?? '')),
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
     * @return array{base_qty:string,min:string,max:string,confirmed:int}|false
     */
    private static function expectedQuantity(string $orderQuantity, string $unitName, array $item): array|false
    {
        $unit = self::canonicalUnit($unitName);
        $factors = ['斤' => '1.00', '公斤' => '2.00', '两' => '0.10'];
        if (isset($factors[$unit])) {
            return [
                'base_qty' => bcmul($orderQuantity, $factors[$unit], self::SCALE),
                'min' => self::decimal((string)($item['piece_weight_min'] ?? 0)),
                'max' => self::decimal((string)($item['piece_weight_max'] ?? 0)),
                'confirmed' => self::truthy($item['piece_weight_confirmed'] ?? false) ? 1 : 0,
            ];
        }
        if (!in_array($unit, ['条', '个', '只', '盒', '件'], true) || !preg_match('/^\d+\.00$/', $orderQuantity)) {
            self::setError('计数单位数量必须是正整数');
            return false;
        }
        if (!self::truthy($item['piece_weight_confirmed'] ?? false)) {
            self::setError('计数单位必须在本次报货确认单件重量或区间');
            return false;
        }
        $min = self::positive($item['piece_weight_min'] ?? $item['piece_weight'] ?? null, 2);
        $max = self::positive($item['piece_weight_max'] ?? $item['piece_weight'] ?? null, 2);
        if ($min === false || $max === false || bccomp($max, $min, self::SCALE) < 0) {
            self::setError('单件重量区间无效');
            return false;
        }
        return ['base_qty' => bcmul($orderQuantity, $max, self::SCALE), 'min' => $min, 'max' => $max, 'confirmed' => 1];
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
