<?php
declare(strict_types=1);

namespace app\api\jxc\logic;

/** 盘点实物记录：同一筐只计一次，原秤值不经显示舍入后再计算。 */
final class InventoryMeasurements
{
    public static function normalize(array $input, array $unit, int $cutoff, int $now, ?callable $packagingResolver = null): array
    {
        $rows = $input['measurements'] ?? null;
        if (!is_array($rows) || !array_is_list($rows) || !$rows || count($rows) > 100) {
            throw new \DomainException('请记录1至100组实盘；未盘完不能作为零提交');
        }
        $seen = []; $locations = []; $facts = []; $total = '0.0000';
        foreach ($rows as $row) {
            if (!is_array($row)) { throw new \DomainException('实盘记录格式无效'); }
            $id = FinanceValue::text($row['measurement_id'] ?? '', 96);
            $location = FinanceValue::text($row['location'] ?? '', 100);
            if (isset($seen[$id]) || isset($locations[$location])) { throw new \DomainException('同一筐或位置不能重复累加，请修改原记录'); }
            $seen[$id] = true; $locations[$location] = true;
            $at = FinanceValue::id($row['measured_at'] ?? null);
            if ($at < $cutoff || $at > $now) { throw new \DomainException('实盘时间须在本次截止之后且不能在未来'); }
            $zero = ($row['explicit_zero'] ?? false) === true;
            $original = $row['net_weight_input'] ?? null;
            if ($zero) {
                if (count($rows) !== 1 || $original !== null || isset($row['gross_weight_input']) || isset($row['quantity'])) {
                    throw new \DomainException('确认现场为零不能同时累加其他实物');
                }
                $quantity = '0.0000'; $original = ['explicit_zero' => true];
            } elseif (isset($row['packaging'])) {
                if (!$packagingResolver || $original !== null || isset($row['gross_weight_input']) || isset($row['quantity'])) { throw new \DomainException('包装盘点必须核实本仓来源，不能与实称重复累加'); }
                $package = $packagingResolver($row['packaging']);
                $quantity = $package['base_quantity']; $original = ['packaging' => $package];
            } elseif (($unit['measure_type'] ?? '') === 'weight') {
                if ($original !== null && isset($row['gross_weight_input'])) { throw new \DomainException('净重与毛皮重只能采用一份来源'); }
                if (isset($row['gross_weight_input'])) {
                    $gross = $row['gross_weight_input']; $tare = $row['tare_weight_input'] ?? [];
                    $grossJin = TradeQuantityFacts::weightToJin($gross['value'] ?? null, (string)($gross['unit'] ?? ''));
                    $tareValue = TradeQuantityFacts::decimal($tare['value'] ?? null, 4, true);
                    $tareJin = bccomp($tareValue, '0', 4) === 0 ? '0.0000' : TradeQuantityFacts::weightToJin($tareValue, (string)($tare['unit'] ?? ''));
                    $jin = bcsub($grossJin, $tareJin, 4); $original = ['gross' => $gross, 'tare' => $tare];
                    if (bccomp($jin, '0', 4) <= 0) { throw new \DomainException('净重须大于零，皮重不能超过毛重'); }
                } else {
                    $jin = TradeQuantityFacts::weightToJin($original['value'] ?? null, (string)($original['unit'] ?? ''));
                }
                $factor = (string)($unit['jin_factor'] ?? '0');
                if (bccomp($factor, '0', 6) <= 0) { throw new \DomainException('库存重量单位尚未核实'); }
                $quantity = bcdiv($jin, $factor, 4);
                if (bccomp(bcmul($quantity, $factor, 8), $jin, 8) !== 0) { throw new \DomainException('称量超出库存单位精度'); }
            } elseif (($unit['measure_type'] ?? '') === 'count') {
                if ($original !== null || isset($row['gross_weight_input'])) { throw new \DomainException('计件库存请清点真实基础数量，不能用斤数代替'); }
                $quantity = TradeQuantityFacts::decimal($row['quantity'] ?? null, (int)($unit['quantity_precision'] ?? 0));
                $original = ['quantity' => $row['quantity'], 'unit_id' => $unit['unit_id'] ?? 0];
            } else {
                throw new \DomainException('库存基础单位尚未核实为重量或计数单位');
            }
            $facts[] = ['measurement_id' => $id, 'location' => $location, 'measured_at' => $at,
                'original' => $original, 'base_quantity' => $quantity, 'source' => isset($row['packaging']) ? 'packaging_rule' : (($unit['measure_type'] ?? '') === 'weight' ? 'measured' : 'counted')];
            $total = bcadd($total, $quantity, 4);
        }
        return ['measurements' => $facts, 'observed_quantity' => $total, 'counted_quantity' => $total];
    }
}
