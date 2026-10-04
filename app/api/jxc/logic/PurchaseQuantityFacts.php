<?php
declare(strict_types=1);
namespace app\api\jxc\logic;

/** 入库只采用实际点收/称量，不把供方报量或默认包装值伪装成实收。 */
final class PurchaseQuantityFacts
{
    public static function normalize(array $input, array $unit): array
    {
        if (!array_key_exists('measurement', $input)) { return $input; }
        if (!is_array($input['measurement'])) { throw new \DomainException('实际收货计量格式无效'); }
        $measurement = $input['measurement'];
        $measurement['measurement_id'] = 'arrival'; $measurement['location'] = '本次到货';
        $measurement['measured_at'] = time();
        $facts = InventoryMeasurements::normalize(['measurements' => [$measurement]], $unit, 0, time());
        if (bccomp($facts['counted_quantity'], '0', 4) <= 0) { throw new \DomainException('实际到货数量必须大于零'); }
        return array_replace($input, ['actual_quantity' => $facts['counted_quantity'],
            'measurement_snapshot' => $facts['measurements'][0]]);
    }
}
