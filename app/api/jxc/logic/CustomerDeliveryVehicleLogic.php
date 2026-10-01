<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use app\common\model\jxc\CustomerDeliveryVehicle;
use think\facade\Db;
use think\facade\Log;

final class CustomerDeliveryVehicleLogic extends BaseLogic
{
    /**
     * @return array{customer_id:int,version:int,earliest_delivery_time:string,plate_number:string,vehicle_location:string,driver_phone:string,sort:int,is_enabled:int}
     */
    public static function normalizeInput(array $params): array
    {
        return [
            'customer_id' => (int)($params['customer_id'] ?? 0),
            'version' => (int)($params['version'] ?? 0),
            'earliest_delivery_time' => trim((string)($params['earliest_delivery_time'] ?? '')),
            'plate_number' => strtoupper(trim((string)($params['plate_number'] ?? ''))),
            'vehicle_location' => trim((string)($params['vehicle_location'] ?? '')),
            'driver_phone' => trim((string)($params['driver_phone'] ?? '')),
            'sort' => (int)($params['sort'] ?? 0),
            'is_enabled' => (int)($params['is_enabled'] ?? 1) === 0 ? 0 : 1,
        ];
    }

    /**
     * @return array{customer_id:int,version:int,earliest_delivery_time:string,plate_number:string,vehicle_location:string,driver_phone:string,sort:int,is_enabled:int}|false
     */
    public static function validateInput(array $params): array|false
    {
        $data = self::normalizeInput($params);
        if ($data['customer_id'] <= 0) {
            self::setError('客户不存在');
            return false;
        }
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $data['earliest_delivery_time'])) {
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
        if (mb_strlen($data['plate_number']) > 32) {
            self::setError('车辆车牌号最多 32 字');
            return false;
        }
        if (mb_strlen($data['vehicle_location']) > 255) {
            self::setError('车辆地点最多 255 字');
            return false;
        }
        if ($data['driver_phone'] !== '' && !preg_match('/^[0-9+\-\s]{6,20}$/', $data['driver_phone'])) {
            self::setError('请输入正确的司机电话');
            return false;
        }

        return $data;
    }

    public static function lists(array $params): array
    {
        $tenantId = self::tenantId();
        $customerId = (int)($params['customer_id'] ?? 0);
        if ($tenantId <= 0 || $customerId <= 0) {
            return [];
        }

        $query = CustomerDeliveryVehicle::where('tenant_id', $tenantId)
            ->where('customer_id', $customerId);
        if ((int)($params['enabled_only'] ?? 0) === 1) {
            $query->where('is_enabled', 1);
        }

        return array_map(
            [self::class, 'formatItem'],
            $query->order(['sort' => 'asc', 'earliest_delivery_time' => 'asc', 'id' => 'asc'])
                ->select()
                ->toArray()
        );
    }

    /**
     * @return array{data:array<int,array<string,mixed>>,total:int,page:int,pagesize:int}
     */
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
        $query = Db::name('customer_delivery_vehicle')
            ->alias('vehicle')
            ->join('customer customer', 'customer.id=vehicle.customer_id AND customer.tenant_id=vehicle.tenant_id')
            ->where('vehicle.tenant_id', $tenantId)
            ->whereNull('vehicle.delete_time');

        if ($keyword !== '') {
            $escapedKeyword = addcslashes($keyword, '%_\\');
            $query->whereLike(
                'vehicle.plate_number|vehicle.vehicle_location|vehicle.driver_phone|customer.customer_name',
                '%' . $escapedKeyword . '%'
            );
        }
        if ($status === 'enabled') {
            $query->where('vehicle.is_enabled', 1);
        } elseif ($status === 'disabled') {
            $query->where('vehicle.is_enabled', 0);
        }

        $total = (int)(clone $query)->count('vehicle.id');
        $rows = $query->field([
                'vehicle.id',
                'vehicle.customer_id',
                'customer.customer_name',
                'vehicle.earliest_delivery_time',
                'vehicle.plate_number',
                'vehicle.vehicle_location',
                'vehicle.driver_phone',
                'vehicle.sort',
                'vehicle.is_enabled',
                'vehicle.version',
                'vehicle.create_time',
                'vehicle.update_time',
            ])
            ->order([
                'vehicle.is_enabled' => 'desc',
                'vehicle.earliest_delivery_time' => 'asc',
                'customer.customer_name' => 'asc',
                'vehicle.id' => 'asc',
            ])
            ->limit(($page - 1) * $pageSize, $pageSize)
            ->select()
            ->toArray();

        return [
            'data' => array_map(static function (array $row): array {
                return [
                    ...self::formatItem($row),
                    'customer_name' => (string)($row['customer_name'] ?? ''),
                ];
            }, $rows),
            'total' => $total,
            'page' => $page,
            'pagesize' => $pageSize,
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
                    $before = self::findActive($id, $tenantId, $data['customer_id']);
                    if (!$before) {
                        self::setError('客户候选车辆不存在');
                        return false;
                    }
                    if ($data['version'] <= 0 || $data['version'] !== (int)$before['version']) {
                        self::setError('客户候选车辆已被修改，请重新加载');
                        return false;
                    }
                }

                $duplicate = Db::name('customer_delivery_vehicle')
                    ->where('tenant_id', $tenantId)
                    ->where('customer_id', $data['customer_id'])
                    ->where('plate_number', $data['plate_number'])
                    ->whereNull('delete_time');
                if ($id > 0) {
                    $duplicate->where('id', '<>', $id);
                }
                if ($duplicate->find()) {
                    self::setError('该客户已存在相同车牌的候选车辆');
                    return false;
                }

                $now = time();
                $operatorId = self::operatorId();
                $writeData = [
                    'customer_id' => $data['customer_id'],
                    'earliest_delivery_time' => $data['earliest_delivery_time'],
                    'plate_number' => $data['plate_number'],
                    'vehicle_location' => $data['vehicle_location'],
                    'driver_phone' => $data['driver_phone'],
                    'sort' => $data['sort'],
                    'is_enabled' => $data['is_enabled'],
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
                            'version' => $data['version'] + 1,
                        ]);
                    if ($updated !== 1) {
                        self::setError('客户候选车辆已被修改，请重新加载');
                        return false;
                    }
                }

                $after = self::findActive($id, $tenantId, $data['customer_id']);
                AuditService::logWithinTransaction(
                    'customer_delivery_vehicle',
                    $before ? 'update' : 'create',
                    $id,
                    'customer-delivery-vehicle:' . $id . ':v' . (int)($after['version'] ?? 1),
                    $before,
                    $after,
                    $before ? '更新客户候选车辆' : '新增客户候选车辆'
                );
                return self::formatItem($after ?: []);
            });
        } catch (\Throwable $e) {
            Log::error('客户候选车辆保存失败: ' . $e->getMessage());
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
            self::setError('客户候选车辆不存在');
            return false;
        }

        try {
            return Db::transaction(static function () use ($id, $tenantId, $version) {
                $before = self::findActive($id, $tenantId);
                if (!$before) {
                    self::setError('客户候选车辆不存在');
                    return false;
                }
                if ($version <= 0 || $version !== (int)$before['version']) {
                    self::setError('客户候选车辆已被修改，请重新加载');
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
                    self::setError('客户候选车辆已被修改，请重新加载');
                    return false;
                }
                AuditService::logWithinTransaction(
                    'customer_delivery_vehicle',
                    'delete',
                    $id,
                    'customer-delivery-vehicle:' . $id . ':delete:v' . ($version + 1),
                    $before,
                    ['id' => $id, 'version' => $version + 1, 'delete_time' => $now],
                    '删除客户候选车辆'
                );
                return ['id' => $id, 'version' => $version + 1];
            });
        } catch (\Throwable $e) {
            Log::error('客户候选车辆删除失败: ' . $e->getMessage());
            self::setError('操作失败，请稍后重试');
            return false;
        }
    }

    public static function formatItem(array $item): array
    {
        return [
            'id' => (int)($item['id'] ?? 0),
            'customer_id' => (int)($item['customer_id'] ?? 0),
            'earliest_delivery_time' => (string)($item['earliest_delivery_time'] ?? ''),
            'plate_number' => (string)($item['plate_number'] ?? ''),
            'vehicle_location' => (string)($item['vehicle_location'] ?? ''),
            'driver_phone' => (string)($item['driver_phone'] ?? ''),
            'sort' => (int)($item['sort'] ?? 0),
            'is_enabled' => (int)($item['is_enabled'] ?? 0),
            'version' => (int)($item['version'] ?? 0),
            'create_time' => $item['create_time'] ?? '',
            'update_time' => $item['update_time'] ?? '',
        ];
    }

    private static function tenantId(): int
    {
        return (int)(request()->tenantId ?? 0);
    }

    private static function operatorId(): int
    {
        return (int)(request()->adminId ?? request()->userId ?? 0);
    }

    private static function findActive(int $id, int $tenantId, int $customerId = 0): ?array
    {
        $query = Db::name('customer_delivery_vehicle')
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->whereNull('delete_time');
        if ($customerId > 0) {
            $query->where('customer_id', $customerId);
        }
        $row = $query->find();
        return $row ?: null;
    }
}
