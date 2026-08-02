<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use app\common\model\jxc\CustomerReport;
use app\common\model\jxc\CustomerReportItem;
use app\common\model\jxc\CustomerReportReservation;
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
                $items = CustomerReportLineService::normalizeItems((array)($params['items'] ?? []), (int)($params['main_customer_id'] ?? 0));
                if ($items === false) {
                    self::setError(CustomerReportLineService::getError());
                    return false;
                }
                $now = time();
                $reportId = (int)Db::name('customer_report')->insertGetId([
                    'tenant_id' => $tenantId, 'sn' => self::sn(),
                    'main_customer_id' => (int)$items[0]['main_customer_id'], 'main_customer_name' => (string)$items[0]['main_customer_name'],
                    'status' => 'submitted_ready', 'idempotency_key' => $key, 'request_fingerprint' => $fingerprint,
                    'version' => 1, 'submitted_time' => $now, 'remark' => trim((string)($params['remark'] ?? '')),
                    'create_time' => $now, 'update_time' => $now,
                ]);
                $summary = self::writeNewItems($reportId, $items, $now);
                Db::name('customer_report')->where('id', $reportId)->where('tenant_id', $tenantId)->update($summary + ['update_time' => $now]);
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
        return self::detailById((int)($params['id'] ?? 0));
    }

    /** @return array{lists:array<int,array<string,mixed>>,count:int,page_no:int,page_size:int} */
    public static function lists(array $params): array
    {
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

    /** @return array{warehouse_id:int,goods_id:int,available_base_qty:string} */
    public static function availability(array $params): array
    {
        $warehouseId = (int)($params['warehouse_id'] ?? 0);
        $goodsId = (int)($params['goods_id'] ?? 0);
        return [
            'warehouse_id' => $warehouseId,
            'goods_id' => $goodsId,
            'available_base_qty' => WarehouseGoodsBalanceService::available($warehouseId, $goodsId),
        ];
    }

    /** 将无缺货且已计价的报货单按仓库转为标准销售单。 */
    public static function convert(array $params): array|false
    {
        self::clearError();
        $reportId = (int)($params['id'] ?? 0);
        $version = (int)($params['version'] ?? 0);
        try {
            return self::transactionWithRetry(static function () use ($reportId, $version) {
                $report = CustomerReport::where('tenant_id', self::tenantId())->where('id', $reportId)->lock(true)->find();
                if (!$report) { self::setError('报货单不存在、版本冲突或不可转销售'); return false; }
                $existing = self::salesOrdersByReport($reportId, true);
                if ($existing !== []) {
                    return self::detailById($reportId);
                }
                if (
                    (int)$report->version !== $version
                    || (string)$report->status !== 'submitted_ready'
                    || bccomp((string)$report->shortage_base_qty, '0', self::SCALE) !== 0
                ) {
                    self::setError('报货单不存在、版本冲突或不可转销售'); return false;
                }
                $items = CustomerReportItem::where('tenant_id', self::tenantId())->where('report_id', $reportId)
                    ->whereNull('delete_time')->order(['goods_id' => 'asc', 'warehouse_id' => 'asc', 'id' => 'asc'])
                    ->lock(true)->select()->toArray();
                if ($items === []) { self::setError('报货单没有可转销售的明细'); return false; }

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
        $reportId = (int)($params['id'] ?? 0);
        $version = (int)($params['version'] ?? 0);
        $items = CustomerReportLineService::normalizeItems((array)($params['items'] ?? []), (int)($params['main_customer_id'] ?? 0));
        if ($items === false) { self::setError(CustomerReportLineService::getError()); return false; }
        try {
            return self::transactionWithRetry(static function () use ($reportId, $version, $params, $items) {
                $report = self::editableReport($reportId, $version);
                if ($report === false) { return false; }
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
        $reportId = (int)($params['id'] ?? 0); $version = (int)($params['version'] ?? 0);
        try {
            return self::transactionWithRetry(static function () use ($reportId, $version) {
                $report = self::editableReport($reportId, $version);
                if ($report === false) { return false; }
                $rows = CustomerReportItem::where('tenant_id', self::tenantId())->where('report_id', $reportId)->order(['goods_id' => 'asc', 'warehouse_id' => 'asc', 'id' => 'asc'])->lock(true)->select()->toArray();
                $now = time(); $summaryRows = [];
                foreach ($rows as $row) {
                    $needed = bcsub(self::decimal((string)$row['expected_base_qty']), self::decimal((string)$row['reserved_base_qty']), self::SCALE);
                    $added = bccomp($needed, '0.00', self::SCALE) > 0 ? WarehouseGoodsBalanceService::reserveUpToWithinTransaction((int)$row['warehouse_id'], (int)$row['goods_id'], $needed) : '0.00';
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
        self::clearError(); $reportId = (int)($params['id'] ?? 0); $version = (int)($params['version'] ?? 0);
        try {
            return self::transactionWithRetry(static function () use ($reportId, $version) {
                $report = CustomerReport::where('tenant_id', self::tenantId())->where('id', $reportId)->lock(true)->find();
                if (!$report || (int)$report->version !== $version || in_array((string)$report->status, ['completed','cancelled'], true)) {
                    self::setError('报货单不存在、版本冲突或不可取消'); return false;
                }
                $items = CustomerReportItem::where('tenant_id', self::tenantId())->where('report_id', $reportId)->order(['goods_id' => 'asc', 'warehouse_id' => 'asc', 'id' => 'asc'])->lock(true)->select()->toArray();
                $now=time();
                foreach ($items as $item) {
                    self::releaseLine($item);
                    CustomerReportItem::where('tenant_id', self::tenantId())->where('id',(int)$item['id'])->update(['reserved_base_qty'=>'0.00','shortage_base_qty'=>'0.00','status'=>'cancelled','update_time'=>$now]);
                    CustomerReportReservation::where('tenant_id', self::tenantId())->where('report_item_id',(int)$item['id'])->update(['reserved_base_qty'=>'0.00','released_base_qty'=>self::decimal((string)$item['reserved_base_qty']),'status'=>'released','update_time'=>$now]);
                }
                CustomerReport::where('tenant_id', self::tenantId())->where('id',$reportId)->where('version',$version)->update(['status'=>'cancelled','reserved_base_qty'=>'0.00','shortage_base_qty'=>'0.00','version'=>$version+1,'update_time'=>$now]);
                return self::detailById($reportId);
            });
        } catch (\Throwable) { if (!self::hasError()) { self::setError('取消客户报货失败'); } return false; }
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
        $reserved=WarehouseGoodsBalanceService::reserveUpToWithinTransaction((int)$line['warehouse_id'],(int)$line['goods_id'],(string)$line['expected_base_qty']);
        if ($reserved===false) { throw new \RuntimeException('reserve_failed'); }
        $shortage=bcsub((string)$line['expected_base_qty'],$reserved,self::SCALE); $status=bccomp($shortage,'0.00',self::SCALE)===0?'submitted_ready':'submitted_shortage';
        $data=$line; unset($data['client_line_id']);
        $itemId = (int)Db::name('customer_report_item')->insertGetId($data+['tenant_id'=>self::tenantId(),'report_id'=>$reportId,'reserved_base_qty'=>$reserved,'shortage_base_qty'=>$shortage,'fulfilled_base_qty'=>'0.00','status'=>$status,'create_time'=>$now,'update_time'=>$now]);
        Db::name('customer_report_reservation')->insert(['tenant_id'=>self::tenantId(),'report_id'=>$reportId,'report_item_id'=>$itemId,'warehouse_id'=>(int)$line['warehouse_id'],'goods_id'=>(int)$line['goods_id'],'reserved_base_qty'=>$reserved,'consumed_base_qty'=>'0.00','released_base_qty'=>'0.00','status'=>'reserved','create_time'=>$now,'update_time'=>$now]);
        CustomerReportPreferenceService::remember($line);
        return $line+['reserved_base_qty'=>$reserved,'shortage_base_qty'=>$shortage,'status'=>$status];
    }
    /** @param array<string,mixed> $old @param array<string,mixed> $line @return array<string,mixed> */
    private static function updateItem(int $itemId, array $old, array $line, int $now): array
    {
        $oldReserved=self::decimal((string)$old['reserved_base_qty']); $target=(string)$line['expected_base_qty'];
        if ((int)$old['warehouse_id']===(int)$line['warehouse_id'] && (int)$old['goods_id']===(int)$line['goods_id']) {
            $delta=bcsub($target,$oldReserved,self::SCALE);
            if (bccomp($delta,'0.00',self::SCALE)<0) { if (WarehouseGoodsBalanceService::releaseWithinTransaction((int)$old['warehouse_id'],(int)$old['goods_id'],ltrim($delta,'-'))===false) { throw new \RuntimeException('release_failed'); } $reserved=$target; }
            else { $added=bccomp($delta,'0.00',self::SCALE)>0?WarehouseGoodsBalanceService::reserveUpToWithinTransaction((int)$old['warehouse_id'],(int)$old['goods_id'],$delta):'0.00'; if ($added===false) { throw new \RuntimeException('reserve_failed'); } $reserved=bcadd($oldReserved,$added,self::SCALE); }
        } else {
            self::releaseLine($old); $reserved=WarehouseGoodsBalanceService::reserveUpToWithinTransaction((int)$line['warehouse_id'],(int)$line['goods_id'],$target); if ($reserved===false) { throw new \RuntimeException('reserve_failed'); }
        }
        $shortage=bcsub($target,$reserved,self::SCALE); $status=bccomp($shortage,'0.00',self::SCALE)===0?'submitted_ready':'submitted_shortage';
        $data=$line; unset($data['client_line_id']);
        CustomerReportItem::where('tenant_id',self::tenantId())->where('id',$itemId)->update($data+['reserved_base_qty'=>$reserved,'shortage_base_qty'=>$shortage,'status'=>$status,'update_time'=>$now]);
        CustomerReportReservation::where('tenant_id',self::tenantId())->where('report_item_id',$itemId)->update(['warehouse_id'=>(int)$line['warehouse_id'],'goods_id'=>(int)$line['goods_id'],'reserved_base_qty'=>$reserved,'status'=>'reserved','update_time'=>$now]);
        CustomerReportPreferenceService::remember($line);
        return $line+['reserved_base_qty'=>$reserved,'shortage_base_qty'=>$shortage,'status'=>$status];
    }
    /** @param array<string,mixed> $item */
    private static function releaseLine(array $item): void
    {
        $reserved=self::decimal((string)($item['reserved_base_qty']??0));
        if (bccomp($reserved,'0.00',self::SCALE)>0 && WarehouseGoodsBalanceService::releaseWithinTransaction((int)$item['warehouse_id'],(int)$item['goods_id'],$reserved)===false) { throw new \RuntimeException('release_failed'); }
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
        return [(int)$left['goods_id'], (int)$left['warehouse_id'], (int)($left['sort'] ?? $left['id'] ?? 0)] <=> [(int)$right['goods_id'], (int)$right['warehouse_id'], (int)($right['sort'] ?? $right['id'] ?? 0)];
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
        $data['sales_orders'] = self::salesOrdersByReport($id);
        return $data;
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
    private static function tenantId(): int { return (int)(request()->tenantId??0); }
    private static function sn(): string { return 'CR'.date('YmdHis').random_int(1000,9999); }
    private static function isRetryableTransactionError(\Throwable $exception): bool
    {
        return str_contains($exception->getMessage(), '1213') || str_contains($exception->getMessage(), '1205');
    }
}
