<?php
declare(strict_types=1);
namespace app\api\jxc\logic;
use think\facade\Db;

/** Reuses the existing unit directory and goods bindings. */
final class TradeQuantityService
{
    public static function unit(int $id): ?array
    {
        $unit = Db::name('goods_unit')->where('tenant_id', (int)request()->tenantId)->where('id', $id)->where('status', 1)->find();
        if (!$unit) { return null; }
        $factor = match (mb_strtolower(trim((string)$unit['name']))) {
            '斤', 'jin' => '1', '公斤', '千克', 'kg' => '2', '克', 'g' => '0.002', '两' => '0.1', default => null,
        };
        $legacyCount = in_array((string)$unit['name'], ['条', '只', '个', '把', '块', '瓶', '袋', '盒', '包', '件', '箱'], true);
        return ['quantity_contract_version' => 2, 'unit_id' => $id, 'unit_name' => (string)$unit['name'],
            'measure_type' => ($unit['measure_type'] ?? '') ?: ($factor === null ? ($legacyCount ? 'count' : 'unknown') : 'weight'),
            'quantity_precision' => empty($unit['measure_type']) ? ($factor === null ? 0 : 4) : (int)$unit['quantity_precision'], 'jin_factor' => $factor];
    }

    public static function boundUnits(array $item): array
    {
        $rows = GoodsLogic::boundUnitsForGoods((int)$item['goods_id'], ['unit_id' => $item['base_unit_id'], 'units' => $item['base_unit_name']]);
        $units = [];
        foreach ($rows as $row) {
            if ((int)$row['status'] !== 1) { continue; }
            $unit = self::unit((int)$row['unit_id']);
            if ($unit) { $units[(int)$row['unit_id']] = $unit; }
        }
        return $units;
    }

    public static function weightFromJin(string $weight, int $unitId): string
    {
        $unit = self::unit($unitId);
        if (!$unit || $unit['measure_type'] !== 'weight' || !$unit['jin_factor']) {
            throw new \DomainException('计价单位没有可信重量换算');
        }
        return TradeQuantityFacts::exactDivide($weight, $unit['jin_factor'], (int)$unit['quantity_precision']);
    }

    public static function snapshot(array $group): ?array
    {
        $data = json_decode((string)($group['quantity_facts_snapshot'] ?? ''), true);
        return is_array($data) && (int)($data['fact_version'] ?? 0) === 2 ? $data : null;
    }
}
