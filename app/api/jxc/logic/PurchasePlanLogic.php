<?php
declare(strict_types=1);
namespace app\api\jxc\logic;
use app\common\logic\BaseLogic;
use think\facade\Db;
/** 采购安排：需求单位与库存基础量独立；纸单只输出已确认的执行范围。 */
final class PurchasePlanLogic extends BaseLogic
{

    private static function q(string $table)
    {
        return Db::name($table)->where('tenant_id', self::tenantId());
    }

    private static function tenantId(): int
    {
        return (int)(request()->tenantId ?? 0);
    }

    private static function decimal($v): string
    {
        return bcadd((string)$v, '0', 4);
    }

    private static function displayDecimal($value): string
    {
        return rtrim(rtrim(self::decimal($value), '0'), '.');
    }

    private static function quantity($v, bool $zero = false): string
    {
        $v = (string)$v;
        if (!preg_match('/^\d{1,13}(?:\.\d{1,4})?$/D', $v) || bccomp($v, '0', 4) < ($zero ? 0 : 1)) {
            throw new \DomainException('数量必须为有效正数，最多四位小数');
        }
        return self::decimal($v);
    }

    private static function quantityForUnit($value, int $unitId, bool $zero = false): string
    {
        $quantity = self::quantity($value, $zero);
        $unit = TradeQuantityService::unit($unitId);
        if (!$unit || bccomp($quantity, bcadd($quantity, '0', (int)$unit['quantity_precision']), 4) !== 0) {
            throw new \DomainException('数量精度不符合本次需求单位');
        }
        return $quantity;
    }

