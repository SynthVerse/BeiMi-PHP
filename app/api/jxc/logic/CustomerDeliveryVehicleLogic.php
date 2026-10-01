<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use think\facade\Db;
use think\facade\Log;

/**
 * 车辆档案与客户车辆绑定。
 *
 * 车辆档案保存共享的车牌、司机电话和车辆状态；客户车辆绑定保存客户自己的
 * 最早送货时间、交接地点、排序和绑定状态。旧 customer_delivery_vehicle 表
 * 继续作为绑定表使用，以保持报货安排快照的 source_vehicle_id 兼容。
 */
final class CustomerDeliveryVehicleLogic extends BaseLogic
{
    /** @return array<string,int|string> */
    public static function normalizeInput(array $params): array
    {
        return [
            'customer_id' => (int)($params['customer_id'] ?? 0),
            'vehicle_id' => (int)($params['vehicle_id'] ?? 0),
            'version' => (int)($params['version'] ?? 0),
            'vehicle_version' => (int)($params['vehicle_version'] ?? 0),
            'earliest_delivery_time' => trim((string)($params['earliest_delivery_time'] ?? '')),
            'plate_number' => strtoupper(trim((string)($params['plate_number'] ?? ''))),
            'vehicle_location' => trim((string)($params['vehicle_location'] ?? '')),
            'driver_phone' => trim((string)($params['driver_phone'] ?? '')),
            'sort' => (int)($params['sort'] ?? 0),
            'is_enabled' => (int)($params['is_enabled'] ?? 1) === 0 ? 0 : 1,
            'vehicle_is_enabled' => (int)($params['vehicle_is_enabled'] ?? $params['is_enabled'] ?? 1) === 0 ? 0 : 1,
        ];
    }

