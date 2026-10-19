<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use think\facade\Db;

/** 待定货物拥有原预留的锁定量；留库释放、实际退离消耗，页面离开不改变归属。 */
final class PendingGoodsLogic extends BaseLogic
{
    private static function query(string $table = 'pending_goods')
    {
        return Db::name($table)->where('tenant_id', (int)(request()->tenantId ?? 0));
    }

    /** 调用方已经锁住报货及明细；在 releaseLine 后恢复相同锁定，整个取消事务不暴露可用空档。 */
    public static function captureAllocatedWithinTransaction(int $report, array $items): array
    {
        $holds = [];
        foreach ($items as $item) {
            $remaining = (string)$item['reserved_base_qty'];
            $allocations = Db::name('purchase_plan_allocation')->alias('a')
                ->join('purchase_plan_source s', 's.id=a.source_id AND s.tenant_id=a.tenant_id')
                ->join('purchase_plan_arrival v', 'v.id=a.arrival_id AND v.tenant_id=a.tenant_id')
                ->where('a.tenant_id', (int)request()->tenantId)->where('s.report_id', $report)
                ->where('s.report_item_id', (int)$item['id'])->field('a.id,a.allocated_qty,v.purchase_batch_id')->order('a.id')->lock(true)->select()->toArray();
            foreach ($allocations as $allocation) {
                $quantity = self::minimum($remaining, (string)$allocation['allocated_qty']);
                if (bccomp($quantity, '0', 4) <= 0) { continue; }
                self::createHold('allocation:' . $allocation['id'], $report, (int)$allocation['purchase_batch_id'], $item, $quantity);
                $holds[] = [(int)$item['warehouse_id'], (int)$item['sku_id'], $quantity];
                $remaining = bcsub($remaining, $quantity, 4);
            }
        }
        return $holds;
    }

    public static function retainAllocatedWithinTransaction(array $holds): void
    {
        foreach ($holds as [$warehouse, $sku, $quantity]) {
            if (WarehouseSkuBalanceService::reserveWithinTransaction($warehouse, $sku, $quantity) === false) {
                throw new \DomainException('采购余货转待定失败，取消未保存');
            }
        }
    }

    /** 未分配到货在最后一个需求退出时转移所有权，不释放仓库锁定。 */
    public static function captureBatchWithinTransaction(int $report, array $plan, array $batch, string $quantity): void
    {
        self::createHold('batch:' . $batch['id'], $report, (int)$batch['id'], $plan, $quantity);
    }

    private static function createHold(string $origin, int $report, int $batch, array $item, string $quantity): void
    {
        if (self::query()->where('origin_key', $origin)->lock(true)->find()) {
            throw new \DomainException('这批货物已经转入待定，请刷新后核对');
        }
        self::query()->insert(['tenant_id' => (int)request()->tenantId, 'origin_key' => $origin, 'report_id' => $report,
            'purchase_batch_id' => $batch, 'warehouse_id' => $item['warehouse_id'], 'goods_id' => $item['goods_id'], 'sku_id' => $item['sku_id'],
            'total_qty' => $quantity, 'pending_qty' => $quantity, 'create_time' => time(), 'update_time' => time()]);
    }

    public static function lists(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requireAnyPermission(['purchase.plan.view', 'purchase.plan.manage', 'report.edit'])) { return false; }
        $query = self::query();
        if ((int)($params['report_id'] ?? 0) > 0) { $query->where('report_id', (int)$params['report_id']); }
        $done = ($params['status'] ?? '') === 'done';
        $query->whereRaw($done ? 'pending_qty=0 AND return_qty=0' : '(pending_qty>0 OR return_qty>0)');
        $keyword = trim((string)($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $ids = self::query('goods')->whereLike('name', '%' . addcslashes(mb_substr($keyword, 0, 60), '%_\\') . '%')->column('id');
            $query->whereIn('goods_id', $ids ?: [0]);
        }
        $count = (clone $query)->count(); $page = max(1, (int)($params['page_no'] ?? 1));
        $rows = $query->order('id desc')->page($page, 30)->select()->toArray();
        return ['lists' => array_map([self::class, 'present'], $rows), 'count' => $count, 'page_no' => $page, 'page_size' => 30];
    }

