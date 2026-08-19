<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use think\facade\Db;

/** 租户内第三方即时配送司机登记；交付事件只引用已启用身份并冻结快照。 */
final class ThirdPartyDriverLogic extends BaseLogic
{
    /** @return array<string,mixed>|false */
    public static function save(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('delivery.confirm')) {
            self::setError(WorkforceLogic::getError());
            return false;
        }
        $id = (int)($params['id'] ?? 0);
        $version = (int)($params['version'] ?? 0);
        $name = mb_substr(trim((string)($params['name'] ?? '')), 0, 120);
        $mobile = mb_substr(trim((string)($params['mobile'] ?? '')), 0, 32);
        $platform = mb_substr(trim((string)($params['platform'] ?? '')), 0, 80);
        $vehicleNo = mb_substr(trim((string)($params['vehicle_no'] ?? '')), 0, 32);
        $enabled = (int)($params['is_enabled'] ?? 1) === 1 ? 1 : 0;
        if ($name === '' || $mobile === '' || $platform === '') {
            self::setError('第三方司机姓名、电话和配送平台不能为空');
            return false;
        }
        try {
            return Db::transaction(static function () use ($id, $version, $name, $mobile, $platform, $vehicleNo, $enabled) {
                $now = FulfillmentClock::now();
                if ($id > 0) {
                    $before = Db::name('third_party_driver')->where('tenant_id', self::tenantId())
                        ->where('id', $id)->lock(true)->find();
                    if (!$before) {
                        self::setError('第三方司机不存在');
                        return false;
                    }
                    if ($version <= 0 || (int)$before['version'] !== $version) {
                        self::setError('第三方司机资料已变化，请刷新后重试');
                        return false;
                    }
                    $nextVersion = $version + 1;
                    Db::name('third_party_driver')->where('tenant_id', self::tenantId())->where('id', $id)->update([
                        'name' => $name, 'mobile' => $mobile, 'platform' => $platform,
                        'vehicle_no' => $vehicleNo, 'is_enabled' => $enabled,
                        'version' => $nextVersion, 'operator_id' => self::operatorId(), 'update_time' => $now,
                    ]);
                    $after = self::byId($id);
                    AuditService::logWithinTransaction(
                        'third_party_driver', 'update', $id, 'driver:' . $id . ':v' . $nextVersion,
                        $before, $after, '更新第三方司机登记'
                    );
                    return $after;
                }
                $driverId = (int)Db::name('third_party_driver')->insertGetId([
                    'tenant_id' => self::tenantId(), 'name' => $name, 'mobile' => $mobile,
                    'platform' => $platform, 'vehicle_no' => $vehicleNo, 'is_enabled' => $enabled,
                    'version' => 1, 'operator_id' => self::operatorId(),
                    'create_time' => $now, 'update_time' => $now,
                ]);
                if ($driverId <= 0) {
                    throw new \RuntimeException('third_party_driver_insert_failed');
                }
                $after = self::byId($driverId);
                AuditService::logWithinTransaction(
                    'third_party_driver', 'create', $driverId, 'driver:' . $driverId . ':v1',
                    null, $after, '登记第三方司机'
                );
                return $after;
            });
        } catch (\Throwable) {
            if (!self::hasError()) {
                self::setError('第三方司机保存失败');
            }
            return false;
        }
    }

    /** @return array{lists:array<int,array<string,mixed>>}|false */
    public static function lists(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('delivery.confirm')) {
            self::setError(WorkforceLogic::getError());
            return false;
        }
        $query = Db::name('third_party_driver')->where('tenant_id', self::tenantId());
        if (array_key_exists('is_enabled', $params)) {
            $query->where('is_enabled', (int)$params['is_enabled'] === 1 ? 1 : 0);
        }
        $rows = $query->order(['is_enabled' => 'desc', 'name' => 'asc', 'id' => 'asc'])->select()->toArray();
        return ['lists' => array_map([self::class, 'normalize'], $rows)];
    }

    /** @return array<string,mixed>|null */
    private static function byId(int $id): ?array
    {
        $row = Db::name('third_party_driver')->where('tenant_id', self::tenantId())->where('id', $id)->find();
        return $row ? self::normalize($row) : null;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function normalize(array $row): array
    {
        foreach (['id', 'tenant_id', 'is_enabled', 'version', 'operator_id', 'create_time', 'update_time'] as $field) {
            $row[$field] = (int)($row[$field] ?? 0);
        }
        return $row;
    }

    private static function tenantId(): int
    {
        return (int)(request()->tenantId ?? 0);
    }

    private static function operatorId(): int
    {
        return (int)(request()->userId ?? request()->adminId ?? 0);
    }
}
