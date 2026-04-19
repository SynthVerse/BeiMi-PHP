<?php

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use app\common\model\jxc\Customer;
use app\common\model\jxc\Goods;
use app\common\model\jxc\OrderGoods;
use app\common\model\jxc\PurchaseOrder;
use app\common\model\jxc\Warehouse;
use think\facade\Db;

class PurchaseOrderLogic extends BaseLogic
{
    private const ORDER_TYPE        = 'purchase';
    private const DEFAULT_PURPOSE   = '客户订货';
    private const DEFAULT_PURPOSE_TYPE = 'purchase';

    // =========================================================
    // 公开方法
    // =========================================================

    public static function add(array $params): array|false
    {
        // 幂等键检查（事务开始前）
        $idempotentKey = trim((string)($params['idempotent_key'] ?? ''));
        if ($idempotentKey !== '') {
            $tenantId = (int)(request()->tenantId ?? 0);
            $existing = PurchaseOrder::where('tenant_id', $tenantId)
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
            $order = PurchaseOrder::create($built['order']);
            self::replaceGoods((int)$order->id, $built['goods']);

            Db::commit();

            return [
                'id'       => (int)$order->id,
                'order_sn' => (string)$order->order_sn,
            ];
        } catch (\Throwable $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
    }

    public static function edit(array $params): array|false
    {
        $order = PurchaseOrder::findOrEmpty((int)$params['id']);
        if ($order->isEmpty()) {
            self::setError('订货单不存在');
            return false;
        }

        $currentStatus = (int)$order->status;
        if (!in_array($currentStatus, [PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_SENT])) {
            self::setError('只有草稿或已发送状态的订货单可以编辑');
            return false;
        }

        $built = self::buildOrderData($params, $order->toArray());
        if ($built === false) {
            return false;
        }

        Db::startTrans();
        try {
            $order->save($built['order']);
            self::replaceGoods((int)$order->id, $built['goods']);

            Db::commit();

            return self::detail(['id' => (int)$order->id]);
        } catch (\Throwable $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
    }

    public static function remove(array $params): array|false
    {
        $order = PurchaseOrder::findOrEmpty((int)$params['id']);
        if ($order->isEmpty()) {
            self::setError('订货单不存在');
            return false;
        }

        if ((int)$order->status !== PurchaseOrder::STATUS_DRAFT) {
            self::setError('只有草稿状态的订货单可以删除');
            return false;
        }

        Db::startTrans();
        try {
            OrderGoods::where('order_id', (int)$order->id)
                ->where('order_type', self::ORDER_TYPE)
                ->delete();
            $order->delete();

            Db::commit();

            return [
                'id' => (int)$params['id'],
            ];
        } catch (\Throwable $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
    }

    public static function detail(array $params): array
    {
        $order = PurchaseOrder::findOrEmpty((int)$params['id']);
        if ($order->isEmpty()) {
            return [];
        }

        $item = self::formatItem($order->toArray(), true);
        $item['goods'] = self::formatGoodsRows(
            OrderGoods::where('order_id', (int)$order->id)
                ->where('order_type', self::ORDER_TYPE)
                ->order(['sort' => 'asc', 'id' => 'asc'])
                ->select()
                ->toArray()
        );

        return $item;
    }

    public static function confirm(array $params): array|false
    {
        $order = PurchaseOrder::findOrEmpty((int)$params['id']);
        if ($order->isEmpty()) {
            self::setError('订货单不存在');
            return false;
        }

        $currentStatus = (int)$order->status;

        // 如果指定了目标状态则使用，否则自动推进到下一状态
        if (!empty($params['target_status'])) {
            $newStatus = (int)$params['target_status'];
        } else {
            // 自动推进：按照状态顺序 draft→sent→received→delivered→completed
            $progressMap = [
                PurchaseOrder::STATUS_DRAFT     => PurchaseOrder::STATUS_SENT,
                PurchaseOrder::STATUS_SENT      => PurchaseOrder::STATUS_RECEIVED,
                PurchaseOrder::STATUS_RECEIVED  => PurchaseOrder::STATUS_DELIVERED,
                PurchaseOrder::STATUS_DELIVERED => PurchaseOrder::STATUS_COMPLETED,
            ];
            if (!isset($progressMap[$currentStatus])) {
                self::setError('当前状态无法推进');
                return false;
            }
            $newStatus = $progressMap[$currentStatus];
        }

        if (!PurchaseOrder::canTransitionTo($currentStatus, $newStatus)) {
            self::setError(sprintf(
                '不允许从 %s 转移到 %s',
                PurchaseOrder::getStatusText($currentStatus),
                PurchaseOrder::getStatusText($newStatus)
            ));
            return false;
        }

        $order->save(['status' => $newStatus]);

        return self::detail(['id' => (int)$order->id]);
    }

    public static function cancel(array $params): array|false
    {
        $order = PurchaseOrder::findOrEmpty((int)$params['id']);
        if ($order->isEmpty()) {
            self::setError('订货单不存在');
            return false;
        }

        $currentStatus = (int)$order->status;
        if (in_array($currentStatus, [PurchaseOrder::STATUS_COMPLETED, PurchaseOrder::STATUS_CANCELLED])) {
            self::setError('已完成或已取消的订货单不可再取消');
            return false;
        }

        $cancelReason = trim((string)($params['cancel_reason'] ?? ''));

        $order->save([
            'status'        => PurchaseOrder::STATUS_CANCELLED,
            'cancel_reason' => $cancelReason,
        ]);

        return self::detail(['id' => (int)$order->id]);
    }

    public static function convertToSalesOrder(array $params): array|false
    {
        $order = PurchaseOrder::findOrEmpty((int)$params['id']);
        if ($order->isEmpty()) {
            self::setError('订货单不存在');
            return false;
        }

        $currentStatus = (int)$order->status;
        if (!in_array($currentStatus, [PurchaseOrder::STATUS_SENT, PurchaseOrder::STATUS_RECEIVED])) {
            self::setError('只有已发送或已收货状态的订货单可以转为销售单');
            return false;
        }

        // 读取订货单商品明细
        $goodsRows = OrderGoods::where('order_id', (int)$order->id)
            ->where('order_type', self::ORDER_TYPE)
            ->order(['sort' => 'asc', 'id' => 'asc'])
            ->select()
            ->toArray();

        if (empty($goodsRows)) {
            self::setError('订货单没有商品明细');
            return false;
        }

        // 构建销售单商品参数
        $salesGoods = array_map(function ($row) {
            return [
                'goods_id' => (int)$row['goods_id'],
                'name'     => (string)$row['name'],
                'units'    => (string)$row['units'],
                'number'   => (float)$row['number'],
                'price'    => (float)$row['price'],
            ];
        }, $goodsRows);

        // 决定仓库ID
        $warehouseId = !empty($params['warehouse_id']) ? $params['warehouse_id'] : $order->warehouse_id;

        // 构建销售单参数
        $salesParams = [
            'customer_id'     => (int)$order->customer_id,
            'warehouse_id'    => $warehouseId,
            'goods'           => $salesGoods,
            'order_pay_money' => (float)$order->order_pay_money,
            'datetimesingle'  => time(),
            'remarks'         => '由订货单 ' . $order->order_sn . ' 转入',
        ];

        Db::startTrans();
        try {
            $salesResult = SalesOrderLogic::publish($salesParams);
            if ($salesResult === false) {
                self::setError(SalesOrderLogic::getError());
                Db::rollback();
                return false;
            }

            // 更新订货单状态为已完成
            $order->save(['status' => PurchaseOrder::STATUS_COMPLETED]);

            Db::commit();

            return [
                'sales_order_id' => (int)$salesResult['id'],
                'sales_order_sn' => (string)$salesResult['order_sn'],
            ];
        } catch (\Throwable $e) {
            Db::rollback();
            self::setError($e->getMessage());
            return false;
        }
    }

    public static function parsePastedText(array $params): array
    {
        $text = trim((string)($params['pastedText'] ?? ''));
        if ($text === '') {
            return ['goods' => [], 'customerName' => '', 'confidence' => 0];
        }

        $lines = preg_split('/[\r\n]+/', $text);
        $goodsList = [];
        $customerName = '';
        $matchedCount = 0;

        // 尝试从第一行提取客户名称
        if (!empty($lines[0])) {
            $firstLine = trim($lines[0]);
            // 如果第一行不像商品行，尝试作为客户名称
            if (!preg_match('/\d+/', $firstLine) || mb_strlen($firstLine) > 20) {
                $customerName = $firstLine;
            }
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // 支持多种分隔符：制表符、多空格、中文空格
            $line = preg_replace('/[\x{3000}\s]+/u', ' ', $line);
            $parts = preg_split('/[\s\t,，]+/', $line, -1, PREG_SPLIT_NO_EMPTY);

            if (count($parts) < 2) {
                continue;
            }

            // 解析逻辑：名称 数量 [单位] [单价]
            $name   = '';
            $number = 0.0;
            $units  = '';
            $price  = 0.0;

            // 第一个非数字串作为商品名称
            $nameIdx = -1;
            foreach ($parts as $i => $part) {
                if (!is_numeric($part)) {
                    $name = $part;
                    $nameIdx = $i;
                    break;
                }
            }

            if ($name === '') {
                continue;
            }

            // 剩余部分：寻找数量（第一个数字）、单位（紧跟数字后的非数字串）、单价
            $numericParts = [];
            $textParts    = [];
            foreach ($parts as $i => $part) {
                if ($i === $nameIdx) {
                    continue;
                }
                if (is_numeric($part)) {
                    $numericParts[] = (float)$part;
                } else {
                    $textParts[] = $part;
                }
            }

            // 尝试用正则从原始行中提取 "数量+单位+价格" 格式
            // 格式：数量[单位] 价格 或 数量 单位 价格
            if (preg_match('/(\d+(?:\.\d+)?)\s*([^\d\s]*?)\s+(\d+(?:\.\d+)?)\s*元?$/u', $line, $m)) {
                $number = (float)$m[1];
                $units  = $m[2];
                $price  = (float)$m[3];
            } elseif (count($numericParts) >= 2) {
                $number = $numericParts[0];
                $price  = $numericParts[1];
                $units  = $textParts[0] ?? '';
            } elseif (count($numericParts) === 1) {
                $number = $numericParts[0];
                $units  = $textParts[0] ?? '';
            } else {
                continue;
            }

            if ($number <= 0) {
                continue;
            }

            $amount = bcmul((string)$number, (string)$price, 2);

            // 在数据库中搜索匹配的商品
            $goodsId = 0;
            $matched = false;
            if ($name !== '') {
                $goodsModel = Goods::whereLike('name', '%' . $name . '%')
                    ->where('is_disabled', 0)
                    ->findOrEmpty();
                if (!$goodsModel->isEmpty()) {
                    $goodsId = (int)$goodsModel->id;
                    $name    = (string)$goodsModel->name;
                    $units   = $units ?: (string)$goodsModel->units;
                    $price   = $price > 0 ? $price : (float)$goodsModel->price;
                    $amount  = bcmul((string)$number, (string)$price, 2);
                    $matched = true;
                    $matchedCount++;
                }
            }

            $goodsList[] = [
                'goods_id' => $goodsId,
                'name'     => $name,
                'units'    => $units,
                'number'   => $number,
                'price'    => self::money($price),
                'amount'   => $amount,
                'matched'  => $matched,
            ];
        }

        $confidence = empty($goodsList) ? 0 : (int)round($matchedCount / count($goodsList) * 100);

        return [
            'goods'        => $goodsList,
            'customerName' => $customerName,
            'confidence'   => $confidence,
        ];
    }

    public static function statistics(array $params): array
    {
        $baseQuery = PurchaseOrder::field(['id', 'order_money', 'order_pay_money', 'status', 'datetimesingle']);
        self::applyTimeRange($baseQuery, $params);

        $allOrders = $baseQuery->select()->toArray();

        $totalOrders  = count($allOrders);
        $totalAmount  = '0.00';
        $statusCounts = [
            'draft'     => 0,
            'sent'      => 0,
            'received'  => 0,
            'delivered' => 0,
            'completed' => 0,
            'cancelled' => 0,
        ];

        foreach ($allOrders as $row) {
            $totalAmount = bcadd($totalAmount, (string)$row['order_money'], 2);
            $statusText  = PurchaseOrder::getStatusText((int)$row['status']);
            if (isset($statusCounts[$statusText])) {
                $statusCounts[$statusText]++;
            }
        }

        return [
            'total_orders'  => $totalOrders,
            'total_amount'  => $totalAmount,
            'status_counts' => $statusCounts,
        ];
    }

    // =========================================================
    // 格式化方法
    // =========================================================

    public static function formatList(array $items): array
    {
        if (empty($items)) {
            return [];
        }

        $customerIds  = array_values(array_unique(array_filter(array_map(fn($item) => (int)($item['customer_id'] ?? 0), $items))));
        $warehouseIds = array_values(array_unique(array_filter(array_map(fn($item) => (int)($item['warehouse_id'] ?? 0), $items))));

        $customerRows  = empty($customerIds) ? [] : Customer::whereIn('id', $customerIds)->select()->toArray();
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
        $customerId  = (int)($item['customer_id'] ?? 0);
        $warehouseId = (int)($item['warehouse_id'] ?? 0);
        $status      = (int)($item['status'] ?? PurchaseOrder::STATUS_DRAFT);

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

        $customerName  = (string)($customer['customer_name'] ?? $item['customer_name'] ?? '');
        $warehouseName = (string)($warehouse['name'] ?? '');
        $datetimesingle = (int)($item['datetimesingle'] ?? 0);

        return [
            'id'              => (int)($item['id'] ?? 0),
            'order_sn'        => (string)($item['order_sn'] ?? ''),
            'customer_id'     => $customerId,
            'customer_name'   => $customerName,
            'customer'        => $customer ?: [
                'id'            => $customerId,
                'customer_name' => $customerName,
            ],
            'warehouse_id'    => $warehouseId,
            'warehouse_name'  => $warehouseName,
            'warehouse'       => $warehouseName,
            'warehouse_info'  => $warehouse,
            'order_money'     => self::money($item['order_money'] ?? 0),
            'order_pay_money' => self::money($item['order_pay_money'] ?? 0),
            'datetimesingle'  => $datetimesingle,
            'predicted_date'  => (int)($item['predicted_date'] ?? 0),
            'createdate'      => self::dateText($datetimesingle ?: ($item['create_time'] ?? 0)),
            'status'          => PurchaseOrder::getStatusText($status),
            'status_value'    => $status,
            'cancel_reason'   => (string)($item['cancel_reason'] ?? ''),
            'purpose'         => self::DEFAULT_PURPOSE,
            'purpose_type'    => self::DEFAULT_PURPOSE_TYPE,
            'remarks'         => (string)($item['remarks'] ?? ''),
            'remark'          => (string)($item['remarks'] ?? ''),
            'admin_id'        => (int)($item['admin_id'] ?? 0),
            'create_time'     => $item['create_time'] ?? '',
            'update_time'     => $item['update_time'] ?? '',
        ];
    }

    // =========================================================
    // 内部方法
    // =========================================================

    protected static function buildOrderData(array $params, array $current = []): array|false
    {
        $customer = Customer::findOrEmpty((int)$params['customer_id']);
        if ($customer->isEmpty()) {
            self::setError('客户不存在');
            return false;
        }
        if ((int)$customer->is_disabled === 1) {
            self::setError('停用客户不可开订货单');
            return false;
        }

        // 仓库可选（0 表示未指定）
        $warehouseId = (int)($params['warehouse_id'] ?? ($current['warehouse_id'] ?? 0));
        if ($warehouseId > 0) {
            $warehouse = self::resolveWarehouse($warehouseId);
            if (!$warehouse) {
                return false;
            }
            $warehouseId = (int)$warehouse->id;
        }

        $goodsRows = self::buildGoodsRows($params['goods'] ?? []);
        if ($goodsRows === false) {
            return false;
        }

        $orderMoney    = array_reduce($goodsRows, fn($sum, $row) => bcadd($sum, (string)$row['amount'], 2), '0.00');
        $rawPay = (string)max(0, (float)($params['order_pay_money'] ?? ($current['order_pay_money'] ?? 0)));
        $orderPayMoney = bccomp($rawPay, (string)$orderMoney, 2) > 0 ? (string)$orderMoney : $rawPay;
        $orderPayMoney = self::money($orderPayMoney);
        $tenantId      = (int)(request()->tenantId ?? 0);
        $adminId       = (int)(request()->adminId ?? 0);
        $orderSn       = trim((string)($params['order_sn'] ?? ($current['order_sn'] ?? '')));
        $idempotentKey = trim((string)($params['idempotent_key'] ?? ''));

        if ($orderSn === '') {
            $orderSn = self::generateOrderSn();
        } elseif (!self::assertOrderSnUnique($orderSn, (int)($current['id'] ?? 0))) {
            return false;
        }

        return [
            'order' => [
                'tenant_id'      => $tenantId,
                'order_sn'       => $orderSn,
                'customer_id'    => (int)$customer->id,
                'customer_name'  => (string)$customer->customer_name,
                'warehouse_id'   => $warehouseId,
                'order_money'    => $orderMoney,
                'order_pay_money'=> $orderPayMoney,
                'datetimesingle' => (int)($params['datetimesingle'] ?? ($current['datetimesingle'] ?? time())),
                'predicted_date' => (int)($params['predicted_date'] ?? ($current['predicted_date'] ?? 0)),
                'status'         => (int)($current['status'] ?? PurchaseOrder::STATUS_DRAFT),
                'cancel_reason'  => (string)($current['cancel_reason'] ?? ''),
                'remarks'        => trim((string)($params['remarks'] ?? $params['remark'] ?? ($current['remarks'] ?? ''))),
                'admin_id'       => $adminId,
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
                self::setError('停用商品不可开订货单');
                return false;
            }

            $number = round(max(0, (float)($item['number'] ?? 0)), 4);
            if ($number <= 0) {
                self::setError('商品数量必须大于0');
                return false;
            }

            $price  = self::money($item['price'] ?? $item['units_money'] ?? $goodsModel->price);
            $amount = bcmul((string)$number, (string)$price, 2);

            $rows[] = [
                'tenant_id'  => (int)(request()->tenantId ?? 0),
                'order_type' => self::ORDER_TYPE,
                'goods_id'   => $goodsId,
                'name'       => trim((string)($item['name'] ?? $item['product_name'] ?? $goodsModel->name)),
                'units'      => trim((string)($item['units'] ?? $item['unit'] ?? $goodsModel->units)),
                'number'     => number_format($number, 4, '.', ''),
                'price'      => $price,
                'amount'     => $amount,
                'remark'     => trim((string)($item['remark'] ?? '')),
                'sort'       => $index,
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
            self::setError('停用仓库不可使用');
            return null;
        }

        return $warehouse;
    }

    protected static function generateOrderSn(): string
    {
        $tenantId = (int)(request()->tenantId ?? 0);
        $maxRetries = 3;
        for ($i = 0; $i < $maxRetries; $i++) {
            $sn = 'DDH' . date('YmdHis') . str_pad((string)mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
            $exists = PurchaseOrder::where('tenant_id', $tenantId)
                ->where('order_sn', $sn)
                ->count();
            if ($exists == 0) {
                return $sn;
            }
            usleep(1000);
        }
        // 3次冲突后使用微秒级后缀
        return 'DDH' . date('YmdHis') . substr((string)((int)(microtime(true) * 10000)), -10);
    }

    protected static function assertOrderSnUnique(string $orderSn, int $ignoreId = 0): bool
    {
        $query = PurchaseOrder::where('order_sn', $orderSn);
        if ($ignoreId > 0) {
            $query->where('id', '<>', $ignoreId);
        }
        if ($query->count() > 0) {
            self::setError('订货单号已存在');
            return false;
        }
        return true;
    }

    protected static function formatGoodsRows(array $rows): array
    {
        return array_map(function ($row) {
            $number = rtrim(rtrim(number_format((float)($row['number'] ?? 0), 4, '.', ''), '0'), '.');
            return [
                'id'             => (int)($row['id'] ?? 0),
                'order_goods_id' => (int)($row['id'] ?? 0),
                'goods_id'       => (int)($row['goods_id'] ?? 0),
                'name'           => (string)($row['name'] ?? ''),
                'product_name'   => (string)($row['name'] ?? ''),
                'units'          => (string)($row['units'] ?? ''),
                'unit'           => (string)($row['units'] ?? ''),
                'number'         => $number === '' ? '0' : $number,
                'price'          => self::money($row['price'] ?? 0),
                'units_money'    => self::money($row['price'] ?? 0),
                'amount'         => self::money($row['amount'] ?? 0),
                'remark'         => (string)($row['remark'] ?? ''),
                'sort'           => (int)($row['sort'] ?? 0),
            ];
        }, $rows);
    }

    protected static function applyTimeRange($query, array $params): void
    {
        $startTime = (int)($params['start_time'] ?? 0);
        $endTime   = (int)($params['end_time'] ?? 0);
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
