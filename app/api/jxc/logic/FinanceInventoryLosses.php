<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 库内实物减少先保留待核实成本，责任查明后按原来源分次确认损失。 */
final class FinanceInventoryLosses
{
    public static function options(array $params): array
    {
        $tenant = FinanceAccess::tenant(); $page = max(1, FinanceValue::id($params['page'] ?? 1));
        $resolved = Db::name('finance_inventory_loss_resolution')->where('tenant_id', $tenant)
            ->field('incident_document_id,SUM(quantity) AS quantity,MAX(id) AS latest_id')->group('incident_document_id')->buildSql();
        $query = Db::name('finance_inventory_loss')->alias('i')->leftJoin([$resolved => 'r'], 'r.incident_document_id=i.document_id')
            ->where('i.tenant_id', $tenant)->whereRaw('i.quantity>COALESCE(r.quantity,0)');
        if (!empty($params['warehouse_id'])) { $query->where('i.warehouse_id', FinanceValue::id($params['warehouse_id'])); }
        if (!empty($params['incident_document_id'])) { $query->where('i.document_id', FinanceValue::id($params['incident_document_id'])); }
        $rows = $query->field('i.*,i.quantity-COALESCE(r.quantity,0) AS remaining_quantity,COALESCE(r.latest_id,0) AS expected_resolution_id')
            ->order('i.business_date,i.id')->limit(($page - 1) * 20, 21)->select()->toArray();
        $more = count($rows) > 20; $incidents = [];
        foreach (array_slice($rows, 0, 20) as $row) {
            $incidents[] = array_merge(FinanceValue::decode($row['snapshot']), ['incident_document_id' => (int)$row['document_id'],
                'remaining_quantity' => bcadd($row['remaining_quantity'], '0', 4), 'expected_resolution_id' => (int)$row['expected_resolution_id']]);
        }
        return ['sources' => [], 'has_more' => false, 'incidents' => $incidents, 'incident_has_more' => $more,
            'warehouses' => Db::name('warehouse')->where('tenant_id', $tenant)->field('id,name')->order('id')->select()->toArray()];
    }

    public static function confirm(FinanceLedger $ledger, array $document, array $data): array
    {
        FinanceAccess::require('', true); $tenant = FinanceAccess::tenant();
        $warehouse = FinanceValue::id($data['warehouse_id'] ?? null); $skuId = FinanceValue::id($data['sku_id'] ?? null);
        $store = Db::name('warehouse')->where('tenant_id', $tenant)->where('id', $warehouse)->find();
        $sku = Db::name('goods_sku')->where('tenant_id', $tenant)->where('id', $skuId)->find();
        $goods = $sku ? Db::name('goods')->where('tenant_id', $tenant)->where('id', $sku['goods_id'])->find() : null;
        if (!$store || !$sku || !$goods) { throw new \DomainException('请选择当前门店实际损耗仓库与商品规格'); }
        $quantity = FinancePurchaseSettlement::quantity($data['quantity'] ?? null); $date = FinanceValue::date($data['actual_date'] ?? null);
        if ($date > date('Y-m-d')) { throw new \DomainException('实物损耗日期不能晚于今天'); } $ledger->postingMonth($date);
        if (($data['physical_confirmed'] ?? null) !== 1) { throw new \DomainException('须明确核实本次是已入库商品的实际减少，不得重复登记原到货已排除的损失'); }
        $reference = FinanceValue::text($data['source_reference'] ?? null, 160);
        if (Db::name('finance_inventory_loss')->where('tenant_id', $tenant)->where('warehouse_id', $warehouse)->where('sku_id', $skuId)->where('source_reference', $reference)->lock(true)->find()) {
            throw new \DomainException('同仓库同商品的这项实物事件已登记，请关联原记录继续核实');
        }
        $snapshot = ['type' => 'inventory_loss', 'warehouse_id' => $warehouse, 'warehouse_name' => $store['name'], 'sku_id' => $skuId,
            'goods_id' => (int)$sku['goods_id'], 'goods_name' => $goods['name'], 'sku_name' => $sku['sku_name'], 'base_unit_name' => $sku['base_unit_name'],
            'quantity' => $quantity, 'actual_date' => $date, 'source_reference' => $reference, 'reason' => FinanceValue::text($data['reason'] ?? null, 1000),
            'responsibility' => FinanceValue::text($data['responsibility'] ?? null, 1000), 'resolution_pending' => true,
            'confirmed_by' => FinanceAccess::actor(), 'confirmed_at' => time()];
        Db::name('finance_inventory_loss')->insert(['tenant_id' => $tenant, 'document_id' => $document['id'], 'warehouse_id' => $warehouse,
            'sku_id' => $skuId, 'quantity' => $quantity, 'business_date' => $date, 'source_reference' => $reference,
            'snapshot' => FinanceValue::json($snapshot), 'create_time' => time()]);
        $flow = StockService::outboundFinanceInventoryLossWithinTransaction($warehouse, (int)$sku['goods_id'], $skuId, $quantity, (int)$document['id'], $date);
        $cost = new FinanceCostLedger($tenant); $destination = $cost->destination($warehouse, $skuId, 'pending', 'inventory-loss:' . $document['id']);
        return self::result($ledger, $document, $snapshot + ['incident_document_id' => (int)$document['id'], 'stock_flow_id' => $flow,
            'remaining_quantity' => $quantity, 'pending_cost' => $destination['cost'], 'known_pending_cost' => $destination['known_cost'], 'cost_pending' => $destination['pending']]);
    }

