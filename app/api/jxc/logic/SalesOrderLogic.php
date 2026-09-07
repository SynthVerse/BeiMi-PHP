<?php

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use app\common\model\jxc\Customer;
use app\common\model\jxc\Goods;
use app\common\model\jxc\OrderGoods;
use app\common\model\jxc\ReceivableFlow;
use app\common\model\jxc\SalesOrder;
use app\common\model\jxc\SalesReturnOrder;
use app\common\model\jxc\Warehouse;
use app\common\service\goods\GoodsSkuSelectionService;
use think\facade\Db;
use think\facade\Log;
use app\api\jxc\logic\StockService;
use app\api\jxc\logic\FinanceService;
use app\api\jxc\logic\AuditService;
use app\api\jxc\exception\BusinessException;

class SalesOrderLogic extends BaseLogic
{
    private const ORDER_TYPE = 'sales';
    private const DEFAULT_PURPOSE = '销售出库';
    private const DEFAULT_PURPOSE_TYPE = 'sales';
    public const DOCUMENT_KIND_DIRECT_RECEIPT = 'direct_receipt';
    public const DOCUMENT_KIND_CUSTOMER_SETTLEMENT = 'customer_settlement';

    /**
     * Return a read-only presentation identity without changing the underlying
     * sales-order business identity. Only customer-report orders can be opened
     * by the settlement detail because that endpoint owns version/debt facts.
     *
     * @param array<string,mixed> $item
     */
    public static function salesDocumentKind(array $item): string
    {
        return self::isCustomerReportSource($item)
            ? self::DOCUMENT_KIND_CUSTOMER_SETTLEMENT
            : self::DOCUMENT_KIND_DIRECT_RECEIPT;
    }

    public static function publish(array $params): array|false
    {
        return self::publishInternal($params, true);
    }

    /**
     * Used by canonical reservation conversion. The caller owns the outer transaction.
     */
    public static function publishWithinTransaction(array $params): array|false
    {
        return self::publishInternal($params, false);
    }

    /**
     * Publish a canonical sales order from stock already reserved by an
     * upstream workflow. The caller owns the transaction.
     */
    public static function publishReservedWithinTransaction(array $params): array|false
    {
        return self::publishInternal($params, false, true);
    }

    /**
     * 为交付出库建立现有 sales_order 体系内的待结算身份；这里不扣库存、不记应收。
     * 后续销售结算只正式化该身份，不能新建平行订单或再次出库。
     *
     * @param array<string,mixed> $report
     * @return array{id:int,order_sn:string,settlement_status:string}|false
     */
    public static function ensurePendingDeliveryOrderWithinTransaction(array $report, int $warehouseId): array|false
    {
        $tenantId = (int)(request()->tenantId ?? 0);
        $reportId = (int)($report['id'] ?? 0);
        if ($tenantId <= 0 || $reportId <= 0 || $warehouseId <= 0) {
            self::setError('交付销售单身份无效');
            return false;
        }
        $existing = Db::name('sales_order')->where('tenant_id', $tenantId)
            ->where('source_type', 'customer_report')->where('source_id', $reportId)
            ->where('warehouse_id', $warehouseId)->lock(true)->find();
        if ($existing) {
            if ((string)($existing['settlement_status'] ?? 'formal') !== 'pending') {
                self::setError('该报货单已存在正式销售单，不能重复交付出库');
                return false;
            }
            return [
                'id' => (int)$existing['id'],
                'order_sn' => (string)$existing['order_sn'],
                'settlement_status' => (string)$existing['settlement_status'],
            ];
        }
        $now = time();
        $orderSn = self::generateOrderSn();
        $orderId = (int)Db::name('sales_order')->insertGetId([
            'tenant_id' => $tenantId,
            'order_sn' => $orderSn,
            'customer_id' => (int)$report['main_customer_id'],
            'customer_name' => (string)$report['main_customer_name'],
            'warehouse_id' => $warehouseId,
            'order_money' => '0.00',
            'order_pay_money' => '0.00',
            'order_arrears_money' => '0.00',
            'datetimesingle' => $now,
            'source_type' => 'customer_report',
            'source_id' => $reportId,
            'source_version' => (int)($report['version'] ?? 1),
            'settlement_status' => 'pending',
            'cost_status' => 'confirmed',
            'profit_status' => 'pending_settlement',
            'status' => SalesOrder::STATUS_SOLD,
            'purpose_type' => 'customer_report',
            'remarks' => '待销售结算；来源报货单：' . (string)($report['sn'] ?? ''),
            'admin_id' => (int)(request()->adminId ?? request()->userId ?? 0),
            'idempotent_key' => 'delivery-pending:' . $reportId . ':warehouse:' . $warehouseId,
            'create_time' => $now,
            'update_time' => $now,
        ]);
        if ($orderId <= 0) {
            self::setError('待结算销售单身份创建失败');
            return false;
        }
        AuditService::logWithinTransaction(
            AuditService::MODULE_SALES_ORDER,
            'delivery_pending_create',
            $orderId,
            $orderSn,
            null,
            ['settlement_status' => 'pending', 'source_type' => 'customer_report', 'source_id' => $reportId],
            '实际交付先建立销售单归因身份，不产生应收'
        );
        return ['id' => $orderId, 'order_sn' => $orderSn, 'settlement_status' => 'pending'];
    }

