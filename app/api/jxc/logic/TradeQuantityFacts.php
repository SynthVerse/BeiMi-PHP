<?php
declare(strict_types=1);

namespace app\api\jxc\logic;

/** Independent physical facts. No database writes, prices or inferred piece counts. */
final class TradeQuantityFacts
{
    public const SCALE = 4;

    public static function exactDivide(string $value, string $factor, int $precision = 4): string
    {
        $result = bcdiv($value, $factor, $precision);
        if (bccomp(bcmul($result, $factor, 12), $value, 12) !== 0) {
            throw new \DomainException('换算结果超出单位精度，不能截断实际数量');
        }
        return bcadd($result, '0', 4);
    }

    public static function exactMultiply(string $value, string $factor, int $precision = 4): string
    {
        $precise = bcmul($value, $factor, 12);
        $result = bcadd($precise, '0', $precision);
        if (bccomp($precise, $result, 12) !== 0) { throw new \DomainException('换算结果超出单位精度，不能截断实际数量'); }
        return bcadd($result, '0', 4);
    }

    public static function decimal(mixed $input, int $precision = 4, bool $zero = false): string
    {
        $text = trim((string)$input);
        $pattern = $precision === 0 ? '/^\d+(?:\.0{1,4})?$/' : '/^\d+(?:\.\d{1,' . $precision . '})?$/';
        if (!preg_match($pattern, $text)
            || ($precision === 0 && bccomp($text, bcadd($text, '0', 0), 4) !== 0)
            || strlen(explode('.', $text)[0]) > 12
            || bccomp($text, '0', 4) < ($zero ? 0 : 1)) {
            throw new \DomainException('数量必须是符合单位精度的' . ($zero ? '非负数' : '正数'));
        }
        return bcadd($text, '0', 4);
    }

    public static function weightToJin(mixed $value, string $unit): string
    {
        $factor = match (mb_strtolower(trim($unit))) {
            '斤', 'jin' => '1', 'kg', '公斤', '千克' => '2', 'g', '克' => '0.002', '两' => '0.1',
            default => throw new \DomainException('秤单位须为克、公斤或斤'),
        };
        $precise = bcmul(self::decimal($value), $factor, 7);
        $normalized = bcadd($precise, '0', 4);
        if (bccomp($precise, $normalized, 7) !== 0 || bccomp($normalized, '0', 4) <= 0) {
            throw new \DomainException('换算结果超出库存四位小数精度，请核对秤的计量精度');
        }
        return $normalized;
    }