    public static function resolve(FinanceLedger $ledger, array $document, array $data): array
    {
        FinanceAccess::require('', true); $tenant = FinanceAccess::tenant(); $source = FinanceValue::id($data['incident_document_id'] ?? null);
        $incident = Db::name('finance_inventory_loss')->where('tenant_id', $tenant)->where('document_id', $source)->lock(true)->find();
        if (!$incident) { throw new \DomainException('原库内实物损耗不存在或不属于当前门店'); }
        $rows = Db::name('finance_inventory_loss_resolution')->where('tenant_id', $tenant)->where('incident_document_id', $source)->order('id')->lock(true)->select()->toArray();
        $remaining = $incident['quantity']; $latest = 0;
        foreach ($rows as $row) { $remaining = bcsub($remaining, $row['quantity'], 4); $latest = (int)$row['id']; }
        if (FinanceValue::id($data['expected_resolution_id'] ?? null, true) !== $latest) { throw new \DomainException('原损耗已有后续处理，请读取最新剩余数量'); }
        $quantity = FinancePurchaseSettlement::quantity($data['quantity'] ?? null);
        if (bccomp($quantity, $remaining, 4) > 0) { throw new \DomainException('本次确认数量超过原事件尚待核实数量'); }
        if (($data['loss_confirmed'] ?? null) !== 1) { throw new \DomainException('须明确确认本次核实部分由门店承担，未查明部分继续待处理'); }
        $basis = FinanceValue::decode($incident['snapshot']); $reference = 'inventory-loss-resolution:' . $document['id'];
        $snapshot = array_merge($basis, ['type' => 'inventory_loss_resolution', 'incident_document_id' => $source, 'quantity' => $quantity,
            'remaining_quantity' => bcsub($remaining, $quantity, 4), 'previous_resolution_id' => $latest,
            'incident_source_reference' => $basis['source_reference'], 'source_reference' => FinanceValue::text($data['source_reference'] ?? null, 160),
            'reason' => FinanceValue::text($data['reason'] ?? null, 1000), 'responsibility' => FinanceValue::text($data['responsibility'] ?? null, 1000),
            'confirmed_by' => FinanceAccess::actor(), 'confirmed_at' => time(), 'resolution_pending' => bccomp($remaining, $quantity, 4) > 0]);
        $cost = new FinanceCostLedger($tenant);
        $cost->recordWithinTransaction(['reference' => $reference, 'type' => 'reclassify', 'warehouse_id' => (int)$incident['warehouse_id'],
            'sku_id' => (int)$incident['sku_id'], 'quantity' => $quantity, 'document_id' => (int)$document['id'], 'business_date' => $incident['business_date'],
            'bucket' => 'pending', 'target_reference' => 'inventory-loss:' . $source, 'to_bucket' => 'loss', 'to_reference' => $reference, 'snapshot' => $snapshot]);
        $destination = $cost->destination((int)$incident['warehouse_id'], (int)$incident['sku_id'], 'loss', $reference);
        $snapshot += ['loss_cost' => $destination['cost'], 'known_loss_cost' => $destination['known_cost'], 'cost_pending' => $destination['pending']];
        $id = (int)Db::name('finance_inventory_loss_resolution')->insertGetId(['tenant_id' => $tenant, 'document_id' => $document['id'],
            'incident_document_id' => $source, 'quantity' => $quantity, 'snapshot' => FinanceValue::json($snapshot), 'create_time' => time()]);
        return self::result($ledger, $document, $snapshot + ['resolution_id' => $id]);
    }

    private static function result(FinanceLedger $ledger, array $document, array $line): array
    {
        $impacts = (new FinanceCostLedger(FinanceAccess::tenant()))->documentImpacts((int)$document['id'], $line['cost_pending'] ? [$line['sku_id'] => true] : []);
        $months = array_values(array_unique(array_merge([$ledger->postingMonth($line['actual_date'])], array_column($impacts, 'posting_month')))); sort($months);
        $line += ['cost_impacts' => $impacts, 'posting_months' => $months];
        return $line + ['lines' => [$line], 'created_sources' => []];
    }
}