    /** @param array<string,mixed> $item */
    public static function recordDeliveredItemWithinTransaction(int $orderId, array $item, string $actualQuantity): bool
    {
        $tenantId = (int)(request()->tenantId ?? 0);
        $existing = Db::name('order_goods')->where('tenant_id', $tenantId)->where('order_id', $orderId)
            ->where('order_type', self::ORDER_TYPE)->where('source_line_type', 'customer_report_item')
            ->where('source_line_id', (int)$item['id'])->lock(true)->find();
        if ($existing) {
            $next = bcadd((string)$existing['base_quantity'], $actualQuantity, 4);
            return Db::name('order_goods')->where('tenant_id', $tenantId)->where('id', (int)$existing['id'])->update([
                'number' => $next,
                'base_quantity' => $next,
                'update_time' => time(),
            ]) !== false;
        }
        $now = time();
        return Db::name('order_goods')->insert([
            'tenant_id' => $tenantId,
            'order_id' => $orderId,
            'order_type' => self::ORDER_TYPE,
            'goods_id' => (int)$item['goods_id'],
            'sku_id' => (int)$item['sku_id'],
            'sku_name' => (string)($item['sku_name'] ?? ''),
            'name' => (string)$item['goods_name'],
            'units' => (string)$item['base_unit_name'],
            'number' => $actualQuantity,
            'base_quantity' => $actualQuantity,
            'price' => '0.00',
            'amount' => '0.00',
            'pricing_unit_id' => (int)$item['base_unit_id'],
            'source_line_type' => 'customer_report_item',
            'source_line_id' => (int)$item['id'],
            'remark' => (string)($item['line_remark'] ?? ''),
            'sort' => (int)($item['sort'] ?? 0),
            'create_time' => $now,
            'update_time' => $now,
        ]) === 1;
    }

    public static function markCostPendingWithinTransaction(int $orderId): void
    {
        Db::name('sales_order')->where('tenant_id', (int)(request()->tenantId ?? 0))->where('id', $orderId)->update([
            'cost_status' => 'pending',
            'profit_status' => 'cost_pending',
            'update_time' => time(),
        ]);
    }

