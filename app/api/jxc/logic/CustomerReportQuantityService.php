<?php
declare(strict_types=1);
namespace app\api\jxc\logic;
use think\facade\Db;

/** Called only by the authorized, versioned report-weight entry point. */
final class CustomerReportQuantityService
{
    public static function save(array $params): void
    {
        $tenant = (int)request()->tenantId;
        Db::transaction(static function () use ($params, $tenant): void {
            FinanceIntegration::lock();
            $report = Db::name('customer_report')->where('tenant_id', $tenant)->where('id', (int)$params['id'])->lock(true)->find();
            $key = trim((string)($params['idempotency_key'] ?? ''));
            $fingerprint = hash('sha256', json_encode($params, JSON_UNESCAPED_UNICODE));
            if ($key === '' || strlen($key) > 96) { throw new \DomainException('数量保存须携带原操作编号以便超时核实'); }
            if ($report && ($report['quantity_operation_key'] ?? '') === $key) {
                if (!hash_equals((string)$report['quantity_operation_fingerprint'], $fingerprint)) { throw new \DomainException('同一操作编号不能提交不同数量事实'); }
                return;
            }
            if (!$report || (int)$report['version'] !== (int)($params['version'] ?? 0)) {
                throw new \DomainException('另一人已修改，请保留草稿并核对最新版本');
            }
            if (!in_array($report['status'], ['submitted_ready', 'submitted_shortage', 'submitted_pending_measurement', 'partially_delivered_pending'], true)) {
                throw new \DomainException('当前报货已经交付或不可修改数量事实');
            }
            $items = Db::name('customer_report_item')->where('tenant_id', $tenant)->where('report_id', $report['id'])
                ->whereNull('delete_time')->order('warehouse_id,sku_id,id')->lock(true)->select()->toArray();
            $entries = [];
            foreach ((array)($params['groups'] ?? []) as $entry) {
                $id = (int)($entry['id'] ?? 0);
                if ($id === 0 && (int)($entry['append_to_group_id'] ?? 0) > 0) {
                    $id = self::appendGroup($entry, $items, $tenant);
                }
                if ($id <= 0 || isset($entries[$id])) { throw new \DomainException('交付分组身份缺失或重复'); }
                $entries[$id] = $entry;
            }
            if ($entries === []) { throw new \DomainException('请提交本次实际称量的分组'); }
            $allBase = '0.0000'; $allReserved = '0.0000'; $allShortage = '0.0000'; $hasUnknown = false;
            foreach ($items as $item) {
                if ((int)$item['quantity_contract_version'] !== 2) { throw new \DomainException('本次报货须统一使用数量事实 V2'); }
                $groups = Db::name('customer_report_processing_group')->where('tenant_id', $tenant)->where('report_item_id', $item['id'])->order('id')->lock(true)->select()->toArray();
                if (array_intersect(array_keys($entries), array_column($groups, 'id')) === []) {
                    $hasUnknown = $hasUnknown || ($item['estimate_status'] ?? '') === 'unknown';
                    $allBase = bcadd($allBase, (string)$item['expected_base_qty'], 4);
                    $allReserved = bcadd($allReserved, (string)$item['reserved_base_qty'], 4);
                    $allShortage = bcadd($allShortage, (string)$item['shortage_base_qty'], 4);
                    continue;
                }
                $units = TradeQuantityService::boundUnits($item);
                if (!isset($units[(int)$item['base_unit_id']], $units[(int)$item['unit_id']])) { throw new \DomainException('报货或库存单位已停用，请核实后继续'); }
                $normalized = []; $baseTotal = '0.0000'; $weightTotal = null; $countTotals = []; $countNames = []; $lossTotal = '0.0000'; $newCounts = []; $unmeasured = false;
                foreach ($groups as $group) {
                    $id = (int)$group['id'];
                    $existing = TradeQuantityService::snapshot($group);
                    if (!isset($entries[$id])) {
                        if (!$existing) { $unmeasured = true; continue; }
                        if (!Db::name('fulfillment_delivery_item')->where('tenant_id', $tenant)->where('delivery_group_key', $existing['delivery_group_key'])->count()) {
                            $existing['version'] = (int)$report['version'] + 1;
                            Db::name('customer_report_processing_group')->where('tenant_id', $tenant)->where('id', $id)
                                ->update(['quantity_facts_snapshot' => json_encode($existing, JSON_UNESCAPED_UNICODE), 'update_time' => time()]);
                        }
                        $baseTotal = bcadd($baseTotal, $existing['inventory_base_quantity'], 4);
                        if ($existing['actual_delivery_weight'] !== null) { $weightTotal = bcadd($weightTotal ?? '0', $existing['actual_delivery_weight'], 4); }
                        foreach ($existing['quantity_facts'] as $count) {
                            $countTotals[$count['unit_id']] = bcadd($countTotals[$count['unit_id']] ?? '0', $count['quantity'], 4);
                            $countNames[$count['unit_id']] = $count['unit_name'];
                        }
                        continue;
                    }
                    if ($existing && Db::name('fulfillment_delivery_item')->where('tenant_id', $tenant)->where('delivery_group_key', $existing['delivery_group_key'])->count() > 0) {
                        throw new \DomainException('已交付分组不能覆盖，请新增剩余补交分组');
                    }
                    if ((int)$group['processing_loss_flow_id'] > 0) { throw new \DomainException('加工损耗已正式处理，不能覆盖原事实；请走关联更正'); }
                    $entry = $entries[$id]; $package = null;
                    if (isset($entry['packaging'])) {
                        if (isset($entry['net_weight_input']) || isset($entry['gross_weight_input']) || !empty($entry['final_actual_weight'])) {
                            throw new \DomainException('已有实称不能被包装默认覆盖，请选择保留实称或明确重新核实来源');
                        }
                        $package = GoodsPackagingSourceService::verify($entry['packaging'], $item);
                        unset($entry['packaging']);
                        $entry['net_weight_input'] = ['value' => $package['net_weight'], 'unit' => '斤'];
                        $counts = (array)($entry['quantity_facts'] ?? []); $found = false;
                        foreach ($counts as $count) {
                            if ((int)$count['unit_id'] === (int)$package['unit_id']) {
                                $found = true;
                                if (bccomp((string)$count['quantity'], $package['quantity'], 4) !== 0) { throw new \DomainException('包装来源数量和实交包装数量不一致'); }
                            }
                        }
                        if (!$found) { $counts[] = ['unit_id' => $package['unit_id'], 'quantity' => $package['quantity'], 'source' => 'counted']; }
                        $entry['quantity_facts'] = $counts;
                    }
                    $facts = TradeQuantityFacts::normalize($entry, $item, $units);
                    if ($package !== null) {
                        $facts['packaging'] = $package; $facts['weight_source'] = 'verified_packaging_rule'; $facts['net_weight_input'] = null;
                        if ($facts['processing'] !== null) { throw new \DomainException('包装加工破坏完整条件，请录实际净重并核实来源'); }
                    }
                    // 工序是现场作业要求；本版不自动核算加工损耗，也不伪造零损耗事实。
                    unset($entries[$id]);
                    $facts['measured_at'] = time(); $facts['recorded_by'] = (int)(request()->userId ?? request()->adminId ?? 0);
                    $facts['report_item_id'] = (int)$item['id']; $facts['processing_group_id'] = $id;
                    $facts['delivery_group_key'] = 'report:' . $report['id'] . ':group:' . $id;
                    $facts['version'] = (int)$report['version'] + 1;
                    if ((int)$item['piece_weight_confirmed'] === 1 && !empty($params['specification_verifications'][$item['id']])) {
                        $verification = $params['specification_verifications'][$item['id']] ?? $params['specification_verifications'][(string)$item['id']] ?? null;
                        if (!is_array($verification) || ($verification['confirmed'] ?? false) !== true) { throw new \DomainException('客户有大小要求，请先按要求核对大小并确认'); }
                        $mode = $verification['mode'] ?? 'measured_range';
                        if (!in_array($mode, ['manual_confirmation', 'measured_range'], true)
                            || ($verification['stage'] ?? '') !== ($item['size_check_stage'] ?? 'delivery')) {
                            throw new \DomainException('大小核验方式或核验阶段不正确');
                        }
                        if ($mode === 'measured_range') {
                            $minimum = TradeQuantityFacts::decimal($verification['min'] ?? null);
                            $maximum = TradeQuantityFacts::decimal($verification['max'] ?? null);
                            if (bccomp($minimum, (string)$item['piece_weight_min'], 4) < 0 || bccomp($maximum, (string)$item['piece_weight_max'], 4) > 0
                                || bccomp($maximum, $minimum, 4) < 0) {
                                throw new \DomainException('逐件核验范围不符合客户要求');
                            }
                        } else {
                            // Manual confirmation records the customer's requirement, never invented measurements.
                            $verification = array_intersect_key($verification, array_flip(['mode', 'confirmed', 'stage', 'count']));
                            $verification['requirement'] = ['min' => (string)$item['piece_weight_min'], 'max' => (string)$item['piece_weight_max'], 'unit' => '斤'];
                        }
                        $facts['specification_verification'] = $verification;
                    }
                    $baseTotal = bcadd($baseTotal, $facts['inventory_base_quantity'], 4);
                    if ($facts['actual_delivery_weight'] !== null) { $weightTotal = bcadd($weightTotal ?? '0', $facts['actual_delivery_weight'], 4); }
                    $lossTotal = bcadd($lossTotal, TradeQuantityFacts::exactDivide($facts['processing_loss_weight'], $units[(int)$item['base_unit_id']]['jin_factor'] ?: '1'), 4);
                    foreach ($facts['quantity_facts'] as $count) {
                        $countNames[$count['unit_id']] = $count['unit_name'];
                        $countTotals[$count['unit_id']] = bcadd($countTotals[$count['unit_id']] ?? '0', $count['quantity'], 4);
                        $newCounts[$count['unit_id']] = bcadd($newCounts[$count['unit_id']] ?? '0', $count['quantity'], 4);
                    }
                    $normalized[$id] = $facts;
                }
                if (isset($countTotals[(int)$item['unit_id']]) && bccomp($countTotals[(int)$item['unit_id']], (string)$item['order_qty'], 4) > 0) {
                    throw new \DomainException('实际交付数量超过原需求，请先明确追加需求');
                }
                if ((int)$item['piece_weight_confirmed'] === 1 && $normalized !== [] && !empty($params['specification_verifications'][$item['id']])) {
                    $verification = $params['specification_verifications'][$item['id']] ?? [];
                    if (($verification['mode'] ?? 'measured_range') !== 'manual_confirmation'
                        || array_key_exists('count', $verification) || isset($newCounts[(int)$item['unit_id']])) {
                        $count = TradeQuantityFacts::decimal($verification['count'] ?? null, 0);
                        if (isset($newCounts[(int)$item['unit_id']]) && bccomp($count, $newCounts[(int)$item['unit_id']], 4) !== 0) {
                            throw new \DomainException('逐件核验数量必须与实际清点数量一致');
                        }
                    }
                }
                $remainingCount = isset($countTotals[(int)$item['unit_id']]) ? bcsub((string)$item['order_qty'], $countTotals[(int)$item['unit_id']], 4) : null;
                $complete = $remainingCount === null ? !$unmeasured : bccomp($remainingCount, '0', 4) === 0;
                if (bccomp((string)$item['acceptable_base_qty_max'], '0', 4) > 0 && $weightTotal !== null
                    && (($complete && bccomp($weightTotal, (string)$item['acceptable_base_qty_min'], 4) < 0) || bccomp($weightTotal, (string)$item['acceptable_base_qty_max'], 4) > 0)) {
                    throw new \DomainException('实际总重不符合客户明确的总重范围');
                }
                $pendingBase = bcsub($baseTotal, (string)$item['fulfilled_base_qty'], 4);
                $target = bcadd($pendingBase, $lossTotal, 4); $reserved = (string)$item['reserved_base_qty'];
                $delta = bcsub($target, $reserved, 4);
                if (bccomp($delta, '0', 4) > 0) {
                    $added = WarehouseSkuBalanceService::reserveUpToWithinTransaction((int)$item['warehouse_id'], (int)$item['sku_id'], $delta);
                    if ($added === false) { throw new \DomainException('本仓库存核对失败'); }
                    $reserved = bcadd($reserved, $added, 4);
                } elseif (bccomp($delta, '0', 4) < 0) {
                    if (WarehouseSkuBalanceService::releaseWithinTransaction((int)$item['warehouse_id'], (int)$item['sku_id'], ltrim($delta, '-')) === false) { throw new \DomainException('释放预留失败'); }
                    $reserved = $target;
                }
                if (bccomp($lossTotal, '0', 4) > 0) {
                    if (!WorkforceLogic::requirePermission('delivery.confirm')) { throw new \DomainException('加工损耗出库需要交付库存确认权限'); }
                    if (bccomp($reserved, $target, 4) < 0) { throw new \DomainException('加工取料库存不足，请先核实实物来源'); }
                }
                foreach ($normalized as $groupId => $facts) {
                    $flowId = 0; $loss = TradeQuantityFacts::exactDivide($facts['processing_loss_weight'], $units[(int)$item['base_unit_id']]['jin_factor'] ?: '1');
                    if (bccomp($loss, '0', 4) > 0) {
                        if (!StockService::outboundReservedWithinTransaction((int)$item['warehouse_id'], (int)$item['goods_id'], $loss,
                            $groupId, 'fulfillment_internal_loss', 'PROCESS-' . $groupId, $facts['processing']['reason'], (int)$item['sku_id'])) {
                            throw new \DomainException('加工损耗库存和成本处理失败');
                        }
                        $flowId = (int)Db::name('stock_flow')->where('tenant_id', $tenant)->where('order_type', 'fulfillment_internal_loss')->where('order_id', $groupId)->order('id desc')->value('id');
                        $facts['processing_loss_flow_id'] = $flowId;
                    }
                    Db::name('customer_report_processing_group')->where('tenant_id', $tenant)->where('id', $groupId)->update([
                        'quantity_facts_snapshot' => json_encode($facts, JSON_UNESCAPED_UNICODE), 'final_actual_weight' => $facts['actual_delivery_weight'],
                        'processing_loss_flow_id' => $flowId, 'update_time' => time()]);
                }
                $reserved = bcsub($reserved, $lossTotal, 4); $shortage = bcsub($pendingBase, $reserved, 4);
                $snapshot = ['fact_version' => 2, 'inventory_base_quantity' => $baseTotal, 'actual_delivery_weight' => $weightTotal,
                    'quantity_facts' => array_map(static fn($id, $quantity) => ['unit_id' => $id, 'unit_name' => $countNames[$id], 'quantity' => $quantity, 'source' => 'counted'], array_keys($countTotals), array_values($countTotals)),
                    'remaining_order_quantity' => $remainingCount, 'has_unmeasured_groups' => $unmeasured];
                Db::name('customer_report_item')->where('tenant_id', $tenant)->where('id', $item['id'])->update([
                    'quantity_facts_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE), 'final_actual_weight' => $weightTotal,
                    'expected_base_qty' => $baseTotal, 'reserved_base_qty' => $reserved, 'shortage_base_qty' => $shortage,
                    'estimate_status' => 'measured', 'estimate_source' => 'actual_facts', 'fulfillment_status' => 'final_weight_recorded',
                    'status' => bccomp($shortage, '0', 4) > 0 ? 'submitted_shortage' : 'submitted_ready', 'update_time' => time()]);
                Db::name('customer_report_reservation')->where('tenant_id', $tenant)->where('report_item_id', $item['id'])->update([
                    'reserved_base_qty' => $reserved, 'status' => 'reserved', 'update_time' => time()]);
                $allBase = bcadd($allBase, $baseTotal, 4); $allReserved = bcadd($allReserved, $reserved, 4); $allShortage = bcadd($allShortage, $shortage, 4);
            }
            if ($entries !== []) { throw new \DomainException('交付分组不属于当前报货单'); }
            Db::name('customer_report')->where('tenant_id', $tenant)->where('id', $report['id'])->update([
                'version' => (int)$report['version'] + 1, 'total_base_qty' => $allBase, 'reserved_base_qty' => $allReserved,
                'quantity_operation_key' => $key, 'quantity_operation_fingerprint' => $fingerprint,
                'shortage_base_qty' => $allShortage, 'status' => $hasUnknown ? 'submitted_pending_measurement' : (bccomp($allShortage, '0', 4) > 0 ? 'submitted_shortage' : 'submitted_ready'), 'update_time' => time()]);
        });
    }

