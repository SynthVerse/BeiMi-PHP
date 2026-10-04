<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

/** 计费只消费明确交付事实，不推算件数，不改变库存。 */
final class FinanceSalesQuantity
{
    public static function quantity(mixed $value, int $precision = 4): string
    {
        if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]{0,11})(\.[0-9]{1,4})?$/D', $value) || bccomp($value, '0', 4) <= 0) {
            throw new \DomainException('计费数量须为大于零的十进制数');
        }
        if (bccomp($value, bcadd($value, '0', $precision), 4) !== 0) { throw new \DomainException('计费数量不符合该单位的精度'); }
        return bcadd($value, '0', 4);
    }

    public static function price(array $source, array $input): array
    {
        if (($input['fact_version'] ?? 0) !== 2) { throw new \DomainException('该交付须使用新版数量计价，请刷新页面'); }
        if (!is_string($input['source_version'] ?? null) || !hash_equals($source['source_version'], $input['source_version'])) {
            throw new \DomainException('交付事实或计价依据已变化，请重新读取');
        }
        $unitId = FinanceValue::id($input['price_unit_id'] ?? null);
        $units = array_column($source['pricing_units'], null, 'id');
        if (!isset($units[$unitId])) { throw new \DomainException('计价单位不属于该商品当前有效绑定'); }
        $unit = $units[$unitId];
        if ($unit['quantity'] === null) { throw new \DomainException(($unit['unavailable_reason'] ?? '该计价单位缺少本次真实交付依据') . '，金额保持待确认'); }
        $quantity = self::quantity($input['settlement_quantity'] ?? null, (int)$unit['precision']);
        if ($unit['kind'] !== 'weight' && bccomp($quantity, $unit['quantity'], 4) !== 0) {
            throw new \DomainException('计数结算须覆盖完整交付子组；部分确认请选择已核实的独立子组');
        }
        $weight = $unit['kind'] === 'weight' ? bcmul($quantity, $unit['jin_factor'], 4) : null;
        $difference = $weight !== null ? bcsub($weight, $source['actual_weight'], 4) : '0.0000';
        return ['price_unit_id' => $unitId, 'price_unit_name' => $unit['name'], 'pricing_unit_name' => $unit['name'],
            'settlement_quantity' => $quantity, 'pricing_quantity' => $quantity, 'settlement_weight' => $weight,
            'covered_weight' => $source['actual_weight'], 'weight_difference' => $difference];
    }
}
