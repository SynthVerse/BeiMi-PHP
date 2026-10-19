<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 历史采购只建立来源身份；数量成本已经在期初承接，不重做库存、应付或资金。 */
final class FinancePurchaseLegacySources
{
    public static function linked(int $order): bool
    {
        return self::installed() && Db::name('finance_purchase_legacy_link')->where('tenant_id', FinanceAccess::tenant())->where('original_supply_order_id', $order)->lock(true)->count() > 0;
    }

    public static function recordCurrentWithinTransaction(array $order, array $goods): void
    {
        FinanceDocumentPolicy::authorize('purchase_arrival', true);
        $tenant = FinanceAccess::tenant(); $ledger = new FinanceLedger($tenant); $ledger->lockBook();
        if (bccomp((string)$order['order_pay_money'], '0', 2) > 0) { throw new \DomainException('请先登记到货，再从财务实际付款选择账户与来源，不能在进货单内代记付款'); }
        $payload = ['subject_id' => (int)$order['supplier_id'], 'warehouse_id' => (int)$order['warehouse_id'],
            'actual_date' => date('Y-m-d', (int)$order['datetimesingle']), 'source_reference' => '原进货单 ' . $order['order_sn'],
            'reason' => '按原进货行确认实际到货，供应商结算与付款另外办理',
            'lines' => array_map(static fn(array $row): array => ['sku_id' => (int)$row['sku_id'], 'actual_quantity' => (string)$row['actual_base_qty']], $goods)];
        $actor = FinanceValue::json(FinanceAccess::actor());
        $documentId = (int)Db::name('finance_document')->insertGetId(['tenant_id' => $tenant, 'type' => 'purchase_arrival', 'status' => 'confirmed',
            'version' => 1, 'payload' => FinanceValue::json($payload), 'confirmed_result' => '{}', 'created_by' => $actor,
            'last_modified_by' => $actor, 'confirmed_by' => $actor, 'confirmed_at' => time(), 'create_time' => time(), 'update_time' => time()]);
        $result = FinancePurchaseArrivals::confirm($ledger, ['id' => $documentId], $payload);
        foreach ($result['lines'] as $index => &$line) {
            $original = $goods[$index];
            $arrival = Db::name('purchase_arrival_detail')->where('tenant_id', $tenant)->where('order_goods_id', $original['id'])->where('supply_order_id', $order['id'])->find();
            $line += ['original_supply_order_id' => (int)$order['id'], 'original_order_sn' => $order['order_sn'], 'original_order_goods_id' => (int)$original['id'],
                'original_arrival_detail_id' => (int)$arrival['id'], 'historical_returned_quantity' => '0.0000', 'legacy_history' => false];
            Db::name('finance_purchase_arrival_line')->where('tenant_id', $tenant)->where('id', $line['arrival_line_id'])->update(['snapshot' => FinanceValue::json($line)]);
            Db::name('finance_purchase_legacy_link')->insert(['tenant_id' => $tenant, 'original_supply_order_id' => $order['id'],
                'original_order_goods_id' => $original['id'], 'original_arrival_detail_id' => $arrival['id'], 'arrival_line_id' => $line['arrival_line_id'],
                'fingerprint' => hash('sha256', FinanceValue::json([$order, $original, $arrival])), 'snapshot' => FinanceValue::json($line), 'create_time' => time()]);
        } unset($line);
        $result += ['original_supply_order_id' => (int)$order['id']];
        Db::name('finance_document')->where('tenant_id', $tenant)->where('id', $documentId)->update(['confirmed_result' => FinanceValue::json($result)]);
    }