    private static function publishInternal(
        array $params,
        bool $ownsTransaction,
        bool $consumeReserved = false
    ): array|false
    {
        $tenantId = (int)(request()->tenantId ?? 0);
        if ($tenantId <= 0) { self::setError('租户无效'); return false; }
        $idempotentKey = trim((string)($params['idempotent_key'] ?? ''));
        $built = self::buildOrderData($params);
        if ($built === false) {
            return false;
        }

        if ($ownsTransaction) {
            Db::startTrans();
        }
        try {
            FinanceIntegration::lock();
            $lockedTenantId = (int)Db::name('tenant')
                ->where('id', $tenantId)
                ->where('disable', 0)
                ->lock(true)
                ->value('id');
            if ($lockedTenantId !== $tenantId) {
                throw new BusinessException('租户无效');
            }
            if ($idempotentKey !== '') {
                $existing = SalesOrder::where('tenant_id', $tenantId)
                    ->where('idempotent_key', $idempotentKey)
                    ->find();
                if ($existing) {
                    if ($ownsTransaction) {
                        Db::commit();
                    }
                    return [
                        'id' => (int)$existing->id,
                        'order_sn' => (string)$existing->order_sn,
                    ];
                }
            }
            $now = time();
            $built['order']['create_time'] = $now;
            $built['order']['update_time'] = $now;
            $orderId = (int)Db::name('sales_order')->insertGetId($built['order']);
            if ($orderId <= 0) {
                throw new BusinessException('销售单创建失败');
            }
            self::replaceGoods($orderId, $built['goods']);

            // === 库存出库 ===
            usort($built['goods'], static fn(array $left, array $right): int =>
                [(int)$left['sku_id'], (int)$left['goods_id']] <=> [(int)$right['sku_id'], (int)$right['goods_id']]
            );
            foreach ($built['goods'] as $row) {
                $quantity = (string)($row['base_quantity'] ?? $row['number']);
                $issued = $consumeReserved
                    ? StockService::outboundReservedWithinTransaction(
                        (int)$built['order']['warehouse_id'],
                        (int)$row['goods_id'],
                        $quantity,
                        $orderId,
                        'sales',
                        $built['order']['order_sn'],
                        '',
                        (int)($row['sku_id'] ?? 0),
                        (int)($row['batch_id'] ?? 0)
                    )
                    : StockService::outbound(
                        (int)$built['order']['warehouse_id'],
                        (int)$row['goods_id'],
                        $quantity,
                        $orderId,
                        'sales',
                        $built['order']['order_sn'],
                        '',
                        (int)($row['sku_id'] ?? 0),
                        (int)($row['batch_id'] ?? 0)
                    );
                if (!$issued) { throw new BusinessException('库存处理失败'); }
            }

            // === 应收增加 ===
            $arrearsMoney = (string)$built['order']['order_arrears_money'];
            if (bccomp($arrearsMoney, '0', 2) > 0) {
                if (!FinanceService::addReceivable(
                    (int)$built['order']['customer_id'],
                    $arrearsMoney,
                    $orderId,
                    'sales',
                    $built['order']['order_sn']
                )) { throw new BusinessException('应收处理失败'); }
            }

            if (!$ownsTransaction) {
                AuditService::logWithinTransaction(
                    AuditService::MODULE_SALES_ORDER,
                    AuditService::ACTION_CREATE,
                    $orderId,
                    (string)$built['order']['order_sn'],
                    null,
                    $built['order'],
                    '上游工作流事务内创建标准销售单'
                );
            }

            if ($ownsTransaction) {
                Db::commit();
            }

            if ($ownsTransaction) {
                AuditService::log(
                    AuditService::MODULE_SALES_ORDER,
                    AuditService::ACTION_CREATE,
                    $orderId,
                    (string)$built['order']['order_sn'],
                    null,
                    $built['order']
                );
            }

            return [
                'id' => $orderId,
                'order_sn' => (string)$built['order']['order_sn'],
            ];
        } catch (BusinessException $e) {
            if ($ownsTransaction) {
                Db::rollback();
            }
            self::setError($e->getMessage());
            return false;
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                Db::rollback();
            }
            Log::error('销售单创建失败: ' . $e->getMessage(), [
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            self::setError('操作失败，请稍后重试');
            return false;
        }
    }

    public static function edit(array $params): array|false
    {
        $tenantId = (int)(request()->tenantId ?? 0);
        if ($tenantId <= 0) { self::setError('租户无效'); return false; }
        $order = SalesOrder::where('id', (int)$params['id'])
            ->where('tenant_id', $tenantId)
            ->findOrEmpty();
        if ($order->isEmpty()) {
            self::setError('销售单不存在');
            return false;
        }
        if (self::isCustomerReportSource($order->toArray())) {
            self::setError('客户报货生成的销售单不可直接编辑，请通过销售退货处理');
            return false;
        }

        $built = self::buildOrderData($params, $order->toArray());
        if ($built === false) {
            return false;
        }

        Db::startTrans();
        try {
            FinanceIntegration::lock();
            $order = SalesOrder::where('id', (int)$params['id'])
                ->where('tenant_id', $tenantId)
                ->lock(true)
                ->findOrEmpty();
            if ($order->isEmpty()) {
                throw new BusinessException('销售单不存在');
            }
            if (self::isCustomerReportSource($order->toArray())) {
                throw new BusinessException('客户报货生成的销售单不可直接编辑，请通过销售退货处理');
            }
            $built = self::buildOrderData($params, $order->toArray());
            if ($built === false) {
                Db::rollback();
                return false;
            }
            // === 回滚旧库存和旧应收 ===
            if (!StockService::rollback((int)$order->id, 'sales')) { throw new BusinessException('库存回滚失败'); }
            if (!FinanceService::rollbackReceivable((int)$order->id, 'sales')) { throw new BusinessException('应收回滚失败'); }

            $order->save($built['order']);
            self::replaceGoods((int)$order->id, $built['goods']);

            // === 重新出库 ===
            usort($built['goods'], static fn(array $left, array $right): int =>
                [(int)$left['sku_id'], (int)$left['goods_id']] <=> [(int)$right['sku_id'], (int)$right['goods_id']]
            );
            foreach ($built['goods'] as $row) {
                if (!StockService::outbound(
                    (int)$built['order']['warehouse_id'],
                    (int)$row['goods_id'],
                    (string)($row['base_quantity'] ?? $row['number']),
                    (int)$order->id,
                    'sales',
                    $order->order_sn,
                    '',
                    (int)($row['sku_id'] ?? 0),
                    (int)($row['batch_id'] ?? 0)
                )) { throw new BusinessException('库存处理失败'); }
            }

            // === 重新计算应收 ===
            $arrearsMoney = (string)$built['order']['order_arrears_money'];
            if (bccomp($arrearsMoney, '0', 2) > 0) {
                if (!FinanceService::addReceivable(
                    (int)$built['order']['customer_id'],
                    $arrearsMoney,
                    (int)$order->id,
                    'sales',
                    $order->order_sn
                )) { throw new BusinessException('应收处理失败'); }
            }

            Db::commit();

            $result = self::detail(['id' => (int)$order->id]);
            AuditService::log(
                AuditService::MODULE_SALES_ORDER,
                AuditService::ACTION_EDIT,
                (int)$order->id,
                (string)$order->order_sn,
                $built['order'],
                $result
            );

            return $result;
        } catch (BusinessException $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('销售单编辑失败: ' . $e->getMessage(), [
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            self::setError('操作失败，请稍后重试');
            return false;
        }
    }

    public static function remove(array $params): array|false
    {
        $tenantId = (int)(request()->tenantId ?? 0);
        if ($tenantId <= 0) { self::setError('租户无效'); return false; }
        $order = SalesOrder::where('id', (int)$params['id'])
            ->where('tenant_id', $tenantId)
            ->findOrEmpty();
        if ($order->isEmpty()) {
            self::setError('销售单不存在');
            return false;
        }
        if (self::isCustomerReportSource($order->toArray())) {
            self::setError('客户报货生成的销售单不可直接删除，请通过销售退货处理');
            return false;
        }

        Db::startTrans();
        try {
            FinanceIntegration::lock();
            $order = SalesOrder::where('id', (int)$params['id'])
                ->where('tenant_id', $tenantId)
                ->lock(true)
                ->findOrEmpty();
            if ($order->isEmpty()) {
                throw new BusinessException('销售单不存在');
            }
            if (self::isCustomerReportSource($order->toArray())) {
                throw new BusinessException('客户报货生成的销售单不可直接删除，请通过销售退货处理');
            }
            // === 回滚库存和应收 ===
            if (!StockService::rollback((int)$order->id, 'sales')) { throw new BusinessException('库存回滚失败'); }
            if (!FinanceService::rollbackReceivable((int)$order->id, 'sales')) { throw new BusinessException('应收回滚失败'); }

            $orderData = $order->toArray();
            OrderGoods::where('order_id', (int)$order->id)
                ->where('order_type', self::ORDER_TYPE)
                ->where('tenant_id', $tenantId)
                ->delete();
            $order->delete();
            Db::commit();

            AuditService::log(
                AuditService::MODULE_SALES_ORDER,
                AuditService::ACTION_DELETE,
                (int)$params['id'],
                (string)($orderData['order_sn'] ?? ''),
                $orderData,
                null
            );

            return [
                'id' => (int)$params['id'],
            ];
        } catch (BusinessException $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('销售单删除失败: ' . $e->getMessage(), [
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            self::setError('操作失败，请稍后重试');
            return false;
        }
    }

    public static function detail(array $params): array
    {
        $tenantId = (int)(request()->tenantId ?? 0);
        if ($tenantId <= 0) {
            return [];
        }

        $order = SalesOrder::where('id', (int)$params['id'])
            ->where('tenant_id', $tenantId)
            ->findOrEmpty();
        if ($order->isEmpty()) {
            return [];
        }

        $item = self::formatItem($order->toArray(), true);
        $goodsRows = OrderGoods::where('order_id', (int)$order->id)
            ->where('order_type', self::ORDER_TYPE)
            ->where('tenant_id', $tenantId)
            ->order(['sort' => 'asc', 'id' => 'asc'])
            ->select()
            ->toArray();
        $item['goods'] = self::formatGoodsRows($goodsRows, self::returnedSalesQtyMap((int)$order->id));
        return $item;
    }

    public static function statistics(array $params): array
    {
        $tenantId = (int)(request()->tenantId ?? 0);
        if ($tenantId <= 0) return ['number'=>0,'order_money'=>'0.00','order_pay_money'=>'0.00','order_arrears_money'=>'0.00'];
        $query = SalesOrder::where('tenant_id', $tenantId)->field(['id', 'order_money', 'order_pay_money', 'order_arrears_money', 'datetimesingle']);
        self::applyTimeRange($query, $params);

        return [
            'number' => (int)$query->count(),
            'order_money' => self::money((float)$query->sum('order_money')),
            'order_pay_money' => self::money((float)$query->sum('order_pay_money')),
            'order_arrears_money' => self::money((float)$query->sum('order_arrears_money')),
        ];
    }

    public static function formatList(array $items): array
    {
        if (empty($items)) {
            return [];
        }

        $customerIds = array_values(array_unique(array_filter(array_map(fn($item) => (int)($item['customer_id'] ?? 0), $items))));
        $warehouseIds = array_values(array_unique(array_filter(array_map(fn($item) => (int)($item['warehouse_id'] ?? 0), $items))));

        $tenantId = (int)(request()->tenantId ?? 0);
        if ($tenantId <= 0) return [];
        $customerRows = empty($customerIds) ? [] : Customer::whereIn('id', $customerIds)->where('tenant_id', $tenantId)->select()->toArray();
        $warehouseRows = empty($warehouseIds) ? [] : Warehouse::whereIn('id', $warehouseIds)->where('tenant_id', $tenantId)->select()->toArray();

        $customerMap = [];
        foreach ($customerRows as $customer) {
            $customerMap[(int)$customer['id']] = CustomerLogic::formatItem($customer);
        }

        $warehouseMap = [];
        foreach ($warehouseRows as $warehouse) {
            $warehouseMap[(int)$warehouse['id']] = WarehouseLogic::formatItem($warehouse);
        }

        return array_map(fn($item) => self::formatItem($item, false, $customerMap, $warehouseMap), $items);
    }

    public static function formatItem(array $item, bool $includeCustomer = false, array $customerMap = [], array $warehouseMap = []): array
    {
        $tenantId = (int)(request()->tenantId ?? 0);
        if ($tenantId <= 0) {
            return [];
        }
        $customerId = (int)($item['customer_id'] ?? 0);
        $warehouseId = (int)($item['warehouse_id'] ?? 0);
        $customer = $customerMap[$customerId] ?? null;
        if ($includeCustomer && !$customer && $customerId > 0) {
            $customerModel = Customer::where('id', $customerId)->where('tenant_id', $tenantId)->findOrEmpty();
            $customer = $customerModel->isEmpty() ? null : CustomerLogic::formatItem($customerModel->toArray());
        }

        $warehouse = $warehouseMap[$warehouseId] ?? null;
        if ($includeCustomer && !$warehouse && $warehouseId > 0) {
            $warehouseModel = Warehouse::where('id', $warehouseId)->where('tenant_id', $tenantId)->findOrEmpty();
            $warehouse = $warehouseModel->isEmpty() ? null : WarehouseLogic::formatItem($warehouseModel->toArray());
        }

        $customerName = (string)($customer['customer_name'] ?? $item['customer_name'] ?? '');
        $warehouseName = (string)($warehouse['name'] ?? '');
        $datetimesingle = (int)($item['datetimesingle'] ?? 0);

        return [
            'id' => (int)($item['id'] ?? 0),
            'order_sn' => (string)($item['order_sn'] ?? ''),
            'customer_id' => $customerId,
            'customer_name' => $customerName,
            'customer' => $customer ?: [
                'id' => $customerId,
                'customer_name' => $customerName,
            ],
            'warehouse_id' => $warehouseId,
            'warehouse_name' => $warehouseName,
            'warehouse' => $warehouseName,
            'warehouse_info' => $warehouse,
            'order_money' => self::money($item['order_money'] ?? 0),
            'order_pay_money' => self::money($item['order_pay_money'] ?? 0),
            'order_arrears_money' => self::money($item['order_arrears_money'] ?? 0),
            'datetimesingle' => $datetimesingle,
            'createdate' => self::dateText($datetimesingle ?: ($item['create_time'] ?? 0)),
            'status' => (int)($item['status'] ?? 1),
            'status_label' => SalesOrder::statusLabel((int)($item['status'] ?? 1)),
            'purpose' => self::DEFAULT_PURPOSE,
            'purpose_type' => (string)($item['purpose_type'] ?? self::DEFAULT_PURPOSE_TYPE),
            'remarks' => (string)($item['remarks'] ?? ''),
            'remark' => (string)($item['remarks'] ?? ''),
            'source_type' => (string)($item['source_type'] ?? ''),
            'source_id' => (int)($item['source_id'] ?? 0),
            'source_version' => (int)($item['source_version'] ?? 0),
            'document_kind' => self::salesDocumentKind($item),
            'settlement_status' => (string)($item['settlement_status'] ?? 'formal'),
            'cost_status' => (string)($item['cost_status'] ?? 'confirmed'),
            'profit_status' => (string)($item['profit_status'] ?? 'accurate'),
            'admin_id' => (int)($item['admin_id'] ?? 0),
            'create_time' => $item['create_time'] ?? '',
            'update_time' => $item['update_time'] ?? '',
        ];
    }

    /** @param array<string,mixed> $order */
    private static function isCustomerReportSource(array $order): bool
    {
        return (string)($order['source_type'] ?? '') === 'customer_report';
    }

    protected static function buildOrderData(array $params, array $current = []): array|false
    {
        $tenantId = (int)(request()->tenantId ?? 0);
        if ($tenantId <= 0) { self::setError('租户无效'); return false; }
        $customer = Customer::where('id', (int)$params['customer_id'])->where('tenant_id', $tenantId)->findOrEmpty();
        if ($customer->isEmpty()) {
            self::setError('客户不存在');
            return false;
        }
        if ((int)$customer->is_disabled === 1) {
            self::setError('停用客户不可开销售单');
            return false;
        }

        $warehouse = self::resolveWarehouse($params['warehouse_id'] ?? 0);
        if (!$warehouse) {
            return false;
        }

        $goodsRows = self::buildGoodsRows($params['goods'] ?? []);
        if ($goodsRows === false) {
            return false;
        }

        $orderMoney = array_reduce($goodsRows, fn($sum, $row) => bcadd($sum, (string)$row['amount'], 2), '0.00');
        $rawPay = (string)max(0, (float)($params['order_pay_money'] ?? ($current['order_pay_money'] ?? 0)));
        $orderPayMoney = bccomp($rawPay, (string)$orderMoney, 2) > 0 ? (string)$orderMoney : $rawPay;
        $tenantId = (int)(request()->tenantId ?? 0);
        $adminId = (int)(request()->adminId ?? 0);
        $orderSn = trim((string)($params['order_sn'] ?? ($current['order_sn'] ?? '')));
        $idempotentKey = trim((string)($params['idempotent_key'] ?? ''));

        if ($orderSn === '') {
            $orderSn = self::generateOrderSn();
        } elseif (!self::assertOrderSnUnique($orderSn, (int)($current['id'] ?? 0))) {
            return false;
        }
        $sourceType = trim((string)($params['source_type'] ?? ($current['source_type'] ?? '')));
        $sourceId = (int)($params['source_id'] ?? ($current['source_id'] ?? 0));
        $sourceVersion = (int)($params['source_version'] ?? ($current['source_version'] ?? 0));

        return [
            'order' => [
                'tenant_id' => $tenantId,
                'order_sn' => $orderSn,
                'customer_id' => (int)$customer->id,
                'customer_name' => (string)$customer->customer_name,
                'warehouse_id' => (int)$warehouse->id,
                'order_money' => self::money($orderMoney),
                'order_pay_money' => self::money($orderPayMoney),
                'order_arrears_money' => bcsub((string)$orderMoney, (string)$orderPayMoney, 2),
                'datetimesingle' => (int)($params['datetimesingle'] ?? ($current['datetimesingle'] ?? time())),
                'status' => (int)($current['status'] ?? 1),
                'purpose_type' => trim((string)($params['purpose_type'] ?? $params['purpose'] ?? ($current['purpose_type'] ?? self::DEFAULT_PURPOSE_TYPE))),
                'remarks' => trim((string)($params['remarks'] ?? $params['remark'] ?? ($current['remarks'] ?? ''))),
                'source_type' => $sourceType !== '' ? $sourceType : null,
                'source_id' => $sourceType !== '' && $sourceId > 0 ? $sourceId : null,
                'source_version' => $sourceType !== '' && $sourceVersion > 0 ? $sourceVersion : null,
                'admin_id' => $adminId,
                'idempotent_key' => $idempotentKey,
            ],
            'goods' => $goodsRows,
        ];
    }

    protected static function buildGoodsRows(array $goods): array|false
    {
        if (empty($goods)) {
            self::setError('请选择商品');
            return false;
        }

        $rows = [];
        foreach (array_values($goods) as $index => $item) {
            $goodsId = (int)($item['goods_id'] ?? $item['id'] ?? 0);
            if ($goodsId <= 0) {
                self::setError('商品明细缺少商品ID');
                return false;
            }

            $goodsModel = Goods::where('id', $goodsId)->where('tenant_id', (int)(request()->tenantId ?? 0))->findOrEmpty();
            if ($goodsModel->isEmpty()) {
                self::setError('商品不存在');
                return false;
            }
            if ((int)$goodsModel->is_disabled === 1) {
                self::setError('停用商品不可开销售单');
                return false;
            }
            $skuId = (int)($item['sku_id'] ?? 0);
            try {
                $sku = GoodsSkuSelectionService::forSale(
                    (int)(request()->tenantId ?? 0),
                    $goodsId,
                    $skuId
                );
            } catch (\InvalidArgumentException $exception) {
                self::setError($exception->getMessage());
                return false;
            }
            $skuId = (int)$sku->id;
            $skuName = (string)$sku->sku_name;

            $number = round(max(0, (float)($item['number'] ?? 0)), 4);
            if ($number <= 0) {
                self::setError('商品数量必须大于0');
                return false;
            }
            $baseQuantity = round(max(0, (float)($item['base_quantity'] ?? $number)), 4);
            if ($baseQuantity <= 0) {
                self::setError('商品基础数量必须大于0');
                return false;
            }

            $price = self::money($item['price'] ?? $item['units_money'] ?? $goodsModel->price);
            $amount = bcmul((string)$number, (string)$price, 2);
            $rows[] = [
                'tenant_id' => (int)(request()->tenantId ?? 0),
                'order_type' => self::ORDER_TYPE,
                'goods_id' => $goodsId,
                'sku_id' => $skuId,
                'sku_name' => $skuName,
                'name' => trim((string)($item['name'] ?? $item['product_name'] ?? $goodsModel->name)),
                'units' => trim((string)($item['units'] ?? $item['unit'] ?? $goodsModel->units)),
                'number' => number_format($number, 4, '.', ''),
                'base_quantity' => number_format($baseQuantity, 4, '.', ''),
                'price' => $price,
                'amount' => $amount,
                'pricing_unit_id' => (int)($item['pricing_unit_id'] ?? 0),
                'source_line_type' => trim((string)($item['source_line_type'] ?? '')),
                'source_line_id' => (int)($item['source_line_id'] ?? 0),
                'remark' => trim((string)($item['remark'] ?? '')),
                'sort' => $index,
            ];
        }

        return $rows;
    }

    protected static function replaceGoods(int $orderId, array $rows): void
    {
        OrderGoods::where('order_id', $orderId)
            ->where('order_type', self::ORDER_TYPE)
            ->where('tenant_id', (int)(request()->tenantId ?? 0))
            ->delete();

        foreach ($rows as $row) {
            $row['order_id'] = $orderId;
            $row['create_time'] = time();
            $row['update_time'] = $row['create_time'];
            Db::name('order_goods')->insert($row);
        }
    }

    protected static function resolveWarehouse(mixed $warehouseId): ?Warehouse
    {
        if ($warehouseId === 'default') {
            $warehouse = Warehouse::where('name', '默认仓库')->where('tenant_id', (int)(request()->tenantId ?? 0))->findOrEmpty();
        } else {
            $warehouse = Warehouse::where('id', (int)$warehouseId)->where('tenant_id', (int)(request()->tenantId ?? 0))->findOrEmpty();
        }

        if ($warehouse->isEmpty()) {
            self::setError('仓库不存在');
            return null;
        }
        if ((int)$warehouse->is_enabled !== 1) {
            self::setError('停用仓库不可开销售单');
            return null;
        }

        return $warehouse;
    }

    protected static function generateOrderSn(): string
    {
        $tenantId = (int)(request()->tenantId ?? 0);
        $maxRetries = 3;
        for ($i = 0; $i < $maxRetries; $i++) {
            $sn = 'XSD' . date('YmdHis') . str_pad((string)mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
            $exists = SalesOrder::where('tenant_id', $tenantId)
                ->where('order_sn', $sn)
                ->count();
            if ($exists == 0) {
                return $sn;
            }
            usleep(1000);
        }
        // 3次冲突后使用微秒级后缀
        return 'XSD' . date('YmdHis') . substr((string)((int)(microtime(true) * 10000)), -10);
    }

    protected static function assertOrderSnUnique(string $orderSn, int $ignoreId = 0): bool
    {
        $query = SalesOrder::where('order_sn', $orderSn)->where('tenant_id', (int)(request()->tenantId ?? 0));
        if ($ignoreId > 0) {
            $query->where('id', '<>', $ignoreId);
        }
        if ($query->count() > 0) {
            self::setError('销售单号已存在');
            return false;
        }
        return true;
    }

    protected static function formatGoodsRows(array $rows, array $returnedMap = []): array
    {
        $uniqueGoodsSkuKeys = self::uniqueGoodsSkuKeys($rows);

        return array_map(function ($row) use ($returnedMap, $uniqueGoodsSkuKeys) {
            $number = rtrim(rtrim(number_format((float)($row['number'] ?? 0), 4, '.', ''), '0'), '.');
            $returnedNumber = self::salesReturnReturnedQtyForOrigin($row, $returnedMap, $uniqueGoodsSkuKeys);
            $returnableNumber = bcsub((string)($row['number'] ?? '0.0000'), $returnedNumber, 4);
            if (bccomp($returnableNumber, '0', 4) < 0) {
                $returnableNumber = '0.0000';
            }
            return [
                'id' => (int)($row['id'] ?? 0),
                'order_goods_id' => (int)($row['id'] ?? 0),
                'goods_id' => (int)($row['goods_id'] ?? 0),
                'sku_id' => (int)($row['sku_id'] ?? 0),
                'sku_name' => (string)($row['sku_name'] ?? ''),
                'name' => (string)($row['name'] ?? ''),
                'product_name' => (string)($row['name'] ?? ''),
                'units' => (string)($row['units'] ?? ''),
                'unit' => (string)($row['units'] ?? ''),
                'number' => $number === '' ? '0' : $number,
                'returned_number' => self::quantityText($returnedNumber),
                'returnable_number' => self::quantityText($returnableNumber),
                'max_return_number' => self::quantityText($returnableNumber),
                'price' => self::money($row['price'] ?? 0),
                'units_money' => self::money($row['price'] ?? 0),
                'amount' => self::money($row['amount'] ?? 0),
                'remark' => (string)($row['remark'] ?? ''),
                'sort' => (int)($row['sort'] ?? 0),
            ];
        }, $rows);
    }

    protected static function uniqueGoodsSkuKeys(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $key = 'goods:' . (int)($row['goods_id'] ?? 0) . ':' . (int)($row['sku_id'] ?? 0);
            $counts[$key] = (int)($counts[$key] ?? 0) + 1;
        }

        return array_keys(array_filter($counts, fn($count) => $count === 1));
    }

    protected static function returnedSalesQtyMap(int $originalOrderId): array
    {
        $returnIds = SalesReturnOrder::where('original_sales_order_id', $originalOrderId)
            ->where('tenant_id', (int)(request()->tenantId ?? 0))
            ->column('id');
        if (empty($returnIds)) {
            return [];
        }

        $rows = OrderGoods::whereIn('order_id', $returnIds)
            ->where('order_type', 'sales-return')
            ->where('tenant_id', (int)(request()->tenantId ?? 0))
            ->select()
            ->toArray();

        $map = [];
        foreach ($rows as $row) {
            $key = self::salesReturnDimensionKey($row);
            $map[$key] = bcadd((string)($map[$key] ?? '0.0000'), (string)($row['number'] ?? '0.0000'), 4);
        }

        return $map;
    }

    protected static function salesReturnDimensionKey(array $row): string
    {
        $originLineId = (int)($row['original_sales_order_list_id'] ?? $row['original_order_goods_id'] ?? 0);
        if ($originLineId > 0) {
            return 'line:' . $originLineId;
        }

        return 'goods:' . (int)($row['goods_id'] ?? 0) . ':' . (int)($row['sku_id'] ?? 0);
    }

    protected static function salesReturnReturnedQtyForOrigin(array $originRow, array $returnedMap, array $uniqueGoodsSkuKeys = []): string
    {
        $lineKey = self::salesReturnDimensionKey([
            'original_sales_order_list_id' => (int)($originRow['id'] ?? 0),
            'goods_id' => (int)($originRow['goods_id'] ?? 0),
            'sku_id' => (int)($originRow['sku_id'] ?? 0),
        ]);
        $goodsSkuKey = 'goods:' . (int)($originRow['goods_id'] ?? 0) . ':' . (int)($originRow['sku_id'] ?? 0);

        if (isset($returnedMap[$lineKey])) {
            return (string)$returnedMap[$lineKey];
        }

        return in_array($goodsSkuKey, $uniqueGoodsSkuKeys, true)
            ? (string)($returnedMap[$goodsSkuKey] ?? '0.0000')
            : '0.0000';
    }

    protected static function quantityText(mixed $value): string
    {
        $text = rtrim(rtrim(number_format((float)$value, 4, '.', ''), '0'), '.');
        return $text === '' ? '0' : $text;
    }

    protected static function applyTimeRange($query, array $params): void
    {
        $startTime = (int)($params['start_time'] ?? 0);
        $endTime = (int)($params['end_time'] ?? 0);
        if ($startTime > 0 && $endTime > 0) {
            $query->whereBetween('datetimesingle', [$startTime, $endTime]);
        } elseif ($startTime > 0) {
            $query->where('datetimesingle', '>=', $startTime);
        } elseif ($endTime > 0) {
            $query->where('datetimesingle', '<=', $endTime);
        }
    }

    protected static function dateText(mixed $value): string
    {
        if (is_numeric($value) && (int)$value > 0) {
            return date('Y-m-d', (int)$value);
        }

        $text = (string)$value;
        return strlen($text) >= 10 ? substr($text, 0, 10) : $text;
    }

    /**
     * @throws BusinessException
     */
    protected static function money(mixed $value): string
    {
        return number_format(max(0, (float)$value), 2, '.', '');
    }
}
