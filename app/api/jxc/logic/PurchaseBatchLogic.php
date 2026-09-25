<?php

namespace app\api\jxc\logic;

use app\api\jxc\exception\BusinessException;
use app\common\logic\BaseLogic;
use app\common\model\jxc\Goods;
use app\common\model\jxc\OrderGoods;
use app\common\model\jxc\PurchaseBatch;
use app\common\model\jxc\PurchaseBatchSupplyOrder;
use app\common\model\jxc\SupplyOrder;
use app\common\model\jxc\Vendor;
use app\common\model\jxc\Warehouse;
use app\common\service\goods\GoodsSkuSelectionService;
use think\facade\Db;
use think\facade\Log;

/**
 * 多供应商采购录入的原子提交边界。
 *
 * 父批次只记录共享录入信息和子单链接；库存、到货、应付、结算与采购退货仍由
 * SupplyOrderLogic 在每张单供应商子进货单上完成。
 */
class PurchaseBatchLogic extends BaseLogic
{
    /** @var array<int,array{client_line_id:string,field:string,code:string,message:string}> */
    private static array $errors = [];

    public static function getErrors(): array
    {
        return self::$errors;
    }

    public static function submit(array $params): array|false
    {
        self::clearError();
        self::$errors = [];

        $tenantId = self::tenantId();
        $idempotencyKey = trim((string)($params['idempotency_key'] ?? ''));
        if ($tenantId <= 0) {
            self::fail('租户无效');
            return false;
        }
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 64) {
            self::fail('提交需要有效的幂等键', [[
                'client_line_id' => '', 'field' => 'idempotency_key',
                'code' => 'IDEMPOTENCY_KEY_INVALID', 'message' => '提交需要有效的幂等键',
            ]]);
            return false;
        }