    private static function key(array $p): string
    {
        $key = (string)($p['idempotency_key'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{12,100}$/D', $key)) {
            throw new \DomainException('提交标识无效，请重新进入');
        }
        return $key;
    }

    private static function run(callable $work, bool $manage = false): array|false
    {
        self::clearError();
        if ($manage ? !WorkforceLogic::requirePermission('purchase.plan.manage') : !WorkforceLogic::requireAnyPermission(['purchase.plan.view', 'purchase.plan.manage'])) {
            return false;
        }
        try {
            return $work();
        } catch (\Throwable $e) {
            self::setError($e instanceof \DomainException ? $e->getMessage() : '采购操作失败，请核实原请求结果后重试');
            return false;
        }
    }

    private static function item(int $id, bool $lock = false): array
    {
        $q = self::q('customer_report_item')->where('id', $id)->whereNull('delete_time');
        $item = ($lock ? $q->lock(true) : $q)->find();
        if (!$item) {
            throw new \DomainException('报货明细不存在');
        }
        $q = self::q('customer_report')->where('id', $item['report_id'])->whereNull('delete_time');
        $report = ($lock ? $q->lock(true) : $q)->find();
        if (!$report || in_array($report['status'], array_merge(['draft', 'cancelled'], CustomerReportSalesDocument::CLOSED_REPORT_STATUSES)) || (in_array($item['status'], ['cancelled', 'undelivered']) || in_array($item['fulfillment_status'] ?? '', ['delivered', 'partially_delivered_completed', 'undelivered', 'delivery_exception_completed']))) {
            throw new \DomainException('报货已结束或不能继续安排采购');
        }
        return $item + ['report_sn' => $report['sn'], 'report_version' => $report['version'], 'delivery_date' => $report['delivery_date'], 'main_customer_name' => $report['main_customer_name']];
    }

    private static function demand(array $i, bool $lock = false): array
    {
        $qty = self::decimal($i['order_qty']);
        $known = (int)$i['unit_id'] === (int)$i['base_unit_id'];
        $ratio = $known ? '1.0000' : null;
        // 同维度固定换算只采用报货已经核实的单位转换，不把自然条重估算当系数。
        $unit = TradeQuantityService::unit((int)$i['unit_id']);
        $base = TradeQuantityService::unit((int)$i['base_unit_id']);
        if (!$known && ($unit['measure_type'] ?? '') === 'weight' && ($base['measure_type'] ?? '') === 'weight' && bccomp((string)$i['expected_base_qty'], '0', 4) > 0) {
            $known = true;
            $ratio = bcdiv((string)$unit['jin_factor'], (string)$base['jin_factor'], 8);
        }
        $effective = $known?bcdiv((string)$i['expected_base_qty'], (string)$ratio, 4):$qty;
        $coverageQuery = self::q('purchase_demand_coverage')->where('report_item_id', $i['id'])->order('id desc');
        $coverageRows = ($lock?$coverageQuery->lock(true):$coverageQuery)->select()->toArray();
        $sourceQuery = self::q('purchase_plan_source')->where('report_item_id', $i['id'])->order('id');
        $sourceRows = ($lock?$sourceQuery->lock(true):$sourceQuery)->select()->toArray();
        $manual = $fulfilled = $allocationBase = '0.0000';
        foreach ($coverageRows as $coverage) {
            $manual = bcadd($manual, $coverage['quantity'], 4);
        }
        foreach ($sourceRows as $source) {
            $fulfilled = bcadd($fulfilled, $source['fulfilled_qty'], 4);
            $allocationBase = bcadd($allocationBase, $source['allocated_qty'], 4);
        }
        $covered = $known ? bcdiv((string)$i['reserved_base_qty'], (string)$ratio, 4) : bcadd($manual, $fulfilled, 4);
        $covered = bccomp($covered, $effective, 4) > 0 ? $effective : $covered;
        $assigned = '0.0000';
        foreach ($sourceRows as $s) {
            if ($s['status'] === 'released') {
                continue;
            }
            $assigned = bcadd($assigned, bcsub($s['planned_qty'], $s['fulfilled_qty'], 4), 4);
        }
        $remaining = bcsub(bcsub($effective, $covered, 4), $assigned, 4);
        if (bccomp($remaining, '0', 4) < 0) {
            $remaining = '0.0000';
        }
        $balanceQuery = self::q('warehouse_sku_balance')->where('warehouse_id', $i['warehouse_id'])->where('sku_id', $i['sku_id']);
        $balance = ($lock?$balanceQuery->lock(true):$balanceQuery)->find();
        $available = self::decimal($balance['available_qty'] ?? '0');
        $verifiedBase = '0.0000';
        foreach ($coverageRows as$coverage) {
            $verifiedBase = bcadd($verifiedBase, $coverage['base_qty'], 4);
        }
        $unverifiedOwn = bcsub(bcsub((string)$i['reserved_base_qty'], $verifiedBase, 4), $allocationBase, 4);
        $lastVerification = $coverageRows[0] ?? null;
        $verifiedNow = $lastVerification && hash_equals((string)$lastVerification['stock_snapshot'], self::stockSnapshot($i));
        $stockVerification = !$verifiedNow && bccomp($remaining, '0', 4) > 0 && ((!$known && (bccomp($available, '0', 4) > 0 || bccomp($unverifiedOwn, '0', 4) > 0)) || ($known && bccomp($available, '0', 4) > 0));
        $requirement = trim((string)($i['processing_requirement'] ?? ''));
        if ((int)($i['piece_weight_confirmed'] ?? 0) === 1) {
            $min = self::displayDecimal($i['piece_weight_min']);
            $max = self::displayDecimal($i['piece_weight_max']);
            $requirement .= ($requirement === '' ? '' : '；') . '单条 ' . $min . '～' . $max . '斤';
        }
        return ['report_item_id' => (int)$i['id'], 'report_id' => (int)$i['report_id'], 'report_sn' => $i['report_sn'], 'report_version' => (int)$i['report_version'], 'customer_name' => $i['delivery_customer_name'] ?: $i['main_customer_name'], 'goods_id' => (int)$i['goods_id'], 'goods_name' => $i['goods_name'], 'sku_id' => (int)$i['sku_id'], 'sku_name' => $i['sku_name'], 'warehouse_id' => (int)$i['warehouse_id'], 'warehouse_name' => (string)self::q('warehouse')->where('id', $i['warehouse_id'])->value('name'), 'delivery_date' => $i['delivery_date'], 'unit_name' => $i['unit_name'], 'base_unit_name' => $i['base_unit_name'], 'demand_qty' => $qty, 'effective_qty' => $effective, 'covered_qty' => $covered, 'assigned_qty' => $assigned, 'remaining_qty' => $remaining, 'stock_verification_required' => $stockVerification, 'available_base_qty' => $available, 'requirement' => $requirement];
    }

    private static function stockSnapshot(array $i): string
    {
        $balance = self::q('warehouse_sku_balance')->where('warehouse_id', $i['warehouse_id'])->where('sku_id', $i['sku_id'])->lock(true)->find();
        return hash('sha256', json_encode([$i['report_version'], self::decimal($balance['on_hand_qty'] ?? '0'), self::decimal($balance['available_qty'] ?? '0'), $i['reserved_base_qty']]));
    }

    public static function demands(array $p): array|false
    {
        return self::run(static function() use($p) {
            $q = self::q('customer_report_item')->whereNull('delete_time')->whereNotIn('status', ['cancelled', 'undelivered']);
            if ((int)($p['warehouse_id'] ?? 0) > 0) {
                $q->where('warehouse_id', (int)$p['warehouse_id']);
            }
            if ((int)($p['report_id'] ?? 0) > 0) {
                $q->where('report_id', (int)$p['report_id']);
            }
            $rows = [];
            $keyword = mb_strtolower(trim((string)($p['keyword'] ?? '')));
            foreach ($q->order('id')->select()->toArray() as $i) {
                try {
                    $i = self::item((int)$i['id']);
                } catch (\DomainException) {
                    continue;
                }
                if (($p['delivery_date'] ?? '') !== '' && $i['delivery_date'] !== $p['delivery_date']) {
                    continue;
                }
                $d = self::demand($i, true);
                if (bccomp($d['remaining_qty'], '0', 4) <= 0) {
                    continue;
                }
                if ($keyword !== '' && !str_contains(mb_strtolower(implode(' ', [$d['customer_name'], $d['goods_name'], $d['sku_name'], $d['report_sn'], $d['requirement']])), $keyword)) {
                    continue;
                }
                $rows[] = $d;
            }
            $page = max(1, (int)($p['page_no'] ?? 1));
            $size = min(100, max(1, (int)($p['page_size'] ?? 20)));
            return ['lists' => array_slice($rows, ($page - 1)*$size, $size), 'count' => count($rows), 'page_no' => $page, 'page_size' => $size];
        });
    }

    public static function verifyStock(array $p): array|false
    {
        return self::run(static fn() => Db::transaction(static function() use($p) {
            $key = self::key($p);
            $fp = hash('sha256', json_encode($p));
            $i = self::item((int)($p['report_item_id'] ?? 0), true);
            $old = self::q('purchase_demand_coverage')->where('idempotency_key', $key)->find();
            if ($old) {
                if ($old['request_fingerprint'] !== $fp) {
                    throw new \DomainException('同一提交标识的内容不一致');
                }
                return self::demand($i);
            }
            if ((int)$p['report_version'] !== (int)$i['report_version']) {
                throw new \DomainException('报货版本已变化，请刷新');
            }
            $quantity = self::quantityForUnit($p['covered_qty'] ?? '', (int)$i['unit_id'], true);
            $base = self::quantity($p['base_qty'] ?? '', true);
            if ((bccomp($quantity, '0', 4) === 0) !== (bccomp($base, '0', 4) === 0)) {
                throw new \DomainException('零覆盖必须同时记录零基础量');
            }
            if ((int)$i['unit_id'] === (int)$i['base_unit_id'] && bccomp($quantity, $base, 4) !== 0) {
                throw new \DomainException('同单位库存覆盖量必须等于基础量');
            }
            $from = TradeQuantityService::unit((int)$i['unit_id']);
            $to = TradeQuantityService::unit((int)$i['base_unit_id']);
            if (($from['measure_type'] ?? '') === 'weight' && ($to['measure_type'] ?? '') === 'weight' && bccomp($base, bcdiv(bcmul($quantity, $from['jin_factor'], 8), $to['jin_factor'], 4), 4) !== 0) {
                throw new \DomainException('重量单位库存覆盖换算不一致');
            }
            $reason = trim((string)($p['reason'] ?? ''));
            $d = self::demand($i, true);
            if ($reason === '' || mb_strlen($reason) > 500 || bccomp($quantity, $d['remaining_qty'], 4) > 0) {
                throw new \DomainException('核实原因必填，覆盖数量不能超过尚未安排需求');
            }
            $already = self::decimal(self::q('purchase_demand_coverage')->where('report_item_id', $i['id'])->sum('base_qty'));
            $allocated = self::decimal(self::q('purchase_plan_source')->where('report_item_id', $i['id'])->sum('allocated_qty'));
            $own = bcsub(bcsub($i['reserved_base_qty'], $already, 4), $allocated, 4);
            if (bccomp($own, '0', 4) < 0) {
                $own = '0';
            }
            $extra = bcsub($base, $own, 4);
            if (bccomp($extra, '0', 4) > 0 && WarehouseSkuBalanceService::reserveWithinTransaction((int)$i['warehouse_id'], (int)$i['sku_id'], $extra) === false) {
                throw new \DomainException('可用库存不足，核实未保存');
            }
            if (bccomp($extra, '0', 4) > 0) {
                self::addHeldToItem($i, $extra);
            }
            CustomerReportLogic::refreshProcurementWithinTransaction((int)$i['report_id']);
            self::q('purchase_demand_coverage')->insert(['tenant_id' => self::tenantId(), 'report_item_id' => $i['id'], 'quantity' => $quantity, 'base_qty' => $base, 'reason' => $reason, 'idempotency_key' => $key, 'request_fingerprint' => $fp, 'stock_snapshot' => self::stockSnapshot(self::item((int)$i['id'])), 'create_time' => time()]);
            return self::demand(self::item((int)$i['id']));
        }), true);
    }

    public static function lists(array $p): array|false
    {
        return self::run(static function()use($p) {
            $q = self::q('purchase_plan');
            if (($p['status'] ?? '') !== '') {
                $q->where('status', $p['status']);
            }
            if (($p['delivery_date'] ?? '') !== '') {
                $q->where('delivery_date', $p['delivery_date']);
            }
            $count = (clone $q)->count();
            $page = max(1, (int)($p['page_no'] ?? 1));
            $size = min(100, max(1, (int)($p['page_size'] ?? 20)));
            $rows = $q->order('id desc')->page($page, $size)->select()->toArray();
            foreach ($rows as &$r) {
                $r = self::present($r);
            }
            return ['lists' => $rows, 'count' => $count, 'page_no' => $page, 'page_size' => $size];
        });
    }

    public static function detail(array $p): array|false
    {
        return self::run(static fn() => self::detailById((int)($p['id'] ?? 0)));
    }

    private static function plan(int $id, bool $lock = false): array
    {
        $q = self::q('purchase_plan')->where('id', $id);
        $r = ($lock?$q->lock(true):$q)->find();
        if (!$r) {
            throw new \DomainException('采购计划不存在');
        }
        return $r;
    }

    private static function lines(int $id, bool $lock = false): array
    {
        $query = self::q('purchase_plan_source')->where('purchase_plan_id', $id)->order('id');
        return ($lock ? $query->lock(true) : $query)->select()->toArray();
    }

    private static function present(array $p): array
    {
        $ls = self::lines((int)$p['id']);
        $summary = [];
        foreach ($ls as &$l) {
            $requirements = self::q('customer_report_item')->where('id', $l['report_item_id'])->find();
            $l['requires_size_verification'] = (int)($requirements['piece_weight_confirmed'] ?? 0) === 1;
            $demandUnitId = (int)($requirements['unit_id'] ?? 0);
            $baseUnitId = (int)($requirements['base_unit_id'] ?? 0);
            $l['same_allocation_unit'] = $demandUnitId > 0 && $baseUnitId > 0
                ? $demandUnitId === $baseUnitId
                : null;
            $report = self::q('customer_report')->where('id', $l['report_id'])->field('sn,status')->find();
            $l['report_sn'] = (string)($report['sn'] ?? '');
            $l['source_closed'] = !$report || in_array($report['status'],
                array_merge(['draft', 'cancelled'], CustomerReportSalesDocument::CLOSED_REPORT_STATUSES), true)
                || !$requirements || in_array($requirements['status'], ['cancelled', 'undelivered'], true)
                || in_array($requirements['fulfillment_status'] ?? '',
                    ['delivered', 'partially_delivered_completed', 'undelivered', 'delivery_exception_completed'], true);
            $l['remaining_qty'] = bcsub($l['planned_qty'], $l['fulfilled_qty'], 4);
            $l['unbought_qty'] = bcsub($l['planned_qty'], $l['bought_qty'], 4);
            $u = $l['unit_name'];
            $summary[$u] = bcadd($summary[$u] ?? '0', $l['planned_qty'], 4);
        }
        unset($l);
        foreach (['batch_id', 'goods_id', 'goods_name', 'sku_id', 'sku_name', 'base_unit_name', 'planned_qty', 'arrived_qty', 'allocated_qty', 'surplus_qty', 'active_scope_key', 'termination_reason', 'buyer_id', 'buyer_name', 'replaces_plan_id', 'recall_note']as$obsolete) {
            unset($p[$obsolete]);
        }
        $p['lines'] = $ls;
        $p['line_count'] = count($ls);
        $p['quantity_summary'] = implode('＋', array_map(static fn($u, $n) => rtrim(rtrim($n, '0'), '.') . $u, array_keys($summary), array_values($summary)));
        return $p;
    }

    private static function detailById(int $id): array
    {
        $p = self::present(self::plan($id));
        $p['sheets'] = array_map([self::class, 'sheetView'], self::q('purchase_plan_sheet')->where('purchase_plan_id', $id)->order('id')->select()->toArray());
        $p['arrivals'] = self::q('purchase_plan_hold')->where('purchase_plan_id', $id)->order('id')->select()->toArray();
        foreach ($p['arrivals']as&$a) {
            $a['allocated_qty'] = self::decimal(self::q('purchase_plan_allocation')->where('arrival_line_id', $a['arrival_line_id'])->sum('allocated_qty'));
            $a['surplus_qty'] = bcsub(bcsub($a['arrival_qty'], $a['remaining_qty'], 4), $a['allocated_qty'], 4);
        }
        unset($a);
        $pendingIds = self::q('purchase_plan_hold')->where('purchase_plan_id', $id)->where('remaining_qty', '>', 0)->column('purchase_batch_id');
        $p['pending_arrival_batches'] = $pendingIds?self::q('purchase_batch')->whereIn('id', array_unique($pendingIds))->field('id,batch_no,status')->order('id')->select()->toArray():[];
        return $p;
    }

    public static function create(array $p): array|false
    {
        return self::run(static fn() => Db::transaction(static function()use($p) {
            $key = self::key($p);
            $fp = hash('sha256', json_encode($p));
            $old = self::q('purchase_plan')->where('idempotency_key', $key)->find();
            if ($old) {
                if ($old['request_fingerprint'] !== $fp) {
                    throw new \DomainException('同一提交标识的采购内容不一致');
                }
                return self::detailById((int)$old['id']);
            }
            $warehouse = self::q('warehouse')->where('id', (int)($p['warehouse_id'] ?? 0))->where('is_enabled', 1)->find();
            $date = (string)($p['delivery_date'] ?? '');
            if (!$warehouse || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) || !($p['lines'] ?? [])) {
                throw new \DomainException('请选择有效仓库、日期及需求');
            }
            $entries = $p['lines'];
            usort($entries, static fn($a, $b) => (int)$a['report_item_id'] <=> (int)$b['report_item_id']);
            $seen = [];
            $lines = [];
            foreach ($entries as $entry) {
                $id = (int)($entry['report_item_id'] ?? 0);
                if (isset($seen[$id])) {
                    throw new \DomainException('同一来源不能重复选择');
                }
                $seen[$id] = true;
                $i = self::item($id, true);
                $replay = self::q('purchase_plan')->where('idempotency_key', $key)->lock(true)->find();
                if ($replay) {
                    if ($replay['request_fingerprint'] !== $fp) {
                        throw new \DomainException('原采购内容不一致');
                    }
                    return self::detailById((int)$replay['id']);
                }
                $quantity = self::quantityForUnit($entry['quantity'] ?? '', (int)$i['unit_id']);
                if ((int)($entry['report_version'] ?? 0) !== (int)$i['report_version'] || (int)$i['warehouse_id'] !== (int)$warehouse['id'] || $i['delivery_date'] !== $date) {
                    throw new \DomainException('需求版本、仓库或送货日期不一致，请刷新');
                }
                $d = self::demand($i, true);
                if ($d['stock_verification_required']) {
                    throw new \DomainException('库存能满足的需求数量待核实，请先核实库存');
                }
                if (bccomp($quantity, $d['remaining_qty'], 4) > 0) {
                    throw new \DomainException('本次采购量超过尚未安排需求，可能已由他人安排');
                }
                $lines[] = [$i, $d, $quantity];
            }
            // 重放检查在来源锁后再次执行，防止同请求并发创建。
            $old = self::q('purchase_plan')->where('idempotency_key', $key)->lock(true)->find();
            if ($old) {
                if ($old['request_fingerprint'] !== $fp) {
                    throw new \DomainException('同一提交标识内容冲突');
                }
                return self::detailById((int)$old['id']);
            }
            $now = time();
            $id = (int)self::q('purchase_plan')->insertGetId(['tenant_id' => self::tenantId(), 'plan_no' => 'PP' . date('ymdHis') . bin2hex(random_bytes(3)), 'warehouse_id' => $warehouse['id'], 'warehouse_name' => $warehouse['name'], 'delivery_date' => $date, 'status' => 'pending', 'version' => 1, 'idempotency_key' => $key, 'request_fingerprint' => $fp, 'create_time' => $now, 'update_time' => $now]);
            foreach ($lines as [$i, $d, $qty]) {
                self::q('purchase_plan_source')->insert(['tenant_id' => self::tenantId(), 'purchase_plan_id' => $id, 'report_id' => $i['report_id'], 'report_item_id' => $i['id'], 'report_version' => $i['report_version'], 'customer_name' => $d['customer_name'], 'delivery_date' => $date, 'goods_id' => $i['goods_id'], 'goods_name' => $i['goods_name'], 'sku_id' => $i['sku_id'], 'sku_name' => $i['sku_name'], 'unit_name' => $i['unit_name'], 'base_unit_name' => $i['base_unit_name'], 'planned_qty' => $qty, 'shortage_qty' => '0', 'requirement' => $d['requirement'], 'create_time' => $now, 'update_time' => $now]);
            }
            $result = self::detailById($id);
            AuditService::logWithinTransaction('purchase_plan', AuditService::ACTION_CREATE, $id, $result['plan_no'], null, $result);
            return $result;
        }), true);
    }

