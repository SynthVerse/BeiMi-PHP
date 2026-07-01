<?php

namespace app\api\jxc\logic;

use app\api\jxc\exception\BusinessException;
use app\common\logic\BaseLogic;
use app\common\model\jxc\Goods;
use app\common\model\jxc\OrderGoods;
use app\common\model\jxc\PayableFlow;
use app\common\model\jxc\PurchaseReturnOrder;
use app\common\model\jxc\PurchaseReturnOrderDetail;
use app\common\model\jxc\SupplyOrder;
use app\common\model\jxc\Vendor;
use app\common\model\jxc\Warehouse;
use think\facade\Db;
use think\facade\Log;

class PurchaseReturnOrderLogic extends BaseLogic
{
    private const ORDER_TYPE = 'purchase-return';
    private const DEFAULT_PURPOSE = '采购退货';
    private const DEFAULT_PURPOSE_TYPE = 'purchase-return';

    public static function publish(array $params): array|false
    {
        self::clearError();

        $idempotentKey = trim((string)($params['idempotent_key'] ?? ''));
        if ($idempotentKey !== '') {
            $existing = PurchaseReturnOrder::where('tenant_id', (int)(request()->tenantId ?? 0))
                ->where('idempotent_key', $idempotentKey)
                ->find();
            if ($existing) {
                return [
                    'id' => (int)$existing->id,
                    'order_sn' => (string)$existing->order_sn,
                    'sn' => (string)$existing->order_sn,
                ];
            }
        }

        $built = self::buildOrderData($params);
        if ($built === false) {
            return false;
        }

        Db::startTrans();
        try {
            $order = PurchaseReturnOrder::create($built['order']);
            self::replaceDetails((int)$order->id, $built['goods']);

            foreach ($built['goods'] as $row) {
                $stockOk = StockService::outbound(
                    (int)$built['order']['warehouse_id'],
                    (int)$row['goods_id'],
                    (string)$row['return_num'],
                    (int)$order->id,
                    self::ORDER_TYPE,
                    (string)$order->order_sn,
                    '采购退货-' . (string)$row['goods_name'],
                    (int)($row['sku_id'] ?? 0)
                );
                if (!$stockOk) {
                    self::throwFailure('库存出库失败', 'RETURN_STOCK_FAILED');
                }
            }

            $orderMoney = (string)$built['order']['order_money'];
            if (bccomp($orderMoney, '0', 2) > 0) {
                $financeOk = FinanceService::reducePayable(
                    (int)$built['order']['supplier_id'],
                    $orderMoney,
                    (int)$order->id,
                    self::ORDER_TYPE,
                    (string)$order->order_sn,
                    '采购退货应付减少-' . (string)$order->order_sn,
                    PayableFlow::TYPE_RETURN_REDUCE
                );
                if (!$financeOk) {
                    self::throwFailure('应付冲减失败', 'RETURN_FINANCE_FAILED');
                }
            }

            self::refreshSupplyReturnStatus((int)$built['order']['original_supply_order_id']);
            Db::commit();

            AuditService::log(
                AuditService::MODULE_PURCHASE_RETURN_ORDER,
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
            Log::error('采购退货单创建失败: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            self::failWithCode('操作失败，请稍后重试', 'RETURN_FINANCE_FAILED');
            return false;
        }
    }

    public static function edit(array $params): array|false
    {
        self::clearError();
        $order = PurchaseReturnOrder::where('id', (int)$params['id'])
            ->where('tenant_id', (int)(request()->tenantId ?? 0))
            ->findOrEmpty();
        if ($order->isEmpty()) {
            self::failWithCode('采购退货单不存在', 'RETURN_ORDER_NOT_FOUND');
            return false;
        }

        $oldOriginalOrderId = (int)$order->original_supply_order_id;
        $built = self::buildOrderData($params, $order->toArray());
        if ($built === false) {
            return false;
        }

        Db::startTrans();
        try {
            if (!StockService::rollback((int)$order->id, self::ORDER_TYPE)) {
                self::throwFailure('旧库存回滚失败', 'RETURN_STOCK_FAILED');
            }
            if (!FinanceService::rollbackPayable((int)$order->id, self::ORDER_TYPE)) {
                self::throwFailure('旧应付回滚失败', 'RETURN_FINANCE_FAILED');
            }

            $order->save($built['order']);
            self::replaceDetails((int)$order->id, $built['goods']);

            foreach ($built['goods'] as $row) {
                $stockOk = StockService::outbound(
                    (int)$built['order']['warehouse_id'],
                    (int)$row['goods_id'],
                    (string)$row['return_num'],
                    (int)$order->id,
                    self::ORDER_TYPE,
                    (string)$order->order_sn,
                    '采购退货-' . (string)$row['goods_name'],
                    (int)($row['sku_id'] ?? 0)
                );
                if (!$stockOk) {
                    self::throwFailure('库存出库失败', 'RETURN_STOCK_FAILED');
                }
            }

            $orderMoney = (string)$built['order']['order_money'];
            if (bccomp($orderMoney, '0', 2) > 0) {
                $financeOk = FinanceService::reducePayable(
                    (int)$built['order']['supplier_id'],
                    $orderMoney,
                    (int)$order->id,
                    self::ORDER_TYPE,
                    (string)$order->order_sn,
                    '采购退货应付减少-' . (string)$order->order_sn,
                    PayableFlow::TYPE_RETURN_REDUCE
                );
                if (!$financeOk) {
                    self::throwFailure('应付冲减失败', 'RETURN_FINANCE_FAILED');
                }
            }

            self::refreshSupplyReturnStatus($oldOriginalOrderId);
            if ((int)$built['order']['original_supply_order_id'] !== $oldOriginalOrderId) {
                self::refreshSupplyReturnStatus((int)$built['order']['original_supply_order_id']);
            }
            Db::commit();

            $result = self::detail(['id' => (int)$order->id]);
            AuditService::log(
                AuditService::MODULE_PURCHASE_RETURN_ORDER,
                AuditService::ACTION_EDIT,
                (int)$order->id,
                (string)$order->order_sn,
                $order->toArray(),
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
            Log::error('采购退货单编辑失败: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            self::failWithCode('操作失败，请稍后重试', 'RETURN_FINANCE_FAILED');
            return false;
        }
    }

    public static function remove(array $params): array|false
    {
        self::clearError();
        $order = PurchaseReturnOrder::where('id', (int)$params['id'])
            ->where('tenant_id', (int)(request()->tenantId ?? 0))
            ->findOrEmpty();
        if ($order->isEmpty()) {
            self::failWithCode('采购退货单不存在', 'RETURN_ORDER_NOT_FOUND');
            return false;
        }

        $orderData = $order->toArray();
        $originalOrderId = (int)$order->original_supply_order_id;
        Db::startTrans();
        try {
            if (!StockService::rollback((int)$order->id, self::ORDER_TYPE)) {
                self::throwFailure('旧库存回滚失败', 'RETURN_STOCK_FAILED');
            }
            if (!FinanceService::rollbackPayable((int)$order->id, self::ORDER_TYPE)) {
                self::throwFailure('旧应付回滚失败', 'RETURN_FINANCE_FAILED');
            }
            PurchaseReturnOrderDetail::where('purchase_return_order_id', (int)$order->id)
                ->where('tenant_id', (int)(request()->tenantId ?? 0))
                ->delete();
            $order->delete();
            self::refreshSupplyReturnStatus($originalOrderId);
            Db::commit();

            AuditService::log(
                AuditService::MODULE_PURCHASE_RETURN_ORDER,
                AuditService::ACTION_DELETE,
                (int)$params['id'],
                (string)($orderData['order_sn'] ?? ''),
                $orderData,
                null
            );

            return ['id' => (int)$params['id']];
        } catch (BusinessException $e) {
            Db::rollback();
            self::setError($e->getMessage());
            self::ensureFailureCode('RETURN_FINANCE_FAILED');
            return false;
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('采购退货单删除失败: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            self::failWithCode('操作失败，请稍后重试', 'RETURN_FINANCE_FAILED');
            return false;
        }
    }

    public static function detail(array $params): array|false
    {
        self::clearError();
        $order = PurchaseReturnOrder::where('id', (int)$params['id'])
            ->where('tenant_id', (int)(request()->tenantId ?? 0))
            ->findOrEmpty();
        if ($order->isEmpty()) {
            self::failWithCode('采购退货单不存在', 'RETURN_ORDER_NOT_FOUND');
            return false;
        }

        $item = self::formatItem($order->toArray(), true);
        $item['goods'] = self::formatDetailRows(PurchaseReturnOrderDetail::where('purchase_return_order_id', (int)$order->id)
            ->where('tenant_id', (int)(request()->tenantId ?? 0))
            ->order(['sort' => 'asc', 'id' => 'asc'])
            ->select()
            ->toArray());

        return $item;
    }

    public static function formatList(array $items): array
    {
        return array_map(fn($item) => self::formatItem($item), $items);
    }

    public static function formatItem(array $item, bool $includeOriginal = false): array
    {
        $datetimesingle = (int)($item['datetimesingle'] ?? 0);
        $result = [
            'id' => (int)($item['id'] ?? 0),
            'order_sn' => (string)($item['order_sn'] ?? ''),
            'sn' => (string)($item['order_sn'] ?? ''),
            'original_supply_order_id' => (int)($item['original_supply_order_id'] ?? 0),
            'original_order_id' => (int)($item['original_supply_order_id'] ?? 0),
            'original_order_sn' => (string)($item['original_order_sn'] ?? ''),
            'supplier_id' => (int)($item['supplier_id'] ?? 0),
            'supplier_name' => (string)($item['supplier_name'] ?? ''),
            'warehouse_id' => (int)($item['warehouse_id'] ?? 0),
            'order_money' => self::money($item['order_money'] ?? 0),
            'return_reason' => (string)($item['return_reason'] ?? ''),
            'datetimesingle' => $datetimesingle,
            'createdate' => self::dateText($datetimesingle ?: ($item['create_time'] ?? 0)),
            'status' => (int)($item['status'] ?? 1),
            'status_label' => '正常',
            'purpose' => self::DEFAULT_PURPOSE,
            'purpose_type' => self::DEFAULT_PURPOSE_TYPE,
            'remarks' => (string)($item['remarks'] ?? ''),
            'remark' => (string)($item['remarks'] ?? ''),
            'admin_id' => (int)($item['admin_id'] ?? 0),
            'create_time' => $item['create_time'] ?? '',
            'update_time' => $item['update_time'] ?? '',
        ];

        if ($includeOriginal && (int)$result['original_supply_order_id'] > 0) {
            $original = SupplyOrder::where('id', (int)$result['original_supply_order_id'])
                ->where('tenant_id', (int)(request()->tenantId ?? 0))
                ->findOrEmpty();
            $result['original_supply_order'] = $original->isEmpty() ? null : [
                'id' => (int)$original->id,
                'order_sn' => (string)$original->order_sn,
                'return_status' => (int)($original->return_status ?? 0),
                'return_status_label' => SupplyOrder::returnStatusLabel((int)($original->return_status ?? 0)),
            ];
        }

        return $result;
    }

    protected static function buildOrderData(array $params, array $current = []): array|false
    {
        $originalOrderId = (int)($params['original_order_id'] ?? $params['original_supply_order_id'] ?? ($current['original_supply_order_id'] ?? 0));
        if ($originalOrderId <= 0) {
            self::failWithCode('原进货单ID不能为空', 'RETURN_ORIGINAL_REQUIRED');
            return false;
        }

        $original = SupplyOrder::where('id', $originalOrderId)
            ->where('tenant_id', (int)(request()->tenantId ?? 0))
            ->findOrEmpty();
        if ($original->isEmpty()) {
            self::failWithCode('原进货单不存在', 'RETURN_ORIGINAL_NOT_FOUND');
            return false;
        }

        $supplierId = (int)($params['supplier_id'] ?? 0);
        if ($supplierId > 0 && $supplierId !== (int)$original->supplier_id) {
            self::failWithCode('供应商与原进货单不匹配', 'RETURN_ORIGINAL_NOT_FOUND');
            return false;
        }

        $vendor = Vendor::where('id', (int)$original->supplier_id)
            ->where('tenant_id', (int)(request()->tenantId ?? 0))
            ->findOrEmpty();
        if ($vendor->isEmpty()) {
            self::failWithCode('供应商不存在', 'RETURN_ORIGINAL_NOT_FOUND');
            return false;
        }
        if ((int)$vendor->is_disabled === 1) {
            self::failWithCode('停用供应商不可退货', 'RETURN_ORIGINAL_NOT_FOUND');
            return false;
        }

        $warehouse = Warehouse::where('id', (int)$original->warehouse_id)
            ->where('tenant_id', (int)(request()->tenantId ?? 0))
            ->findOrEmpty();
        if ($warehouse->isEmpty()) {
            self::failWithCode('原进货单仓库不存在', 'RETURN_ORIGINAL_NOT_FOUND');
            return false;
        }

        $details = self::buildDetailRows($params['goods'] ?? [], $originalOrderId, (int)($current['id'] ?? 0));
        if ($details === false) {
            return false;
        }

        $orderMoney = array_reduce($details, fn($sum, $row) => bcadd((string)$sum, (string)$row['amount'], 2), '0.00');
        $orderSn = trim((string)($params['order_sn'] ?? ($current['order_sn'] ?? '')));
        if ($orderSn === '') {
            $orderSn = self::generateOrderSn();
        } elseif (!self::assertOrderSnUnique($orderSn, (int)($current['id'] ?? 0))) {
            return false;
        }

        return [
            'order' => [
                'tenant_id' => (int)(request()->tenantId ?? 0),
                'order_sn' => $orderSn,
                'original_supply_order_id' => $originalOrderId,
                'original_order_sn' => (string)($original->order_sn ?? ''),
                'supplier_id' => (int)$original->supplier_id,
                'supplier_name' => (string)$original->supplier_name,
                'warehouse_id' => (int)$original->warehouse_id,
                'order_money' => self::money($orderMoney),
                'return_reason' => trim((string)($params['return_reason'] ?? ($current['return_reason'] ?? ''))),
                'datetimesingle' => (int)($params['datetimesingle'] ?? ($current['datetimesingle'] ?? time())),
                'status' => (int)($current['status'] ?? 1),
                'remarks' => trim((string)($params['remarks'] ?? $params['remark'] ?? ($current['remarks'] ?? ''))),
                'admin_id' => (int)(request()->adminId ?? 0),
                'idempotent_key' => trim((string)($params['idempotent_key'] ?? '')),
            ],
            'goods' => $details,
        ];
    }

    protected static function buildDetailRows(array $goods, int $originalOrderId, int $ignoreReturnOrderId = 0): array|false
    {
        if (empty($goods)) {
            self::failWithCode('请选择商品', 'RETURN_ITEMS_EMPTY');
            return false;
        }

        $originalRows = OrderGoods::where('order_id', $originalOrderId)
            ->where('order_type', 'supply')
            ->where('tenant_id', (int)(request()->tenantId ?? 0))
            ->select()
            ->toArray();
        $byId = [];
        $byGoods = [];
        foreach ($originalRows as $row) {
            $byId[(int)$row['id']] = $row;
            $key = (int)$row['goods_id'] . ':' . (int)($row['sku_id'] ?? 0);
            $byGoods[$key] = $row;
        }

        $returnedMap = self::returnedSupplyQtyMap($originalOrderId, $ignoreReturnOrderId);
        $rows = [];
        foreach (array_values($goods) as $index => $item) {
            $originLineId = (int)($item['original_supply_order_list_id'] ?? $item['original_order_goods_id'] ?? $item['order_goods_id'] ?? 0);
            $goodsId = (int)($item['goods_id'] ?? $item['id'] ?? 0);
            $skuId = (int)($item['sku_id'] ?? 0);
            $origin = $originLineId > 0 ? ($byId[$originLineId] ?? null) : ($byGoods[$goodsId . ':' . $skuId] ?? null);
            if (!$origin) {
                self::failWithCode('退货商品不属于原进货单', 'RETURN_ORIGINAL_NOT_FOUND');
                return false;
            }

            $returnNum = round((float)($item['return_num'] ?? $item['number'] ?? 0), 4);
            if ($returnNum <= 0) {
                self::failWithCode('商品数量必须大于0', 'RETURN_QTY_INVALID');
                return false;
            }

            $originLineId = (int)$origin['id'];
            $originalQty = (string)$origin['number'];
            $returnedQty = (string)($returnedMap[$originLineId] ?? '0.0000');
            $availableQty = bcsub($originalQty, $returnedQty, 4);
            if (bccomp((string)$returnNum, $availableQty, 4) > 0) {
                self::failWithCode('退货数量超过可退数量', 'RETURN_QTY_EXCEEDS_AVAILABLE');
                return false;
            }

            $goodsModel = Goods::where('id', (int)$origin['goods_id'])
                ->where('tenant_id', (int)(request()->tenantId ?? 0))
                ->findOrEmpty();
            if ($goodsModel->isEmpty()) {
                self::failWithCode('商品不存在', 'RETURN_ORIGINAL_NOT_FOUND');
                return false;
            }

            $price = self::money($item['price'] ?? $origin['price'] ?? 0);
            $rows[] = [
                'tenant_id' => (int)(request()->tenantId ?? 0),
                'original_supply_order_id' => $originalOrderId,
                'original_supply_order_list_id' => $originLineId,
                'goods_id' => (int)$origin['goods_id'],
                'sku_id' => (int)($origin['sku_id'] ?? 0),
                'goods_name' => (string)($origin['name'] ?? $goodsModel->name),
                'unit_name' => (string)($origin['units'] ?? $goodsModel->units),
                'original_num' => number_format((float)$originalQty, 4, '.', ''),
                'return_num' => number_format($returnNum, 4, '.', ''),
                'price' => $price,
                'amount' => self::money($returnNum * (float)$price),
                'remark' => trim((string)($item['remark'] ?? '')),
                'sort' => $index,
            ];
        }

        return $rows;
    }

    protected static function replaceDetails(int $orderId, array $rows): void
    {
        PurchaseReturnOrderDetail::where('purchase_return_order_id', $orderId)
            ->where('tenant_id', (int)(request()->tenantId ?? 0))
            ->delete();

        foreach ($rows as $row) {
            $row['purchase_return_order_id'] = $orderId;
            PurchaseReturnOrderDetail::create($row);
        }
    }

    protected static function returnedSupplyQtyMap(int $originalOrderId, int $ignoreReturnOrderId = 0): array
    {
        $query = PurchaseReturnOrderDetail::where('original_supply_order_id', $originalOrderId)
            ->where('tenant_id', (int)(request()->tenantId ?? 0));
        if ($ignoreReturnOrderId > 0) {
            $query->where('purchase_return_order_id', '<>', $ignoreReturnOrderId);
        }
        $rows = $query->field('original_supply_order_list_id,SUM(return_num) as returned_num')
            ->group('original_supply_order_list_id')
            ->select()
            ->toArray();

        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['original_supply_order_list_id']] = (string)$row['returned_num'];
        }
        return $map;
    }

    protected static function refreshSupplyReturnStatus(int $supplyOrderId): void
    {
        if ($supplyOrderId <= 0) {
            return;
        }

        $originalRows = OrderGoods::where('order_id', $supplyOrderId)
            ->where('order_type', 'supply')
            ->where('tenant_id', (int)(request()->tenantId ?? 0))
            ->select()
            ->toArray();
        if (empty($originalRows)) {
            SupplyOrder::where('id', $supplyOrderId)
                ->where('tenant_id', (int)(request()->tenantId ?? 0))
                ->update(['return_status' => SupplyOrder::RETURN_STATUS_NONE]);
            return;
        }

        $returnedMap = self::returnedSupplyQtyMap($supplyOrderId);
        $hasReturned = false;
        $allReturned = true;
        foreach ($originalRows as $row) {
            $originLineId = (int)$row['id'];
            $returnedQty = (string)($returnedMap[$originLineId] ?? '0.0000');
            if (bccomp($returnedQty, '0', 4) > 0) {
                $hasReturned = true;
            }
            if (bccomp($returnedQty, (string)$row['number'], 4) < 0) {
                $allReturned = false;
            }
        }

        $status = !$hasReturned
            ? SupplyOrder::RETURN_STATUS_NONE
            : ($allReturned ? SupplyOrder::RETURN_STATUS_FULL : SupplyOrder::RETURN_STATUS_PARTIAL);
        SupplyOrder::where('id', $supplyOrderId)
            ->where('tenant_id', (int)(request()->tenantId ?? 0))
            ->update(['return_status' => $status]);
    }

    protected static function formatDetailRows(array $rows): array
    {
        return array_map(function ($row) {
            $returnNum = rtrim(rtrim(number_format((float)($row['return_num'] ?? 0), 4, '.', ''), '0'), '.');
            return [
                'id' => (int)($row['id'] ?? 0),
                'original_supply_order_id' => (int)($row['original_supply_order_id'] ?? 0),
                'original_supply_order_list_id' => (int)($row['original_supply_order_list_id'] ?? 0),
                'order_goods_id' => (int)($row['original_supply_order_list_id'] ?? 0),
                'goods_id' => (int)($row['goods_id'] ?? 0),
                'sku_id' => (int)($row['sku_id'] ?? 0),
                'goods_name' => (string)($row['goods_name'] ?? ''),
                'name' => (string)($row['goods_name'] ?? ''),
                'product_name' => (string)($row['goods_name'] ?? ''),
                'unit_name' => (string)($row['unit_name'] ?? ''),
                'units' => (string)($row['unit_name'] ?? ''),
                'unit' => (string)($row['unit_name'] ?? ''),
                'original_num' => (string)($row['original_num'] ?? '0.0000'),
                'return_num' => $returnNum === '' ? '0' : $returnNum,
                'number' => $returnNum === '' ? '0' : $returnNum,
                'price' => self::money($row['price'] ?? 0),
                'units_money' => self::money($row['price'] ?? 0),
                'amount' => self::money($row['amount'] ?? 0),
                'remark' => (string)($row['remark'] ?? ''),
                'sort' => (int)($row['sort'] ?? 0),
            ];
        }, $rows);
    }

    protected static function generateOrderSn(): string
    {
        $tenantId = (int)(request()->tenantId ?? 0);
        for ($i = 0; $i < 3; $i++) {
            $sn = 'CGTH' . date('YmdHis') . str_pad((string)mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
            $exists = PurchaseReturnOrder::where('tenant_id', $tenantId)
                ->where('order_sn', $sn)
                ->count();
            if ($exists == 0) {
                return $sn;
            }
            usleep(1000);
        }
        return 'CGTH' . date('YmdHis') . substr((string)((int)(microtime(true) * 10000)), -10);
    }

    protected static function assertOrderSnUnique(string $orderSn, int $ignoreId = 0): bool
    {
        $query = PurchaseReturnOrder::where('order_sn', $orderSn)
            ->where('tenant_id', (int)(request()->tenantId ?? 0));
        if ($ignoreId > 0) {
            $query->where('id', '<>', $ignoreId);
        }
        if ($query->count() > 0) {
            self::failWithCode('采购退货单号已存在', 'RETURN_ORIGINAL_NOT_FOUND');
            return false;
        }
        return true;
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

    protected static function throwFailure(string $message, string $errorCode): void
    {
        self::failWithCode($message, $errorCode);
        throw new BusinessException($message);
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