        $fingerprint = self::fingerprint($params);
        $caught = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $result = Db::transaction(static function () use ($params, $tenantId, $idempotencyKey, $fingerprint) {
                    FinanceIntegration::lock();
                    $existing = PurchaseBatch::where('tenant_id', $tenantId)
                        ->where('idempotency_key', $idempotencyKey)
                        ->findOrEmpty();
                    if (!$existing->isEmpty()) {
                        if ((string)$existing->request_fingerprint !== $fingerprint) {
                            self::fail('幂等键已用于不同的采购批次内容', [[
                                'client_line_id' => '', 'field' => 'idempotency_key',
                                'code' => 'IDEMPOTENCY_KEY_REUSED', 'message' => '幂等键已用于不同的采购批次内容',
                            ]]);
                            return false;
                        }
                        return self::detailById((int)$existing->id, $tenantId);
                    }

                    $base = self::normalizeBase($params, $tenantId);
                    if ($base === false) {
                        return false;
                    }
                    $supplierGroups = self::normalizeSupplierGroups((array)($params['items'] ?? []), $tenantId);
                    if ($supplierGroups === false) {
                        return false;
                    }
                    if ($base['purchase_plan_id'] > 0) {
                        $containsPlanSku = false;
                        foreach ($supplierGroups as $supplierGroup) {
                            foreach ($supplierGroup['goods'] as $goodsLine) {
                                if ((int)$goodsLine['sku_id'] === $base['purchase_plan_sku_id']) {
                                    $containsPlanSku = true;
                                    break 2;
                                }
                            }
                        }
                        if (!$containsPlanSku) {
                            self::fail('采购批次必须包含采购计划对应的 SKU', [[
                                'client_line_id' => '', 'field' => 'items',
                                'code' => 'PURCHASE_PLAN_SKU_REQUIRED', 'message' => '请保留至少一条采购计划对应的 SKU 明细',
                            ]]);
                            return false;
                        }
                    }

                    $now = time();
                    $batch = PurchaseBatch::create([
                        'tenant_id' => $tenantId,
                        'purchase_plan_id' => $base['purchase_plan_id'],
                        'batch_no' => self::generateBatchNo($tenantId),
                        'warehouse_id' => $base['warehouse_id'],
                        'warehouse_name' => $base['warehouse_name'],
                        'datetimesingle' => $base['datetimesingle'],
                        'remarks' => $base['remarks'],
                        'status' => 'submitted',
                        'supplier_count' => count($supplierGroups),
                        'line_count' => 0,
                        'total_amount' => '0.00',
                        'idempotency_key' => $idempotencyKey,
                        'request_fingerprint' => $fingerprint,
                        'admin_id' => (int)(request()->adminId ?? 0),
                        'create_time' => $now,
                        'update_time' => $now,
                    ]);

                    $lineCount = 0;
                    $totalAmount = '0.00';
                    $children = [];
                    foreach ($supplierGroups as $group) {
                        $child = SupplyOrderLogic::publishWithinTransaction([
                            'supplier_id' => $group['supplier_id'],
                            'warehouse_id' => $base['warehouse_id'],
                            'datetimesingle' => $base['datetimesingle'],
                            'remarks' => $base['remarks'],
                            'order_pay_money' => 0,
                            'purchase_batch_id' => (int)$batch->id,
                            'idempotent_key' => self::childIdempotencyKey((int)$batch->id, (int)$group['supplier_id']),
                            'goods' => $group['goods'],
                        ]);
                        if ($child === false) {
                            self::fail(
                                SupplyOrderLogic::getError() ?: '供应商进货单校验失败',
                                self::groupErrors($group['client_line_ids'], SupplyOrderLogic::getError() ?: '供应商进货单校验失败')
                            );
                            throw new BusinessException(self::getError());
                        }

                        PurchaseBatchSupplyOrder::create([
                            'tenant_id' => $tenantId,
                            'purchase_batch_id' => (int)$batch->id,
                            'supply_order_id' => (int)$child['id'],
                            'supplier_id' => (int)$child['supplier_id'],
                            'supplier_name' => (string)$child['supplier_name'],
                            'line_count' => count($group['goods']),
                            'order_money' => (string)$child['order_money'],
                            'create_time' => $now,
                            'update_time' => $now,
                        ]);
                        $lineCount += count($group['goods']);
                        $totalAmount = bcadd($totalAmount, (string)$child['order_money'], 2);
                        $children[] = $child;
                    }

                    $batch->save([
                        'line_count' => $lineCount,
                        'total_amount' => $totalAmount,
                        'update_time' => $now,
                    ]);
                    AuditService::logWithinTransaction(
                        AuditService::MODULE_PURCHASE_BATCH,
                        AuditService::ACTION_CREATE,
                        (int)$batch->id,
                        (string)$batch->batch_no,
                        null,
                        [
                            'warehouse_id' => $base['warehouse_id'],
                            'datetimesingle' => $base['datetimesingle'],
                            'supplier_count' => count($supplierGroups),
                            'line_count' => $lineCount,
                            'total_amount' => $totalAmount,
                            'supply_order_ids' => array_column($children, 'id'),
                        ]
                    );

                    return self::detailById((int)$batch->id, $tenantId);
                });
                if ($result !== false) {
                    return $result;
                }
                return false;
            } catch (\Throwable $exception) {
                $caught = $exception;
                if ((self::isRetryableTransactionError($exception) || self::isConcurrentIdempotencyConflict($exception)) && $attempt < 2) {
                    usleep(($attempt + 1) * 20_000);
                    continue;
                }
                break;
            }
        }

        $replayed = self::replayAfterConcurrentCommit($tenantId, $idempotencyKey, $fingerprint);
        if ($replayed !== false) {
            return $replayed;
        }

        if (!self::hasError()) {
            Log::error('采购批次创建失败: ' . ($caught?->getMessage() ?? 'unknown'), [
                'exception' => $caught ? get_class($caught) : '',
            ]);
            self::fail('采购批次提交失败，请稍后重试');
        }
        return false;
    }

    public static function detail(array $params): array|false
    {
        self::clearError();
        self::$errors = [];
        $tenantId = self::tenantId();
        if ($tenantId <= 0) {
            self::fail('租户无效');
            return false;
        }
        return self::detailById((int)($params['id'] ?? 0), $tenantId);
    }

    /**
     * 子进货单在其自身编辑路径中保存后，重新写入批次的只读汇总快照。
     * 调用方必须已经持有事务，避免批次显示与子单事实不一致。
     */
    public static function refreshSummaryWithinTransaction(int $batchId): void
    {
        $tenantId = self::tenantId();
        $batch = PurchaseBatch::where('id', $batchId)
            ->where('tenant_id', $tenantId)
            ->lock(true)
            ->findOrEmpty();
        if ($batch->isEmpty()) {
            throw new BusinessException('采购批次不存在');
        }

        $links = PurchaseBatchSupplyOrder::where('purchase_batch_id', $batchId)
            ->where('tenant_id', $tenantId)
            ->order(['id' => 'asc'])
            ->lock(true)
            ->select()
            ->toArray();
        $supplyOrderIds = array_values(array_unique(array_map(
            static fn(array $link): int => (int)$link['supply_order_id'],
            $links
        )));
        $orders = $supplyOrderIds === [] ? [] : SupplyOrder::where('tenant_id', $tenantId)
            ->whereIn('id', $supplyOrderIds)
            ->select()
            ->toArray();
        $ordersById = [];
        foreach ($orders as $order) {
            $ordersById[(int)$order['id']] = $order;
        }

        $lineCounts = [];
        if ($supplyOrderIds !== []) {
            $counts = OrderGoods::where('tenant_id', $tenantId)
                ->where('order_type', 'supply')
                ->whereIn('order_id', $supplyOrderIds)
                ->field('order_id,COUNT(1) AS line_count')
                ->group('order_id')
                ->select()
                ->toArray();
            foreach ($counts as $count) {
                $lineCounts[(int)$count['order_id']] = (int)$count['line_count'];
            }
        }

        $totalAmount = '0.00';
        $lineCount = 0;
        foreach ($links as $link) {
            $supplyOrderId = (int)$link['supply_order_id'];
            $order = $ordersById[$supplyOrderId] ?? null;
            if ($order === null) {
                throw new BusinessException('采购批次关联进货单不存在');
            }
            $currentLineCount = (int)($lineCounts[$supplyOrderId] ?? 0);
            $currentAmount = self::money((string)($order['order_money'] ?? '0.00'));
            PurchaseBatchSupplyOrder::where('id', (int)$link['id'])
                ->where('tenant_id', $tenantId)
                ->update([
                    'supplier_id' => (int)$order['supplier_id'],
                    'supplier_name' => (string)$order['supplier_name'],
                    'line_count' => $currentLineCount,
                    'order_money' => $currentAmount,
                    'update_time' => time(),
                ]);
            $lineCount += $currentLineCount;
            $totalAmount = bcadd($totalAmount, $currentAmount, 2);
        }

        $batch->save([
            'supplier_count' => count($links),
            'line_count' => $lineCount,
            'total_amount' => $totalAmount,
            'update_time' => time(),
        ]);
    }

    private static function detailById(int $id, int $tenantId): array|false
    {
        $batch = PurchaseBatch::where('id', $id)->where('tenant_id', $tenantId)->findOrEmpty();
        if ($batch->isEmpty()) {
            self::fail('采购批次不存在');
            return false;
        }
        $children = PurchaseBatchSupplyOrder::where('purchase_batch_id', $id)
            ->where('tenant_id', $tenantId)
            ->order(['id' => 'asc'])
            ->select()
            ->toArray();
        return array_merge($batch->toArray(), ['supply_orders' => $children]);
    }

    /** @return array{warehouse_id:int,warehouse_name:string,datetimesingle:int,remarks:string,purchase_plan_id:int,purchase_plan_sku_id:int}|false */
    private static function normalizeBase(array $params, int $tenantId): array|false
    {
        $warehouseId = (int)($params['warehouse_id'] ?? 0);
        $warehouse = Warehouse::where('id', $warehouseId)->where('tenant_id', $tenantId)->findOrEmpty();
        if ($warehouse->isEmpty() || (int)($warehouse->is_enabled ?? 1) !== 1) {
            self::fail('入库仓库不存在或已停用', [[
                'client_line_id' => '', 'field' => 'warehouse_id',
                'code' => 'WAREHOUSE_UNAVAILABLE', 'message' => '请选择可用的入库仓库',
            ]]);
            return false;
        }
        $datetimesingle = (int)($params['datetimesingle'] ?? 0);
        if ($datetimesingle <= 0) {
            self::fail('采购日期不能为空', [[
                'client_line_id' => '', 'field' => 'datetimesingle',
                'code' => 'PURCHASE_DATE_REQUIRED', 'message' => '请选择采购日期',
            ]]);
            return false;
        }
        $purchasePlanId = (int)($params['purchase_plan_id'] ?? 0);
        $purchasePlanSkuId = 0;
        if ($purchasePlanId > 0) {
            $plan = Db::name('purchase_plan')->where('tenant_id', $tenantId)->where('id', $purchasePlanId)
                ->whereIn('status', ['pending', 'partial'])->field('id,warehouse_id,sku_id')->find();
            if (!$plan || (int)$plan['warehouse_id'] !== $warehouseId) {
                self::fail('采购计划不存在、已结束或入库仓库不一致', [[
                    'client_line_id' => '', 'field' => 'purchase_plan_id',
                    'code' => 'PURCHASE_PLAN_UNAVAILABLE', 'message' => '请从有效采购计划发起采购到货',
                ]]);
                return false;
            }
            $purchasePlanSkuId = (int)$plan['sku_id'];
        }
        return [
            'warehouse_id' => $warehouseId,
            'warehouse_name' => (string)($warehouse->name ?? ''),
            'datetimesingle' => $datetimesingle,
            'remarks' => trim((string)($params['remarks'] ?? $params['remark'] ?? '')),
            'purchase_plan_id' => $purchasePlanId,
            'purchase_plan_sku_id' => $purchasePlanSkuId,
        ];
    }

    /** @return array<int,array{supplier_id:int,goods:array<int,array<string,mixed>>,client_line_ids:array<int,string>}>|false */
    private static function normalizeSupplierGroups(array $items, int $tenantId): array|false
    {
        if ($items === []) {
            self::fail('请至少提交一条采购明细', [[
                'client_line_id' => '', 'field' => 'items',
                'code' => 'LINE_REQUIRED', 'message' => '请至少添加一条采购明细',
            ]]);
            return false;
        }

        $groups = [];
        $seenLineIds = [];
        foreach (array_values($items) as $index => $item) {
            $position = $index + 1;
            if (!is_array($item)) {
                self::fail('采购明细格式无效', self::lineError('', 'items', 'LINE_FORMAT_INVALID', "第{$position}行格式无效"));
                return false;
            }
            $lineId = trim((string)($item['client_line_id'] ?? ''));
            if ($lineId === '' || strlen($lineId) > 64 || isset($seenLineIds[$lineId])) {
                self::fail('采购明细行标识无效', self::lineError($lineId, 'client_line_id', 'LINE_ID_INVALID', "第{$position}行标识无效或重复"));
                return false;
            }
            $seenLineIds[$lineId] = true;

            $supplierId = (int)($item['supplier_id'] ?? 0);
            if ($supplierId <= 0) {
                self::fail('采购明细缺少供应商', self::lineError($lineId, 'supplier_id', 'SUPPLIER_REQUIRED', '请选择供应商'));
                return false;
            }
            $vendor = Vendor::where('id', $supplierId)->where('tenant_id', $tenantId)->findOrEmpty();
            if ($vendor->isEmpty() || (int)($vendor->is_disabled ?? 0) === 1) {
                self::fail('供应商不存在或已停用', self::lineError($lineId, 'supplier_id', 'SUPPLIER_UNAVAILABLE', '该供应商不存在或已停用'));
                return false;
            }

            $goodsId = (int)($item['goods_id'] ?? $item['id'] ?? 0);
            if ($goodsId <= 0) {
                self::fail('采购明细缺少商品', self::lineError($lineId, 'goods_id', 'GOODS_REQUIRED', '请选择商品'));
                return false;
            }
            $goods = Goods::where('id', $goodsId)->where('tenant_id', $tenantId)->findOrEmpty();
            if ($goods->isEmpty() || (int)($goods->is_disabled ?? 0) === 1) {
                self::fail('商品不存在或已停用', self::lineError($lineId, 'goods_id', 'GOODS_UNAVAILABLE', '该商品不存在或已停用'));
                return false;
            }

            $skuId = (int)($item['sku_id'] ?? 0);
            if ($skuId <= 0) {
                self::fail('采购明细缺少SKU', self::lineError($lineId, 'sku_id', 'SKU_REQUIRED', '请选择有效的SKU'));
                return false;
            }
            try {
                GoodsSkuSelectionService::forPurchase($tenantId, $goodsId, $skuId);
            } catch (\InvalidArgumentException $exception) {
                self::fail($exception->getMessage(), self::lineError($lineId, 'sku_id', 'SKU_UNAVAILABLE', $exception->getMessage()));
                return false;
            }
            if (GoodsSupplierMatrixLogic::assertCanSupply($supplierId, $goodsId, $skuId) === false) {
                $message = GoodsSupplierMatrixLogic::getError() ?: '该供应商不能供应此SKU';
                self::fail($message, self::lineError($lineId, 'supplier_id', 'SUPPLIER_SKU_UNAVAILABLE', $message));
                return false;
            }

            $orderQty = $item['order_qty'] ?? $item['number'] ?? 0;
            if (!is_numeric($orderQty) || (float)$orderQty <= 0) {
                self::fail('商品数量必须大于0', self::lineError($lineId, 'order_qty', 'QUANTITY_INVALID', '数量必须大于0'));
                return false;
            }
            $price = $item['price'] ?? $item['units_money'] ?? null;
            if (!is_numeric($price) || (float)$price < 0) {
                self::fail('商品单价无效', self::lineError($lineId, 'price', 'PRICE_INVALID', '请输入大于或等于0的单价'));
                return false;
            }

            $groups[$supplierId] ??= ['supplier_id' => $supplierId, 'goods' => [], 'client_line_ids' => []];
            $groups[$supplierId]['goods'][] = array_merge($item, [
                'goods_id' => $goodsId,
                'sku_id' => $skuId,
                'order_qty' => $orderQty,
                'price' => $price,
            ]);
            $groups[$supplierId]['client_line_ids'][] = $lineId;
        }
        ksort($groups, SORT_NUMERIC);
        return array_values($groups);
    }

    /** @return array<int,array{client_line_id:string,field:string,code:string,message:string}> */
    private static function groupErrors(array $lineIds, string $message): array
    {
        return array_map(static fn(string $lineId) => [
            'client_line_id' => $lineId,
            'field' => 'line',
            'code' => 'SUPPLY_ORDER_VALIDATION_FAILED',
            'message' => $message,
        ], $lineIds);
    }

    /** @return array<int,array{client_line_id:string,field:string,code:string,message:string}> */
    private static function lineError(string $lineId, string $field, string $code, string $message): array
    {
        return [[
            'client_line_id' => $lineId,
            'field' => $field,
            'code' => $code,
            'message' => $message,
        ]];
    }

    private static function fail(string $message, array $errors = []): void
    {
        self::$errors = $errors;
        self::setError($message);
    }

    private static function tenantId(): int
    {
        return (int)(request()->tenantId ?? 0);
    }

    private static function generateBatchNo(int $tenantId): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $candidate = 'PCB' . date('YmdHis') . random_int(100, 999);
            if (!PurchaseBatch::where('tenant_id', $tenantId)->where('batch_no', $candidate)->find()) {
                return $candidate;
            }
        }
        throw new BusinessException('采购批次号生成失败');
    }

    private static function childIdempotencyKey(int $batchId, int $supplierId): string
    {
        return 'pb:' . $batchId . ':supplier:' . $supplierId;
    }

    private static function fingerprint(array $params): string
    {
        $payload = [
            'purchase_plan_id' => (int)($params['purchase_plan_id'] ?? 0),
            'warehouse_id' => (int)($params['warehouse_id'] ?? 0),
            'datetimesingle' => (int)($params['datetimesingle'] ?? 0),
            'remarks' => trim((string)($params['remarks'] ?? $params['remark'] ?? '')),
            'items' => array_values((array)($params['items'] ?? [])),
        ];
        return hash('sha256', json_encode(self::sortForFingerprint($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function sortForFingerprint(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (self::isList($value)) {
            return array_map([self::class, 'sortForFingerprint'], $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = self::sortForFingerprint($item);
        }
        return $value;
    }

    private static function isList(array $value): bool
    {
        $index = 0;
        foreach ($value as $key => $_) {
            if ($key !== $index++) {
                return false;
            }
        }
        return true;
    }

    private static function isRetryableTransactionError(\Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());
        return str_contains($message, 'deadlock') || str_contains($message, 'lock wait timeout');
    }

    private static function money(string $value): string
    {
        return bcadd($value === '' ? '0' : $value, '0', 2);
    }

    private static function isConcurrentIdempotencyConflict(\Throwable $exception): bool
    {
        return str_contains(strtolower($exception->getMessage()), 'duplicate');
    }

    private static function replayAfterConcurrentCommit(int $tenantId, string $idempotencyKey, string $fingerprint): array|false
    {
        $existing = PurchaseBatch::where('tenant_id', $tenantId)
            ->where('idempotency_key', $idempotencyKey)
            ->findOrEmpty();
        if ($existing->isEmpty()) {
            return false;
        }
        if ((string)$existing->request_fingerprint !== $fingerprint) {
            self::fail('幂等键已用于不同的采购批次内容', [[
                'client_line_id' => '', 'field' => 'idempotency_key',
                'code' => 'IDEMPOTENCY_KEY_REUSED', 'message' => '幂等键已用于不同的采购批次内容',
            ]]);
            return false;
        }
        return self::detailById((int)$existing->id, $tenantId);
    }
}