    public static function options(array $params): array
    {
        FinanceDocumentPolicy::authorize('purchase_return_actual');
        if (!self::installed()) { throw new \DomainException('历史采购来源迁移尚未安装，请联系负责人'); }
        $tenant = FinanceAccess::tenant(); $id = FinanceValue::id($params['original_supply_order_id'] ?? null);
        $order = Db::name('supply_order')->where('tenant_id', $tenant)->where('id', $id)->lock(true)->find();
        if (!$order || (int)$order['status'] !== 1) { throw new \DomainException('原进货单不存在、不属于当前门店或尚未实际到货'); }
        if ((int)$order['datetimesingle'] <= 0) { throw new \DomainException('原实际到货日期尚未核实，不能替换为今天或虚构日期'); }
        $date = date('Y-m-d', (int)$order['datetimesingle']);
        $cutoff = Db::name('finance_preparation')->where('tenant_id', $tenant)->value('activation_date');
        if (!$cutoff || $date >= $cutoff) { throw new \DomainException('该进货发生在启用日后，应从原到货完成财务衔接，不能伪造为历史期初'); }
        $goods = Db::name('order_goods')->where('tenant_id', $tenant)->where('order_type', 'supply')->where('order_id', $id)->order('id')->lock(true)->select()->toArray();
        $lines = []; $fingerprintRows = [];
        $returnedMap = PurchaseReturnOrderLogic::historicalReturnedSupplyQtyMap($id);
        foreach ($goods as $row) {
            $arrival = Db::name('purchase_arrival_detail')->where('tenant_id', $tenant)->where('supply_order_id', $id)->where('order_goods_id', $row['id'])->lock(true)->find();
            if (!$arrival || (int)$arrival['sku_id'] !== (int)$row['sku_id']) { throw new \DomainException('原进货行缺少可靠实际到货身份，请先核实，不能按商品汇总虚构'); }
            if (!Db::name('goods_sku')->where('tenant_id', $tenant)->where('id', $row['sku_id'])->where('goods_id', $row['goods_id'])->find()) { throw new \DomainException('原进货规格身份尚未核实，不能重新分配或虚构SKU'); }
            $quantity = FinancePurchaseSettlement::quantity((string)$arrival['actual_base_qty']);
            $returns = Db::name('purchase_return_order_lists')->where('tenant_id', $tenant)->where('original_supply_order_id', $id)
                ->where('original_supply_order_list_id', $row['id'])->order('id')->lock(true)->select()->toArray();
            $returned = $returnedMap[(int)$row['id']] ?? '0.0000';
            // 旧退货是直接以基础量出库；单位快照不一致不能按现行换算猜填。
            if (($row['units'] ?? '') !== ($row['base_unit_name'] ?? '') && $returns) { throw new \DomainException('历史退货计量口径与基础单位不同，请先核实实际退离量'); }
            if (bccomp($returned, $quantity, 4) > 0) { throw new \DomainException('历史已退量超过原实际到货量，请先核实'); }
            $link = Db::name('finance_purchase_legacy_link')->where('tenant_id', $tenant)->where('original_order_goods_id', $row['id'])->lock(true)->find();
            $currentReturned = '0.0000';
            if ($link) {
                $currentReturned = bcsub((string)Db::name('finance_purchase_return_line')->where('tenant_id', $tenant)->where('arrival_line_id', $link['arrival_line_id'])->sum('quantity'),
                    (string)Db::name('finance_purchase_return_resolution')->where('tenant_id', $tenant)->where('arrival_line_id', $link['arrival_line_id'])->where('kind', 'returned')->sum('quantity'), 4);
            }
            $line = ['original_order_goods_id' => (int)$row['id'], 'original_arrival_detail_id' => (int)$arrival['id'],
                'sku_id' => (int)$row['sku_id'], 'goods_id' => (int)$row['goods_id'], 'goods_name' => $row['name'], 'sku_name' => $row['sku_name'] ?? '',
                'base_unit_name' => $row['base_unit_name'] ?? $row['units'], 'actual_quantity' => $quantity,
                'historical_returned_quantity' => $returned, 'new_returned_quantity' => $currentReturned, 'returnable_quantity' => bcsub(bcsub($quantity, $returned, 4), $currentReturned, 4),
                'original_amount' => FinanceValue::money((string)$row['amount'], true), 'original_price' => FinanceValue::money((string)$row['price'], true), 'arrival_line_id' => (int)($link['arrival_line_id'] ?? 0)];
            $lines[] = $line; $fingerprintRows[] = [$row, $arrival, $returns, $returned];
        }
        if (!$lines || count($lines) > 200) { throw new \DomainException('原单没有可核实到货明细或超过二百行'); }
        $fingerprint = hash('sha256', FinanceValue::json([$order, $fingerprintRows]));
        $vendor = (int)$order['supplier_id']; $ledger = new FinanceLedger($tenant);
        $sources = $ledger->sources('payable', $vendor);
        if (!FinanceIntegration::active()) {
            $sources = Db::name('finance_opening_item')->where('tenant_id', $tenant)->where('category', 'payable')->where('subject_id', $vendor)->order('id')->select()->toArray();
            $sources = array_map(static fn(array $item): array => ['reference' => 'i:' . $item['id'], 'balance' => $item['amount'],
                'confirmed_amount' => $item['amount'], 'snapshot' => ['source_reference' => $item['source_reference']]], $sources);
        }
        return ['tenant_id' => $tenant, 'original_supply_order_id' => $id, 'original_order_sn' => $order['order_sn'],
            'subject_id' => $vendor, 'subject_name' => $order['supplier_name'], 'warehouse_id' => (int)$order['warehouse_id'],
            'actual_date' => $date, 'expected_fingerprint' => $fingerprint, 'lines' => $lines,
            'recorded_arrears_amount' => (string)$order['order_arrears_money'], 'payable_sources' => $sources,
            'can_confirm' => FinanceAccess::owner(), 'requires_confirmation' => count(array_filter($lines, static fn(array $line): bool => $line['arrival_line_id'] === 0)) > 0];
    }