    public static function detail(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requireAnyPermission(['purchase.plan.view', 'purchase.plan.manage', 'report.edit'])) { return false; }
        $row = self::query()->where('id', (int)($params['id'] ?? 0))->find();
        if (!$row) { self::setError('待定货物不存在或不属于当前门店'); return false; }
        return self::present($row);
    }

    private static function present(array $row): array
    {
        $row['goods_name'] = (string)self::query('goods')->where('id', $row['goods_id'])->value('name');
        $sku = self::query('goods_sku')->where('id', $row['sku_id'])->find();
        $row['sku_name'] = $sku['sku_name'] ?? ''; $row['unit_name'] = $sku['base_unit_name'] ?? '';
        $row['warehouse_name'] = (string)self::query('warehouse')->where('id', $row['warehouse_id'])->value('name');
        $row['report_sn'] = (string)self::query('customer_report')->where('id', $row['report_id'])->value('sn');
        $ids = self::query('purchase_batch_supply_order')->where('purchase_batch_id', $row['purchase_batch_id'])->column('supply_order_id');
        $row['supply_orders'] = $ids ? self::query('supply_order')->whereIn('id', $ids)->field('id,order_sn,supplier_id,supplier_name')->select()->toArray() : [];
        $row['returns'] = self::query('pending_goods_return')->where('pending_goods_id', $row['id'])->order('id desc')->select()->toArray();
        foreach ($row['returns'] as &$return) { $return['facts'] = PendingGoodsReturnService::facts((int)$return['id']); }
        unset($return);
        $row['actions'] = self::query('pending_goods_action')->where('pending_goods_id', $row['id'])->order('id desc')->select()->toArray();
        return $row;
    }

    public static function action(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('purchase.plan.manage')) { return false; }
        try {
            if ((int)($params['expected_tenant_id'] ?? 0) !== (int)request()->tenantId) { throw new \DomainException('门店已变化，请重新进入待定货物'); }
            $key = (string)($params['idempotency_key'] ?? '');
            if (!preg_match('/^[a-zA-Z0-9_-]{16,96}$/D', $key)) { throw new \DomainException('提交标识无效，请重新进入页面'); }
            $fingerprint = hash('sha256', json_encode($params, JSON_UNESCAPED_UNICODE) . ':' . (int)(request()->adminId ?? 0));
            return Db::transaction(static function () use ($params, $key, $fingerprint) {
                FinanceIntegration::lock();
                $basis = self::query()->where('id', (int)($params['id'] ?? 0))->find();
                if ($basis) { WarehouseSkuBalanceService::lockBalanceWithinTransaction((int)$basis['warehouse_id'], (int)$basis['sku_id']); }
                $row = self::query()->where('id', (int)($params['id'] ?? 0))->lock(true)->find();
                if (!$row) { throw new \DomainException('待定货物不存在或不属于当前门店'); }
                $existing = self::query('pending_goods_action')->where('idempotency_key', $key)->find();
                if ($existing) {
                    if (!hash_equals($existing['fingerprint'], $fingerprint)) { throw new \DomainException('不能用同一提交标识处理不同内容'); }
                    return self::present($row);
                }
                if ((int)($params['version'] ?? 0) !== (int)$row['version']) { throw new \DomainException('货物已被处理，请刷新剩余数量'); }
                $action = (string)($params['action'] ?? ''); $quantity = '0.0000'; $update = [];
                if (in_array($action, ['keep', 'plan_return'], true)) {
                    $quantity = self::quantity($params['quantity'] ?? '');
                    if (bccomp($quantity, $row['pending_qty'], 4) > 0) { throw new \DomainException('数量不能超过当前待处理数量'); }
                    $update['pending_qty'] = bcsub($row['pending_qty'], $quantity, 4);
                    if ($action === 'keep') {
                        if (WarehouseSkuBalanceService::releaseWithinTransaction((int)$row['warehouse_id'], (int)$row['sku_id'], $quantity) === false) { throw new \DomainException('库存锁定量已变化，请核对实物'); }
                        $update['kept_qty'] = bcadd($row['kept_qty'], $quantity, 4);
                    } else {
                        $source = (int)($params['supply_order_id'] ?? 0);
                        if (!self::query('purchase_batch_supply_order')->where('purchase_batch_id', $row['purchase_batch_id'])->where('supply_order_id', $source)->find()) { throw new \DomainException('请选择这批货物的原供应商采购单'); }
                        self::query('pending_goods_return')->insert(['tenant_id' => (int)request()->tenantId, 'pending_goods_id' => $row['id'], 'supply_order_id' => $source,
                            'quantity' => $quantity, 'remaining_qty' => $quantity, 'create_time' => time(), 'update_time' => time()]);
                        $update['return_qty'] = bcadd($row['return_qty'], $quantity, 4);
                    }
                } elseif ($action === 'cancel_return') {
                    $return = self::query('pending_goods_return')->where('pending_goods_id', $row['id'])->where('id', (int)($params['return_id'] ?? 0))->lock(true)->find();
                    if (!$return || $return['status'] !== 'planned' || bccomp($return['remaining_qty'], '0', 4) <= 0) { throw new \DomainException('仅可撤回尚未退离的数量'); }
                    $quantity = $return['remaining_qty'];
                    $update = ['pending_qty' => bcadd($row['pending_qty'], $quantity, 4), 'return_qty' => bcsub($row['return_qty'], $quantity, 4)];
                    self::query('pending_goods_return')->where('id', $return['id'])->update(['status' => 'cancelled', 'remaining_qty' => '0.0000', 'update_time' => time()]);
                } elseif (in_array($action, ['accept_return', 'return_back', 'return_loss', 'settle_return'], true)) {
                    PendingGoodsReturnService::resolveLegacy($row, $params);
                    $row = self::query()->where('id', $row['id'])->find();
                } else { throw new \DomainException('不支持的货物处理动作'); }
                self::query()->where('id', $row['id'])->update($update + ['version' => (int)$row['version'] + 1, 'update_time' => time()]);
                self::query('pending_goods_action')->insert(['tenant_id' => (int)request()->tenantId, 'pending_goods_id' => $row['id'], 'idempotency_key' => $key,
                    'fingerprint' => $fingerprint, 'action' => $action, 'quantity' => $quantity, 'operator_id' => (int)(request()->adminId ?? 0), 'create_time' => time()]);
                return self::present(self::query()->where('id', $row['id'])->find());
            });
        } catch (\DomainException $e) { self::setError($e->getMessage()); return false; }
    }

    /** 原退货流程在同一事务内验证来源、释放且消耗对应锁定；失败时一起回滚。 */
    public static function consumeReturnWithinTransaction(int $id, array $order, array $lines, int $returnOrderId): void
    {
        if (!WorkforceLogic::requirePermission('purchase.plan.manage')) { throw new \DomainException('无权处理待定货物'); }
        $reference = self::query('pending_goods_return')->where('id', $id)->find();
        $basis = $reference ? self::query()->where('id', $reference['pending_goods_id'])->find() : null;
        if ($basis) { WarehouseSkuBalanceService::lockBalanceWithinTransaction((int)$basis['warehouse_id'], (int)$basis['sku_id']); }
        $row = $basis ? self::query()->where('id', $basis['id'])->lock(true)->find() : null;
        $return = self::query('pending_goods_return')->where('id', $id)->lock(true)->find();
        if (!$row || !$return || $return['status'] !== 'planned' || (int)$order['original_supply_order_id'] !== (int)$return['supply_order_id']
            || (int)$order['warehouse_id'] !== (int)$row['warehouse_id']) { throw new \DomainException('待退记录已变化或原采购来源不一致'); }
        $quantity = '0.0000';
        foreach ($lines as $line) {
            if ((int)$line['sku_id'] !== (int)$row['sku_id']) { throw new \DomainException('本次只可退这条待定货物'); }
            $origin = self::query('order_goods')->where('id', $line['original_supply_order_list_id'])->where('order_id', $return['supply_order_id'])->where('order_type', 'supply')->find();
            if (!$origin || (bccomp((string)$origin['actual_base_qty'], (string)$origin['number'], 4) !== 0)) { throw new \DomainException('原采购单位与库存基础数量不同，请先核对退货换算，待定量保持锁定'); }
            $quantity = bcadd($quantity, $line['return_num'], 4);
        }
        if (bccomp($quantity, '0', 4) <= 0 || bccomp($quantity, $return['remaining_qty'], 4) > 0) { throw new \DomainException('实际退货量超过尚未退离数量'); }
        if (WarehouseSkuBalanceService::releaseWithinTransaction((int)$row['warehouse_id'], (int)$row['sku_id'], $quantity) === false) { throw new \DomainException('待退库存锁定不一致'); }
        self::query('pending_goods_return_order')->insert(['tenant_id' => (int)request()->tenantId, 'pending_return_id' => $id, 'return_order_id' => $returnOrderId, 'quantity' => $quantity, 'create_time' => time()]);
        $remaining = bcsub($return['remaining_qty'], $quantity, 4);
        self::query('pending_goods_return')->where('id', $id)->update(['remaining_qty' => $remaining, 'status' => bccomp($remaining, '0', 4) === 0 ? 'awaiting_supplier' : 'planned', 'return_order_id' => $returnOrderId, 'update_time' => time()]);
        self::query('pending_goods_return_fact')->insert(['tenant_id' => (int)request()->tenantId, 'pending_return_id' => $id, 'legacy_order_id' => $returnOrderId, 'quantity' => $quantity, 'create_time' => time()]);
        self::query()->where('id', $row['id'])->update(['version' => (int)$row['version'] + 1, 'update_time' => time()]);
    }


    /** 旧取消记录只提供可核对的上限；不会在列表读取时补扣库存。 */
    public static function recoveryPreview(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('purchase.plan.manage')) { return false; }
        try { return self::recoveryCandidates((int)($params['report_id'] ?? 0)); }
        catch (\DomainException $e) { self::setError($e->getMessage()); return false; }
    }

    private static function recoveryCandidates(int $report): array
    {
        $document = self::query('customer_report')->where('id', $report)->find();
        if (!$document || $document['status'] !== 'cancelled') { throw new \DomainException('仅可补处理本门店已取消的报货单'); }
        $candidates = []; $plans = self::query('purchase_plan_source')->where('report_id', $report)->column('purchase_plan_id');
        foreach (array_unique($plans) as $planId) {
            $plan = self::query('purchase_plan')->where('id', $planId)->find();
            if (!$plan) { continue; }
            $batches = self::query('purchase_batch')->where('purchase_plan_id', $planId)->order('id')->select()->toArray();
            foreach ($batches as $batch) {
                $arrival = self::query('purchase_plan_arrival')->where('purchase_batch_id', $batch['id'])->find();
                $parts = []; $allocated = '0.0000';
                if ($arrival) {
                    foreach (self::query('purchase_plan_allocation')->where('arrival_id', $arrival['id'])->select()->toArray() as $allocation) {
                        $allocated = bcadd($allocated, $allocation['allocated_qty'], 4);
                        $source = self::query('purchase_plan_source')->where('id', $allocation['source_id'])->find();
                        if ($source && (int)$source['report_id'] === $report) { $parts[] = ['origin_key' => 'allocation:' . $allocation['id'], 'maximum' => $allocation['allocated_qty']]; }
                    }
                } elseif (self::query('purchase_plan_source')->where('purchase_plan_id', $planId)->where('status', '<>', 'released')->count() === 0) {
                    $orders = self::query('purchase_batch_supply_order')->where('purchase_batch_id', $batch['id'])->column('supply_order_id');
                    $total = $orders ? (string)self::query('order_goods')->where('order_type', 'supply')->whereIn('order_id', $orders)->where('sku_id', $plan['sku_id'])->sum('actual_base_qty') : '0';
                    $parts[] = ['origin_key' => 'batch:' . $batch['id'], 'maximum' => $total];
                }
                // 旧数据缺少逐批销售归属。出现后续出库时不猜测这批仍剩多少。
                $moved = self::query('stock_flow')->where('warehouse_id', $plan['warehouse_id'])->where('sku_id', $plan['sku_id'])->where('flow_type', 2)->where('create_time', '>=', $batch['create_time'])->count() > 0;
                $available = WarehouseSkuBalanceService::available((int)$plan['warehouse_id'], (int)$plan['sku_id']);
                foreach ($parts as $part) {
                    if (self::query()->where('origin_key', $part['origin_key'])->find()) { continue; }
                    $maximum = $moved ? '0.0000' : self::minimum((string)$available, $part['maximum']);
                    $candidates[] = $part + ['purchase_batch_id' => (int)$batch['id'], 'warehouse_id' => (int)$plan['warehouse_id'], 'sku_id' => (int)$plan['sku_id'],
                        'goods_id' => (int)$plan['goods_id'], 'goods_name' => (string)self::query('goods')->where('id', $plan['goods_id'])->value('name'),
                        'unit_name' => (string)self::query('goods_sku')->where('id', $plan['sku_id'])->value('base_unit_name'),
                        'recoverable_qty' => $maximum, 'blocked_reason' => $moved ? '该商品已有后续出库，请先核对批次实物，不能自动认领原采购量' : ''];
                }
            }
        }
        return ['report_id' => $report, 'candidates' => $candidates];
    }

    public static function recover(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('purchase.plan.manage')) { return false; }
        try {
            if ((int)($params['expected_tenant_id'] ?? 0) !== (int)request()->tenantId || ($params['physical_confirmed'] ?? 0) !== 1) { throw new \DomainException('请在当前门店确认这批实物仍在仓库'); }
            return Db::transaction(static function () use ($params) {
                $report = (int)($params['report_id'] ?? 0);
                self::query('customer_report')->where('id', $report)->lock(true)->find();
                $origin = (string)($params['origin_key'] ?? '');
                $quantity = self::quantity($params['quantity'] ?? '');
                $existing = self::query()->where('origin_key', $origin)->lock(true)->find();
                if ($existing) {
                    if ((int)$existing['report_id'] !== $report || bccomp($existing['total_qty'], $quantity, 4) !== 0) { throw new \DomainException('这批货物已处理，请刷新列表'); }
                    return self::present($existing);
                }
                $preview = self::recoveryCandidates($report); $candidate = null;
                foreach ($preview['candidates'] as $item) { if ($item['origin_key'] === $origin) { $candidate = $item; break; } }
                if (!$candidate || bccomp($quantity, $candidate['recoverable_qty'], 4) > 0) { throw new \DomainException('来源或剩余库存已变化，请重新核对'); }
                // 同一采购批次只认领一次；锁批次后用当前读再次核验全局唯一来源。
                $batch = self::query('purchase_batch')->where('id', $candidate['purchase_batch_id'])->lock(true)->find();
                WarehouseSkuBalanceService::lockBalanceWithinTransaction($candidate['warehouse_id'], $candidate['sku_id']);
                if (self::query('stock_flow')->where('warehouse_id', $candidate['warehouse_id'])->where('sku_id', $candidate['sku_id'])
                    ->where('flow_type', 2)->where('create_time', '>=', $batch['create_time'])->lock(true)->find()) {
                    throw new \DomainException('该商品已有后续出库，请重新核对批次实物');
                }
                if (self::query()->where('origin_key', $origin)->lock(true)->find()) { throw new \DomainException('这批货物已被补处理，请刷新列表'); }
                if (WarehouseSkuBalanceService::reserveWithinTransaction($candidate['warehouse_id'], $candidate['sku_id'], $quantity) === false) { throw new \DomainException('可用库存不足，不能占用其他订单的预留'); }
                self::createHold($origin, $report, $candidate['purchase_batch_id'], $candidate, $quantity);
                return self::present(self::query()->where('origin_key', $origin)->find());
            });
        } catch (\DomainException $e) { self::setError($e->getMessage()); return false; }
    }


    /** 在库存行锁保护下以当前读核对，真实交付也不能挪用待定实物。 */
    public static function permitsOutboundWithinTransaction(int $warehouse, int $sku, string $quantity): bool
    {
        $prefix = (string)config('database.connections.mysql.prefix', 'la_');
        if (!Db::query('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$prefix . 'pending_goods'])) { return true; }
        $held = '0.0000';
        foreach (self::query()->where('warehouse_id', $warehouse)->where('sku_id', $sku)->lock(true)->select()->toArray() as $row) {
            $planned = '0.0000';
            foreach (self::query('pending_goods_return')->where('pending_goods_id', $row['id'])->where('status', 'planned')->lock(true)->select()->toArray() as $return) {
                $planned = bcadd($planned, $return['remaining_qty'], 4);
            }
            $held = bcadd($held, bcadd($row['pending_qty'], $planned, 4), 4);
        }
        if (bccomp($held, '0', 4) <= 0) { return true; }
        $balance = self::query('warehouse_sku_balance')->where('warehouse_id', $warehouse)->where('sku_id', $sku)->lock(true)->find();
        return $balance && bccomp(bcsub($balance['on_hand_qty'], $quantity, 4), $held, 4) >= 0;
    }

    public static function assertDirectReturnAllowed(int $supplyOrder): void
    {
        $batches = self::query('purchase_batch_supply_order')->where('supply_order_id', $supplyOrder)->column('purchase_batch_id');
        if ($batches && self::query()->whereIn('purchase_batch_id', $batches)->whereRaw('(pending_qty>0 OR return_qty>0)')->lock(true)->find()) {
            throw new \DomainException('原采购还有待定货物，请从待定货物列表选择退供应商');
        }
    }

    public static function quantity(mixed $input): string
    {
        $value = (string)$input;
        if (!preg_match('/^\d{1,12}(?:\.\d{1,4})?$/D', $value) || bccomp($value, '0', 4) <= 0) { throw new \DomainException('请输入大于 0 的数量，最多四位小数'); }
        return bcadd($value, '0', 4);
    }

    private static function minimum(string $left, string $right): string { return bccomp($left, $right, 4) < 0 ? $left : $right; }
}
