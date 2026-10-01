<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use think\facade\Db;

/** 把客户默认车辆冻结为报货单自己的送货安排快照。 */
final class CustomerReportDeliveryArrangementService extends BaseLogic
{
    private const METHODS = ['customer_vehicle', 'third_party', 'self_delivery'];
    private const REQUIRED_CUSTOMER_VEHICLE_FIELDS = [
        'earliest_delivery_time',
        'plate_number',
        'vehicle_location',
    ];

    /** @return array{storage:array<string,mixed>,snapshot:array<string,mixed>}|false */
    public static function forSubmit(
        array $params,
        int $deliveryCustomerId,
        string $deliveryDate,
        int $tenantId
    ): array|false {
        self::clearError();
        $input = is_array($params['delivery_arrangement'] ?? null)
            ? $params['delivery_arrangement']
            : [];
        $method = trim((string)($input['delivery_method'] ?? 'customer_vehicle'));
        if (!in_array($method, self::METHODS, true)) {
            self::setError('配送方式无效');
            return false;
        }

        $snapshot = [
            'delivery_method' => $method,
            'status' => 'ready',
            'delivery_date' => $deliveryDate,
            'delivery_customer_id' => $deliveryCustomerId,
            'source_vehicle_id' => 0,
            'source_vehicle_version' => 0,
            'earliest_delivery_time' => '',
            'plate_number' => '',
            'vehicle_location' => '',
            'driver_phone' => '',
            'missing_fields' => [],
        ];

        if ($method === 'customer_vehicle') {
            $sourceVehicleId = (int)($input['source_vehicle_id'] ?? 0);
            if ($sourceVehicleId > 0) {
                $vehicle = Db::name('customer_delivery_vehicle')
                    ->alias('binding')
                    ->leftJoin(
                        'delivery_vehicle vehicle',
                        'vehicle.id=binding.vehicle_id AND vehicle.tenant_id=binding.tenant_id AND vehicle.delete_time IS NULL'
                    )
                    ->where('binding.tenant_id', $tenantId)
                    ->where('binding.customer_id', $deliveryCustomerId)
                    ->where('binding.id', $sourceVehicleId)
                    ->where('binding.is_enabled', 1)
                    ->whereNull('binding.delete_time')
                    ->field([
                        'binding.*',
                        'vehicle.id' => 'master_vehicle_id',
                        'vehicle.plate_number' => 'master_plate_number',
                        'vehicle.driver_phone' => 'master_driver_phone',
                        'vehicle.is_enabled' => 'master_is_enabled',
                        'vehicle.version' => 'master_vehicle_version',
                    ])
                    ->lock(true)
                    ->find();
                if (!$vehicle || ((int)($vehicle['master_vehicle_id'] ?? 0) > 0 && (int)$vehicle['master_is_enabled'] !== 1)) {
                    self::setError('所选客户候选车辆不属于本次实际收货客户');
                    return false;
                }
                $snapshot = array_replace($snapshot, [
                    'source_vehicle_id' => (int)$vehicle['id'],
                    'source_vehicle_version' => (int)$vehicle['version'],
                    'earliest_delivery_time' => trim((string)$vehicle['earliest_delivery_time']),
                    'plate_number' => strtoupper(trim((string)(($vehicle['master_plate_number'] ?? '') ?: $vehicle['plate_number']))),
                    'vehicle_location' => trim((string)$vehicle['vehicle_location']),
                    'driver_phone' => trim((string)(($vehicle['master_driver_phone'] ?? '') ?: $vehicle['driver_phone'])),
                ]);
            }
            foreach (['earliest_delivery_time', 'plate_number', 'vehicle_location', 'driver_phone'] as $field) {
                if (array_key_exists($field, $input)) {
                    $snapshot[$field] = trim((string)$input[$field]);
                }
            }
            $snapshot['plate_number'] = strtoupper((string)$snapshot['plate_number']);
            if (!self::validateCustomerVehicleFields($snapshot)) {
                return false;
            }
            $snapshot['missing_fields'] = self::missingCustomerVehicleFields($snapshot);
            $snapshot['status'] = $snapshot['missing_fields'] === [] ? 'ready' : 'pending_completion';
        }

        $encoded = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            self::setError('本次送货安排无法保存');
            return false;
        }
        return [
            'storage' => [
                'delivery_method' => $method,
                'delivery_arrangement_status' => $snapshot['status'],
                'delivery_customer_id' => $deliveryCustomerId,
                'earliest_delivery_time' => $snapshot['earliest_delivery_time'],
                'delivery_arrangement_snapshot' => $encoded,
            ],
            'snapshot' => $snapshot,
        ];
    }

    /** @return array<string,mixed> */
    public static function fromRow(array $row): array
    {
        $method = trim((string)($row['delivery_method'] ?? ''));
        $decoded = json_decode((string)($row['delivery_arrangement_snapshot'] ?? ''), true);
        $snapshot = is_array($decoded) ? $decoded : [];
        $result = [
            'delivery_method' => $method,
            'status' => trim((string)($row['delivery_arrangement_status'] ?? '')),
            'delivery_date' => (string)($row['delivery_date'] ?? ''),
            'delivery_customer_id' => (int)($row['delivery_customer_id'] ?? 0),
            'source_vehicle_id' => 0,
            'source_vehicle_version' => 0,
            'earliest_delivery_time' => (string)($row['earliest_delivery_time'] ?? ''),
            'plate_number' => '',
            'vehicle_location' => '',
            'driver_phone' => '',
            'missing_fields' => [],
        ];
        foreach (array_keys($result) as $field) {
            if (array_key_exists($field, $snapshot)) {
                $result[$field] = $snapshot[$field];
            }
        }
        $result['delivery_method'] = $method;
        $result['delivery_date'] = (string)($row['delivery_date'] ?? ($snapshot['delivery_date'] ?? ''));
        $result['delivery_customer_id'] = (int)($row['delivery_customer_id'] ?? ($snapshot['delivery_customer_id'] ?? 0));
        $result['earliest_delivery_time'] = (string)($row['earliest_delivery_time'] ?? ($snapshot['earliest_delivery_time'] ?? ''));
        $result['source_vehicle_id'] = (int)$result['source_vehicle_id'];
        $result['source_vehicle_version'] = (int)$result['source_vehicle_version'];

        if ($method === '') {
            $result['status'] = 'missing';
            $result['missing_fields'] = [
                'delivery_method',
                ...self::REQUIRED_CUSTOMER_VEHICLE_FIELDS,
            ];
        } elseif ($method === 'customer_vehicle') {
            $result['missing_fields'] = self::missingCustomerVehicleFields($result);
            $result['status'] = $result['missing_fields'] === [] ? 'ready' : 'pending_completion';
        } else {
            $result['status'] = 'ready';
            $result['missing_fields'] = [];
        }
        return $result;
    }

    /** @param array<string,mixed> $snapshot */
    private static function validateCustomerVehicleFields(array $snapshot): bool
    {
        $time = (string)$snapshot['earliest_delivery_time'];
        if ($time !== '' && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
            self::setError('最早送货时间格式应为 HH:MM');
            return false;
        }
        if (mb_strlen((string)$snapshot['plate_number']) > 32) {
            self::setError('车辆车牌号最多 32 字');
            return false;
        }
        if (mb_strlen((string)$snapshot['vehicle_location']) > 255) {
            self::setError('车辆地点最多 255 字');
            return false;
        }
        $phone = (string)$snapshot['driver_phone'];
        if ($phone !== '' && preg_match('/^[0-9+\-\s]{6,20}$/', $phone) !== 1) {
            self::setError('请输入正确的司机电话');
            return false;
        }
        return true;
    }

    /** @param array<string,mixed> $snapshot @return array<int,string> */
    private static function missingCustomerVehicleFields(array $snapshot): array
    {
        return array_values(array_filter(
            self::REQUIRED_CUSTOMER_VEHICLE_FIELDS,
            static fn(string $field): bool => trim((string)($snapshot[$field] ?? '')) === ''
        ));
    }
}