    public static function confirm(array $params): array
    {
        FinanceAccess::require('', true);
        if (FinanceValue::id($params['expected_tenant_id'] ?? null) !== FinanceAccess::tenant()) { throw new \DomainException('门店已变化，请重新核实'); }
        if (($params['historical_confirmed'] ?? null) !== 1 || ($params['settlement_confirmed'] ?? null) !== 1) { throw new \DomainException('请明确核实历史实际到货、已退量和供应商已确认的结算事实'); }
        $state = self::options($params); $tenant = FinanceAccess::tenant();
        if (!hash_equals($state['expected_fingerprint'], FinanceValue::text($params['expected_fingerprint'] ?? '', 64))) { throw new \DomainException('原采购或历史退货已变化，请重新核对'); }
        $basis = FinanceValue::text($params['source_reference'] ?? null, 160);
        $linked = FinanceValue::money($params['payable_amount'] ?? null, true);
        $source = FinanceValue::text($params['payable_source'] ?? '', 40, false);
        if (!$state['requires_confirmation']) {
            $links = Db::name('finance_purchase_legacy_link')->where('tenant_id', $tenant)->where('original_supply_order_id', $state['original_supply_order_id'])->lock(true)->select()->toArray();
            $actualLinked = '0.00'; foreach ($links as $link) { $actualLinked = bcadd($actualLinked, $link['linked_payable_amount'], 2); }
            $canonicalSource = $source;
            if (str_starts_with($source, 'i:')) {
                $final = Db::name('finance_opening_source')->where('tenant_id', $tenant)->where('opening_item_id', (int)substr($source, 2))->value('id');
                if ($final) { $canonicalSource = 'o:' . $final; }
            }
            if (!$links || $links[0]['payable_source'] !== $canonicalSource || bccomp($actualLinked, $linked, 2) !== 0 || FinanceValue::decode($links[0]['snapshot'])['source_reference'] !== $basis) {
                throw new \DomainException('原来源已经核实，不能用不同内容覆盖；请保留原记录并办理关联更正');
            }
            return $state;
        }
        if (count(array_filter($state['lines'], static fn(array $line): bool => $line['arrival_line_id'] > 0))) { throw new \DomainException('原采购已部分衔接，请负责人核实，不能覆盖既有来源'); }
        $ledger = new FinanceLedger($tenant);
        if (bccomp($linked, '0', 2) > 0) {
            if (preg_match('/^i:([1-9][0-9]{0,17})$/D', $source, $match) && !FinanceIntegration::active()) {
                $item = Db::name('finance_opening_item')->where('tenant_id', $tenant)->where('id', $match[1])->lock(true)->find();
                $payable = $item ? ['category' => $item['category'], 'subject_id' => (int)$item['subject_id'], 'confirmed_amount' => $item['amount']] : null;
            } else { $payable = str_starts_with($source, 'o:') ? $ledger->source($source) : null; }
            if (!$payable || $payable['category'] !== 'payable' || $payable['subject_id'] !== $state['subject_id']) { throw new \DomainException('旧欠款只能关联本供应商核实的期初应付'); }
            $already = (string)Db::name('finance_purchase_legacy_link')->where('tenant_id', $tenant)->where('payable_source', $source)->sum('linked_payable_amount');
            if (bccomp(bcadd($already, $linked, 2), $payable['confirmed_amount'], 2) > 0) { throw new \DomainException('关联旧欠款合计不能超过该期初来源已核实金额'); }
        } elseif ($source !== '') { throw new \DomainException('没有旧欠款时请移除应付关联'); }
        $snapshot = ['type' => 'purchase_arrival', 'legacy_history' => true, 'original_supply_order_id' => $state['original_supply_order_id'],
            'original_order_sn' => $state['original_order_sn'], 'subject_id' => $state['subject_id'], 'subject_name' => $state['subject_name'],
            'warehouse_id' => $state['warehouse_id'], 'actual_date' => $state['actual_date'], 'source_reference' => $basis,
            'reason' => '历史采购来源核实，库存成本及欠款已包含在期初', 'confirmed_by' => FinanceAccess::actor(), 'confirmed_at' => time()];
        $document = (int)Db::name('finance_document')->insertGetId(['tenant_id' => $tenant, 'type' => 'purchase_arrival', 'status' => 'confirmed',
            'version' => 1, 'payload' => FinanceValue::json($snapshot), 'confirmed_result' => '{}', 'created_by' => FinanceValue::json(FinanceAccess::actor()),
            'last_modified_by' => FinanceValue::json(FinanceAccess::actor()), 'confirmed_by' => FinanceValue::json(FinanceAccess::actor()),
            'confirmed_at' => time(), 'create_time' => time(), 'update_time' => time()]);
        foreach ($state['lines'] as $index => &$line) {
            $line = array_merge($snapshot, $line, ['historical_returned_quantity' => $line['historical_returned_quantity'], 'payable_source' => $source]);
            $id = (int)Db::name('finance_purchase_arrival_line')->insertGetId(['tenant_id' => $tenant, 'document_id' => $document, 'line_number' => $index + 1,
                'vendor_id' => $state['subject_id'], 'warehouse_id' => $state['warehouse_id'], 'sku_id' => $line['sku_id'], 'business_date' => $state['actual_date'],
                'actual_quantity' => $line['actual_quantity'], 'estimated_amount' => $line['original_amount'], 'snapshot' => FinanceValue::json($line), 'create_time' => time()]);
            $line['arrival_line_id'] = $id;
            Db::name('finance_purchase_settlement_line')->insert(['tenant_id' => $tenant, 'document_id' => $document, 'arrival_line_id' => $id,
                'covered_quantity' => $line['actual_quantity'], 'settlement_quantity' => $line['actual_quantity'], 'price' => $line['original_price'], 'amount' => $line['original_amount'],
                'payable_source' => $source, 'snapshot' => FinanceValue::json($line), 'create_time' => time()]);
            Db::name('finance_purchase_legacy_link')->insert(['tenant_id' => $tenant, 'original_supply_order_id' => $state['original_supply_order_id'],
                'original_order_goods_id' => $line['original_order_goods_id'], 'original_arrival_detail_id' => $line['original_arrival_detail_id'], 'arrival_line_id' => $id,
                'payable_source' => $source, 'linked_payable_amount' => $index === 0 ? $linked : '0.00', 'fingerprint' => $state['expected_fingerprint'],
                'snapshot' => FinanceValue::json($line), 'create_time' => time()]);
        } unset($line);
        Db::name('finance_document')->where('tenant_id', $tenant)->where('id', $document)->update(['confirmed_result' => FinanceValue::json($snapshot + ['lines' => $state['lines'], 'created_sources' => []])]);
        return self::options($params);
    }