    /** @return array<string,int|string>|false */
    public static function validateInput(array $params): array|false
    {
        $data = self::normalizeInput($params);
        if ($data['customer_id'] <= 0) {
            self::setError('客户不存在');
            return false;
        }
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string)$data['earliest_delivery_time'])) {
            self::setError('最早送货时间格式应为 HH:MM');
            return false;
        }
        if ($data['plate_number'] === '') {
            self::setError('请输入车辆车牌号');
            return false;
        }
        if ($data['vehicle_location'] === '') {
            self::setError('请输入车辆地点');
            return false;
        }
        if (mb_strlen((string)$data['plate_number']) > 32) {
            self::setError('车辆车牌号最多 32 字');
            return false;
        }
        if (mb_strlen((string)$data['vehicle_location']) > 255) {
            self::setError('车辆地点最多 255 字');
            return false;
        }
        if ($data['driver_phone'] !== '' && !preg_match('/^[0-9+\-\s]{6,20}$/', (string)$data['driver_phone'])) {
            self::setError('请输入正确的司机电话');
            return false;
        }

        return $data;
    }

    /** @return array<int,array<string,mixed>> */
    public static function lists(array $params): array
    {
        $tenantId = self::tenantId();
        $customerId = (int)($params['customer_id'] ?? 0);
        if ($tenantId <= 0 || $customerId <= 0) {
            return [];
        }

        $query = self::bindingQuery($tenantId)
            ->where('binding.customer_id', $customerId);
        if ((int)($params['enabled_only'] ?? 0) === 1) {
            $query->where('binding.is_enabled', 1)
                ->where(function ($builder) {
                    $builder->whereNull('vehicle.id')->whereOr('vehicle.is_enabled', 1);
                });
        }

        $rows = $query->field(self::bindingFields())
            ->order([
                'binding.sort' => 'asc',
                'binding.earliest_delivery_time' => 'asc',
                'binding.id' => 'asc',
            ])
            ->select()
            ->toArray();

        return array_map([self::class, 'formatBindingItem'], $rows);
    }

    /** @return array{data:array<int,array<string,mixed>>,total:int,page:int,pagesize:int} */
    public static function crossCustomerLists(array $params): array
    {
        $tenantId = self::tenantId();
        $page = max(1, (int)($params['page'] ?? $params['page_no'] ?? 1));
        $pageSize = min(50, max(1, (int)($params['pagesize'] ?? $params['page_size'] ?? 20)));
        if ($tenantId <= 0) {
            return ['data' => [], 'total' => 0, 'page' => $page, 'pagesize' => $pageSize];
        }

        $keyword = trim((string)($params['keyword'] ?? ''));
        $status = strtolower(trim((string)($params['status'] ?? 'all')));
        $query = Db::name('delivery_vehicle')
            ->alias('vehicle')
            ->join(
                'customer_delivery_vehicle binding',
                'binding.vehicle_id=vehicle.id AND binding.tenant_id=vehicle.tenant_id AND binding.delete_time IS NULL'
            )
            ->join(
                'customer customer',
                'customer.id=binding.customer_id AND customer.tenant_id=binding.tenant_id'
            )
            ->where('vehicle.tenant_id', $tenantId)
            ->whereNull('vehicle.delete_time');

        if ($keyword !== '') {
            $escapedKeyword = addcslashes($keyword, '%_\\');
            $query->whereLike(
                'vehicle.plate_number|vehicle.driver_phone|binding.vehicle_location|customer.customer_name|customer.phone',
                '%' . $escapedKeyword . '%'
            );
        }
        if ($status === 'enabled') {
            $query->where('vehicle.is_enabled', 1);
        } elseif ($status === 'disabled') {
            $query->where('vehicle.is_enabled', 0);
        }

        $total = (int)(clone $query)->distinct(true)->count('vehicle.id');
        $rows = $query->field([
                'vehicle.id',
                'vehicle.plate_number',
                'vehicle.driver_phone',
                'vehicle.is_enabled',
                'vehicle.version',
                'vehicle.create_time',
                'vehicle.update_time',
                'COUNT(DISTINCT binding.customer_id)' => 'bound_customer_count',
                'MIN(binding.earliest_delivery_time)' => 'earliest_delivery_time',
            ])
            ->group('vehicle.id')
            ->order([
                'vehicle.is_enabled' => 'desc',
                'earliest_delivery_time' => 'asc',
                'vehicle.plate_number' => 'asc',
                'vehicle.id' => 'asc',
            ])
            ->limit(($page - 1) * $pageSize, $pageSize)
            ->select()
            ->toArray();

        $data = array_map(static function (array $vehicle) use ($tenantId): array {
            $primary = self::firstBindingForVehicle((int)$vehicle['id'], $tenantId);
            return [
                ...self::formatVehicle($vehicle),
                'bound_customer_count' => (int)($vehicle['bound_customer_count'] ?? 0),
                'customer_id' => (int)($primary['customer_id'] ?? 0),
                'customer_name' => (string)($primary['customer_name'] ?? ''),
                'earliest_delivery_time' => (string)($primary['earliest_delivery_time'] ?? ''),
                'vehicle_location' => (string)($primary['vehicle_location'] ?? ''),
                'binding_is_enabled' => (int)($primary['binding_is_enabled'] ?? 0),
            ];
        }, $rows);

        return [
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'pagesize' => $pageSize,
        ];
    }

    /** @return array{vehicle:array<string,mixed>,customers:array<int,array<string,mixed>>}|false */
    public static function vehicleCustomers(array $params): array|false
    {
        self::clearError();
        $tenantId = self::tenantId();
        $vehicleId = (int)($params['id'] ?? $params['vehicle_id'] ?? 0);
        $vehicle = self::findVehicle($vehicleId, $tenantId);
        if (!$vehicle) {
            self::setError('车辆不存在');
            return false;
        }

        $rows = Db::name('customer_delivery_vehicle')
            ->alias('binding')
            ->join('customer customer', 'customer.id=binding.customer_id AND customer.tenant_id=binding.tenant_id')
            ->where('binding.tenant_id', $tenantId)
            ->where('binding.vehicle_id', $vehicleId)
            ->whereNull('binding.delete_time')
            ->field([
                'binding.id' => 'binding_id',
                'binding.vehicle_id',
                'binding.customer_id',
                'binding.earliest_delivery_time',
                'binding.vehicle_location',
                'binding.sort',
                'binding.is_enabled' => 'binding_is_enabled',
                'binding.version' => 'binding_version',
                'customer.customer_name',
                'customer.contact',
                'customer.phone' => 'customer_phone',
                'customer.parent_id',
                'customer.is_disabled' => 'customer_is_disabled',
            ])
            ->order([
                'binding.is_enabled' => 'desc',
                'binding.earliest_delivery_time' => 'asc',
                'customer.customer_name' => 'asc',
                'binding.id' => 'asc',
            ])
            ->select()
            ->toArray();

        return [
            'vehicle' => [
                ...self::formatVehicle($vehicle),
                'bound_customer_count' => count($rows),
            ],
            'customers' => array_map(static function (array $row): array {
                return [
                    'binding_id' => (int)$row['binding_id'],
                    'vehicle_id' => (int)$row['vehicle_id'],
                    'customer_id' => (int)$row['customer_id'],
                    'customer_name' => (string)$row['customer_name'],
                    'customer_no' => '#' . (int)$row['customer_id'],
                    'customer_type_label' => (int)$row['parent_id'] > 0 ? '子客户' : '独立客户',
                    'contact' => (string)($row['contact'] ?: '未填写联系人'),
                    'customer_phone' => (string)($row['customer_phone'] ?? ''),
                    'earliest_delivery_time' => (string)$row['earliest_delivery_time'],
                    'vehicle_location' => (string)$row['vehicle_location'],
                    'is_enabled' => (int)$row['binding_is_enabled'],
                    'customer_is_enabled' => (int)$row['customer_is_disabled'] === 0 ? 1 : 0,
                    'version' => (int)$row['binding_version'],
                ];
            }, $rows),
        ];
    }

    public static function save(array $params): array|false
    {
        self::clearError();
        $tenantId = self::tenantId();
        if ($tenantId <= 0) {
            self::setError('租户无效');
            return false;
        }
        $data = self::validateInput($params);
        if ($data === false) {
            return false;
        }

        $id = (int)($params['id'] ?? 0);
        try {
            return Db::transaction(static function () use ($data, $id, $tenantId) {
                $customer = Db::name('customer')
                    ->where('tenant_id', $tenantId)
                    ->where('id', $data['customer_id'])
                    ->lock(true)
                    ->find();
                if (!$customer) {
                    self::setError('客户不存在');
                    return false;
                }

                $before = null;
                if ($id > 0) {
                    $before = self::findActiveBinding($id, $tenantId, (int)$data['customer_id']);
                    if (!$before) {
                        self::setError('客户车辆绑定不存在');
                        return false;
                    }
                    if ((int)$data['version'] <= 0 || (int)$data['version'] !== (int)$before['version']) {
                        self::setError('客户车辆绑定已被修改，请重新加载');
                        return false;
                    }
                    if ((int)$data['vehicle_id'] <= 0 && (int)$before['vehicle_id'] > 0) {
                        $data['vehicle_id'] = (int)$before['vehicle_id'];
                    }
                }

                $vehicle = self::resolveVehicle($data, $tenantId, $id > 0);
                if ($vehicle === false) {
                    return false;
                }

                $duplicate = Db::name('customer_delivery_vehicle')
                    ->where('tenant_id', $tenantId)
                    ->where('customer_id', $data['customer_id'])
                    ->where('vehicle_id', $vehicle['id'])
                    ->whereNull('delete_time');
                if ($id > 0) {
                    $duplicate->where('id', '<>', $id);
                }
                if ($duplicate->find()) {
                    self::setError('该客户已绑定这辆车');
                    return false;
                }

                $now = time();
                $operatorId = self::operatorId();
                $writeData = [
                    'vehicle_id' => (int)$vehicle['id'],
                    'customer_id' => (int)$data['customer_id'],
                    'earliest_delivery_time' => (string)$data['earliest_delivery_time'],
                    'vehicle_location' => (string)$data['vehicle_location'],
                    // 兼容旧查询与历史工具；读取时以 delivery_vehicle 为准。
                    'plate_number' => (string)$vehicle['plate_number'],
                    'driver_phone' => (string)$vehicle['driver_phone'],
                    'sort' => (int)$data['sort'],
                    'is_enabled' => (int)$data['is_enabled'],
                    'operator_id' => $operatorId,
                    'update_time' => $now,
                ];

                if ($id <= 0) {
                    $id = (int)Db::name('customer_delivery_vehicle')->insertGetId([
                        ...$writeData,
                        'tenant_id' => $tenantId,
                        'version' => 1,
                        'create_time' => $now,
                    ]);
                    if ($id <= 0) {
                        throw new \RuntimeException('customer_delivery_vehicle_insert_failed');
                    }
                } else {
                    $updated = Db::name('customer_delivery_vehicle')
                        ->where('tenant_id', $tenantId)
                        ->where('customer_id', $data['customer_id'])
                        ->where('id', $id)
                        ->where('version', $data['version'])
                        ->whereNull('delete_time')
                        ->update([
                            ...$writeData,
                            'version' => (int)$data['version'] + 1,
                        ]);
                    if ($updated !== 1) {
                        self::setError('客户车辆绑定已被修改，请重新加载');
                        return false;
                    }
                }

                $after = self::findBindingWithVehicle($id, $tenantId, (int)$data['customer_id']);
                AuditService::logWithinTransaction(
                    'customer_vehicle_binding',
                    $before ? 'update' : 'create',
                    $id,
                    'customer-vehicle-binding:' . $id . ':v' . (int)($after['version'] ?? 1),
                    $before,
                    $after,
                    $before ? '更新客户车辆绑定' : '新增客户车辆绑定'
                );
                return self::formatBindingItem($after ?: []);
            });
        } catch (\Throwable $e) {
            Log::error('客户车辆绑定保存失败: ' . $e->getMessage());
            self::setError('操作失败，请稍后重试');
            return false;
        }
    }

    public static function delete(array $params): array|false
    {
        self::clearError();
        $tenantId = self::tenantId();
        $id = (int)($params['id'] ?? 0);
        $version = (int)($params['version'] ?? 0);
        if ($tenantId <= 0 || $id <= 0) {
            self::setError('客户车辆绑定不存在');
            return false;
        }

        try {
            return Db::transaction(static function () use ($id, $tenantId, $version) {
                $before = self::findActiveBinding($id, $tenantId);
                if (!$before) {
                    self::setError('客户车辆绑定不存在');
                    return false;
                }
                if ($version <= 0 || $version !== (int)$before['version']) {
                    self::setError('客户车辆绑定已被修改，请重新加载');
                    return false;
                }

                $now = time();
                $updated = Db::name('customer_delivery_vehicle')
                    ->where('tenant_id', $tenantId)
                    ->where('id', $id)
                    ->where('version', $version)
                    ->whereNull('delete_time')
                    ->update([
                        'version' => $version + 1,
                        'operator_id' => self::operatorId(),
                        'update_time' => $now,
                        'delete_time' => $now,
                    ]);
                if ($updated !== 1) {
                    self::setError('客户车辆绑定已被修改，请重新加载');
                    return false;
                }
                AuditService::logWithinTransaction(
                    'customer_vehicle_binding',
                    'delete',
                    $id,
                    'customer-vehicle-binding:' . $id . ':delete:v' . ($version + 1),
                    $before,
                    ['id' => $id, 'version' => $version + 1, 'delete_time' => $now],
                    '解除客户车辆绑定'
                );
                return ['id' => $id, 'version' => $version + 1];
            });
        } catch (\Throwable $e) {
            Log::error('客户车辆绑定删除失败: ' . $e->getMessage());
            self::setError('操作失败，请稍后重试');
            return false;
        }
    }

    /** @return array<string,mixed>|false */
    private static function resolveVehicle(array $data, int $tenantId, bool $allowUpdate): array|false
    {
        $vehicle = null;
        if ((int)$data['vehicle_id'] > 0) {
            $vehicle = self::findVehicle((int)$data['vehicle_id'], $tenantId, true);
            if (!$vehicle) {
                self::setError('车辆不存在');
                return false;
            }
        }
        if (!$vehicle) {
            $vehicle = Db::name('delivery_vehicle')
                ->where('tenant_id', $tenantId)
                ->where('plate_number', $data['plate_number'])
                ->whereNull('delete_time')
                ->lock(true)
                ->find();
        }

        if (!$vehicle) {
            $now = time();
            $vehicleId = (int)Db::name('delivery_vehicle')->insertGetId([
                'tenant_id' => $tenantId,
                'plate_number' => (string)$data['plate_number'],
                'driver_phone' => (string)$data['driver_phone'],
                'is_enabled' => (int)$data['vehicle_is_enabled'],
                'operator_id' => self::operatorId(),
                'version' => 1,
                'create_time' => $now,
                'update_time' => $now,
            ]);
            if ($vehicleId <= 0) {
                throw new \RuntimeException('delivery_vehicle_insert_failed');
            }
            return self::findVehicle($vehicleId, $tenantId) ?: false;
        }

        if (!$allowUpdate && (int)$data['vehicle_id'] <= 0) {
            return $vehicle;
        }

        $changed = (string)$vehicle['plate_number'] !== (string)$data['plate_number']
            || (string)$vehicle['driver_phone'] !== (string)$data['driver_phone']
            || (int)$vehicle['is_enabled'] !== (int)$data['vehicle_is_enabled'];
        if (!$changed) {
            return $vehicle;
        }
        if ((int)$data['vehicle_version'] <= 0 || (int)$data['vehicle_version'] !== (int)$vehicle['version']) {
            self::setError('车辆档案已被修改，请重新加载');
            return false;
        }

        $duplicate = Db::name('delivery_vehicle')
            ->where('tenant_id', $tenantId)
            ->where('plate_number', $data['plate_number'])
            ->where('id', '<>', $vehicle['id'])
            ->whereNull('delete_time')
            ->find();
        if ($duplicate) {
            self::setError('该车牌已存在车辆档案');
            return false;
        }

        $updated = Db::name('delivery_vehicle')
            ->where('tenant_id', $tenantId)
            ->where('id', $vehicle['id'])
            ->where('version', $data['vehicle_version'])
            ->whereNull('delete_time')
            ->update([
                'plate_number' => (string)$data['plate_number'],
                'driver_phone' => (string)$data['driver_phone'],
                'is_enabled' => (int)$data['vehicle_is_enabled'],
                'operator_id' => self::operatorId(),
                'version' => (int)$data['vehicle_version'] + 1,
                'update_time' => time(),
            ]);
        if ($updated !== 1) {
            self::setError('车辆档案已被修改，请重新加载');
            return false;
        }
        return self::findVehicle((int)$vehicle['id'], $tenantId) ?: false;
    }

    private static function bindingQuery(int $tenantId)
    {
        return Db::name('customer_delivery_vehicle')
            ->alias('binding')
            ->leftJoin(
                'delivery_vehicle vehicle',
                'vehicle.id=binding.vehicle_id AND vehicle.tenant_id=binding.tenant_id AND vehicle.delete_time IS NULL'
            )
            ->where('binding.tenant_id', $tenantId)
            ->whereNull('binding.delete_time');
    }

    /** @return array<int|string,string> */
    private static function bindingFields(): array
    {
        return [
            'binding.id',
            'binding.vehicle_id',
            'binding.customer_id',
            'binding.earliest_delivery_time',
            'binding.plate_number',
            'binding.vehicle_location',
            'binding.driver_phone',
            'binding.sort',
            'binding.is_enabled',
            'binding.version',
            'binding.create_time',
            'binding.update_time',
            'vehicle.plate_number' => 'master_plate_number',
            'vehicle.driver_phone' => 'master_driver_phone',
            'vehicle.is_enabled' => 'vehicle_is_enabled',
            'vehicle.version' => 'vehicle_version',
        ];
    }

    /** @return array<string,mixed> */
    private static function formatBindingItem(array $item): array
    {
        return [
            'id' => (int)($item['id'] ?? 0),
            'vehicle_id' => (int)($item['vehicle_id'] ?? 0),
            'customer_id' => (int)($item['customer_id'] ?? 0),
            'earliest_delivery_time' => (string)($item['earliest_delivery_time'] ?? ''),
            'plate_number' => (string)(($item['master_plate_number'] ?? '') ?: ($item['plate_number'] ?? '')),
            'vehicle_location' => (string)($item['vehicle_location'] ?? ''),
            'driver_phone' => (string)(($item['master_driver_phone'] ?? '') ?: ($item['driver_phone'] ?? '')),
            'sort' => (int)($item['sort'] ?? 0),
            'is_enabled' => (int)($item['is_enabled'] ?? 0),
            'vehicle_is_enabled' => array_key_exists('vehicle_is_enabled', $item)
                ? (int)$item['vehicle_is_enabled']
                : (int)($item['is_enabled'] ?? 0),
            'version' => (int)($item['version'] ?? 0),
            'vehicle_version' => (int)($item['vehicle_version'] ?? 0),
            'create_time' => $item['create_time'] ?? '',
            'update_time' => $item['update_time'] ?? '',
        ];
    }

    /** @return array<string,mixed> */
    private static function formatVehicle(array $vehicle): array
    {
        return [
            'id' => (int)($vehicle['id'] ?? 0),
            'plate_number' => (string)($vehicle['plate_number'] ?? ''),
            'driver_phone' => (string)($vehicle['driver_phone'] ?? ''),
            'is_enabled' => (int)($vehicle['is_enabled'] ?? 0),
            'version' => (int)($vehicle['version'] ?? 0),
            'create_time' => $vehicle['create_time'] ?? '',
            'update_time' => $vehicle['update_time'] ?? '',
        ];
    }

    private static function firstBindingForVehicle(int $vehicleId, int $tenantId): ?array
    {
        $row = Db::name('customer_delivery_vehicle')
            ->alias('binding')
            ->join('customer customer', 'customer.id=binding.customer_id AND customer.tenant_id=binding.tenant_id')
            ->where('binding.tenant_id', $tenantId)
            ->where('binding.vehicle_id', $vehicleId)
            ->whereNull('binding.delete_time')
            ->field([
                'binding.customer_id',
                'customer.customer_name',
                'binding.earliest_delivery_time',
                'binding.vehicle_location',
                'binding.is_enabled' => 'binding_is_enabled',
            ])
            ->order([
                'binding.is_enabled' => 'desc',
                'binding.earliest_delivery_time' => 'asc',
                'binding.id' => 'asc',
            ])
            ->find();
        return $row ?: null;
    }

    private static function tenantId(): int
    {
        return (int)(request()->tenantId ?? 0);
    }

    private static function operatorId(): int
    {
        return (int)(request()->adminId ?? request()->userId ?? 0);
    }

    private static function findActiveBinding(int $id, int $tenantId, int $customerId = 0): ?array
    {
        $query = Db::name('customer_delivery_vehicle')
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->whereNull('delete_time');
        if ($customerId > 0) {
            $query->where('customer_id', $customerId);
        }
        $row = $query->lock(true)->find();
        return $row ?: null;
    }

    private static function findBindingWithVehicle(int $id, int $tenantId, int $customerId = 0): ?array
    {
        $query = self::bindingQuery($tenantId)->where('binding.id', $id);
        if ($customerId > 0) {
            $query->where('binding.customer_id', $customerId);
        }
        $row = $query->field(self::bindingFields())->find();
        return $row ?: null;
    }

    private static function findVehicle(int $id, int $tenantId, bool $lock = false): ?array
    {
        if ($id <= 0 || $tenantId <= 0) {
            return null;
        }
        $query = Db::name('delivery_vehicle')
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->whereNull('delete_time');
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        return $row ?: null;
    }
}