    private static function version(array $plan, array $p): void
    {
        if ((int)($p['version'] ?? 0) !== (int)$plan['version']) {
            throw new \DomainException('采购计划版本已变化，请刷新后继续');
        }
    }

    private static function mutate(array $p, string $action, callable $work): array|false
    {
        return self::run(static fn() => Db::transaction(static function()use($p, $action, $work) {
            $plan = self::plan((int)($p['id'] ?? 0), true);
            $key = self::key($p);
            $fp = hash('sha256', json_encode($p));
            $old = self::q('purchase_plan_action')->where('idempotency_key', $key)->find();
            if ($old) {
                if ((int)$old['purchase_plan_id'] !== (int)$plan['id'] || $old['action'] !== $action || $old['fingerprint'] !== $fp) {
                    throw new \DomainException('原请求内容与本次不同');
                }
                return json_decode($old['result'], true);
            }
            self::version($plan, $p);
            $before = self::detailById((int)$plan['id']);
            $work($plan);
            self::q('purchase_plan')->where('id', $plan['id'])->inc('version')->update(['update_time' => time()]);
            $result = self::detailById((int)$plan['id']);
            self::q('purchase_plan_action')->insert(['tenant_id' => self::tenantId(), 'purchase_plan_id' => $plan['id'], 'action' => $action, 'idempotency_key' => $key, 'fingerprint' => $fp, 'payload' => json_encode(['request' => $p, 'before' => $before], JSON_UNESCAPED_UNICODE), 'result' => json_encode($result, JSON_UNESCAPED_UNICODE), 'operator_id' => FinanceAccess::operator(), 'create_time' => time()]);
            return $result;
        }), true);
    }