    public static function finalizeOpening(int $tenant): void
    {
        if (!self::installed()) {
            $activation = (string)Db::name('finance_preparation')->where('tenant_id', $tenant)->value('activation_date');
            if (Db::name('supply_order')->where('tenant_id', $tenant)->where('status', 1)->where('datetimesingle', '<', strtotime($activation))->count()) {
                throw new \DomainException('历史采购来源迁移尚未安装，不能跳过核实');
            }
            return;
        }
        $activation = (string)Db::name('finance_preparation')->where('tenant_id', $tenant)->value('activation_date');
        $blockers = self::openingBlockers($tenant, $activation);
        if ($blockers) { throw new \DomainException('历史采购来源仍需核实：' . $blockers[0]['name'] . '，' . $blockers[0]['message']); }
        foreach (Db::name('finance_purchase_legacy_link')->where('tenant_id', $tenant)->lock(true)->select()->toArray() as $link) {
            $order = Db::name('supply_order')->where('tenant_id', $tenant)->where('id', $link['original_supply_order_id'])->find();
            if (!$order || (int)$order['datetimesingle'] >= strtotime($activation)) { throw new \DomainException('已核实历史采购的原单已删除或截止日期已变化，请先核对'); }
        }
        foreach (Db::name('finance_purchase_legacy_link')->where('tenant_id', $tenant)->whereLike('payable_source', 'i:%')->lock(true)->select()->toArray() as $link) {
            $item = (int)substr($link['payable_source'], 2);
            $source = Db::name('finance_opening_source')->where('tenant_id', $tenant)->where('opening_item_id', $item)->lock(true)->find();
            $basis = FinanceValue::decode($link['snapshot']);
            $linked = (string)Db::name('finance_purchase_legacy_link')->where('tenant_id', $tenant)->where('payable_source', $link['payable_source'])->sum('linked_payable_amount');
            if (!$source || $source['category'] !== 'payable' || (int)$source['subject_id'] !== (int)$basis['subject_id'] || bccomp($linked, $source['amount'], 2) > 0) {
                throw new \DomainException('历史采购关联的期初欠款已变化，请重新核对');
            }
            $reference = 'o:' . $source['id']; $basis['payable_source'] = $reference;
            Db::name('finance_purchase_legacy_link')->where('tenant_id', $tenant)->where('id', $link['id'])->update(['payable_source' => $reference, 'snapshot' => FinanceValue::json($basis)]);
            Db::name('finance_purchase_arrival_line')->where('tenant_id', $tenant)->where('id', $link['arrival_line_id'])->update(['snapshot' => FinanceValue::json($basis)]);
            Db::name('finance_purchase_settlement_line')->where('tenant_id', $tenant)->where('arrival_line_id', $link['arrival_line_id'])->update(['payable_source' => $reference, 'snapshot' => FinanceValue::json($basis)]);
        }
    }

