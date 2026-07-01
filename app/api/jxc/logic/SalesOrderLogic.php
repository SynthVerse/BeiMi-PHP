<?php

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use app\common\model\jxc\Customer;
use app\common\model\jxc\Goods;
use app\common\model\jxc\OrderGoods;
use app\common\model\jxc\SalesOrder;
use app\common\model\jxc\Warehouse;
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

    public static function publish(array $params): array|false
    {
        // 幂等键检查（事务开始前）
        $idempotentKey = trim((string)($params['idempotent_key'] ?? ''));
        if ($idempotentKey !== '') {
            $tenantId = (int)(request()->tenantId ?? 0);
            $existing = SalesOrder::where('tenant_id', $tenantId)
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
            $order = SalesOrder::create($built['order']);
            self::replaceGoods((int)$order->id, $built['goods']);

            // === 库存出库 ===
            foreach ($built['goods'] as $row) {
                StockService::outbound(
                    (int)$built['order']['warehouse_id'],
                    (int)$row['goods_id'],
                    (string)$row['number'],
                    (int)$order->id,
                    'sales',
                    $built['order']['order_sn']
                );
            }

            // === 应收增加 ===
            $arrearsMoney = (string)$built['order']['order_arrears_money'];
            if (bccomp($arrearsMoney, '0', 2) > 0) {
                FinanceService::addReceivable(
                    (int)$built['order']['customer_id'],
                    $arrearsMoney,
                    (int)$order->id,
                    'sales',
                    $built['order']['order_sn']
                );
            }

            Db::commit();

            AuditService::log(
                AuditService::MODULE_SALES_ORDER,
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
        $order = SalesOrder::where('id', (int)$params['id'])
            ->where('tenant_id', (int)(request()->tenantId ?? 0))
            ->findOrEmpty();
        if ($order->isEmpty()) {
            self::setError('销售单不存在');
            return false;
        }

        $built = self::buildOrderData($params, $order->toArray());
        if ($built === false) {
            return false;
        }

        Db::startTrans();
        try {
            // === 回滚旧库存和旧应收 ===
            StockService::rollback((int)$order->id, 'sales');
            FinanceService::rollbackReceivable((int)$order->id, 'sales');

            $order->save($built['order']);
            self::replaceGoods((int)$order->id, $built['goods']);

            // === 重新出库 ===
            foreach ($built['goods'] as $row) {
                StockService::outbound(
                    (int)$built['order']['warehouse_id'],
                    (int)$row['goods_id'],
                    (string)$row['number'],
                    (int)$order->id,
                    'sales',
                    $order->order_sn
                );
            }

            // === 重新计算应收 ===
            $arrearsMoney = (string)$built['order']['order_arrears_money'];
            if (bccomp($arrearsMoney, '0', 2) > 0) {
                FinanceService::addReceivable(
                    (int)$built['order']['customer_id'],
                    $arrearsMoney,
                    (int)$order->id,
                    'sales',
                    $order->order_sn
                );
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
        $order = SalesOrder::findOrEmpty((int)$params['id']);
        if ($order->isEmpty()) {
            self::setError('销售单不存在');
            return false;
        }

        Db::startTrans();
        try {
            // === 回滚库存和应收 ===
            StockService::rollback((int)$order->id, 'sales');
            FinanceService::rollbackReceivable((int)$order->id, 'sales');

            $orderData = $order->toArray();
            OrderGoods::where('order_id', (int)$order->id)
                ->where('order_type', self::ORDER_TYPE)
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
        $order = SalesOrder::findOrEmpty((int)$params['id']);
        if ($order->isEmpty()) {
            return [];
        }

        $item = self::formatItem($order->toArray(), true);
        $item['goods'] = self::formatGoodsRows(OrderGoods::where('order_id', (int)$order->id)
            ->where('order_type', self::ORDER_TYPE)
            ->order(['sort' => 'asc', 'id' => 'asc'])
            ->select()
            ->toArray());

        return $item;
    }

    public static function statistics(array $params): array
    {
        $query = SalesOrder::field(['id', 'order_money', 'order_pay_money', 'order_arrears_money', 'datetimesingle']);
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
            'admin_id' => (int)($item['admin_id'] ?? 0),
            'create_time' => $item['create_time'] ?? '',
            'update_time' => $item['update_time'] ?? '',
        ];
    }

    protected static function buildOrderData(array $params, array $current = []): array|false
    {
        $customer = Customer::findOrEmpty((int)$params['customer_id']);
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
                'from_purchase_order_id' => (int)($params['from_purchase_order_id'] ?? ($current['from_purchase_order_id'] ?? 0)),
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

            $goodsModel = Goods::findOrEmpty($goodsId);
            if ($goodsModel->isEmpty()) {
                self::setError('商品不存在');
                return false;
            }
            if ((int)$goodsModel->is_disabled === 1) {
                self::setError('停用商品不可开销售单');
                return false;
            }

            $number = round(max(0, (float)($item['number'] ?? 0)), 4);
            if ($number <= 0) {
                self::setError('商品数量必须大于0');
                return false;
            }

            $price = self::money($item['price'] ?? $item['units_money'] ?? $goodsModel->price);
            $amount = bcmul((string)$number, (string)$price, 2);
            $rows[] = [
                'tenant_id' => (int)(request()->tenantId ?? 0),
                'order_type' => self::ORDER_TYPE,
                'goods_id' => $goodsId,
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
        $query = SalesOrder::where('order_sn', $orderSn);
        if ($ignoreId > 0) {
            $query->where('id', '<>', $ignoreId);
        }
        if ($query->count() > 0) {
            self::setError('销售单号已存在');
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
