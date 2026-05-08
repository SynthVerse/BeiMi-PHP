<?php

declare(strict_types=1);

namespace app\common\service\jxc;

use think\facade\Db;
use think\facade\Log;

/**
 * JXC 默认基础数据初始化服务
 *
 * 为指定租户（tenant_id）写入一套 JXC 默认基础数据：
 *  - 1 条默认仓库
 *  - 1 条默认客户
 *  - 1 条默认供应商
 *  - 若干条默认计量单位
 *
 * 幂等：基于 tenant_id + 业务唯一列 查询，存在则跳过，可重复执行。
 * 隔离：直接使用 Db facade 绕过 BaseModel 全局作用域与 onBeforeInsert 钩子，
 *       避免平台端调用时 request()->tenantId 为空导致 tenant_id 写入异常。
 */
class DefaultDataInitService
{
    /** @var string 默认仓库名称 */
    public const DEFAULT_WAREHOUSE_NAME = '默认仓库';

    /** @var string 默认客户名称 */
    public const DEFAULT_CUSTOMER_NAME = '默认客户';

    /** @var string 默认供应商名称 */
    public const DEFAULT_VENDOR_NAME = '默认供应商';

    /** @var string[] 默认计量单位 */
    public const DEFAULT_GOODS_UNITS = ['个', '件', '箱', '千克', '升'];

    /**
     * 为指定租户初始化默认数据
     * @param int $tenantId 租户 ID，必须 > 0
     * @return bool 成功返回 true；tenant_id 非法返回 false
     */
    public static function initForTenant(int $tenantId): bool
    {
        if ($tenantId <= 0) {
            Log::warning('[DefaultDataInit] invalid tenant_id, skip', ['tenant_id' => $tenantId]);
            return false;
        }

        $created = [];

        Db::transaction(function () use ($tenantId, &$created) {
            if (self::ensureWarehouse($tenantId)) {
                $created[] = 'warehouse';
            }
            if (self::ensureCustomer($tenantId)) {
                $created[] = 'customer';
            }
            if (self::ensureVendor($tenantId)) {
                $created[] = 'vendor';
            }
            $addedUnits = self::ensureGoodsUnits($tenantId);
            if ($addedUnits > 0) {
                $created[] = 'goods_unit(' . $addedUnits . ')';
            }
        });

        Log::info('[DefaultDataInit] done', [
            'tenant_id' => $tenantId,
            'created'   => $created,
        ]);

        return true;
    }

    /**
     * 判断租户是否已经完成默认数据初始化（四张表均已存在默认记录）
     * @param int $tenantId
     * @return bool
     */
    public static function hasInitialized(int $tenantId): bool
    {
        if ($tenantId <= 0) {
            return false;
        }

        $hasWarehouse = Db::name('warehouse')
            ->where('tenant_id', $tenantId)
            ->where('name', self::DEFAULT_WAREHOUSE_NAME)
            ->count() > 0;

        $hasCustomer = Db::name('customer')
            ->where('tenant_id', $tenantId)
            ->where('customer_name', self::DEFAULT_CUSTOMER_NAME)
            ->count() > 0;

        $hasVendor = Db::name('vendor')
            ->where('tenant_id', $tenantId)
            ->where('supplier_name', self::DEFAULT_VENDOR_NAME)
            ->count() > 0;

        return $hasWarehouse && $hasCustomer && $hasVendor;
    }

    /**
     * 写入默认仓库（若不存在）
     * @return bool 是否实际新增
     */
    private static function ensureWarehouse(int $tenantId): bool
    {
        $exists = Db::name('warehouse')
            ->where('tenant_id', $tenantId)
            ->where('name', self::DEFAULT_WAREHOUSE_NAME)
            ->find();
        if ($exists) {
            return false;
        }

        $time = time();
        Db::name('warehouse')->insert([
            'tenant_id'      => $tenantId,
            'name'           => self::DEFAULT_WAREHOUSE_NAME,
            'province'       => '',
            'city'           => '',
            'district'       => '',
            'address'        => '',
            'address_detail' => '',
            'contact'        => '',
            'phone'          => '',
            'is_enabled'     => 1,
            'sort'           => 0,
            'create_time'    => $time,
            'update_time'    => $time,
        ]);
        return true;
    }

    /**
     * 写入默认客户（若不存在）
     * @return bool 是否实际新增
     */
    private static function ensureCustomer(int $tenantId): bool
    {
        $exists = Db::name('customer')
            ->where('tenant_id', $tenantId)
            ->where('customer_name', self::DEFAULT_CUSTOMER_NAME)
            ->find();
        if ($exists) {
            return false;
        }

        $time = time();
        Db::name('customer')->insert([
            'tenant_id'          => $tenantId,
            'customer_name'      => self::DEFAULT_CUSTOMER_NAME,
            'contact'            => '',
            'phone'              => '',
            'address'            => '',
            'remark'             => '系统默认客户',
            'group_id'           => 0,
            'parent_id'          => 0,
            'is_store'           => 0,
            'children_count'     => 0,
            'is_disabled'        => 0,
            'order_receivable'   => 0,
            'order_money'        => 0,
            'order_pay_money'    => 0,
            'create_time'        => $time,
            'update_time'        => $time,
        ]);
        return true;
    }

    /**
     * 写入默认供应商（若不存在）
     * @return bool 是否实际新增
     */
    private static function ensureVendor(int $tenantId): bool
    {
        $exists = Db::name('vendor')
            ->where('tenant_id', $tenantId)
            ->where('supplier_name', self::DEFAULT_VENDOR_NAME)
            ->find();
        if ($exists) {
            return false;
        }

        $time = time();
        Db::name('vendor')->insert([
            'tenant_id'         => $tenantId,
            'supplier_name'     => self::DEFAULT_VENDOR_NAME,
            'contact'           => '',
            'phone'             => '',
            'address'           => '',
            'remark'            => '系统默认供应商',
            'is_disabled'       => 0,
            'order_money'       => 0,
            'order_payable'     => 0,
            'order_paid_money'  => 0,
            'create_time'       => $time,
            'update_time'       => $time,
        ]);
        return true;
    }

    /**
     * 写入默认计量单位（若不存在）
     * @return int 新增条数
     */
    private static function ensureGoodsUnits(int $tenantId): int
    {
        $existingNames = Db::name('goods_unit')
            ->where('tenant_id', $tenantId)
            ->whereIn('name', self::DEFAULT_GOODS_UNITS)
            ->column('name');

        $missing = array_values(array_diff(self::DEFAULT_GOODS_UNITS, $existingNames));
        if (empty($missing)) {
            return 0;
        }

        $time = time();
        $rows = [];
        foreach ($missing as $index => $unitName) {
            $rows[] = [
                'tenant_id'   => $tenantId,
                'name'        => $unitName,
                'status'      => 1,
                'sort'        => $index,
                'create_time' => $time,
                'update_time' => $time,
            ];
        }
        Db::name('goods_unit')->insertAll($rows);
        return count($rows);
    }
}