    /** A delivered physical group remains immutable; a later batch gets a fresh identity. */
    private static function appendGroup(array $entry, array $items, int $tenant): int
    {
        $source = Db::name('customer_report_processing_group')->where('tenant_id', $tenant)
            ->where('id', (int)$entry['append_to_group_id'])->lock(true)->find();
        if (!$source || !in_array((int)$source['report_item_id'], array_map('intval', array_column($items, 'id')), true)) {
            throw new \DomainException('补交来源分组不属于本报货');
        }
        $facts = TradeQuantityService::snapshot($source);
        if (!$facts || !Db::name('fulfillment_delivery_item')->where('tenant_id', $tenant)->where('delivery_group_key', $facts['delivery_group_key'])->count()) {
            throw new \DomainException('只能从已交分组新增补交批次，未交组请直接录入');
        }
        $sourceId = (int)$source['id']; unset($source['id']);
        $source['group_key'] = 'supplement-' . bin2hex(random_bytes(12));
        $source['name'] = '剩余补交'; $source['planned_qty'] = '0.0000';
        $source['quantity_facts_snapshot'] = null; $source['final_actual_weight'] = null; $source['processing_loss_flow_id'] = 0;
        $source['create_time'] = $source['update_time'] = time();
        $id = (int)Db::name('customer_report_processing_group')->insertGetId($source);
        foreach (Db::name('customer_report_processing_group_process')->where('tenant_id', $tenant)->where('processing_group_id', $sourceId)->select()->toArray() as $process) {
            unset($process['id']); $process['processing_group_id'] = $id;
            Db::name('customer_report_processing_group_process')->insert($process);
        }
        return $id;
    }
}