    public static function recordBought(array $p): array|false
    {
        return self::mutate($p, 'bought', static function($plan)use($p) {
            if ($plan['status'] === 'cancelled') {
                throw new \DomainException('采购计划已取消，不能登记已买');
            }
            if (!($p['lines'] ?? [])) {
                throw new \DomainException('请填写本次已买数量');
            }
            $seen = [];
            foreach ((array)($p['lines'] ?? []) as $e) {
                $l = self::line((int)$e['line_id'], (int)$plan['id']);
                if (isset($seen[$l['id']])) {
                    throw new \DomainException('采购明细重复');
                }
                $seen[$l['id']] = true;
                $unitId = (int)self::q('customer_report_item')->where('id', $l['report_item_id'])->value('unit_id');
                $n = bcadd($l['bought_qty'], self::quantityForUnit($e['quantity'] ?? '', $unitId), 4);
                if (bccomp($n, $l['planned_qty'], 4) > 0) {
                    throw new \DomainException('已买数量超过安排范围');
                }
                self::q('purchase_plan_source')->where('id', $l['id'])->update(['bought_qty' => $n, 'update_time' => time()]);
            }
        });
    }

    private static function line(int $id, int $plan): array
    {
        $r = self::q('purchase_plan_source')->where('id', $id)->where('purchase_plan_id', $plan)->lock(true)->find();
        if (!$r) {
            throw new \DomainException('采购明细不属于本计划');
        }
        return $r;
    }

