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
use think\facade\Db;
use think\facade\Log;
use app\api\jxc\logic\StockService;
use app\api\jxc\logic\FinanceService;
use app\api\jxc\logic\AuditService;
use app\api\jxc\exception\BusinessException;

class SalesReturnOrderLogic extends BaseLogic
{
    private const ORDER_TYPE = 'sales-return';
    private const DEFAULT_PURPOSE = '销售退货';
    private const DEFAULT_PURPOSE_TYPE = 'sales-return';

    public static function publish(array $params): array|false
    {
        self::clearError();
        // 幂等键检查（事务开始前）
        $idempotentKey = trim((string)($params['idempotent_key'] ?? ''));
        if ($idempotentKey !== '') {
            $tenantId = (int)(request()->tenantId ?? 0);
            $existing = SalesReturnOrder::where('tenant_id', $tenantId)
                ->where('idempotent_key', $idempotentKey)
                ->find();
            if ($existing) {
                return [
                    'id'       => (int)$existing->id,
                    'order_sn' => (string)$existing->order_sn,
                    'sn'       => (string)$existing->order_sn,
                ];
            }
        }

        $built = self::buildOrderData($params);
        if ($built === false) {
            return false;
        }

        Db::startTrans();
        try {
            $order = SalesReturnOrder::create($built['order']);
            self::replaceGoods((int)$order->id, $built['goods']);

            // === 库存入库（退货收回商品）===
            foreach ($built['goods'] as $row) {
                $stockOk = StockService::inbound(
                    (int)$built['order']['warehouse_id'],
                    (int)$row['goods_id'],
                    (string)$row['number'],
                    (int)$order->id,
                    'sales-return',
                    $built['order']['order_sn']
                );
                if (!$stockOk) {
                    self::throwFailure('库存入库失败', 'RETURN_STOCK_FAILED');
                }
            }

            // === 应收减少（退货导致应收降低）===
            $orderMoney = (string)$built['order']['order_money'];
            if (bccomp($orderMoney, '0', 2) > 0) {
                $financeOk = FinanceService::reduceReceivable(
                    (int)$built['order']['customer_id'],
                    $orderMoney,
                    (int)$order->id,
                    'sales-return',
                    $built['order']['order_sn'],
                    ReceivableFlow::TYPE_RETURN_REDUCE,
                    '销售退货应收减少-' . $built['order']['order_sn']
                );
                if (!$financeOk) {
                    self::throwFailure('应收冲减失败', 'RETURN_FINANCE_FAILED');
                }
            }

            self::refreshSalesOrderReturnStatus((int)$built['order']['original_sales_order_id']);

            Db::commit();

            AuditService::log(
                AuditService::MODULE_RETURN_ORDER,
                AuditService::ACTION_CREATE,
                (int)$order->id,
                (string)$order->order_sn,
                null,
                $built['order']
            );

            return [
                'id' => (int)$order->id,
                'order_sn' => (string)$order->order_sn,
                'sn' => (string)$order->order_sn,
            ];
        } catch (BusinessException $e) {
            Db::rollback();
            self::setError($e->getMessage());
            self::ensureFailureCode('RETURN_FINANCE_FAILED');
            return false;
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('退货单创建失败: ' . $e->getMessage(), [
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            self::failWithCode('操作失败，请稍后重试', 'RETURN_FINANCE_FAILED');
            return false;
        }
    }

    public static function edit(array $params): array|false
    {
        self::clearError();
        $order = SalesReturnOrder::where('id', (int)$params['id'])
            ->where('tenant_id', (int)(request()->tenantId ?? 0))
            ->findOrEmpty();
        if ($order->isEmpty()) {
            self::failWithCode('退货单不存在', 'RETURN_ORDER_NOT_FOUND');
            return false;
        }

        $oldOrderMoney = (string)$order->order_money;
        $oldCustomerId = (int)$order->customer_id;
        $oldOrderSn = (string)$order->order_sn;
        $oldOriginalOrderId = (int)$order->original_sales_order_id;

        $built = self::buildOrderData($params, $order->toArray());
        if ($built === false) {
            return false;
        }

        Db::startTrans();
        try {
            // === 回滚旧库存 ===
            if (!StockService::rollback((int)$order->id, 'sales-return')) {
                self::throwFailure('旧库存回滚失败', 'RETURN_STOCK_FAILED');
            }

            // === 恢复旧应收（退货单之前减少了应收，回滚时需要加回去）===
            if (bccomp($oldOrderMoney, '0', 2) > 0) {
                if (!FinanceService::addReceivable(
                    $oldCustomerId,
                    $oldOrderMoney,
                    (int)$order->id,
                    'sales-return',
                    $oldOrderSn,
                    '退货单编辑回滚应收-' . $oldOrderSn
                )) {
                    self::throwFailure('旧应收回滚失败', 'RETURN_FINANCE_FAILED');
                }
            }

            $order->save($built['order']);
            self::replaceGoods((int)$order->id, $built['goods']);

            // === 重新入库 ===
            foreach ($built['goods'] as $row) {
                $stockOk = StockService::inbound(
                    (int)$built['order']['warehouse_id'],
                    (int)$row['goods_id'],
                    (string)$row['number'],
                    (int)$order->id,
                    'sales-return',
                    $order->order_sn
                );
                if (!$stockOk) {
                    self::throwFailure('库存入库失败', 'RETURN_STOCK_FAILED');
                }
            }

            // === 重新减少应收 ===
            $newOrderMoney = (string)$built['order']['order_money'];
            if (bccomp($newOrderMoney, '0', 2) > 0) {
                $financeOk = FinanceService::reduceReceivable(
                    (int)$built['order']['customer_id'],
                    $newOrderMoney,
                    (int)$order->id,
                    'sales-return',
                    $order->order_sn,
                    ReceivableFlow::TYPE_RETURN_REDUCE,
                    '销售退货应收减少-' . $order->order_sn
                );
                if (!$financeOk) {
                    self::throwFailure('应收冲减失败', 'RETURN_FINANCE_FAILED');
                }
            }

            self::refreshSalesOrderReturnStatus($oldOriginalOrderId);
            if ((int)$built['order']['original_sales_order_id'] !== $oldOriginalOrderId) {
                self::refreshSalesOrderReturnStatus((int)$built['order']['original_sales_order_id']);
            }

            Db::commit();

            $result = self::detail(['id' => (int)$order->id]);
            AuditService::log(
                AuditService::MODULE_RETURN_ORDER,
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
            self::ensureFailureCode('RETURN_FINANCE_FAILED');
            return false;
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('退货单编辑失败: ' . $e->getMessage(), [
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            self::failWithCode('操作失败，请稍后重试', 'RETURN_FINANCE_FAILED');
            return false;
        }
    }

    public static function remove(array $params): array|false
    {
        self::clearError();
        $order = SalesReturnOrder::where('id', (int)$params['id'])
            ->where('tenant_id', (int)(request()->tenantId ?? 0))
            ->findOrEmpty();
        if ($order->isEmpty()) {
            self::failWithCode('退货单不存在', 'RETURN_ORDER_NOT_FOUND');
            return false;
        }

        $oldOrderMoney = (string)$order->order_money;
        $customerId = (int)$order->customer_id;
        $orderSn = (string)$order->order_sn;
        $originalOrderId = (int)$order->original_sales_order_id;

        Db::startTrans();
        try {
            // === 回滚库存 ===
            if (!StockService::rollback((int)$order->id, 'sales-return')) {
                self::throwFailure('旧库存回滚失败', 'RETURN_STOCK_FAILED');
            }

            // === 恢复应收（退货作废，应收加回）===
            if (bccomp($oldOrderMoney, '0', 2) > 0) {
                if (!FinanceService::addReceivable(
                    $customerId,
                    $oldOrderMoney,
                    (int)$order->id,
                    'sales-return',
                    $orderSn,
                    '退货作废恢复应收-' . $orderSn
                )) {
                    self::throwFailure('旧应收回滚失败', 'RETURN_FINANCE_FAILED');
                }
            }

            OrderGoods::where('order_id', (int)$order->id)
                ->where('order_type', self::ORDER_TYPE)
                ->delete();
            $order->delete();
            self::refreshSalesOrderReturnStatus($originalOrderId);
            Db::commit();

            AuditService::log(
                AuditService::MODULE_RETURN_ORDER,
                AuditService::ACTION_DELETE,
                (int)$params['id'],
                $orderSn,
                ['id' => (int)$params['id'], 'order_sn' => $orderSn, 'customer_id' => $customerId, 'order_money' => $oldOrderMoney],
                null
            );

            return [
                'id' => (int)$params['id'],
            ];
        } catch (BusinessException $e) {
            Db::rollback();
            self::setError($e->getMessage());
            self::ensureFailureCode('RETURN_FINANCE_FAILED');
            return false;
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('退货单删除失败: ' . $e->getMessage(), [
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            self::failWithCode('操作失败，请稍后重试', 'RETURN_FINANCE_FAILED');
            return false;
        }
    }

    public static function detail(array $params): array
    {
        $order = SalesReturnOrder::findOrEmpty((int)$params['id']);
        if ($order->isEmpty()) {
            return [];
        }

        $item = self::formatItem($order->toArray(), true);
        $item['goods'] = self::formatGoodsRows(OrderGoods::where('order_id', (int)$order->id)
            ->where('order_type', self::ORDER_TYPE)
            ->order(['sort' => 'asc', 'id' => 'asc'])
            ->select()
            ->toArray());

        // 关联原销售单信息
        $originalOrderId = (int)($order->original_sales_order_id ?? 0);
        if ($originalOrderId > 0) {
            $originalOrder = SalesOrder::findOrEmpty($originalOrderId);
            $item['original_sales_order'] = $originalOrder->isEmpty() ? null : [
                'id' => (int)$originalOrder->id,
                'order_sn' => (string)$originalOrder->order_sn,
                'order_money' => self::money($originalOrder->order_money),
                'status' => (int)$originalOrder->status,
                'status_label' => SalesOrder::statusLabel((int)$originalOrder->status),
            ];
        } else {
            $item['original_sales_order'] = null;
        }

        return $item;
    }

    public static function statistics(array $params): array
    {
        $query = SalesReturnOrder::field(['id', 'order_money', 'datetimesingle']);
        self::applyTimeRange($query, $params);

        return [
            'number' => (int)$query->count(),
            'order_money' => self::money((float)$query->sum('order_money')),
        ];
    }

    public static function formatList(array $items): array
    {
        if (empty($items)) {
            return [];
        }

        $customerIds = array_values(array_unique(array_filter(array_map(fn($item) => (int)($item['customer_id'] ?? 0), $items))));
        $warehouseIds = array_values(array_unique(array_filter(array_map(fn($item) => (int)($item['warehouse_id'] ?? 0), $items))));

        $customerRows = empty($customerIds) ? [] : Customer::whereIn('id', $customerIds)->select()->toArray();
        $warehouseRows = empty($warehouseIds) ? [] : Warehouse::whereIn('id', $warehouseIds)->select()->toArray();

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
        $customerId = (int)($item['customer_id'] ?? 0);
        $warehouseId = (int)($item['warehouse_id'] ?? 0);
        $customer = $customerMap[$customerId] ?? null;
        if ($includeCustomer && !$customer && $customerId > 0) {
            $customerModel = Customer::findOrEmpty($customerId);
            $customer = $customerModel->isEmpty() ? null : CustomerLogic::formatItem($customerModel->toArray());
        }

        $warehouse = $warehouseMap[$warehouseId] ?? null;
        if ($includeCustomer && !$warehouse && $warehouseId > 0) {
            $warehouseModel = Warehouse::findOrEmpty($warehouseId);
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
            'original_sales_order_id' => (int)($item['original_sales_order_id'] ?? 0),
            'original_order_sn' => (string)($item['original_order_sn'] ?? ''),
            'order_money' => self::money($item['order_money'] ?? 0),
            'return_reason' => (string)($item['return_reason'] ?? ''),
            'datetimesingle' => $datetimesingle,
            'createdate' => self::dateText($datetimesingle ?: ($item['create_time'] ?? 0)),
            'status' => (int)($item['status'] ?? 1),
            'purpose' => self::DEFAULT_PURPOSE,
            'purpose_type' => self::DEFAULT_PURPOSE_TYPE,
            'remarks' => (string)($item['remarks'] ?? ''),
            'remark' => (string)($item['remarks'] ?? ''),
            'admin_id' => (int)($item['admin_id'] ?? 0),
            'create_time' => $item['create_time'] ?? '',
            'update_time' => $item['update_time'] ?? '',
        ];
    }

    protected static function buildOrderData(array $params, array $current = []): array|false
    {
        $customer = Customer::findOrEmpty((int)$params['customer_id']);
        if ($customer->isEmpty()) {
            self::failWithCode('客户不存在', 'RETURN_ORIGINAL_NOT_FOUND');
            return false;
        }
        if ((int)$customer->is_disabled === 1) {
            self::failWithCode('停用客户不可开退货单', 'RETURN_ORIGINAL_NOT_FOUND');
            return false;
        }

        $warehouse = self::resolveWarehouse($params['warehouse_id'] ?? 0);
        if (!$warehouse) {
            return false;
        }

        // 验证原销售单存在且 customer_id 匹配
        $originalOrderId = (int)($params['original_order_id'] ?? ($current['original_sales_order_id'] ?? 0));
        if ($originalOrderId <= 0) {
            self::failWithCode('原销售单ID不能为空', 'RETURN_ORIGINAL_REQUIRED');
            return false;
        }
        $originalOrder = SalesOrder::findOrEmpty($originalOrderId);
        if ($originalOrder->isEmpty()) {
            self::failWithCode('原销售单不存在', 'RETURN_ORIGINAL_NOT_FOUND');
            return false;
        }
        if ((int)$originalOrder->customer_id !== (int)$customer->id) {
            self::failWithCode('原销售单客户与退货客户不匹配', 'RETURN_ORIGINAL_NOT_FOUND');
            return false;
        }

        $goodsRows = self::buildGoodsRows($params['goods'] ?? [], $originalOrderId, (int)($current['id'] ?? 0));
        if ($goodsRows === false) {
            return false;
        }

        $orderMoney = array_reduce($goodsRows, fn($sum, $row) => $sum + (float)$row['amount'], 0.0);
        $tenantId = (int)(request()->tenantId ?? 0);
        $adminId = (int)(request()->adminId ?? 0);
        $orderSn = trim((string)($params['order_sn'] ?? ($current['order_sn'] ?? '')));
        $idempotentKey = trim((string)($params['idempotent_key'] ?? ''));

        if ($orderSn === '') {
            $orderSn = self::generateOrderSn();
        } elseif (!self::assertOrderSnUnique($orderSn, (int)($current['id'] ?? 0))) {
            return false;
        }

        $originalOrderSn = trim((string)($params['original_order_sn'] ?? ($current['original_order_sn'] ?? $originalOrder->order_sn)));

        return [
            'order' => [
                'tenant_id' => $tenantId,
                'order_sn' => $orderSn,
                'customer_id' => (int)$customer->id,
                'customer_name' => (string)$customer->customer_name,
                'warehouse_id' => (int)$warehouse->id,
                'original_sales_order_id' => $originalOrderId,
                'original_order_sn' => $originalOrderSn,
                'order_money' => self::money($orderMoney),
                'return_reason' => trim((string)($params['return_reason'] ?? ($current['return_reason'] ?? ''))),
                'datetimesingle' => (int)($params['datetimesingle'] ?? ($current['datetimesingle'] ?? time())),
                'status' => (int)($current['status'] ?? 1),
                'remarks' => trim((string)($params['remarks'] ?? $params['remark'] ?? ($current['remarks'] ?? ''))),
                'admin_id' => $adminId,
                'idempotent_key' => $idempotentKey,
            ],
            'goods' => $goodsRows,
        ];
    }

    protected static function buildGoodsRows(array $goods, int $originalOrderId, int $ignoreReturnOrderId = 0): array|false
    {
        if (empty($goods)) {
            self::failWithCode('请选择商品', 'RETURN_ITEMS_EMPTY');
            return false;
        }

        $originalRows = OrderGoods::where('order_id', $originalOrderId)
            ->where('order_type', 'sales')
            ->where('tenant_id', (int)(request()->tenantId ?? 0))
            ->select()
            ->toArray();
        $originalById = [];
        $originalByGoodsSku = [];
        foreach ($originalRows as $row) {
            $originalById[(int)$row['id']] = $row;
            $originalByGoodsSku[self::goodsSkuSalesReturnKey($row)] = $row;
        }

        $returnedMap = self::returnedSalesQtyMap($originalOrderId, $ignoreReturnOrderId);
        $rows = [];
        foreach (array_values($goods) as $index => $item) {
            $originLineId = (int)($item['original_sales_order_list_id'] ?? $item['original_order_goods_id'] ?? $item['order_goods_id'] ?? 0);
            $goodsId = (int)($item['goods_id'] ?? $item['id'] ?? 0);
            $skuId = (int)($item['sku_id'] ?? 0);
            if ($goodsId <= 0) {
                self::failWithCode('商品明细缺少商品ID', 'RETURN_ITEMS_EMPTY');
                return false;
            }
            $origin = $originLineId > 0
                ? ($originalById[$originLineId] ?? null)
                : ($originalByGoodsSku[self::goodsSkuSalesReturnKey(['goods_id' => $goodsId, 'sku_id' => $skuId])] ?? null);
            if (!$origin) {
                self::failWithCode('退货商品不属于原销售单', 'RETURN_ORIGINAL_NOT_FOUND');
                return false;
            }
            $originLineId = (int)$origin['id'];
            $goodsId = (int)$origin['goods_id'];
            $skuId = (int)($origin['sku_id'] ?? 0);

            $goodsModel = Goods::findOrEmpty($goodsId);
            if ($goodsModel->isEmpty()) {
                self::failWithCode('商品不存在', 'RETURN_ORIGINAL_NOT_FOUND');
                return false;
            }
            if ((int)$goodsModel->is_disabled === 1) {
                self::failWithCode('停用商品不可开退货单', 'RETURN_ORIGINAL_NOT_FOUND');
                return false;
            }

            $number = round((float)($item['return_num'] ?? $item['number'] ?? 0), 4);
            if ($number <= 0) {
                self::failWithCode('商品数量必须大于0', 'RETURN_QTY_INVALID');
                return false;
            }
            $lineKey = self::salesReturnDimensionKey(['original_sales_order_list_id' => $originLineId, 'goods_id' => $goodsId, 'sku_id' => $skuId]);
            $goodsSkuKey = self::goodsSkuSalesReturnKey(['goods_id' => $goodsId, 'sku_id' => $skuId]);
            $originalQty = (string)$origin['number'];
            $returnedQty = (string)($returnedMap[$lineKey] ?? $returnedMap[$goodsSkuKey] ?? '0.0000');
            $availableQty = bcsub($originalQty, $returnedQty, 4);
            if (bccomp((string)$number, $availableQty, 4) > 0) {
                self::failWithCode('退货数量超过可退数量', 'RETURN_QTY_EXCEEDS_AVAILABLE');
                return false;
            }

            $price = self::money($item['price'] ?? $item['units_money'] ?? $goodsModel->price);
            $amount = self::money($number * (float)$price);
            $rows[] = [
                'tenant_id' => (int)(request()->tenantId ?? 0),
                'order_type' => self::ORDER_TYPE,
                'original_sales_order_list_id' => $originLineId,
                'goods_id' => $goodsId,
                'sku_id' => $skuId,
                'name' => trim((string)($item['name'] ?? $item['product_name'] ?? $goodsModel->name)),
                'units' => trim((string)($item['units'] ?? $item['unit'] ?? $goodsModel->units)),
                'number' => number_format($number, 4, '.', ''),
                'price' => $price,
                'amount' => $amount,
                'remark' => trim((string)($item['remark'] ?? '')),
                'sort' => $index,
            ];
        }

        return $rows;
    }

    protected static function returnedSalesQtyMap(int $originalOrderId, int $ignoreReturnOrderId = 0): array
    {
        $returnIdsQuery = SalesReturnOrder::where('original_sales_order_id', $originalOrderId)
            ->where('tenant_id', (int)(request()->tenantId ?? 0));
        if ($ignoreReturnOrderId > 0) {
            $returnIdsQuery->where('id', '<>', $ignoreReturnOrderId);
        }
        $returnIds = $returnIdsQuery->column('id');
        if (empty($returnIds)) {
            return [];
        }

        $rows = OrderGoods::whereIn('order_id', $returnIds)
            ->where('order_type', self::ORDER_TYPE)
            ->where('tenant_id', (int)(request()->tenantId ?? 0))
            ->select()
            ->toArray();

        return self::salesReturnReturnedQtyMapFromRows($rows);
    }

    protected static function salesReturnReturnedQtyMapFromRows(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $key = self::salesReturnDimensionKey($row);
            $map[$key] = bcadd((string)($map[$key] ?? '0.0000'), (string)($row['number'] ?? '0.0000'), 4);
        }
        return $map;
    }

    protected static function refreshSalesOrderReturnStatus(int $salesOrderId): void
    {
        if ($salesOrderId <= 0) {
            return;
        }

        $originalRows = OrderGoods::where('order_id', $salesOrderId)
            ->where('order_type', 'sales')
            ->where('tenant_id', (int)(request()->tenantId ?? 0))
            ->select()
            ->toArray();
        if (empty($originalRows)) {
            SalesOrder::where('id', $salesOrderId)
                ->where('tenant_id', (int)(request()->tenantId ?? 0))
                ->update(['status' => SalesOrder::STATUS_SOLD]);
            return;
        }

        $returnedMap = self::returnedSalesQtyMap($salesOrderId);
        $status = self::salesReturnStatusFromRows($originalRows, $returnedMap);
        SalesOrder::where('id', $salesOrderId)
            ->where('tenant_id', (int)(request()->tenantId ?? 0))
            ->update(['status' => $status]);
    }

    protected static function failWithCode(string $message, string $errorCode): void
    {
        self::setError($message);
        self::setReturnData(['error_code' => $errorCode]);
    }

    protected static function ensureFailureCode(string $fallbackErrorCode): void
    {
        $data = self::getReturnData();
        if (!is_array($data) || empty($data['error_code'])) {
            self::setReturnData(['error_code' => $fallbackErrorCode]);
        }
    }

    protected static function salesReturnDimensionKey(array $row): string
    {
        $originLineId = (int)($row['original_sales_order_list_id'] ?? $row['original_order_goods_id'] ?? 0);
        if ($originLineId > 0) {
            return 'line:' . $originLineId;
        }

        return self::goodsSkuSalesReturnKey($row);
    }

    protected static function goodsSkuSalesReturnKey(array $row): string
    {
        return 'goods:' . (int)($row['goods_id'] ?? 0) . ':' . (int)($row['sku_id'] ?? 0);
    }

    protected static function salesReturnReturnedQtyForOrigin(array $originRow, array $returnedMap): string
    {
        $lineKey = self::salesReturnDimensionKey([
            'original_sales_order_list_id' => (int)($originRow['id'] ?? 0),
            'goods_id' => (int)($originRow['goods_id'] ?? 0),
            'sku_id' => (int)($originRow['sku_id'] ?? 0),
        ]);
        $goodsSkuKey = self::goodsSkuSalesReturnKey($originRow);

        return (string)($returnedMap[$lineKey] ?? $returnedMap[$goodsSkuKey] ?? '0.0000');
    }

    protected static function salesReturnStatusFromRows(array $originalRows, array $returnedMap): int
    {
        $hasReturned = false;
        $allReturned = true;
        foreach ($originalRows as $row) {
            $returnedQty = self::salesReturnReturnedQtyForOrigin($row, $returnedMap);
            if (bccomp($returnedQty, '0', 4) > 0) {
                $hasReturned = true;
            }
            if (bccomp($returnedQty, (string)($row['number'] ?? '0.0000'), 4) < 0) {
                $allReturned = false;
            }
        }

        if (!$hasReturned) {
            return SalesOrder::STATUS_SOLD;
        }

        return $allReturned ? SalesOrder::STATUS_RETURNED : SalesOrder::STATUS_PART_RETURNED;
    }

    protected static function throwFailure(string $message, string $errorCode): void
    {
        self::failWithCode($message, $errorCode);
        throw new BusinessException($message);
    }

    protected static function replaceGoods(int $orderId, array $rows): void
    {
        OrderGoods::where('order_id', $orderId)
            ->where('order_type', self::ORDER_TYPE)
            ->delete();

        foreach ($rows as $row) {
            $row['order_id'] = $orderId;
            OrderGoods::create($row);
        }
    }

    protected static function resolveWarehouse(mixed $warehouseId): ?Warehouse
    {
        if ($warehouseId === 'default') {
            $warehouse = Warehouse::where('name', '默认仓库')->findOrEmpty();
        } else {
            $warehouse = Warehouse::findOrEmpty((int)$warehouseId);
        }

        if ($warehouse->isEmpty()) {
            self::failWithCode('仓库不存在', 'RETURN_ORIGINAL_NOT_FOUND');
            return null;
        }
        if ((int)$warehouse->is_enabled !== 1) {
            self::failWithCode('停用仓库不可开退货单', 'RETURN_ORIGINAL_NOT_FOUND');
            return null;
        }

        return $warehouse;
    }

    protected static function generateOrderSn(): string
    {
        $tenantId = (int)(request()->tenantId ?? 0);
        $maxRetries = 3;
        for ($i = 0; $i < $maxRetries; $i++) {
            $sn = 'THD' . date('YmdHis') . str_pad((string)mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
            $exists = SalesReturnOrder::where('tenant_id', $tenantId)
                ->where('order_sn', $sn)
                ->count();
            if ($exists == 0) {
                return $sn;
            }
            usleep(1000);
        }
        // 3次冲突后使用微秒级后缀
        return 'THD' . date('YmdHis') . substr((string)((int)(microtime(true) * 10000)), -10);
    }

    protected static function assertOrderSnUnique(string $orderSn, int $ignoreId = 0): bool
    {
        $query = SalesReturnOrder::where('order_sn', $orderSn);
        if ($ignoreId > 0) {
            $query->where('id', '<>', $ignoreId);
        }
        if ($query->count() > 0) {
            self::failWithCode('退货单号已存在', 'RETURN_ORIGINAL_NOT_FOUND');
            return false;
        }
        return true;
    }

    protected static function formatGoodsRows(array $rows): array
    {
        return array_map(function ($row) {
            $number = rtrim(rtrim(number_format((float)($row['number'] ?? 0), 4, '.', ''), '0'), '.');
            return [
                'id' => (int)($row['id'] ?? 0),
                'order_goods_id' => (int)($row['id'] ?? 0),
                'original_sales_order_list_id' => (int)($row['original_sales_order_list_id'] ?? 0),
                'goods_id' => (int)($row['goods_id'] ?? 0),
                'name' => (string)($row['name'] ?? ''),
                'product_name' => (string)($row['name'] ?? ''),
                'units' => (string)($row['units'] ?? ''),
                'unit' => (string)($row['units'] ?? ''),
                'number' => $number === '' ? '0' : $number,
                'price' => self::money($row['price'] ?? 0),
                'units_money' => self::money($row['price'] ?? 0),
                'amount' => self::money($row['amount'] ?? 0),
                'remark' => (string)($row['remark'] ?? ''),
                'sort' => (int)($row['sort'] ?? 0),
            ];
        }, $rows);
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

    protected static function money(mixed $value): string
    {
        return number_format(max(0, (float)$value), 2, '.', '');
    }
}