    public static function openingBlockers(int $tenant, string $activation): array
    {
        $orders = Db::name('supply_order')->where('tenant_id', $tenant)->where('status', 1)->where('datetimesingle', '<', strtotime($activation))->order('id')->select()->toArray();
        $items = [];
        foreach ($orders as $order) {
            try {
                if (!self::installed()) { throw new \DomainException('历史采购来源迁移尚未安装'); }
                $state = self::options(['original_supply_order_id' => (int)$order['id']]);
                $links = Db::name('finance_purchase_legacy_link')->where('tenant_id', $tenant)->where('original_supply_order_id', $order['id'])->select()->toArray();
                if ($state['requires_confirmation'] || count($links) !== count($state['lines']) || array_filter($links, static fn(array $link): bool => !hash_equals($link['fingerprint'], $state['expected_fingerprint']))) {
                    throw new \DomainException('请核实原实际到货、历史已退量、结算及旧欠款关联');
                }
            } catch (\DomainException $error) {
                $items[] = ['code' => 'historical_purchase_source', 'category' => 'payable', 'entity_type' => 'supply_order',
                    'id' => (int)$order['id'], 'name' => $order['order_sn'], 'message' => $error->getMessage()];
            }
        }
        return $items;
    }

    private static function installed(): bool
    {
        $table = Db::name('finance_purchase_legacy_link')->getTable();
        return Db::query('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1', [$table]) !== [];
    }
}