    public static function revise(array $p): array|false
    {
        return self::mutate($p, 'revise', static function ($plan) use ($p) {
            $entries = $p['lines'] ?? [];
            if (!is_array($entries) || !$entries || in_array($plan['status'], ['complete', 'cancelled'], true)) {
                throw new \DomainException('当前采购计划没有可调整的数量');
            }
            $reason = trim((string)($p['reason'] ?? ''));
            if (mb_strlen($reason) > 500) {
                throw new \DomainException('原因最多填写500字');
            }
            $seen = [];
            foreach ($entries as $entry) {
                $line = self::line((int)($entry['line_id'] ?? 0), (int)$plan['id']);
                if (isset($seen[$line['id']])) {
                    throw new \DomainException('采购明细重复');
                }
                $seen[$line['id']] = true;
                $item = self::item((int)$line['report_item_id'], true);
                $target = self::quantityForUnit($entry['planned_qty'] ?? '', (int)$item['unit_id'], true);
                $bought = self::quantityForUnit($entry['bought_qty'] ?? $line['bought_qty'], (int)$item['unit_id'], true);
                if (bccomp($bought, $line['bought_qty'], 4) < 0 || bccomp($bought, $line['fulfilled_qty'], 4) < 0
                    || bccomp($bought, $line['planned_qty'], 4) > 0 || bccomp($target, $bought, 4) < 0) {
                    throw new \DomainException('调整数量不能少于已买、在途或已分配数量，请核对采购事实');
                }
                $difference = bcsub($target, $line['planned_qty'], 4);
                if (bccomp($difference, '0', 4) < 0) {
                    self::requireArrivalsResolved((int)$plan['id'], (int)$line['sku_id']);
                } elseif (bccomp($difference, '0', 4) > 0) {
                    $demand = self::demand($item, true);
                    if ($demand['stock_verification_required'] || bccomp($difference, $demand['remaining_qty'], 4) > 0) {
                        throw new \DomainException('增加数量超过可安排范围，或库存尚待核实');
                    }
                }
                self::updateSourceQuantities($line, $target, $bought);
            }
            self::refreshPlanStatus((int)$plan['id']);
        });
    }

    private static function requireArrivalsResolved(int $planId, int $skuId): void
    {
        $hasPending = self::q('purchase_plan_hold')->where('purchase_plan_id', $planId)
            ->where('sku_id', $skuId)->where('remaining_qty', '>', 0)->lock(true)->field('id')->find();
        if ($hasPending) {
            throw new \DomainException('请先核实已到货实物的分配与去向，再调减未买数量');
        }
    }

    private static function updateSourceQuantities(array $line, string $planned, string $bought): void
    {
        $status = 'pending';
        if (bccomp($planned, '0', 4) === 0) {
            $status = 'released';
        } elseif (bccomp($line['fulfilled_qty'], $planned, 4) === 0) {
            $status = 'fulfilled';
        } elseif (bccomp($line['fulfilled_qty'], '0', 4) > 0) {
            $status = 'partial';
        }
        self::q('purchase_plan_source')->where('id', $line['id'])->update([
            'planned_qty' => $planned, 'bought_qty' => $bought, 'status' => $status, 'update_time' => time(),
        ]);
    }

    private static function refreshPlanStatus(int $planId): void
    {
        $hasQuantity = false;
        $hasOutstanding = false;
        $hasFulfilled = false;
        foreach (self::lines($planId, true) as $line) {
            $hasQuantity = $hasQuantity || bccomp($line['planned_qty'], '0', 4) > 0;
            $hasOutstanding = $hasOutstanding || bccomp($line['planned_qty'], $line['fulfilled_qty'], 4) > 0;
            $hasFulfilled = $hasFulfilled || bccomp($line['fulfilled_qty'], '0', 4) > 0;
        }
        $hasPendingArrival = self::q('purchase_plan_hold')->where('purchase_plan_id', $planId)
            ->where('remaining_qty', '>', 0)->lock(true)->field('id')->find();
        $status = !$hasQuantity ? 'cancelled' : (!$hasOutstanding && !$hasPendingArrival ? 'complete' :
            ($hasFulfilled || $hasPendingArrival ? 'partial' : 'pending'));
        self::q('purchase_plan')->where('id', $planId)->update(['status' => $status]);
    }

    public static function preview(array $p): array|false
    {
        return self::run(static function () use ($p) {
            $plan = self::plan((int)($p['id'] ?? 0));
            return ['sheets' => !empty($p['original_sheet_id'])
                ? [self::historicalSheet($plan, (int)$p['original_sheet_id'])]
                : self::previewSheets($plan, (string)($p['mode'] ?? 'merged'))];
        });
    }

    private static function historicalSheet(array $plan, int $sheetId): array
    {
        if (!WorkforceLogic::requirePermission('task.reprint')) {
            throw new \DomainException('没有纸单补打权限');
        }
        $sheet = self::q('purchase_plan_sheet')->where('purchase_plan_id', $plan['id'])
            ->where('id', $sheetId)->where('status', '<>', 'pending')->find();
        if (!$sheet) {
            throw new \DomainException('原纸单不存在或结果待核实');
        }
        $view = self::sheetView($sheet);
        $view['ticket']['is_reprint'] = true;
        $view['ticket']['ticket_no'] = '';
        $view['ticket']['copy_no'] = 1 + (int)self::q('purchase_plan_sheet')
            ->where('purchase_plan_id', $plan['id'])->where('sheet_key', $sheet['sheet_key'])->max('copy_no');
        return $view;
    }

