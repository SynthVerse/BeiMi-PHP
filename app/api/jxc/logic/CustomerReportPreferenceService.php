<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 客户商品偏好仅用于回填建议，提交时必须再次确认。 */
class CustomerReportPreferenceService
{
    /** @return array<string, mixed> */
    public static function suggestion(int $customerId, int $goodsId): array
    {
        if (self::tenantId() <= 0 || $customerId <= 0 || $goodsId <= 0) {
            return [];
        }
        $row = Db::name('customer_goods_report_preference')
            ->where('tenant_id', self::tenantId())
            ->where('customer_id', $customerId)
            ->where('goods_id', $goodsId)
            ->find();
        if (!$row) {
            return [];
        }
        return [
            'customer_id' => (int)$row['customer_id'],
            'goods_id' => (int)$row['goods_id'],
            'unit_id' => (int)$row['unit_id'],
            'unit_name' => (string)$row['unit_name'],
            'piece_weight_min' => self::decimal((string)$row['piece_weight_min']),
            'piece_weight_max' => self::decimal((string)$row['piece_weight_max']),
            'confirmed_time' => (int)$row['confirmed_time'],
            'requires_confirmation' => true,
        ];
    }

    /** @param array<string, mixed> $line */
    public static function remember(array $line): void
    {
        $tenantId = self::tenantId();
        $customerId = (int)($line['delivery_customer_id'] ?? 0);
        $goodsId = (int)($line['goods_id'] ?? 0);
        $maximum = self::decimal((string)($line['piece_weight_max'] ?? 0));
        if ($tenantId <= 0 || $customerId <= 0 || $goodsId <= 0 || (int)($line['piece_weight_confirmed'] ?? 0) !== 1 || bccomp($maximum, '0.00', 2) <= 0) {
            return;
        }
        $now = time();
        $data = [
            'unit_id' => (int)($line['unit_id'] ?? 0),
            'unit_name' => (string)($line['unit_name'] ?? ''),
            'piece_weight_min' => self::decimal((string)($line['piece_weight_min'] ?? 0)),
            'piece_weight_max' => $maximum,
            'confirmed_by' => (int)(request()->adminId ?? 0),
            'confirmed_time' => $now,
            'update_time' => $now,
        ];
        Db::execute(
            'INSERT INTO `la_customer_goods_report_preference` '
            . '(`tenant_id`,`customer_id`,`goods_id`,`unit_id`,`unit_name`,`piece_weight_min`,`piece_weight_max`,`confirmed_by`,`confirmed_time`,`create_time`,`update_time`) '
            . 'VALUES (:tenant_id,:customer_id,:goods_id,:unit_id,:unit_name,:piece_weight_min,:piece_weight_max,:confirmed_by,:confirmed_time,:create_time,:update_time) '
            . 'ON DUPLICATE KEY UPDATE `unit_id`=VALUES(`unit_id`),`unit_name`=VALUES(`unit_name`),`piece_weight_min`=VALUES(`piece_weight_min`),`piece_weight_max`=VALUES(`piece_weight_max`),`confirmed_by`=VALUES(`confirmed_by`),`confirmed_time`=VALUES(`confirmed_time`),`update_time`=VALUES(`update_time`)',
            $data + ['tenant_id' => $tenantId, 'customer_id' => $customerId, 'goods_id' => $goodsId, 'create_time' => $now]
        );
    }

    private static function tenantId(): int { return (int)(request()->tenantId ?? 0); }
    private static function decimal(string $value): string { return bcadd($value, '0', 2); }
}