    /** @param array<int,array> $units keyed by existing bound unit id */
    public static function normalize(array $input, array $item, array $units): array
    {
        $facts = [];
        foreach ((array)($input['quantity_facts'] ?? []) as $fact) {
            $id = (int)($fact['unit_id'] ?? 0);
            if (!isset($units[$id]) || isset($facts[$id]) || ($units[$id]['measure_type'] ?? '') !== 'count') {
                throw new \DomainException('计数事实单位未绑定、重复或不是计数单位');
            }
            if (($fact['source'] ?? 'counted') !== 'counted') {
                throw new \DomainException('本次计数必须实际清点，不能按均重推算');
            }
            $facts[$id] = ['unit_id' => $id, 'unit_name' => $units[$id]['unit_name'],
                'quantity' => self::decimal($fact['quantity'] ?? null, (int)$units[$id]['quantity_precision']), 'source' => 'counted'];
        }
        $original = $input['net_weight_input'] ?? null;
        if ($original === null && isset($input['final_actual_weight']) && $input['final_actual_weight'] !== '') {
            $original = ['value' => $input['final_actual_weight'], 'unit' => '斤'];
        }
        $weight = $original === null ? null : self::weightToJin($original['value'] ?? null, (string)($original['unit'] ?? ''));
        if (isset($input['gross_weight_input'])) {
            if ($original !== null) { throw new \DomainException('净重和毛皮重只能采用一份称量来源'); }
            $gross = self::weightToJin($input['gross_weight_input']['value'] ?? null, (string)($input['gross_weight_input']['unit'] ?? ''));
            $tareInput = $input['tare_weight_input'] ?? [];
            $tareValue = self::decimal($tareInput['value'] ?? null, 4, true);
            $tare = bccomp($tareValue, '0', 4) === 0 ? '0.0000'
                : self::weightToJin($tareInput['value'] ?? null, (string)($tareInput['unit'] ?? ''));
            $weight = bcsub($gross, $tare, 4);
            if (bccomp($weight, '0', 4) <= 0) { throw new \DomainException('净重必须大于零且皮重不能超过毛重'); }
            $original = ['gross' => $input['gross_weight_input'], 'tare' => $tareInput];
        }
        if (isset($input['packaging_rule_id']) || isset($input['packaging'])) {
            throw new \DomainException('当前库存来源尚未核实包装入出库一致性，请填写本次实际净重');
        }
        $baseId = (int)$item['base_unit_id'];
        $base = $units[$baseId] ?? null;
        if (!$base) { throw new \DomainException('库存基础单位配置缺失'); }
        if ($base['measure_type'] === 'weight') {
            if (empty($base['jin_factor'])) { throw new \DomainException('该重量库存单位缺少可信换算关系'); }
            if ($weight === null) { throw new \DomainException('斤库存交付还缺本次实际净重'); }
            $baseQty = self::exactDivide($weight, (string)$base['jin_factor'], (int)$base['quantity_precision']);
        } elseif ($base['measure_type'] === 'count') {
            if (!isset($facts[$baseId])) { throw new \DomainException('交付还缺库存基础单位的实际清点数量'); }
            $baseQty = $facts[$baseId]['quantity'];
            if (isset($input['inventory_base_quantity']) && bccomp(self::decimal($input['inventory_base_quantity']), $baseQty, 4) !== 0) {
                throw new \DomainException('库存基础数量与实际清点数量不一致');
            }
        } else { throw new \DomainException('当前库存计量类型尚不支持此称重入口'); }
        $orderUnit = (int)$item['unit_id'];
        if (($units[$orderUnit]['measure_type'] ?? '') === 'count' && !isset($facts[$orderUnit])) {
            throw new \DomainException('请实际清点并确认报货单位的交付数量');
        }
        $processing = $input['processing'] ?? null;
        $loss = '0.0000';
        if ($processing !== null) {
            if ($base['measure_type'] !== 'weight' || ($processing['mode'] ?? '') !== 'direct_loss'
                || ($processing['disposition'] ?? '') !== 'discarded' || trim((string)($processing['reason'] ?? '')) === '') {
                throw new \DomainException('加工须核清实际废弃去向；可售副产物或库存转换请走已验证的独立流程');
            }
            $raw = self::decimal($processing['raw_input_weight'] ?? null);
            $loss = self::decimal($processing['loss_weight'] ?? null, 4, true);
            if (bccomp($raw, bcadd($weight, $loss, 4), 4) !== 0) {
                throw new \DomainException('加工前取用必须等于交付净重加已核实损耗，差额不能自动当损耗');
            }
            $processing = ['mode' => 'direct_loss', 'raw_input_weight' => $raw, 'loss_weight' => $loss,
                'disposition' => 'discarded', 'reason' => trim((string)$processing['reason'])];
        }
        return ['fact_version' => 2, 'actual_delivery_weight' => $weight,
            'inventory_base_quantity' => $baseQty, 'inventory_base_unit_id' => $baseId,
            'inventory_base_unit_name' => (string)$item['base_unit_name'], 'quantity_facts' => array_values($facts),
            'net_weight_input' => $original, 'weight_source' => $weight === null ? 'unknown' : 'measured',
            'stage' => 'delivery', 'processing' => $processing, 'processing_loss_weight' => $loss];
    }
}