    private static function previewSheets(array $plan, string $mode): array
    {
        if (!in_array($mode, ['merged', 'customer'], true)) {
            throw new \DomainException('打印方式无效');
        }
        if (in_array($plan['status'], ['cancelled', 'complete'], true)) {
            throw new \DomainException('当前采购清单已结束');
        }
        $groups = [];
        foreach (self::lines((int)$plan['id']) as $l) {
            if (bccomp($l['planned_qty'], $l['bought_qty'], 4) <= 0) {
                continue;
            }
            $customerId = self::q('customer_report_item')->where('id', $l['report_item_id'])->value('delivery_customer_id');
            $key = $mode === 'customer'?'customer:' . ($customerId?:'report:' . $l['report_id']):'merged';
            $groups[$key][] = $l;
        }
        if (!$groups) {
            throw new \DomainException('本计划已全部买齐，仅需继续核实到货');
        }

        $previousCopies = self::q('purchase_plan_sheet')->where('purchase_plan_id', $plan['id'])
            ->fieldRaw('sheet_key, MAX(copy_no) AS last_copy_no')->group('sheet_key')->select()->toArray();
        $lastCopyBySheet = array_column($previousCopies, 'last_copy_no', 'sheet_key');
        $out = [];
        foreach ($groups as $key => $lines) {
            usort($lines, static fn($a, $b) => [$a['goods_id'], $a['sku_id'], $a['report_id'], $a['id']] <=> [$b['goods_id'], $b['sku_id'], $b['report_id'], $b['id']]);
            $ticket = [
                'plan_no' => $plan['plan_no'],
                'ticket_no' => '',
                'content_version' => (int)$plan['version'],
                'copy_no' => 1 + (int)($lastCopyBySheet[$key] ?? 0),
                'is_reprint' => $previousCopies !== [],
                'process_name' => '采购',
                'delivery_date' => $plan['delivery_date'],
                'warehouse_name' => $plan['warehouse_name'],
                'print_lines' => [],
            ];
            foreach ($lines as $line) {
                $ticket['print_lines'][] = [
                    'goods_id' => $line['goods_id'],
                    'sku_id' => $line['sku_id'],
                    'goods_name' => $line['goods_name'],
                    'sku_name' => $line['sku_name'],
                    'quantity' => bcsub($line['planned_qty'], $line['bought_qty'], 4),
                    'planned_qty' => self::decimal($line['planned_qty']),
                    'bought_qty' => self::decimal($line['bought_qty']),
                    'fulfilled_qty' => self::decimal($line['fulfilled_qty']),
                    'report_sn' => (string)self::q('customer_report')->where('id', $line['report_id'])->value('sn'),
                    'unit_name' => $line['unit_name'],
                    'customer_name' => $line['customer_name'],
                    'requirement' => (string)$line['requirement'],
                    'report_item_id' => $line['report_item_id'],
                ];
            }
            $out[] = ['sheet_key' => $key, 'customer_name' => $mode === 'customer'?$lines[0]['customer_name']:'合并采购清单', 'ticket' => $ticket];
        }
        return $out;
    }

    private static function sheetView(array $s): array
    {
        $s['ticket'] = json_decode($s['ticket'], true);
        return $s;
    }

    public static function preparePrint(array $p): array|false
    {
        return self::run(static fn() => Db::transaction(static function()use($p) {
            $plan = self::plan((int)($p['id'] ?? 0), true);
            $key = self::key($p);
            $mode = (string)($p['mode'] ?? 'merged');
            $fingerprintInput = $p;
            unset($fingerprintInput['recover_only']);
            $fp = hash('sha256', json_encode($fingerprintInput));
            $prior = self::q('purchase_plan_sheet')->where('purchase_plan_id', $plan['id'])->where('request_key', $key)->select()->toArray();
            if ($prior) {
                if ($prior[0]['request_fingerprint'] !== $fp) {
                    throw new \DomainException('本次打印与原请求内容不一致');
                }
                self::authorizeSheets($prior);
                return ['recover_only' => true, 'sheets' => array_map([self::class, 'sheetView'], $prior)];
            }
            $pending = self::q('purchase_plan_sheet')->where('purchase_plan_id', $plan['id'])->where('status', 'pending')->select()->toArray();
            if ($pending) {
                self::authorizeSheets($pending);
                return ['recover_only' => true, 'sheets' => array_map([self::class, 'sheetView'], $pending)];
            }
            if (($p['recover_only'] ?? false) === true) {
                return ['recover_only' => true, 'sheets' => []];
            }
            self::version($plan, $p);
            $sheets = !empty($p['original_sheet_id'])
                ? [self::historicalSheet($plan, (int)$p['original_sheet_id'])]
                : self::previewSheets($plan, $mode);
            if (isset($p['sheet_keys'])) {
                $keys = array_values(array_unique(array_map('strval', (array)$p['sheet_keys'])));
                $sheets = array_values(array_filter($sheets, static fn($s) => in_array($s['sheet_key'], $keys, true)));
                if (count($sheets) !== count($keys) || !$sheets) {
                    throw new \DomainException('所选纸张不属于本次打印方式');
                }
            }
            $permission = $sheets[0]['ticket']['is_reprint'] ? 'task.reprint' : 'task.print';
            if (!WorkforceLogic::requirePermission($permission)) {
                throw new \DomainException('没有对应打印权限');
            }
            foreach ($sheets as &$s) {
                $copy = $s['ticket']['copy_no'];
                $s['ticket']['ticket_no'] = $plan['plan_no'] . '-' . str_replace(':', '-', $s['sheet_key']) . '-' . $copy;
                $id = self::q('purchase_plan_sheet')->insertGetId(['tenant_id' => self::tenantId(), 'purchase_plan_id' => $plan['id'], 'plan_version' => $s['ticket']['content_version'], 'request_key' => $key, 'request_fingerprint' => $fp, 'print_permission' => $permission, 'sheet_key' => $s['sheet_key'], 'copy_no' => $copy, 'status' => 'pending', 'ticket' => json_encode($s['ticket'], JSON_UNESCAPED_UNICODE), 'create_time' => time(), 'update_time' => time()]);
                $s = self::sheetView(self::q('purchase_plan_sheet')->where('id', $id)->find());
            }
            unset($s);
            return ['sheets' => $sheets];
        }));
    }

    private static function authorizeSheets(array $sheets): void
    {
        foreach ($sheets as $s) {
            if (!WorkforceLogic::requirePermission($s['print_permission'])) {
                throw new \DomainException('没有该纸单对应的打印或补打权限');
            }
        }
    }

    public static function printResult(array $p): array|false
    {
        return self::run(static fn() => Db::transaction(static function()use($p) {
            $s = self::q('purchase_plan_sheet')->where('id', (int)($p['sheet_id'] ?? 0))->lock(true)->find();
            if (!$s) {
                throw new \DomainException('采购纸单不存在');
            }
            if (!in_array($p['success'] ?? null, [0, 1, '0', '1'], true)) {
                throw new \DomainException('请明确纸单发送结果');
            }
            $state = (int)$p['success'] === 1?'printed':'failed';
            if ($s['status'] !== 'pending' && $s['status'] !== $state) {
                throw new \DomainException('纸单结果已确认，不能覆盖');
            }
            self::authorizeSheets([$s]);
            self::q('purchase_plan_sheet')->where('id', $s['id'])->update(['status' => $state, 'error_message' => mb_substr((string)($p['error_message'] ?? ''), 0, 500), 'update_time' => time()]);
            return self::sheetView(self::q('purchase_plan_sheet')->where('id', $s['id'])->find());
        }));
    }
    /** 采购入库事务内按真实到货行锁定库存；不同 SKU 不共用一个数量。 */

