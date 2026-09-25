<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use app\common\model\jxc\CustomerReport;
use app\common\model\jxc\CustomerReportItem;
use app\common\model\jxc\CustomerReportReservation;
use app\common\model\jxc\GoodsSku;
use think\facade\Db;

/** 独立客户报货状态机：提交、补预留、版本编辑、取消和实际履约。 */
class CustomerReportLogic extends BaseLogic
{
    private const SCALE = 2;
    private const SUBMITTED = ['submitted_ready', 'submitted_shortage'];

    /** @return array<string,mixed>|false */
    public static function submit(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('report.create')) {
            return false;
        }
        $tenantId = self::tenantId();
        $key = trim((string)($params['idempotency_key'] ?? ''));
        if ($tenantId <= 0 || $key === '' || strlen($key) > 96) {
            self::setError('提交需要有效的幂等键');
            return false;
        }
        $fingerprint = self::fingerprint($params);
        $exception = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return Db::transaction(static function () use ($params, $tenantId, $key, $fingerprint) {
                $existing = CustomerReport::where('tenant_id', $tenantId)->where('idempotency_key', $key)->find();
                if ($existing) {
                    if ((string)$existing->request_fingerprint !== $fingerprint) {
                        self::setError('幂等键已用于不同的报货内容');
                        return false;
                    }
                    return self::detailById((int)$existing->id);
                }
                $deliveryDate = self::deliveryDate((string)($params['delivery_date'] ?? ''));
                $isSupplement = (int)($params['is_supplement'] ?? 0) === 1;
                $batchId = (int)($params['batch_id'] ?? 0);
                $supplementForReportId = (int)($params['supplement_for_report_id'] ?? 0);
                $items = CustomerReportLineService::normalizeItems((array)($params['items'] ?? []), (int)($params['main_customer_id'] ?? 0));
                if ($items === false) {
                    self::setError(CustomerReportLineService::getError());
                    return false;
                }
                if (self::lockBatchForSubmit(
                    $batchId,
                    $deliveryDate,
                    $isSupplement,
                    $supplementForReportId,
                    $items
                ) === false) {
                    return false;
                }
                $now = time();
                $reportId = (int)Db::name('customer_report')->insertGetId([
                    'tenant_id' => $tenantId, 'batch_id' => $batchId,
                    'supplement_for_report_id' => $supplementForReportId, 'sn' => self::sn(),
                    'main_customer_id' => (int)$items[0]['main_customer_id'], 'main_customer_name' => (string)$items[0]['main_customer_name'],
                    'status' => 'submitted_ready', 'idempotency_key' => $key, 'request_fingerprint' => $fingerprint,
                    'version' => 1, 'submitted_time' => $now,
                    'delivery_date' => $deliveryDate,
                    'is_supplement' => $isSupplement ? 1 : 0,
                    'remark' => trim((string)($params['remark'] ?? '')),
                    'create_time' => $now, 'update_time' => $now,
                ]);
                $summary = self::writeNewItems($reportId, $items, $now);
                Db::name('customer_report')->where('id', $reportId)->where('tenant_id', $tenantId)->update($summary + ['update_time' => $now]);
                FulfillmentTaskLogic::syncForReport($reportId);
                if ($isSupplement) {
                    AuditService::logWithinTransaction(
                        AuditService::MODULE_CUSTOMER_REPORT,
                        AuditService::ACTION_SUPPLEMENT,
                        $reportId,
                        (string)Db::name('customer_report')->where('tenant_id', $tenantId)->where('id', $reportId)->value('sn'),
                        null,
                        [
                            'batch_id' => $batchId,
                            'supplement_for_report_id' => $supplementForReportId,
                            'delivery_date' => $deliveryDate,
                            'is_supplement' => 1,
                        ],
                        '补报进入原报货批次'
                    );
                }
                return self::detailById($reportId);
                });
            } catch (\Throwable $caught) {
                $exception = $caught;
                if (self::isRetryableTransactionError($caught) && $attempt < 2) {
                    usleep(($attempt + 1) * 20_000);
                    continue;
                }
                break;
            }
        }
        $existing = CustomerReport::where('tenant_id', $tenantId)->where('idempotency_key', $key)->find();
        if ($existing && (string)$existing->request_fingerprint === $fingerprint) {
            return self::detailById((int)$existing->id);
        }
        if (!self::hasError()) { self::setError('客户报货提交失败'); }
        return false;
    }

    /** @return array<string,mixed>|false */
    public static function detail(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('report.view')) {
            return false;
        }
        return self::detailById((int)($params['id'] ?? 0));
    }

    /** @return array{lists:array<int,array<string,mixed>>,count:int,page_no:int,page_size:int} */
    public static function lists(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('report.view')) {
            return false;
        }
        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $pageSize = min(100, max(1, (int)($params['page_size'] ?? 20)));
        $statusScope = (string)($params['status_scope'] ?? '');
        $count = self::listQuery($statusScope)->count();
        return [
            'lists' => self::listQuery($statusScope)->order(['id' => 'desc'])->page($pageNo, $pageSize)->select()->toArray(),
            'count' => $count,
            'page_no' => $pageNo,
            'page_size' => $pageSize,
        ];
    }

    private static function listQuery(string $statusScope)
    {
        $query = CustomerReport::where('tenant_id', self::tenantId());
        return match ($statusScope) {
            'pending' => $query->whereIn('status', self::SUBMITTED),
            'completed' => $query->where('status', 'completed'),
            'cancelled' => $query->where('status', 'cancelled'),
            default => $query,
        };
    }

    /** @return array{warehouse_id:int,goods_id:int,sku_id:int,available_base_qty:string} */
    public static function availability(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('report.view')) {
            return false;
        }
        $warehouseId = (int)($params['warehouse_id'] ?? 0);
        $skuId = (int)($params['sku_id'] ?? 0);
        $sku = GoodsSku::where('tenant_id', self::tenantId())->where('id', $skuId)->findOrEmpty();
        if ($sku->isEmpty()) {
            self::setError('SKU不存在');
            return false;
        }
        $warehouseExists = Db::name('warehouse')
            ->where('tenant_id', self::tenantId())
            ->where('id', $warehouseId)
            ->count() > 0;
        if (!$warehouseExists) {
            self::setError('仓库不存在');
            return false;
        }
        $goodsId = (int)$sku->goods_id;
        return [
            'warehouse_id' => $warehouseId,
            'goods_id' => $goodsId,
            'sku_id' => $skuId,
            'available_base_qty' => WarehouseSkuBalanceService::available($warehouseId, $skuId),
        ];
    }

    /** 将无缺货且已计价的报货单按仓库转为标准销售单。 */
    public static function convert(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('settlement.bill')) {
            return false;
        }
        $reportId = (int)($params['id'] ?? 0);
        $version = (int)($params['version'] ?? 0);
        try {
            return self::transactionWithRetry(static function () use ($reportId, $version) {
                FinanceIntegration::lock();
                $report = CustomerReport::where('tenant_id', self::tenantId())->where('id', $reportId)->lock(true)->find();
                if (!$report) { self::setError('报货单不存在、版本冲突或不可转销售'); return false; }
                $existing = self::salesOrdersByReport($reportId, true);
                if ($existing !== []) {
                    foreach ($existing as $salesOrder) {
                        if ((string)($salesOrder['settlement_status'] ?? 'formal') !== 'formal') {
                            self::setError('交付已经完成出库，请在后续销售结算中正式确认，不能再次扣减库存');
                            return false;
                        }
                    }
                    return self::detailById($reportId);
                }
                if ((int)$report->version !== $version) {
                    self::setError('报货单不存在、版本冲突或不可转销售'); return false;
                }
                $items = CustomerReportItem::where('tenant_id', self::tenantId())->where('report_id', $reportId)
                    ->whereNull('delete_time')->order(['goods_id' => 'asc', 'warehouse_id' => 'asc', 'id' => 'asc'])
                    ->lock(true)->select()->toArray();
                $readiness = self::conversionReadiness($reportId, $report->toArray(), $items, true);
                if (!$readiness['allowed']) {
                    self::setError($readiness['blocked_reason']);
                    return false;
                }
                $items = $readiness['items'];
                if ($readiness['has_task_group']) {
                    $settlements = $readiness['settlements'];
                    $now = time();
                    foreach ($items as &$item) {
                        $itemId = (int)$item['id'];
                        $target = self::decimal($settlements[$itemId]['actual_weight']);
                        $oldReserved = self::decimal((string)$item['reserved_base_qty']);
                        $delta = bcsub($target, $oldReserved, self::SCALE);
                        if (bccomp($delta, '0.00', self::SCALE) > 0) {
                            $added = WarehouseSkuBalanceService::reserveUpToWithinTransaction((int)$item['warehouse_id'], (int)$item['sku_id'], $delta);
                            if ($added === false || bccomp($added, $delta, self::SCALE) !== 0) {
                                self::setError('最终实重超过当前可用库存，无法确认开单');
                                throw new \RuntimeException('final_weight_stock_shortage');
                            }
                        } elseif (bccomp($delta, '0.00', self::SCALE) < 0
                            && WarehouseSkuBalanceService::releaseWithinTransaction((int)$item['warehouse_id'], (int)$item['sku_id'], ltrim($delta, '-')) === false) {
                            throw new \RuntimeException('release_failed');
                        }
                        $price = self::decimal($settlements[$itemId]['actual_price']);
                        if (bccomp($price, '0.00', self::SCALE) <= 0) {
                            self::setError('未定价明细不能开单，请先录入大于 0 的已确认单价');
                            return false;
                        }
                        $pricingUnitId = (int)$item['pricing_unit_id'] > 0 ? (int)$item['pricing_unit_id'] : (int)$item['base_unit_id'];
                        $pricingUnitName = trim((string)$item['pricing_unit_name']) !== ''
                            ? trim((string)$item['pricing_unit_name'])
                            : trim((string)$item['base_unit_name']);
                        if ($pricingUnitName === '') {
                            self::setError('结算明细缺少基础计价单位，不能开单');
                            return false;
                        }
                        CustomerReportItem::where('tenant_id', self::tenantId())->where('id', $itemId)->update([
                            'expected_base_qty' => $target, 'reserved_base_qty' => $target, 'shortage_base_qty' => '0.00',
                            'price' => $price, 'price_status' => 'priced',
                            'pricing_unit_id' => $pricingUnitId, 'pricing_unit_name' => $pricingUnitName,
                            'status' => 'submitted_ready', 'update_time' => $now,
                        ]);
                        Db::name('customer_report_reservation')->where('tenant_id', self::tenantId())->where('report_item_id', $itemId)->update([
                            'reserved_base_qty' => $target, 'status' => 'reserved', 'update_time' => $now,
                        ]);
                        $item['expected_base_qty'] = $target;
                        $item['reserved_base_qty'] = $target;
                        $item['shortage_base_qty'] = '0.00';
                        $item['price'] = $price;
                        $item['price_status'] = 'priced';
                        $item['pricing_unit_id'] = $pricingUnitId;
                        $item['pricing_unit_name'] = $pricingUnitName;
                        $item['status'] = 'submitted_ready';
                    }
                    unset($item);
                    Db::name('customer_report')->where('tenant_id', self::tenantId())->where('id', $reportId)
                        ->update(self::summary($items) + ['update_time' => $now]);
                }

                $itemsByWarehouse = [];
                foreach ($items as $item) {
                    if ((string)$item['price_status'] !== 'priced') {
                        self::setError('报货单存在未定价明细，不能转销售');
                        return false;
                    }
                    if (
                        bccomp((string)$item['shortage_base_qty'], '0', self::SCALE) !== 0
                        || bccomp((string)$item['reserved_base_qty'], (string)$item['expected_base_qty'], self::SCALE) !== 0
                    ) {
                        self::setError('报货单库存预留不完整，不能转销售');
                        return false;
                    }
                    $itemsByWarehouse[(int)$item['warehouse_id']][] = $item;
                }

                self::lockGoodsForConversion($items);
                foreach ($itemsByWarehouse as $warehouseId => $warehouseItems) {
                    $goods = [];
                    foreach ($warehouseItems as $item) {
                        $pricingQuantity = self::pricingQuantity($item);
                        if ($pricingQuantity === false) {
                            throw new \RuntimeException('unsupported_pricing_quantity');
                        }
                        $goods[] = [
                            'goods_id' => (int)$item['goods_id'],
                            'sku_id' => (int)($item['sku_id'] ?? 0),
                            'sku_name' => (string)($item['sku_name'] ?? ''),
                            'name' => (string)$item['goods_name'],
                            'units' => (string)$item['pricing_unit_name'],
                            'number' => $pricingQuantity,
                            'base_quantity' => self::decimal((string)$item['expected_base_qty']),
                            'price' => self::decimal((string)$item['price']),
                            'pricing_unit_id' => (int)$item['pricing_unit_id'],
                            'source_line_type' => 'customer_report_item',
                            'source_line_id' => (int)$item['id'],
                            'remark' => (string)$item['line_remark'],
                        ];
                    }
                    $published = SalesOrderLogic::publishReservedWithinTransaction([
                        'customer_id' => (int)$report->main_customer_id,
                        'warehouse_id' => $warehouseId,
                        'goods' => $goods,
                        'order_pay_money' => '0.00',
                        'purpose_type' => 'customer_report',
                        'remarks' => trim('来源报货单：' . (string)$report->sn . ' ' . (string)$report->remark),
                        'source_type' => 'customer_report',
                        'source_id' => $reportId,
                        'source_version' => $version,
                        'idempotent_key' => 'customer-report:' . $reportId . ':warehouse:' . $warehouseId,
                    ]);
                    if ($published === false) {
                        self::setError(SalesOrderLogic::getError() ?: '标准销售单创建失败');
                        throw new \RuntimeException('canonical_sales_order_publish_failed');
                    }
                }

                $now = time();
                foreach ($items as $item) {
                    $reserved = self::decimal((string)$item['reserved_base_qty']);
                    $reservation = Db::name('customer_report_reservation')
                        ->where('tenant_id', self::tenantId())->where('report_item_id', (int)$item['id'])
                        ->lock(true)->find();
                    if (!$reservation) {
                        throw new \RuntimeException('customer_report_reservation_not_found');
                    }
                    Db::name('customer_report_reservation')->where('id', (int)$reservation['id'])->update([
                        'reserved_base_qty' => '0.00',
                        'consumed_base_qty' => bcadd((string)$reservation['consumed_base_qty'], $reserved, self::SCALE),
                        'status' => 'fulfilled',
                        'update_time' => $now,
                    ]);
                    CustomerReportItem::where('tenant_id', self::tenantId())->where('id', (int)$item['id'])->update([
                        'reserved_base_qty' => '0.00',
                        'fulfilled_base_qty' => bcadd((string)$item['fulfilled_base_qty'], $reserved, self::SCALE),
                        'shortage_base_qty' => '0.00',
                        'status' => 'completed',
                        'update_time' => $now,
                    ]);
                }
                CustomerReport::where('tenant_id', self::tenantId())->where('id', $reportId)->where('version', $version)->update([
                    'status' => 'completed',
                    'reserved_base_qty' => '0.00',
                    'shortage_base_qty' => '0.00',
                    'version' => $version + 1,
                    'update_time' => $now,
                ]);
                return self::detailById($reportId);
            });
        } catch (\Throwable) {
            if (!self::hasError()) { self::setError('客户报货转销售失败'); }
            return false;
        }
    }

    /** @return array<string,mixed>|false */
    public static function edit(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('report.edit')) {
            return false;
        }
        $reportId = (int)($params['id'] ?? 0);
        $version = (int)($params['version'] ?? 0);
        $items = CustomerReportLineService::normalizeItems((array)($params['items'] ?? []), (int)($params['main_customer_id'] ?? 0));
        if ($items === false) { self::setError(CustomerReportLineService::getError()); return false; }
        try {
            return self::transactionWithRetry(static function () use ($reportId, $version, $params, $items) {
                $report = self::editableReport($reportId, $version);
                if ($report === false) { return false; }
                $reportTasks = Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('report_id', $reportId)
                    ->order('id asc')->lock(true)->field('id,status')->select()->toArray();
                if (self::hasActivePurchasePlanSource($reportId, true)) {
                    self::setError('报货缺货来源已归入活动采购计划，请先终止采购计划后再编辑');
                    return false;
                }
                $startedTaskIds = [];
                foreach ($reportTasks as $reportTask) {
                    if (in_array((string)$reportTask['status'], ['printed', 'in_progress', 'recovered', 'ready_to_bill', 'completed'], true)) {
                        $startedTaskIds[] = (int)$reportTask['id'];
                    }
                }
                $taskIds = array_map('intval', array_column($reportTasks, 'id'));
                $pendingPrints = $taskIds === [] ? [] : Db::name('fulfillment_print_log')->where('tenant_id', self::tenantId())
                    ->whereIn('task_id', $taskIds)->where('status', 'pending')->order('id asc')->lock(true)->field('id')->select()->toArray();
                if ($startedTaskIds !== [] || $pendingPrints !== []) {
                    self::setError('已有工票进入执行或回收，不能再编辑报货内容');
                    return false;
                }
                $oldItems = CustomerReportItem::where('tenant_id', self::tenantId())->where('report_id', $reportId)->lock(true)->select()->toArray();
                $oldById = [];
                foreach ($oldItems as $old) { $oldById[(int)$old['id']] = $old; }
                $now = time(); $activeIds = []; $summaryRows = [];
                foreach (self::orderedForBalanceLocks($items) as $line) {
                    $old = $oldById[(int)$line['client_line_id']] ?? null;
                    if ($old) {
                        $activeIds[] = (int)$old['id'];
                        $summaryRows[] = self::updateItem((int)$old['id'], $old, $line, $now);
                    } else {
                        $summaryRows[] = self::createItem($reportId, $line, $now);
                    }
                }
                $removedItems = array_values($oldById);
                usort($removedItems, [self::class, 'compareBalanceKey']);
                foreach ($removedItems as $old) {
                    $oldId = (int)$old['id'];
                    if (!in_array($oldId, $activeIds, true)) {
                        self::releaseLine($old);
                        CustomerReportReservation::where('tenant_id', self::tenantId())->where('report_item_id', $oldId)->update(['status' => 'released', 'reserved_base_qty' => '0.00', 'update_time' => $now]);
                        CustomerReportItem::where('tenant_id', self::tenantId())->where('id', $oldId)->update(['delete_time' => $now, 'update_time' => $now]);
                    }
                }
                $summary = self::summary($summaryRows);
                $updated = CustomerReport::where('tenant_id', self::tenantId())->where('id', $reportId)->where('version', $version)->update($summary + [
                    'main_customer_id' => (int)$items[0]['main_customer_id'], 'main_customer_name' => (string)$items[0]['main_customer_name'],
                    'remark' => trim((string)($params['remark'] ?? '')), 'version' => $version + 1, 'update_time' => $now,
                ]);
                if ($updated !== 1) { throw new \RuntimeException('version_conflict'); }
                FulfillmentTaskLogic::syncForReport($reportId);
                return self::detailById($reportId);
            });
        } catch (\Throwable $exception) {
            if ($exception->getMessage() === 'version_conflict') { self::setError('报货单已被其他操作更新，请刷新后重试'); }
            elseif (!self::hasError()) { self::setError('客户报货编辑失败'); }
            return false;
        }
    }

    /** @return array<string,mixed>|false */
    public static function retry(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('report.edit')) {
            return false;
        }
        $reportId = (int)($params['id'] ?? 0); $version = (int)($params['version'] ?? 0);
        try {
            return self::transactionWithRetry(static function () use ($reportId, $version) {
                $report = self::editableReport($reportId, $version);
                if ($report === false) { return false; }
                Db::name('fulfillment_task')->where('tenant_id', self::tenantId())->where('report_id', $reportId)
                    ->order('id')->lock(true)->field('id')->select()->toArray();
                if (self::hasActivePurchasePlanSource($reportId, true)) {
                    self::setError('报货缺货来源已归入活动采购计划，请先终止采购计划后再重试缺货预留');
                    return false;
                }
                $rows = CustomerReportItem::where('tenant_id', self::tenantId())->where('report_id', $reportId)->order(['sku_id' => 'asc', 'goods_id' => 'asc', 'warehouse_id' => 'asc', 'id' => 'asc'])->lock(true)->select()->toArray();
                $now = time(); $summaryRows = [];
                foreach ($rows as $row) {
                    $needed = bcsub(self::decimal((string)$row['expected_base_qty']), self::decimal((string)$row['reserved_base_qty']), self::SCALE);
                    $added = bccomp($needed, '0.00', self::SCALE) > 0 ? WarehouseSkuBalanceService::reserveUpToWithinTransaction((int)$row['warehouse_id'], (int)$row['sku_id'], $needed) : '0.00';
                    if ($added === false) { throw new \RuntimeException('reserve_failed'); }
                    $reserved = bcadd(self::decimal((string)$row['reserved_base_qty']), $added, self::SCALE);
                    $shortage = bcsub(self::decimal((string)$row['expected_base_qty']), $reserved, self::SCALE);
                    $status = bccomp($shortage, '0.00', self::SCALE) === 0 ? 'submitted_ready' : 'submitted_shortage';
                    CustomerReportItem::where('tenant_id', self::tenantId())->where('id', (int)$row['id'])->update(['reserved_base_qty'=>$reserved,'shortage_base_qty'=>$shortage,'status'=>$status,'update_time'=>$now]);
                    CustomerReportReservation::where('tenant_id', self::tenantId())->where('report_item_id', (int)$row['id'])->update(['reserved_base_qty'=>$reserved,'status'=>$status === 'submitted_ready' ? 'reserved' : 'reserved','update_time'=>$now]);
                    $row['reserved_base_qty'] = $reserved; $row['shortage_base_qty'] = $shortage; $summaryRows[] = $row;
                }
                $summary = self::summary($summaryRows);
                CustomerReport::where('tenant_id', self::tenantId())->where('id', $reportId)->where('version', $version)->update($summary + ['version'=>$version+1,'update_time'=>$now]);
                FulfillmentTaskLogic::syncForReport($reportId);
                return self::detailById($reportId);
            });
        } catch (\Throwable $exception) {
            if (!self::hasError()) { self::setError($exception->getMessage() === 'reserve_failed' ? '补预留失败，请重试' : '缺货重试失败'); }
            return false;
        }
    }

    /** @return array<string,mixed>|false */
    public static function cancel(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('report.edit')) {
            return false;
        }
        $reportId = (int)($params['id'] ?? 0); $version = (int)($params['version'] ?? 0);
        $reason = trim((string)($params['reason'] ?? ''));
        if ($reason === '' || mb_strlen($reason) > 255) {
            self::setError('取消报货单必须填写原因');
            return false;
        }
        try {
            return self::transactionWithRetry(static function () use ($reportId, $version, $reason) {
                $report = CustomerReport::where('tenant_id', self::tenantId())->where('id', $reportId)->lock(true)->find();
                if (!$report) {
                    self::setError('报货单不存在、版本冲突或不可取消'); return false;
                }
                if ((string)$report->status === 'cancelled') {
                    $currentVersion = (int)$report->version;
                    if (($version === $currentVersion || $version === $currentVersion - 1)
                        && (string)$report->cancellation_reason === $reason) {
                        return self::detailById($reportId);
                    }
                    self::setError('报货单已取消，取消请求与已保存事实不一致'); return false;
                }
                if ((int)$report->version !== $version || (string)$report->status === 'completed') {
                    self::setError('报货单不存在、版本冲突或不可取消'); return false;
                }
                Db::name('fulfillment_task_group')->where('tenant_id', self::tenantId())
                    ->where('report_id', $reportId)->lock(true)->find();
                $tasks = Db::name('fulfillment_task')->where('tenant_id', self::tenantId())
                    ->where('report_id', $reportId)->order('id')->lock(true)->select()->toArray();
                if (self::hasActivePurchasePlanSource($reportId, true)) {
                    self::setError('报货缺货来源已归入活动采购计划，请先终止采购计划后再取消报货');
                    return false;
                }
                $taskIds = array_map(static fn(array $task): int => (int)$task['id'], $tasks);
                $hasPrintAttempt = $taskIds !== [] && Db::name('fulfillment_print_log')
                    ->where('tenant_id', self::tenantId())->whereIn('task_id', $taskIds)->lock(true)->count() > 0;
                $hasStartedTask = array_filter($tasks, static fn(array $task): bool => !in_array(
                    (string)$task['status'], ['unassigned', 'blocked', 'printable', 'exception', 'cancelled'], true
                )) !== [];
                if ($hasPrintAttempt || $hasStartedTask) {
                    self::setError('报货单已经开始纸票或履约作业，不能取消'); return false;
                }
                $items = CustomerReportItem::where('tenant_id', self::tenantId())->where('report_id', $reportId)->order(['goods_id' => 'asc', 'warehouse_id' => 'asc', 'id' => 'asc'])->lock(true)->select()->toArray();
                $now=time();
                $before = $report->toArray();
                foreach ($items as $item) {
                    self::releaseLine($item);
                    CustomerReportItem::where('tenant_id', self::tenantId())->where('id',(int)$item['id'])->update(['reserved_base_qty'=>'0.00','shortage_base_qty'=>'0.00','status'=>'cancelled','update_time'=>$now]);
                    CustomerReportReservation::where('tenant_id', self::tenantId())->where('report_item_id',(int)$item['id'])->update(['reserved_base_qty'=>'0.00','released_base_qty'=>self::decimal((string)$item['reserved_base_qty']),'status'=>'released','update_time'=>$now]);
                }
                $cancelled = [
                    'status'=>'cancelled','reserved_base_qty'=>'0.00','shortage_base_qty'=>'0.00',
                    'cancellation_reason'=>$reason,'cancelled_by'=>self::operatorId(),'cancelled_time'=>$now,
                    'version'=>$version+1,'update_time'=>$now,
                ];
                $updated = CustomerReport::where('tenant_id', self::tenantId())->where('id',$reportId)
                    ->where('version',$version)->update($cancelled);
                if ($updated !== 1) { throw new \RuntimeException('version_conflict'); }
                FulfillmentTaskLogic::cancelReport($reportId);
                AuditService::logWithinTransaction(
                    AuditService::MODULE_CUSTOMER_REPORT,
                    AuditService::ACTION_CANCEL,
                    $reportId,
                    (string)$report->sn,
                    $before,
                    array_replace($before, $cancelled),
                    $reason
                );
                return self::detailById($reportId);
            });
        } catch (\Throwable) { if (!self::hasError()) { self::setError('取消客户报货失败'); } return false; }
    }

    /** @return array<string,mixed>|false */
    public static function saveProcessingWeights(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('settlement.weight')) {
            return false;
        }
        $reportId = (int)($params['id'] ?? 0);
        $version = (int)($params['version'] ?? 0);
        $submitted = (array)($params['groups'] ?? []);
        if ($reportId <= 0 || $version <= 0 || $submitted === []) {
            self::setError('报货单、版本和加工组实重不能为空');
            return false;
        }
        $weights = [];
        foreach ($submitted as $entry) {
            $groupId = (int)($entry['id'] ?? 0);
            $weightText = trim((string)($entry['final_actual_weight'] ?? ''));
            if ($groupId <= 0 || isset($weights[$groupId])
                || preg_match('/^\d+(?:\.\d{1,2})?$/', $weightText) !== 1
                || bccomp($weightText, '0.00', self::SCALE) <= 0) {
                self::setError('每个加工组只能提交一次且最终实重必须大于 0，最多保留两位小数');
                return false;
            }
            $weights[$groupId] = self::decimal($weightText);
        }
        try {
            return self::transactionWithRetry(static function () use ($reportId, $version, $weights) {
                $report = CustomerReport::where('tenant_id', self::tenantId())->where('id', $reportId)->lock(true)->find();
                if (!$report || (int)$report->version !== $version
                    || !in_array((string)$report->status, self::SUBMITTED, true)) {
                    self::setError('报货单不存在、版本冲突或当前状态不可录入实重');
                    return false;
                }
                if (bccomp((string)$report->shortage_base_qty, '0.00', self::SCALE) !== 0) {
                    self::setError('报货单仍有采购缺口，不能进入待开单称重');
                    return false;
                }
                if (DeliveryInventoryLogic::hasCompletedDelivery($reportId)) {
                    self::setError('报货单已经发生真实交付，不能再修改加工组实重');
                    return false;
                }
                $groups = Db::name('customer_report_processing_group')->where('tenant_id', self::tenantId())
                    ->where('report_id', $reportId)->order(['report_item_id' => 'asc', 'sort' => 'asc', 'id' => 'asc'])
                    ->lock(true)->select()->toArray();
                if ($groups === [] || count($weights) !== count($groups)) {
                    self::setError('必须一次提交该报货单全部加工组的最终实重');
                    return false;
                }
                $expectedGroupIds = array_map(static fn(array $group): int => (int)$group['id'], $groups);
                $submittedGroupIds = array_map('intval', array_keys($weights));
                sort($expectedGroupIds, SORT_NUMERIC);
                sort($submittedGroupIds, SORT_NUMERIC);
                if ($expectedGroupIds !== $submittedGroupIds) {
                    self::setError('提交的加工组与当前报货单不一致，请刷新后重试');
                    return false;
                }
                $items = CustomerReportItem::where('tenant_id', self::tenantId())->where('report_id', $reportId)
                    ->whereNull('delete_time')->order('id')->lock(true)->select()->toArray();
                $groupedItemIds = array_values(array_unique(array_map(
                    static fn(array $group): int => (int)$group['report_item_id'],
                    $groups
                )));
                $reportItemIds = array_map(static fn(array $item): int => (int)$item['id'], $items);
                sort($groupedItemIds, SORT_NUMERIC);
                sort($reportItemIds, SORT_NUMERIC);
                if ($items === [] || $groupedItemIds !== $reportItemIds) {
                    self::setError('报货明细缺少加工分组，不能汇总最终实重');
                    return false;
                }
                $totals = [];
                $now = time();
                foreach ($groups as $group) {
                    $groupId = (int)$group['id'];
                    $itemId = (int)$group['report_item_id'];
                    $totals[$itemId] = bcadd($totals[$itemId] ?? '0.00', $weights[$groupId], self::SCALE);
                    Db::name('customer_report_processing_group')->where('tenant_id', self::tenantId())
                        ->where('id', $groupId)->update(['final_actual_weight' => $weights[$groupId], 'update_time' => $now]);
                }
                foreach ($items as $item) {
                    $itemId = (int)$item['id'];
                    CustomerReportItem::where('tenant_id', self::tenantId())->where('id', $itemId)->update([
                        'final_actual_weight' => $totals[$itemId],
                        'final_weight_task_id' => 0,
                        'fulfillment_status' => 'final_weight_recorded',
                        'update_time' => $now,
                    ]);
                }
                $updated = CustomerReport::where('tenant_id', self::tenantId())->where('id', $reportId)
                    ->where('version', $version)->update(['version' => $version + 1, 'update_time' => $now]);
                if ($updated !== 1) {
                    throw new \RuntimeException('version_conflict');
                }
                return self::detailById($reportId);
            });
        } catch (\Throwable $exception) {
            if (!self::hasError()) {
                self::setError($exception->getMessage() === 'version_conflict'
                    ? '报货单已被其他操作更新，请刷新后重试'
                    : '加工组最终实重保存失败');
            }
            return false;
        }
    }

    /** @param array<int,array<string,mixed>> $items @return array<string,mixed> */
    private static function writeNewItems(int $reportId, array $items, int $now): array
    {
        $rows=[];
        foreach (self::orderedForBalanceLocks($items) as $line) { $rows[]=self::createItem($reportId,$line,$now); }
        return self::summary($rows);
    }
    /** @param array<string,mixed> $line @return array<string,mixed> */
    private static function createItem(int $reportId, array $line, int $now): array
    {
        $reserved=WarehouseSkuBalanceService::reserveUpToWithinTransaction((int)$line['warehouse_id'],(int)$line['sku_id'],(string)$line['expected_base_qty']);
        if ($reserved===false) { throw new \RuntimeException('reserve_failed'); }
        $shortage=bcsub((string)$line['expected_base_qty'],$reserved,self::SCALE); $status=bccomp($shortage,'0.00',self::SCALE)===0?'submitted_ready':'submitted_shortage';
        $groups = (array)($line['processing_groups'] ?? []);
        $data=$line; unset($data['client_line_id'], $data['processing_groups']);
        $itemId = (int)Db::name('customer_report_item')->insertGetId($data+['tenant_id'=>self::tenantId(),'report_id'=>$reportId,'reserved_base_qty'=>$reserved,'shortage_base_qty'=>$shortage,'fulfilled_base_qty'=>'0.00','status'=>$status,'create_time'=>$now,'update_time'=>$now]);
        self::replaceProcessingGroups($reportId, $itemId, $groups, $now);
        Db::name('customer_report_reservation')->insert(['tenant_id'=>self::tenantId(),'report_id'=>$reportId,'report_item_id'=>$itemId,'warehouse_id'=>(int)$line['warehouse_id'],'goods_id'=>(int)$line['goods_id'],'sku_id'=>(int)$line['sku_id'],'reserved_base_qty'=>$reserved,'consumed_base_qty'=>'0.00','released_base_qty'=>'0.00','status'=>'reserved','create_time'=>$now,'update_time'=>$now]);
        CustomerReportPreferenceService::remember($line);
        return $line+['id'=>$itemId,'reserved_base_qty'=>$reserved,'shortage_base_qty'=>$shortage,'status'=>$status];
    }
    /** @param array<string,mixed> $old @param array<string,mixed> $line @return array<string,mixed> */
    private static function updateItem(int $itemId, array $old, array $line, int $now): array
    {
        $oldReserved=self::decimal((string)$old['reserved_base_qty']); $target=(string)$line['expected_base_qty'];
        if ((int)$old['warehouse_id']===(int)$line['warehouse_id'] && (int)$old['sku_id']===(int)$line['sku_id']) {
            $delta=bcsub($target,$oldReserved,self::SCALE);
            if (bccomp($delta,'0.00',self::SCALE)<0) { if (WarehouseSkuBalanceService::releaseWithinTransaction((int)$old['warehouse_id'],(int)$old['sku_id'],ltrim($delta,'-'))===false) { throw new \RuntimeException('release_failed'); } $reserved=$target; }
            else { $added=bccomp($delta,'0.00',self::SCALE)>0?WarehouseSkuBalanceService::reserveUpToWithinTransaction((int)$old['warehouse_id'],(int)$old['sku_id'],$delta):'0.00'; if ($added===false) { throw new \RuntimeException('reserve_failed'); } $reserved=bcadd($oldReserved,$added,self::SCALE); }
        } else {
            self::releaseLine($old); $reserved=WarehouseSkuBalanceService::reserveUpToWithinTransaction((int)$line['warehouse_id'],(int)$line['sku_id'],$target); if ($reserved===false) { throw new \RuntimeException('reserve_failed'); }
        }
        $shortage=bcsub($target,$reserved,self::SCALE); $status=bccomp($shortage,'0.00',self::SCALE)===0?'submitted_ready':'submitted_shortage';
        $groups = (array)($line['processing_groups'] ?? []);
        $data=$line; unset($data['client_line_id'], $data['processing_groups']);
        CustomerReportItem::where('tenant_id',self::tenantId())->where('id',$itemId)->update($data+['reserved_base_qty'=>$reserved,'shortage_base_qty'=>$shortage,'status'=>$status,'update_time'=>$now]);
        self::replaceProcessingGroups((int)$old['report_id'], $itemId, $groups, $now);
        CustomerReportReservation::where('tenant_id',self::tenantId())->where('report_item_id',$itemId)->update(['warehouse_id'=>(int)$line['warehouse_id'],'goods_id'=>(int)$line['goods_id'],'sku_id'=>(int)$line['sku_id'],'reserved_base_qty'=>$reserved,'status'=>'reserved','update_time'=>$now]);
        CustomerReportPreferenceService::remember($line);
        return $line+['reserved_base_qty'=>$reserved,'shortage_base_qty'=>$shortage,'status'=>$status];
    }
    /** @param array<string,mixed> $item */
    private static function releaseLine(array $item): void
    {
        $reserved=self::decimal((string)($item['reserved_base_qty']??0));
        if (bccomp($reserved,'0.00',self::SCALE)>0 && WarehouseSkuBalanceService::releaseWithinTransaction((int)$item['warehouse_id'],(int)$item['sku_id'],$reserved)===false) { throw new \RuntimeException('release_failed'); }
    }
    /** @param array<int,array<string,mixed>> $rows @return array<string,string> */
    private static function summary(array $rows): array
    {
        $total='0.00';$reserved='0.00';$shortage='0.00';
        foreach($rows as $row){$total=bcadd($total,self::decimal((string)$row['expected_base_qty']),self::SCALE);$reserved=bcadd($reserved,self::decimal((string)$row['reserved_base_qty']),self::SCALE);$shortage=bcadd($shortage,self::decimal((string)$row['shortage_base_qty']),self::SCALE);}
        return ['total_base_qty'=>$total,'reserved_base_qty'=>$reserved,'shortage_base_qty'=>$shortage,'status'=>bccomp($shortage,'0.00',self::SCALE)===0?'submitted_ready':'submitted_shortage'];
    }
    /** @param array<int,array<string,mixed>> $items @return array<int,array<string,mixed>> */
    private static function orderedForBalanceLocks(array $items): array
    {
        usort($items, [self::class, 'compareBalanceKey']);
        return $items;
    }

    public static function completePurchaseForItem(int $itemId): bool
    {
        self::clearError();
        try {
            return Db::transaction(static function () use ($itemId): bool {
                $item = CustomerReportItem::where('tenant_id', self::tenantId())->where('id', $itemId)->whereNull('delete_time')->lock(true)->find();
                if (!$item) {
                    self::setError('采购任务关联的报货明细不存在');
                    return false;
                }
                $shortage = self::decimal((string)$item->shortage_base_qty);
                if (bccomp($shortage, '0.00', self::SCALE) <= 0) {
                    return true;
                }
                $added = WarehouseSkuBalanceService::reserveUpToWithinTransaction((int)$item->warehouse_id, (int)$item->sku_id, $shortage);
                if ($added === false || bccomp($added, $shortage, self::SCALE) !== 0) {
                    self::setError('采购库存尚未入库或数量不足，不能完成采购工票');
                    throw new \RuntimeException('purchase_stock_shortage');
                }
                $now = time();
                $reserved = bcadd(self::decimal((string)$item->reserved_base_qty), $added, self::SCALE);
                CustomerReportItem::where('tenant_id', self::tenantId())->where('id', $itemId)->update([
                    'reserved_base_qty' => $reserved, 'shortage_base_qty' => '0.00', 'status' => 'submitted_ready', 'update_time' => $now,
                ]);
                CustomerReportReservation::where('tenant_id', self::tenantId())->where('report_item_id', $itemId)->update([
                    'reserved_base_qty' => $reserved, 'status' => 'reserved', 'update_time' => $now,
                ]);
                $rows = CustomerReportItem::where('tenant_id', self::tenantId())->where('report_id', (int)$item->report_id)
                    ->whereNull('delete_time')->select()->toArray();
                $report = CustomerReport::where('tenant_id', self::tenantId())->where('id', (int)$item->report_id)->lock(true)->find();
                if ($report) {
                    CustomerReport::where('tenant_id', self::tenantId())->where('id', (int)$report->id)->update(
                        self::summary($rows) + ['version' => (int)$report->version + 1, 'update_time' => $now]
                    );
                }
                return true;
            });
        } catch (\Throwable) {
            if (!self::hasError()) {
                self::setError('采购库存确认失败');
            }
            return false;
        }
    }

    /**
     * 采购计划到货已在采购批次事务内整体锁定；此处只把持有量归因到明确选择的来源。
     */
    public static function allocateHeldPurchaseForItemWithinTransaction(int $itemId, string $quantity): bool
    {
        self::clearError();
        $quantity = self::decimal($quantity);
        if ($itemId <= 0 || bccomp($quantity, '0.00', self::SCALE) <= 0) {
            self::setError('采购来源分配数量必须大于 0');
            return false;
        }
        $item = CustomerReportItem::where('tenant_id', self::tenantId())->where('id', $itemId)
            ->whereNull('delete_time')->lock(true)->find();
        if (!$item) {
            self::setError('采购来源关联的报货明细不存在');
            return false;
        }
        $shortage = self::decimal((string)$item->shortage_base_qty);
        if (bccomp($quantity, $shortage, self::SCALE) > 0) {
            self::setError('采购来源分配数量超过当前未满足缺口');
            return false;
        }
        $now = time();
        $reserved = bcadd(self::decimal((string)$item->reserved_base_qty), $quantity, self::SCALE);
        $remaining = bcsub($shortage, $quantity, self::SCALE);
        $status = bccomp($remaining, '0.00', self::SCALE) === 0 ? 'submitted_ready' : 'submitted_shortage';
        CustomerReportItem::where('tenant_id', self::tenantId())->where('id', $itemId)->update([
            'reserved_base_qty' => $reserved,
            'shortage_base_qty' => $remaining,
            'status' => $status,
            'update_time' => $now,
        ]);
        CustomerReportReservation::where('tenant_id', self::tenantId())->where('report_item_id', $itemId)->update([
            'reserved_base_qty' => $reserved,
            'status' => 'reserved',
            'update_time' => $now,
        ]);
        $report = CustomerReport::where('tenant_id', self::tenantId())->where('id', (int)$item->report_id)
            ->lock(true)->find();
        if (!$report) {
            self::setError('采购来源关联的报货单不存在');
            return false;
        }
        $rows = CustomerReportItem::where('tenant_id', self::tenantId())->where('report_id', (int)$item->report_id)
            ->whereNull('delete_time')->select()->toArray();
        CustomerReport::where('tenant_id', self::tenantId())->where('id', (int)$report->id)->update(
            self::summary($rows) + ['version' => (int)$report->version + 1, 'update_time' => $now]
        );
        FulfillmentTaskLogic::syncForReport((int)$report->id);
        return true;
    }
    /** @param array<int,array<string,mixed>> $items */
    private static function lockGoodsForConversion(array $items): void
    {
        $goodsIds = array_values(array_unique(array_map(
            static fn(array $item): int => (int)$item['goods_id'],
            $items
        )));
        sort($goodsIds, SORT_NUMERIC);
        foreach ($goodsIds as $goodsId) {
            $lockedId = (int)Db::name('goods')->where('tenant_id', self::tenantId())
                ->where('id', $goodsId)->lock(true)->value('id');
            if ($lockedId !== $goodsId) {
                throw new \RuntimeException('customer_report_goods_not_found');
            }
        }
    }
    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private static function compareBalanceKey(array $left, array $right): int
    {
        return [(int)$left['sku_id'], (int)$left['goods_id'], (int)$left['warehouse_id'], (int)($left['sort'] ?? $left['id'] ?? 0)] <=> [(int)$right['sku_id'], (int)$right['goods_id'], (int)$right['warehouse_id'], (int)($right['sort'] ?? $right['id'] ?? 0)];
    }
    /** @return CustomerReport|false */
    private static function editableReport(int $id,int $version): CustomerReport|false
    {
        $report=CustomerReport::where('tenant_id',self::tenantId())->where('id',$id)->lock(true)->find();
        if (!$report || (int)$report->version!==$version || !in_array((string)$report->status,self::SUBMITTED,true)) { self::setError('报货单不存在、版本冲突或当前状态不可编辑'); return false; }
        return $report;
    }
    /** @return array<string,mixed>|false */
    private static function detailById(int $id): array|false
    {
        $report=CustomerReport::where('tenant_id',self::tenantId())->where('id',$id)->find();
        if (!$report) { self::setError('客户报货单不存在'); return false; }
        $data=$report->toArray();
        $data['items']=CustomerReportItem::where('tenant_id',self::tenantId())->where('report_id',$id)->whereNull('delete_time')->order('sort asc,id asc')->select()->toArray();
        foreach ($data['items'] as &$item) {
            $item['processing_groups'] = self::processingGroupsForItem((int)$item['id']);
        }
        unset($item);
        $data['sales_orders'] = self::salesOrdersByReport($id);
        $data['task_group'] = FulfillmentTaskLogic::groupForReport($id);
        $data['actions'] = self::actionStates($id, $data, $data['items']);
        return $data;
    }

    /** @param array<int,array<string,mixed>> $groups */
    private static function replaceProcessingGroups(int $reportId, int $itemId, array $groups, int $now): void
    {
        $existingIds = array_map('intval', Db::name('customer_report_processing_group')->where('tenant_id', self::tenantId())
            ->where('report_item_id', $itemId)->column('id'));
        if ($existingIds !== []) {
            Db::name('customer_report_processing_group_process')->where('tenant_id', self::tenantId())
                ->whereIn('processing_group_id', $existingIds)->delete();
        }
        Db::name('customer_report_processing_group')->where('tenant_id', self::tenantId())->where('report_item_id', $itemId)->delete();
        foreach ($groups as $group) {
            $groupId = (int)Db::name('customer_report_processing_group')->insertGetId([
                'tenant_id' => self::tenantId(), 'report_id' => $reportId, 'report_item_id' => $itemId,
                'group_key' => (string)$group['group_key'], 'name' => (string)$group['name'],
                'planned_qty' => (string)$group['planned_qty'], 'final_actual_weight' => '0.00',
                'sort' => (int)$group['sort'], 'create_time' => $now, 'update_time' => $now,
            ]);
            foreach (array_values((array)$group['processes']) as $step => $process) {
                Db::name('customer_report_processing_group_process')->insert([
                    'tenant_id' => self::tenantId(), 'processing_group_id' => $groupId,
                    'process_id' => (int)$process['id'], 'process_name_snapshot' => (string)$process['name'],
                    'process_sort_snapshot' => (int)$process['sort'], 'step_no' => $step + 1, 'create_time' => $now,
                ]);
            }
        }
    }

    /** @return array<int,array<string,mixed>> */
    private static function processingGroupsForItem(int $itemId): array
    {
        $groups = Db::name('customer_report_processing_group')->where('tenant_id', self::tenantId())
            ->where('report_item_id', $itemId)->order(['sort' => 'asc', 'id' => 'asc'])->select()->toArray();
        foreach ($groups as &$group) {
            $group['processes'] = Db::name('customer_report_processing_group_process')->where('tenant_id', self::tenantId())
                ->where('processing_group_id', (int)$group['id'])->order(['step_no' => 'asc', 'id' => 'asc'])->select()->toArray();
        }
        unset($group);
        return $groups;
    }

    /** @param array<string,mixed> $report @param array<int,array<string,mixed>> $items @return array<string,array<string,mixed>> */
    private static function actionStates(int $reportId, array $report, array $items): array
    {
        $canEditReport = WorkforceLogic::hasPermission('report.edit');
        $canBill = WorkforceLogic::hasPermission('settlement.bill');
        $canRecordWeight = WorkforceLogic::hasPermission('settlement.weight');
        $hasActivePurchasePlanSource = self::hasActivePurchasePlanSource($reportId);
        $conversion = $canBill
            ? self::conversionReadiness($reportId, $report, $items)
            : ['allowed' => false, 'blocked_reason' => '没有执行该操作的电子权限'];
        $cancellation = $canEditReport
            ? self::cancellationActionState($reportId, $report)
            : ['allowed' => false, 'blocked_reason' => '没有执行该操作的电子权限', 'reason_required' => true];
        return [
            'retry' => [
                'allowed' => $canEditReport
                    && !$hasActivePurchasePlanSource
                    && (string)($report['status'] ?? '') === 'submitted_shortage',
                'blocked_reason' => !$canEditReport
                    ? '没有执行该操作的电子权限'
                    : ($hasActivePurchasePlanSource
                        ? '报货缺货来源已归入活动采购计划，请先终止采购计划'
                        : ((string)($report['status'] ?? '') === 'submitted_shortage' ? '' : '当前报货单没有可重试的缺货预留')),
            ],
            'cancel' => $cancellation,
            'convert' => [
                'allowed' => $conversion['allowed'],
                'blocked_reason' => $conversion['blocked_reason'],
            ],
            'record_processing_weights' => [
                'allowed' => $canRecordWeight
                    && in_array((string)($report['status'] ?? ''), self::SUBMITTED, true)
                    && bccomp((string)($report['shortage_base_qty'] ?? '0'), '0', self::SCALE) === 0
                    && $items !== []
                    && count(array_filter($items, static fn(array $item): bool => (array)($item['processing_groups'] ?? []) !== [])) === count($items)
                    && !DeliveryInventoryLogic::hasCompletedDelivery($reportId),
                'blocked_reason' => !$canRecordWeight
                    ? '没有录入最终实重的电子权限'
                    : (bccomp((string)($report['shortage_base_qty'] ?? '0'), '0', self::SCALE) !== 0
                        ? '报货单仍有采购缺口'
                        : '当前报货单不能录入加工组实重'),
            ],
        ];
    }

    /** @param array<string,mixed> $report @return array{allowed:bool,blocked_reason:string,reason_required:bool} */
    private static function cancellationActionState(int $reportId, array $report): array
    {
        $unavailable = static fn(string $reason): array => [
            'allowed' => false,
            'blocked_reason' => $reason,
            'reason_required' => true,
        ];
        if (in_array((string)($report['status'] ?? ''), ['cancelled', 'completed'], true)) {
            return $unavailable('当前报货单不可取消');
        }
        if (self::hasActivePurchasePlanSource($reportId)) {
            return $unavailable('报货缺货来源已归入活动采购计划，请先终止采购计划');
        }
        $tasks = Db::name('fulfillment_task')->where('tenant_id', self::tenantId())
            ->where('report_id', $reportId)->order('id')->select()->toArray();
        $taskIds = array_map(static fn(array $task): int => (int)$task['id'], $tasks);
        $hasPrintAttempt = $taskIds !== [] && Db::name('fulfillment_print_log')
            ->where('tenant_id', self::tenantId())->whereIn('task_id', $taskIds)->count() > 0;
        $hasStartedTask = array_filter($tasks, static fn(array $task): bool => !in_array(
            (string)$task['status'], ['unassigned', 'blocked', 'printable', 'exception', 'cancelled'], true
        )) !== [];
        if ($hasPrintAttempt || $hasStartedTask) {
            return $unavailable('报货单已经开始纸票或履约作业，不能取消');
        }
        return ['allowed' => true, 'blocked_reason' => '', 'reason_required' => true];
    }

    private static function hasActivePurchasePlanSource(int $reportId, bool $lock = false): bool
    {
        $query = Db::name('purchase_plan_source')->alias('s')
            ->join('purchase_plan p', 'p.id=s.purchase_plan_id AND p.tenant_id=s.tenant_id')
            ->where('s.tenant_id', self::tenantId())
            ->where('s.report_id', $reportId)
            ->whereIn('p.status', ['pending', 'partial'])
            ->field('s.id');
        if ($lock) {
            $query->lock(true);
        }
        return (bool)$query->find();
    }

    /**
     * 在详情展示和转换事务中共用同一份履约前置条件。
     * 库存与版本仍必须在转换事务内再次裁决，避免详情读取后的并发变化绕过校验。
     *
     * @param array<string,mixed> $report
     * @param array<int,array<string,mixed>> $items
     * @return array{allowed:bool,blocked_reason:string,items:array<int,array<string,mixed>>,has_task_group:bool,settlements:array<int,array<string,mixed>>}
     */
    private static function conversionReadiness(int $reportId, array $report, array $items, bool $lock = false): array
    {
        $unavailable = static fn(string $reason): array => [
            'allowed' => false,
            'blocked_reason' => $reason,
            'items' => [],
            'has_task_group' => false,
            'settlements' => [],
        ];
        if (
            (string)($report['status'] ?? '') !== 'submitted_ready'
            || bccomp((string)($report['shortage_base_qty'] ?? '0'), '0', self::SCALE) !== 0
        ) {
            return $unavailable('报货单不存在、版本冲突或不可转销售');
        }
        if ($items === []) {
            return $unavailable('报货单没有可转销售的明细');
        }
        $usesProcessingGroups = Db::name('customer_report_processing_group')
            ->where('tenant_id', self::tenantId())
            ->where('report_id', $reportId)
            ->count() > 0;
        if ($usesProcessingGroups) {
            return $unavailable('加工组报货请先录入分组实重并确认真实交付，系统会生成待结算销售单');
        }
        $items = array_values(array_filter(
            $items,
            static fn(array $item): bool => (string)($item['fulfillment_status'] ?? 'pending') !== 'undelivered'
        ));
        if ($items === []) {
            return $unavailable('报货单没有实际交付明细，不能生成销售单');
        }

        $hasTaskGroup = Db::name('fulfillment_task_group')->where('tenant_id', self::tenantId())
            ->where('report_id', $reportId)->count() > 0;
        $settlements = [];
        if ($hasTaskGroup) {
            if (FulfillmentTaskLogic::hasUnaccountedPaperForReport($reportId)) {
                return $unavailable('仍有未回收或未完成作废控制的纸质工票，不能结算');
            }
            if (!DeliveryInventoryLogic::hasCompletedDelivery($reportId)) {
                return $unavailable('必须先确认真实交付事件；车辆离店或手工改任务状态都不能触发出库');
            }
            $bookkeepingQuery = Db::name('fulfillment_task')->where('tenant_id', self::tenantId())
                ->where('report_id', $reportId)->where('source_key', 'report:' . $reportId . ':bookkeeping');
            if ($lock) { $bookkeepingQuery->lock(true); }
            $bookkeeping = $bookkeepingQuery->find();
            if (!$bookkeeping || (string)$bookkeeping['status'] !== 'ready_to_bill') {
                return $unavailable('送货与记账工票尚未完成，不能提前开单');
            }
            $settlements = FulfillmentTaskLogic::settlementValuesForReport($reportId);
            foreach ($items as $item) {
                $itemId = (int)$item['id'];
                if (!isset($settlements[$itemId])) {
                    return $unavailable('每条加工明细的最终工序票都必须先回收并录入大于 0 的最终实重、实价');
                }
                if (bccomp((string)$settlements[$itemId]['actual_price'], '0.00', self::SCALE) <= 0) {
                    return $unavailable('未定价明细不能开单，请先录入大于 0 的已确认单价');
                }
            }
        } else {
            foreach ($items as $item) {
                if ((string)$item['price_status'] !== 'priced') {
                    return $unavailable('报货单存在未定价明细，不能转销售');
                }
                if (
                    bccomp((string)$item['shortage_base_qty'], '0', self::SCALE) !== 0
                    || bccomp((string)$item['reserved_base_qty'], (string)$item['expected_base_qty'], self::SCALE) !== 0
                ) {
                    return $unavailable('报货单库存预留不完整，不能转销售');
                }
            }
        }
        return [
            'allowed' => true,
            'blocked_reason' => '',
            'items' => $items,
            'has_task_group' => $hasTaskGroup,
            'settlements' => $settlements,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private static function salesOrdersByReport(int $reportId, bool $lock = false): array
    {
        $query = Db::name('sales_order')->where('tenant_id', self::tenantId())
            ->where('source_type', 'customer_report')->where('source_id', $reportId)
            ->order(['warehouse_id' => 'asc', 'id' => 'asc']);
        if ($lock) { $query->lock(true); }
        $orders = $query->select()->toArray();
        foreach ($orders as &$order) {
            $order['items'] = Db::name('order_goods')->where('tenant_id', self::tenantId())
                ->where('order_type', 'sales')->where('order_id', (int)$order['id'])
                ->order(['sort' => 'asc', 'id' => 'asc'])->select()->toArray();
        }
        unset($order);
        return $orders;
    }

    private static function pricingQuantity(array $item): string|false
    {
        $pricingUnitId = (int)$item['pricing_unit_id'];
        $pricingUnitName = trim((string)$item['pricing_unit_name']);
        $baseUnitId = (int)$item['base_unit_id'];
        $baseUnitName = trim((string)$item['base_unit_name']);
        if (
            ($pricingUnitId > 0 && $baseUnitId > 0 && $pricingUnitId === $baseUnitId)
            || ($pricingUnitName !== '' && $pricingUnitName === $baseUnitName)
        ) {
            return self::decimal((string)$item['expected_base_qty']);
        }
        $orderUnitId = (int)$item['unit_id'];
        $orderUnitName = trim((string)$item['unit_name']);
        if (
            ($pricingUnitId > 0 && $orderUnitId > 0 && $pricingUnitId === $orderUnitId)
            || ($pricingUnitName !== '' && $pricingUnitName === $orderUnitName)
        ) {
            return self::decimal((string)$item['order_qty']);
        }
        self::setError('计价单位暂不支持转换为标准销售单');
        return false;
    }
    /** @template T @param callable():T $operation @return T */
    private static function transactionWithRetry(callable $operation): mixed
    {
        $exception = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return Db::transaction($operation);
            } catch (\Throwable $caught) {
                $exception = $caught;
                if (self::isRetryableTransactionError($caught) && $attempt < 2) {
                    usleep(($attempt + 1) * 20_000);
                    continue;
                }
                throw $caught;
            }
        }
        throw $exception ?? new \RuntimeException('transaction_failed');
    }
    private static function fingerprint(array $params): string { unset($params['idempotency_key']); return hash('sha256',json_encode(self::sort($params),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); }
    private static function sort(array $value): array { foreach($value as $key=>$item){if(is_array($item)){$value[$key]=self::sort($item);}} if(array_keys($value)!==range(0,count($value)-1)){ksort($value);} return $value; }
    private static function quantity(mixed $value,bool $allowZero=false): string|false { $value=trim((string)$value); if($value===''||preg_match('/^\d+(?:\.\d{1,2})?$/',$value)!==1){return false;} $value=self::decimal($value); return bccomp($value,'0.00',self::SCALE)>0||($allowZero&&bccomp($value,'0.00',self::SCALE)===0)?$value:false; }
    private static function decimal(string $value): string { return bcadd($value,'0',self::SCALE); }
    private static function deliveryDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return date('Y-m-d');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('invalid_delivery_date');
        }
        return $value;
    }
    /** @param array<int,array<string,mixed>> $items @return array<string,mixed>|null|false */
    private static function lockBatchForSubmit(
        int $batchId,
        string $deliveryDate,
        bool $isSupplement,
        int $supplementForReportId,
        array $items
    ): array|null|false
    {
        if ($batchId <= 0) {
            if ($isSupplement) {
                self::setError('补报必须进入原报货批次');
                return false;
            }
            if ($supplementForReportId > 0) {
                self::setError('普通报货单不能关联补报原单');
                return false;
            }
            return null;
        }
        $batch = Db::name('customer_report_batch')->where('tenant_id', self::tenantId())
            ->where('id', $batchId)->lock(true)->find();
        if (!$batch) {
            self::setError('报货批次不存在或不属于当前门店');
            return false;
        }
        if ((string)$batch['delivery_date'] !== $deliveryDate) {
            self::setError('报货单送货日期必须与报货批次一致');
            return false;
        }
        if ((string)$batch['status'] === 'ended') {
            self::setError('报货批次已结束，不能继续报货');
            return false;
        }
        if ($isSupplement && (string)$batch['status'] !== 'processing') {
            self::setError('报货批次尚未开始处理，不能提交补报');
            return false;
        }
        if (!$isSupplement && (string)$batch['status'] !== 'open') {
            self::setError('报货批次已开始处理，新增需求必须作为补报提交');
            return false;
        }
        if (!$isSupplement) {
            if ($supplementForReportId > 0) {
                self::setError('普通报货单不能关联补报原单');
                return false;
            }
            return $batch;
        }
        if ($supplementForReportId <= 0) {
            self::setError('补报必须关联原报货单');
            return false;
        }
        $original = CustomerReport::where('tenant_id', self::tenantId())
            ->where('id', $supplementForReportId)->lock(true)->find();
        if (!$original || (int)$original->is_supplement === 1 || (string)$original->status === 'cancelled') {
            self::setError('补报关联的原报货单不存在或不可用');
            return false;
        }
        if ((int)$original->batch_id !== $batchId) {
            self::setError('补报必须进入原报货单所属批次');
            return false;
        }
        $originalItem = CustomerReportItem::where('tenant_id', self::tenantId())
            ->where('report_id', $supplementForReportId)->whereNull('delete_time')
            ->order('id')->lock(true)->find();
        if (!$originalItem
            || (string)$original->delivery_date !== $deliveryDate
            || (int)$original->main_customer_id !== (int)$items[0]['main_customer_id']
            || (int)$originalItem->delivery_customer_id !== (int)$items[0]['delivery_customer_id']) {
            self::setError('补报必须沿用原报货单的主客户和实际收货客户');
            return false;
        }
        return $batch;
    }
    private static function tenantId(): int { return (int)(request()->tenantId??0); }
    private static function operatorId(): int { return (int)(request()->adminId ?? request()->userId ?? 0); }
    private static function sn(): string { return 'CR'.date('YmdHis').random_int(1000,9999); }
    private static function isRetryableTransactionError(\Throwable $exception): bool
    {
        return str_contains($exception->getMessage(), '1213') || str_contains($exception->getMessage(), '1205');
    }
}
