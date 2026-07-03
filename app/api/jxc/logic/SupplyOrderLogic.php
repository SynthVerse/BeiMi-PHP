<?php

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use app\common\model\jxc\Vendor;
use app\common\model\jxc\Goods;
use app\common\model\jxc\GoodsSku;
use app\common\model\jxc\OrderGoods;
use app\common\model\jxc\PurchaseReturnOrderDetail;
use app\common\model\jxc\SupplyOrder;
use app\common\model\jxc\Warehouse;
use think\facade\Db;
use think\facade\Log;
use app\api\jxc\logic\StockService;
use app\api\jxc\logic\FinanceService;
use app\api\jxc\logic\AuditService;
use app\api\jxc\exception\BusinessException;

class SupplyOrderLogic extends BaseLogic
{
    private const ORDER_TYPE = 'supply';
    private const DEFAULT_PURPOSE = '采购入库';
    private const DEFAULT_PURPOSE_TYPE = 'supply';

    public static function publish(array $params): array|false
    {
        // 幂等键检查（事务开始前）
        $idempotentKey = trim((string)($params['idempotent_key'] ?? ''));
        if ($idempotentKey !== '') {
            $tenantId = (int)(request()->tenantId ?? 0);
            $existing = SupplyOrder::where('tenant_id', $tenantId)
                ->where('idempotent_key', $idempotentKey)
                ->find();
            if ($existing) {
                return [
                    'id'       => (int)$existing->id,
                    'order_sn' => (string)$existing->order_sn,
                ];
            }
        }

        $built = self::buildOrderData($params);
        if ($built === false) {
            return false;
        }

        Db::startTrans();
        try {
            $order = SupplyOrder::create($built['order']);
            $createdGoods = self::replaceGoods((int)$order->id, $built['goods']);
            $createdGoods = PurchaseArrivalService::rebuildForSupplyOrder(array_merge($built['order'], [
                'id' => (int)$order->id,
            ]), $createdGoods);

            // === 库存入库 ===
            foreach ($createdGoods as $row) {
                StockService::inbound(
                    (int)$built['order']['warehouse_id'],
                    (int)$row['goods_id'],
                    (string)$row['number'],
                    (int)$order->id,
                    'supply',
                    $built['order']['order_sn'],
                    '采购入库-' . (string)($row['sku_name'] ?: $row['name']),
                    (int)($row['sku_id'] ?? 0),
                    (int)($row['batch_id'] ?? 0)
                );
            }
            ProcurementTaskService::backfillSupplyInbound((int)$order->id, $createdGoods);

            // === 应付增加 ===
            $arrearsMoney = (string)$built['order']['order_arrears_money'];
            if (bccomp($arrearsMoney, '0', 2) > 0) {
                FinanceService::addPayable(
                    (int)$built['order']['supplier_id'],
                    $arrearsMoney,
                    (int)$order->id,
                    'supply',
                    $built['order']['order_sn']
                );
            }

            Db::commit();

            AuditService::log(
                AuditService::MODULE_SUPPLY_ORDER,
                AuditService::ACTION_CREATE,
                (int)$order->id,
                (string)$order->order_sn,
                null,
                $built['order']
            );

            return [
                'id' => (int)$order->id,
                'order_sn' => (string)$order->order_sn,
            ];
        } catch (BusinessException $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('进货单创建失败: ' . $e->getMessage(), [
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
        $order = SupplyOrder::where('id', (int)$params['id'])
            ->where('tenant_id', (int)(request()->tenantId ?? 0))
            ->findOrEmpty();
        if ($order->isEmpty()) {
            self::setError('进货单不存在');
            return false;
        }

        $built = self::buildOrderData($params, $order->toArray());
        if ($built === false) {
            return false;
        }

        Db::startTrans();
        try {
            // === 回滚旧库存和旧应付 ===
            StockService::rollback((int)$order->id, 'supply');
            FinanceService::rollbackPayable((int)$order->id, 'supply');
            PurchaseArrivalService::deleteBySupplyOrder((int)$order->id);

            $order->save($built['order']);
            $createdGoods = self::replaceGoods((int)$order->id, $built['goods']);
            $createdGoods = PurchaseArrivalService::rebuildForSupplyOrder(array_merge($built['order'], [
                'id' => (int)$order->id,
                'order_sn' => (string)$order->order_sn,
            ]), $createdGoods);

            // === 重新入库 ===
            foreach ($createdGoods as $row) {
                StockService::inbound(
                    (int)$built['order']['warehouse_id'],
                    (int)$row['goods_id'],
                    (string)$row['number'],
                    (int)$order->id,
                    'supply',
                    $order->order_sn,
                    '采购入库-' . (string)($row['sku_name'] ?: $row['name']),
                    (int)($row['sku_id'] ?? 0),
                    (int)($row['batch_id'] ?? 0)
                );
            }

            // === 重新计算应付 ===
            $arrearsMoney = (string)$built['order']['order_arrears_money'];
            if (bccomp($arrearsMoney, '0', 2) > 0) {
                FinanceService::addPayable(
                    (int)$built['order']['supplier_id'],
                    $arrearsMoney,
                    (int)$order->id,
                    'supply',
                    $order->order_sn
                );
            }

            Db::commit();

            $result = self::detail(['id' => (int)$order->id]);
            AuditService::log(
                AuditService::MODULE_SUPPLY_ORDER,
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
            Log::error('进货单编辑失败: ' . $e->getMessage(), [
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
        $order = SupplyOrder::where('id', (int)$params['id'])
            ->where('tenant_id', (int)(request()->tenantId ?? 0))
            ->findOrEmpty();
        if ($order->isEmpty()) {
            self::setError('进货单不存在');
            return false;
        }

        Db::startTrans();
        try {
            // === 回滚库存和应付 ===
            StockService::rollback((int)$order->id, 'supply');
            FinanceService::rollbackPayable((int)$order->id, 'supply');
            PurchaseArrivalService::deleteBySupplyOrder((int)$order->id);

            $orderData = $order->toArray();
            OrderGoods::where('order_id', (int)$order->id)
                ->where('order_type', self::ORDER_TYPE)
                ->where('tenant_id', (int)(request()->tenantId ?? 0))
                ->delete();
            $order->delete();
            Db::commit();

            AuditService::log(
                AuditService::MODULE_SUPPLY_ORDER,
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
            Log::error('进货单删除失败: ' . $e->getMessage(), [
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
        $order = SupplyOrder::where('id', (int)$params['id'])
            ->where('tenant_id', (int)(request()->tenantId ?? 0))
            ->findOrEmpty();
        if ($order->isEmpty()) {
            return [];
        }

        $item = self::formatItem($order->toArray(), true);
        $goodsRows = OrderGoods::where('order_id', (int)$order->id)
            ->where('order_type', self::ORDER_TYPE)
            ->where('tenant_id', (int)(request()->tenantId ?? 0))
            ->order(['sort' => 'asc', 'id' => 'asc'])
            ->select()
            ->toArray();
        $item['goods'] = self::formatGoodsRows($goodsRows, self::purchaseReturnedQtyMap((int)$order->id));

        return $item;
    }

    public static function statistics(array $params): array
    {
        $query = SupplyOrder::field(['id', 'order_money', 'order_pay_money', 'order_arrears_money', 'datetimesingle'])
            ->where('tenant_id', (int)(request()->tenantId ?? 0));
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

        $supplierIds = array_values(array_unique(array_filter(array_map(fn($item) => (int)($item['supplier_id'] ?? 0), $items))));
        $warehouseIds = array_values(array_unique(array_filter(array_map(fn($item) => (int)($item['warehouse_id'] ?? 0), $items))));

        $supplierRows = empty($supplierIds) ? [] : Vendor::whereIn('id', $supplierIds)
            ->where('tenant_id', (int)(request()->tenantId ?? 0))
            ->select()
            ->toArray();
        $warehouseRows = empty($warehouseIds) ? [] : Warehouse::whereIn('id', $warehouseIds)
            ->where('tenant_id', (int)(request()->tenantId ?? 0))
            ->select()
            ->toArray();

        $supplierMap = [];
        foreach ($supplierRows as $supplier) {
            $supplierMap[(int)$supplier['id']] = SupplierLogic::formatItem($supplier);
        }

        $warehouseMap = [];
        foreach ($warehouseRows as $warehouse) {
            $warehouseMap[(int)$warehouse['id']] = WarehouseLogic::formatItem($warehouse);
        }

        return array_map(fn($item) => self::formatItem($item, false, $supplierMap, $warehouseMap), $items);
    }

    public static function formatItem(array $item, bool $includeSupplier = false, array $supplierMap = [], array $warehouseMap = []): array
    {
        $supplierId = (int)($item['supplier_id'] ?? 0);
        $warehouseId = (int)($item['warehouse_id'] ?? 0);
        $supplier = $supplierMap[$supplierId] ?? null;
        if ($includeSupplier && !$supplier && $supplierId > 0) {
            $supplierModel = Vendor::where('id', $supplierId)
                ->where('tenant_id', (int)(request()->tenantId ?? 0))
                ->findOrEmpty();
            $supplier = $supplierModel->isEmpty() ? null : SupplierLogic::formatItem($supplierModel->toArray());
        }

        $warehouse = $warehouseMap[$warehouseId] ?? null;
        if ($includeSupplier && !$warehouse && $warehouseId > 0) {
            $warehouseModel = Warehouse::where('id', $warehouseId)
                ->where('tenant_id', (int)(request()->tenantId ?? 0))
                ->findOrEmpty();
            $warehouse = $warehouseModel->isEmpty() ? null : WarehouseLogic::formatItem($warehouseModel->toArray());
        }

        $supplierName = (string)($supplier['supplier_name'] ?? $item['supplier_name'] ?? '');
        $warehouseName = (string)($warehouse['name'] ?? '');
        $datetimesingle = (int)($item['datetimesingle'] ?? 0);

        return [
            'id' => (int)($item['id'] ?? 0),
            'order_sn' => (string)($item['order_sn'] ?? ''),
            'supplier_id' => $supplierId,
            'supplier_name' => $supplierName,
            'supplier' => $supplier ?: [
                'id' => $supplierId,
                'supplier_name' => $supplierName,
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
            'return_status' => (int)($item['return_status'] ?? 0),
            'return_status_label' => SupplyOrder::returnStatusLabel((int)($item['return_status'] ?? 0)),
            'purpose' => self::DEFAULT_PURPOSE,
            'purpose_type' => (string)($item['purpose_type'] ?? self::DEFAULT_PURPOSE_TYPE),
            'remarks' => (string)($item['remarks'] ?? ''),
            'remark' => (string)($item['remarks'] ?? ''),
            'admin_id' => (int)($item['admin_id'] ?? 0),
            'create_time' => $item['create_time'] ?? '',
            'update_time' => $item['update_time'] ?? '',
        ];
    }

    protected static function buildOrderData(array $params, array $current = []): array|false
    {
        $vendor = Vendor::where('id', (int)$params['supplier_id'])
            ->where('tenant_id', (int)(request()->tenantId ?? 0))
            ->findOrEmpty();
        if ($vendor->isEmpty()) {
            self::setError('供应商不存在');
            return false;
        }
        if ((int)$vendor->is_disabled === 1) {
            self::setError('停用供应商不可开进货单');
            return false;
        }

        $tenantId = (int)(request()->tenantId ?? 0);
        $adminId = (int)(request()->adminId ?? 0);
        $orderSn = trim((string)($params['order_sn'] ?? ($current['order_sn'] ?? '')));
        $idempotentKey = trim((string)($params['idempotent_key'] ?? ''));
        $datetimesingle = (int)($params['datetimesingle'] ?? ($current['datetimesingle'] ?? time()));

        $warehouse = self::resolveWarehouse($params['warehouse_id'] ?? 0);
        if (!$warehouse) {
            return false;
        }

        $goodsRows = self::buildGoodsRows($params['goods'] ?? [], (int)$vendor->id, $datetimesingle);
        if ($goodsRows === false) {
            return false;
        }

        $orderMoney = array_reduce($goodsRows, fn($sum, $row) => $sum + (float)$row['amount'], 0.0);
        $orderPayMoney = min($orderMoney, max(0, (float)($params['order_pay_money'] ?? ($current['order_pay_money'] ?? 0))));

        if ($orderSn === '') {
            $orderSn = self::generateOrderSn();
        } elseif (!self::assertOrderSnUnique($orderSn, (int)($current['id'] ?? 0))) {
            return false;
        }

        return [
            'order' => [
                'tenant_id' => $tenantId,
                'order_sn' => $orderSn,
                'supplier_id' => (int)$vendor->id,
                'supplier_name' => (string)$vendor->supplier_name,
                'warehouse_id' => (int)$warehouse->id,
                'order_money' => self::money($orderMoney),
                'order_pay_money' => self::money($orderPayMoney),
                'order_arrears_money' => self::money($orderMoney - $orderPayMoney),
                'datetimesingle' => $datetimesingle,
                'status' => (int)($current['status'] ?? 1),
                'purpose_type' => trim((string)($params['purpose_type'] ?? $params['purpose'] ?? ($current['purpose_type'] ?? self::DEFAULT_PURPOSE_TYPE))),
                'remarks' => trim((string)($params['remarks'] ?? $params['remark'] ?? ($current['remarks'] ?? ''))),
                'admin_id' => $adminId,
                'idempotent_key' => $idempotentKey,
            ],
            'goods' => $goodsRows,
        ];
    }

    protected static function buildGoodsRows(array $goods, int $supplierId, int $datetimesingle): array|false
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

            $goodsModel = Goods::where('id', $goodsId)
                ->where('tenant_id', (int)(request()->tenantId ?? 0))
                ->findOrEmpty();
            if ($goodsModel->isEmpty()) {
                self::setError('商品不存在');
                return false;
            }
            if ((int)$goodsModel->is_disabled === 1) {
                self::setError('停用商品不可开进货单');
                return false;
            }

            $skuId = (int)($item['sku_id'] ?? 0);
            $skuName = '';
            $supplierRelationId = 0;
            $orderQty = round(max(0, (float)($item['order_qty'] ?? $item['number'] ?? 0)), 4);
            if ($orderQty <= 0) {
                self::setError('商品数量必须大于0');
                return false;
            }

            $baseUnitId = (int)($goodsModel->unit_id ?? 0);
            $baseUnitName = (string)($goodsModel->units ?? '');
            $orderUnitId = (int)($item['order_unit_id'] ?? $item['unit_id'] ?? $item['units_id'] ?? 0);
            $orderUnitName = trim((string)($item['order_unit_name'] ?? $item['purchase_unit_name'] ?? $item['units'] ?? $item['unit'] ?? ''));
            $conversionRate = '1.000000';
            $conversionSourceType = 'identity';
            $conversionEffectiveDate = null;
            $defaultPrice = $goodsModel->cost ?: $goodsModel->price;

            if ($skuId > 0) {
                $sku = GoodsSku::where('id', $skuId)
                    ->where('goods_id', $goodsId)
                    ->where('tenant_id', (int)(request()->tenantId ?? 0))
                    ->findOrEmpty();
                if ($sku->isEmpty()) {
                    self::setError('SKU不存在');
                    return false;
                }
                if ((int)$sku->status !== 1 || (int)$sku->purchase_status !== 1) {
                    self::setError('该SKU不可采购');
                    return false;
                }

                $relation = GoodsSupplierMatrixLogic::assertCanSupply($supplierId, $goodsId, $skuId);
                if ($relation === false) {
                    self::setError(GoodsSupplierMatrixLogic::getError());
                    return false;
                }

                $skuName = (string)$sku->sku_name;
                $supplierRelationId = (int)$relation->id;
                $baseUnitId = (int)($sku->base_unit_id ?? $baseUnitId);
                $baseUnitName = (string)($sku->base_unit_name ?: $baseUnitName);
                $orderUnitId = $orderUnitId ?: (int)($relation->purchase_unit_id ?? 0);
                $orderUnitName = $orderUnitName !== '' ? $orderUnitName : (string)($relation->purchase_unit_name ?? '');
                $defaultPrice = $relation->purchase_price ?: $defaultPrice;

                $sameUnit = ($orderUnitId > 0 && $baseUnitId > 0 && $orderUnitId === $baseUnitId)
                    || ($orderUnitName !== '' && $baseUnitName !== '' && $orderUnitName === $baseUnitName);
                if (!$sameUnit) {
                    if ($orderUnitId <= 0 || $baseUnitId <= 0) {
                        self::setError('SKU采购缺少单位ID，无法解析换算');
                        return false;
                    }
                    $conversion = UnitConversionLogic::resolveData(
                        $goodsId,
                        $skuId,
                        $supplierId,
                        $orderUnitId,
                        $baseUnitId,
                        date('Y-m-d', $datetimesingle)
                    );
                    if ($conversion === false) {
                        self::setError(UnitConversionLogic::getError());
                        return false;
                    }
                    $conversionRate = (string)$conversion['ratio'];
                    $conversionSourceType = (string)$conversion['source_type'];
                    $conversionEffectiveDate = $conversion['effective_date'] ?? null;
                }
            } elseif ($orderUnitName === '') {
                $orderUnitName = (string)($goodsModel->units ?? '');
            }

            $expectedBaseQty = self::decimal4($orderQty * (float)$conversionRate);
            $actualBaseQty = self::decimal4($item['actual_base_qty'] ?? $item['actual_qty'] ?? $expectedBaseQty);
            $lossBaseQty = self::decimal4(max(0, (float)$expectedBaseQty - (float)$actualBaseQty));
            $lossRate = (float)$expectedBaseQty > 0 ? number_format((float)$lossBaseQty / (float)$expectedBaseQty, 6, '.', '') : '0.000000';
            $stockNumber = $skuId > 0 ? $actualBaseQty : self::decimal4($orderQty);

            $price = self::money($item['price'] ?? $item['units_money'] ?? $defaultPrice);
            $amount = self::money((float)$stockNumber * (float)$price);
            $rows[] = [
                'tenant_id' => (int)(request()->tenantId ?? 0),
                'order_type' => self::ORDER_TYPE,
                'goods_id' => $goodsId,
                'sku_id' => $skuId,
                'sku_name' => $skuName,
                'supplier_relation_id' => $supplierRelationId,
                'name' => trim((string)($item['name'] ?? $item['product_name'] ?? $goodsModel->name)),
                'units' => $baseUnitName !== '' ? $baseUnitName : trim((string)($item['units'] ?? $item['unit'] ?? $goodsModel->units)),
                'order_unit_id' => $orderUnitId,
                'order_unit_name' => $orderUnitName,
                'order_qty' => number_format($orderQty, 4, '.', ''),
                'base_unit_id' => $baseUnitId,
                'base_unit_name' => $baseUnitName,
                'conversion_rate' => $conversionRate,
                'conversion_source_type' => $conversionSourceType,
                'conversion_effective_date' => $conversionEffectiveDate,
                'expected_base_qty' => $expectedBaseQty,
                'actual_base_qty' => $actualBaseQty,
                'loss_base_qty' => $lossBaseQty,
                'loss_rate' => $lossRate,
                'batch_id' => 0,
                'number' => $stockNumber,
                'price' => $price,
                'amount' => $amount,
                'remark' => trim((string)($item['remark'] ?? '')),
                'sort' => $index,
            ];
        }

        return $rows;
    }

    protected static function replaceGoods(int $orderId, array $rows): array
    {
        OrderGoods::where('order_id', $orderId)
            ->where('order_type', self::ORDER_TYPE)
            ->where('tenant_id', (int)(request()->tenantId ?? 0))
            ->delete();

        $created = [];
        foreach ($rows as $row) {
            $row['order_id'] = $orderId;
            $model = OrderGoods::create($row);
            $row['id'] = (int)$model->id;
            $created[] = $row;
        }

        return $created;
    }

    protected static function purchaseReturnedQtyMap(int $supplyOrderId): array
    {
        $rows = PurchaseReturnOrderDetail::where('original_supply_order_id', $supplyOrderId)
            ->where('tenant_id', (int)(request()->tenantId ?? 0))
            ->field('original_supply_order_list_id,SUM(return_num) as returned_num')
            ->group('original_supply_order_list_id')
            ->select()
            ->toArray();

        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['original_supply_order_list_id']] = (string)($row['returned_num'] ?? '0.0000');
        }

        return $map;
    }

    protected static function resolveWarehouse(mixed $warehouseId): ?Warehouse
    {
        if ($warehouseId === 'default') {
            $warehouse = Warehouse::where('name', '默认仓库')
                ->where('tenant_id', (int)(request()->tenantId ?? 0))
                ->findOrEmpty();
        } else {
            $warehouse = Warehouse::where('id', (int)$warehouseId)
                ->where('tenant_id', (int)(request()->tenantId ?? 0))
                ->findOrEmpty();
        }

        if ($warehouse->isEmpty()) {
            self::setError('仓库不存在');
            return null;
        }
        if ((int)$warehouse->is_enabled !== 1) {
            self::setError('停用仓库不可开进货单');
            return null;
        }

        return $warehouse;
    }

    protected static function generateOrderSn(): string
    {
        $tenantId = (int)(request()->tenantId ?? 0);
        $maxRetries = 3;
        for ($i = 0; $i < $maxRetries; $i++) {
            $sn = 'JHD' . date('YmdHis') . str_pad((string)mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
            $exists = SupplyOrder::where('tenant_id', $tenantId)
                ->where('order_sn', $sn)
                ->count();
            if ($exists == 0) {
                return $sn;
            }
            usleep(1000);
        }
        // 3次冲突后使用微秒级后缀
        return 'JHD' . date('YmdHis') . substr((string)((int)(microtime(true) * 10000)), -10);
    }

    protected static function assertOrderSnUnique(string $orderSn, int $ignoreId = 0): bool
    {
        $query = SupplyOrder::where('order_sn', $orderSn)
            ->where('tenant_id', (int)(request()->tenantId ?? 0));
        if ($ignoreId > 0) {
            $query->where('id', '<>', $ignoreId);
        }
        if ($query->count() > 0) {
            self::setError('进货单号已存在');
            return false;
        }
        return true;
    }

    protected static function formatGoodsRows(array $rows, array $returnedMap = []): array
    {
        return array_map(function ($row) use ($returnedMap) {
            $orderGoodsId = (int)($row['id'] ?? 0);
            $numberValue = (string)($row['number'] ?? 0);
            $number = self::quantityText($numberValue);
            $returnedNumber = (string)($returnedMap[$orderGoodsId] ?? '0.0000');
            $returnableNumber = bcsub($numberValue, $returnedNumber, 4);
            if (bccomp($returnableNumber, '0', 4) < 0) {
                $returnableNumber = '0.0000';
            }

            return [
                'id' => (int)($row['id'] ?? 0),
                'order_goods_id' => (int)($row['id'] ?? 0),
                'goods_id' => (int)($row['goods_id'] ?? 0),
                'sku_id' => (int)($row['sku_id'] ?? 0),
                'sku_name' => (string)($row['sku_name'] ?? ''),
                'supplier_relation_id' => (int)($row['supplier_relation_id'] ?? 0),
                'name' => (string)($row['name'] ?? ''),
                'product_name' => (string)($row['name'] ?? ''),
                'units' => (string)($row['units'] ?? ''),
                'unit' => (string)($row['units'] ?? ''),
                'order_unit_id' => (int)($row['order_unit_id'] ?? 0),
                'order_unit_name' => (string)($row['order_unit_name'] ?? ''),
                'order_unit' => (string)($row['order_unit_name'] ?? ''),
                'order_qty' => (string)($row['order_qty'] ?? $number),
                'base_unit_id' => (int)($row['base_unit_id'] ?? 0),
                'base_unit_name' => (string)($row['base_unit_name'] ?? ''),
                'base_unit' => (string)($row['base_unit_name'] ?? ''),
                'conversion_rate' => (string)($row['conversion_rate'] ?? '1.000000'),
                'conversion_source_type' => (string)($row['conversion_source_type'] ?? ''),
                'conversion_effective_date' => $row['conversion_effective_date'] ?? null,
                'expected_base_qty' => (string)($row['expected_base_qty'] ?? '0.0000'),
                'actual_base_qty' => (string)($row['actual_base_qty'] ?? $number),
                'loss_base_qty' => (string)($row['loss_base_qty'] ?? '0.0000'),
                'loss_rate' => (string)($row['loss_rate'] ?? '0.000000'),
                'batch_id' => (int)($row['batch_id'] ?? 0),
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

    protected static function decimal4(mixed $value): string
    {
        return number_format(max(0, (float)$value), 4, '.', '');
    }

    protected static function quantityText(mixed $value): string
    {
        $text = rtrim(rtrim(number_format((float)$value, 4, '.', ''), '0'), '.');
        return $text === '' ? '0' : $text;
    }
}