    public static function holdArrivalWithinTransaction(int $planId, int $batchId): string|false
    {
        try {
            $plan = self::plan($planId, true);
            $outstanding = array_filter(self::lines($planId, true), static fn(array $line): bool => bccomp($line['planned_qty'], $line['fulfilled_qty'], 4) > 0);
            $allowed = array_unique(array_column($outstanding, 'sku_id'));
            $batch = self::q('purchase_batch')->where('id', $batchId)->where('purchase_plan_id', $planId)->find();
            if (!$batch || (int)$batch['warehouse_id'] !== (int)$plan['warehouse_id']) {
                throw new \DomainException('采购批次与计划仓库不一致');
            }
            $ids = self::q('purchase_batch_supply_order')->where('purchase_batch_id', $batchId)->column('supply_order_id');
            $rows = self::q('order_goods')->where('order_type', 'supply')->whereIn('order_id', $ids)->order('sku_id,id')->select()->toArray();
            if (!$rows) {
                throw new \DomainException('采购批次没有实际到货');
            }
            foreach ($rows as $r) {
                if (!in_array($r['sku_id'], $allowed)) {
                    throw new \DomainException('到货包含不属于本计划的商品');
                }
                $qty = self::quantity($r['actual_base_qty']);
                if (WarehouseSkuBalanceService::reserveWithinTransaction((int)$plan['warehouse_id'], (int)$r['sku_id'], $qty) === false) {
                    throw new \DomainException('计划到货库存锁定失败');
                }
                self::q('purchase_plan_hold')->insert(['tenant_id' => self::tenantId(), 'purchase_plan_id' => $planId, 'purchase_batch_id' => $batchId, 'arrival_line_id' => $r['id'], 'sku_id' => $r['sku_id'], 'arrival_qty' => $qty, 'remaining_qty' => $qty, 'create_time' => time()]);
            }
            // 旧批次标量不承载多商品锁定；权威逐行记录在 purchase_plan_hold。
            return '0.0000';
        } catch (\Throwable $e) {
            self::setError($e instanceof \DomainException?$e->getMessage():'采购到货锁定失败');
            return false;
        }
    }

    public static function arrivalPreview(array $p): array|false
    {
        return self::run(static function()use($p) {
            self::plan((int)($p['id'] ?? 0));
            $rows = self::q('purchase_plan_hold')->where('purchase_plan_id', (int)$p['id'])->where('purchase_batch_id', (int)($p['purchase_batch_id'] ?? 0))->order('id')->select()->toArray();
            foreach ($rows as &$r) {
                $sku = self::q('goods_sku')->where('id', $r['sku_id'])->find();
                $r['base_unit_name'] = $sku['base_unit_name'] ?? '';
                $r['goods_name'] = (string)self::q('goods')->where('id', $sku['goods_id'] ?? 0)->value('name');
            }
            unset($r);
            return ['rows' => $rows];
        });
    }

    public static function attachArrival(array $p): array|false
    {
        return self::mutate($p, 'allocate', static function($plan)use($p) {
            $batchId = (int)($p['purchase_batch_id'] ?? 0);
            $batch = self::q('purchase_batch')->where('id', $batchId)->where('purchase_plan_id', $plan['id'])->lock(true)->find();
            if (!$batch) {
                throw new \DomainException('采购批次不属于本计划');
            }
            $holds = self::q('purchase_plan_hold')->where('purchase_plan_id', $plan['id'])->where('purchase_batch_id', $batchId)->order('id')->lock(true)->select()->toArray();
            $byId = [];
            foreach ($holds as $h) {
                $byId[(int)$h['arrival_line_id']] = $h;
            }
            if (!$holds) {
                throw new \DomainException('本批采购没有可核实到货行');
            }
            $arrival = self::q('purchase_plan_arrival')->where('purchase_plan_id', $plan['id'])->where('purchase_batch_id', $batchId)->find();
            $arrivalId = $arrival?(int)$arrival['id']:(int)self::q('purchase_plan_arrival')->insertGetId(['tenant_id' => self::tenantId(), 'purchase_plan_id' => $plan['id'], 'purchase_batch_id' => $batchId, 'create_time' => time()]);
            $used = [];
            $seen = [];
            $entries = (array)($p['allocations'] ?? []);
            $surplus = (array)($p['surplus'] ?? []);
            if (!$entries && !$surplus) {
                throw new \DomainException('请填写本次来源分配或明确余量');
            }
            foreach ($entries as $e) {
                $l = self::line((int)($e['line_id'] ?? 0), (int)$plan['id']);
                $a = (int)($e['arrival_line_id'] ?? 0);
                $h = $byId[$a] ?? null;
                $identity = $l['id'] . ':' . $a;
                if (!$h || (int)$h['sku_id'] !== (int)$l['sku_id'] || isset($seen[$identity])) {
                    throw new \DomainException('来源和到货行 SKU 不一致或重复');
                }
                $seen[$identity] = true;
                $base = self::quantity($e['base_qty'] ?? '');
                $qty = self::quantity($e['quantity'] ?? '');
                if (bccomp($qty, bcsub($l['planned_qty'], $l['fulfilled_qty'], 4), 4) > 0) {
                    throw new \DomainException('分配超过本来源仍需满足的采购数量');
                }
                $used[$a] = bcadd($used[$a] ?? '0', $base, 4);
                if (bccomp($used[$a], $h['remaining_qty'], 4) > 0) {
                    throw new \DomainException('分配超过到货行剩余基础量');
                }
                $i = self::q('customer_report_item')->where('id', $l['report_item_id'])->lock(true)->find();
                if (!$i) {
                    throw new \DomainException('来源报货明细不存在');
                }
                $qty = self::quantityForUnit($qty, (int)$i['unit_id']);
                $demandUnit = TradeQuantityService::unit((int)$i['unit_id']);
                $baseUnit = TradeQuantityService::unit((int)$i['base_unit_id']);
                if ((int)$i['unit_id'] === (int)$i['base_unit_id'] && bccomp($qty, $base, 4) !== 0) {
                    throw new \DomainException('同单位来源的实物量与覆盖量必须一致');
                }
                if (($demandUnit['measure_type'] ?? '') === 'weight' && ($baseUnit['measure_type'] ?? '') === 'weight') {
                    $expected = bcdiv(bcmul($qty, $demandUnit['jin_factor'], 8), $baseUnit['jin_factor'], 4);
                    if (bccomp($base, $expected, 4) !== 0) {
                        throw new \DomainException('重量单位覆盖量与实物基础量换算不一致');
                    }
                }
                if ((int)($i['piece_weight_confirmed'] ?? 0) === 1 && ($e['requirements_verified'] ?? false) !== true) {
                    throw new \DomainException('请先核实到货符合本客户明确的大小要求');
                }
                $report = self::q('customer_report')->where('id', $i['report_id'])->find();
                if ($report['status'] === 'cancelled') {
                    PendingGoodsLogic::capturePlanArrivalWithinTransaction((int)$i['report_id'], $batchId, $a, (int)$l['id'], $i, $base);
                } else {
                    self::addHeldToItem($i, $base);
                }
                self::q('purchase_plan_source')->where('id', $l['id'])->update(['fulfilled_qty' => bcadd($l['fulfilled_qty'], $qty, 4), 'bought_qty' => bccomp($l['bought_qty'], bcadd($l['fulfilled_qty'], $qty, 4), 4) < 0?bcadd($l['fulfilled_qty'], $qty, 4):$l['bought_qty'], 'allocated_qty' => bcadd($l['allocated_qty'], $base, 4), 'status' => bccomp(bcadd($l['fulfilled_qty'], $qty, 4), $l['planned_qty'], 4) === 0?'fulfilled':'partial', 'update_time' => time()]);
                if ($report['status'] !== 'cancelled') {
                    CustomerReportLogic::refreshProcurementWithinTransaction((int)$i['report_id']);
                }
                self::q('purchase_plan_allocation')->insert(['tenant_id' => self::tenantId(), 'purchase_plan_id' => $plan['id'], 'arrival_id' => $arrivalId, 'source_id' => $l['id'], 'arrival_line_id' => $a, 'quantity' => $qty, 'allocated_qty' => $base, 'create_time' => time()]);
            }
            foreach ($surplus as $e) {
                $a = (int)($e['arrival_line_id'] ?? 0);
                $h = $byId[$a] ?? null;
                if (!$h) {
                    throw new \DomainException('余量到货行不存在');
                }
                foreach (self::lines((int)$plan['id'])as$source) {
                    if ((int)$source['sku_id'] === (int)$h['sku_id'] && bccomp($source['planned_qty'], $source['fulfilled_qty'], 4) > 0 && self::q('customer_report')->where('id', $source['report_id'])->value('status') === 'cancelled') {
                        throw new \DomainException('客户取消对应已购实物须先分配转入待定货物，不能直接转普通库存');
                    }
                }
                $base = self::quantity($e['base_qty'] ?? '');
                $used[$a] = bcadd($used[$a] ?? '0', $base, 4);
                if (bccomp($used[$a], $h['remaining_qty'], 4) > 0) {
                    throw new \DomainException('分配与余量超过本行实收');
                }
                if (WarehouseSkuBalanceService::releaseWithinTransaction((int)$plan['warehouse_id'], (int)$h['sku_id'], $base) === false) {
                    throw new \DomainException('到货余量释放失败');
                }
            }
            foreach ($used as $a => $n) {
                $h = $byId[$a];
                self::q('purchase_plan_hold')->where('id', $h['id'])->update(['remaining_qty' => bcsub($h['remaining_qty'], $n, 4)]);
            }
            self::refreshPlanStatus((int)$plan['id']);
        });
    }

    private static function addHeldToItem(array $i, string $base): void
    {
        $reserved = bcadd($i['reserved_base_qty'], $base, 4);
        $shortage = bcsub((string)$i['expected_base_qty'], $reserved, 4);
        if (bccomp($shortage, '0', 4) < 0) {
            $shortage = '0.0000';
        }
        self::q('customer_report_item')->where('id', $i['id'])->update(['reserved_base_qty' => $reserved, 'shortage_base_qty' => $shortage, 'update_time' => time()]);
        $reservation = self::q('customer_report_reservation')->where('report_item_id', $i['id'])->lock(true)->find();
        if (!$reservation) {
            throw new \DomainException('来源库存预留依据不存在');
        }
        self::q('customer_report_reservation')->where('report_item_id', $i['id'])->update(['reserved_base_qty' => bcadd($reservation['reserved_base_qty'], $base, 4), 'status' => 'reserved', 'update_time' => time()]);
    }

    public static function itemChangeBlockedReason(int $itemId): string
    {
        foreach (self::q('purchase_plan_source')->where('report_item_id', $itemId)->where('status', '<>', 'released')->select()->toArray() as $source) {
            if (bccomp($source['planned_qty'], $source['fulfilled_qty'], 4) > 0) {
                return '仍有采购安排，请先调整未买数量或核实已买货物去向';
            }
        }
        return '';
    }

    public static function releaseReportSourcesWithinTransaction(int $reportId, string $reason): void
    {
        $sources = self::q('purchase_plan_source')->where('report_id', $reportId)
            ->where('status', '<>', 'released')->order('purchase_plan_id,id')->lock(true)->select()->toArray();
        foreach (array_unique(array_column($sources, 'purchase_plan_id')) as $planId) {
            self::plan((int)$planId, true);
            $before = self::detailById((int)$planId);
            foreach ($sources as $line) {
                if ((int)$line['purchase_plan_id'] !== (int)$planId || bccomp($line['planned_qty'], $line['bought_qty'], 4) <= 0) {
                    continue;
                }
                self::requireArrivalsResolved((int)$planId, (int)$line['sku_id']);
                self::updateSourceQuantities($line, $line['bought_qty'], $line['bought_qty']);
            }
            self::refreshPlanStatus((int)$planId);
            self::q('purchase_plan')->where('id', $planId)->inc('version')->update(['update_time' => time()]);
            self::q('purchase_plan_action')->insert([
                'tenant_id' => self::tenantId(), 'purchase_plan_id' => $planId, 'action' => 'report_cancel',
                'idempotency_key' => 'report-cancel-' . $reportId . '-' . $planId,
                'fingerprint' => hash('sha256', $reason),
                'payload' => json_encode(['report_id' => $reportId, 'reason' => $reason, 'before' => $before], JSON_UNESCAPED_UNICODE),
                'result' => json_encode(self::detailById((int)$planId), JSON_UNESCAPED_UNICODE),
                'operator_id' => FinanceAccess::operator(), 'create_time' => time(),
            ]);
        }
    }

    public static function prioritySuggestions(array $ids): array
    {
        $result = [];
        foreach ($ids as $id) {
            $p = self::q('purchase_plan')->where('id', $id)->find();
            if (!$p) {
                continue;
            }
            $result[$id] = ['delivery_date' => $p['delivery_date'], 'earliest_delivery_time' => null, 'source_count' => count(self::lines((int)$id)), 'sources' => self::lines((int)$id)];
        }
        return $result;
    }

    public static function emptyPrioritySuggestion(): array
    {
        return ['delivery_date' => null, 'earliest_delivery_time' => null, 'source_count' => 0, 'sources' => []];
    }
}
