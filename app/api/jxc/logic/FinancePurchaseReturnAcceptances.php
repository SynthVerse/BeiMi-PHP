<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 供应商每次认可独立留痕；未认可余量继续争议，不变更实物库存。 */
final class FinancePurchaseReturnAcceptances
{
    public static function current(int $line, int $vendor): array
    {
        $tenant = FinanceAccess::tenant();
        $returned = Db::name('finance_purchase_return_line')->where('tenant_id', $tenant)->where('vendor_id', $vendor)->where('id', $line)->lock(true)->find();
        if (!$returned) { throw new \DomainException('请选择本门店、本供应商的实际退货明细'); }
        $arrival = Db::name('finance_purchase_arrival_line')->where('tenant_id', $tenant)->where('id', $returned['arrival_line_id'])->lock(true)->find();
        if (!$arrival) { throw new \DomainException('实际退货缺少原到货来源'); }
        $resolved = '0.0000'; $latest = 0;
        foreach (Db::name('finance_purchase_return_resolution')->where('tenant_id', $tenant)->where('return_line_id', $line)->order('id')->lock(true)->select()->toArray() as $row) {
            $resolved = bcadd($resolved, $row['quantity'], 4); $latest = (int)$row['id'];
        }
        $coverage = FinancePurchaseCoverage::totals((int)$arrival['id']);
        return ['row' => $returned, 'arrival' => $arrival, 'remaining_quantity' => bcsub($returned['quantity'], $resolved, 4), 'expected_resolution_id' => $latest,
            'unsettled_available' => bcsub($arrival['actual_quantity'], $coverage['covered_quantity'], 4),
            'settled_available' => bcsub($coverage['settled_quantity'], $coverage['settled_return_quantity'], 4)];
    }

    public static function options(int $vendor, array $params): array
    {
        $tenant = FinanceAccess::tenant(); $page = FinanceValue::id($params['page'] ?? 1); $keyword = FinanceValue::text($params['keyword'] ?? '', 60, false);
        $resolved = Db::name('finance_purchase_return_resolution')->where('tenant_id', $tenant)->field('return_line_id,SUM(quantity) AS quantity')->group('return_line_id')->buildSql();
        $query = Db::name('finance_purchase_return_line')->alias('r')->leftJoin([$resolved => 'c'], 'c.return_line_id=r.id')
            ->where('r.tenant_id', $tenant)->where('r.vendor_id', $vendor)->whereRaw('r.quantity>COALESCE(c.quantity,0)');
        if ($keyword !== '') { $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(r.snapshot,'$.goods_name')) LIKE ?", ['%' . addcslashes($keyword, '%_\\') . '%']); }
        $rows = $query->field('r.id')->order('r.business_date,r.id')->limit(($page - 1) * 20, 21)->select()->toArray(); $choices = [];
        foreach (array_slice($rows, 0, 20) as $row) {
            $state = self::current((int)$row['id'], $vendor); $returned = $state['row'];
            $choices[] = array_merge(FinanceValue::decode($returned['snapshot']), ['return_line_id' => (int)$returned['id'], 'subject_id' => $vendor,
                'return_document_id' => (int)$returned['document_id'], 'return_date' => $returned['business_date'], 'actual_return_quantity' => $returned['quantity'],
                'remaining_quantity' => $state['remaining_quantity'], 'expected_resolution_id' => $state['expected_resolution_id'],
                'unsettled_available' => $state['unsettled_available'], 'settled_available' => $state['settled_available']]);
        }
        return ['sources' => [], 'has_more' => false, 'returns' => $choices, 'return_has_more' => count($rows) > 20];
    }

    public static function confirm(FinanceLedger $ledger, array $document, array $data): array
    {
        FinanceAccess::require('', true); $tenant = FinanceAccess::tenant(); $vendor = FinanceValue::id($data['subject_id'] ?? null);
        $line = FinanceValue::id($data['return_line_id'] ?? null); $state = self::current($line, $vendor); $returned = $state['row']; $arrival = $state['arrival'];
        if (FinanceValue::id($data['expected_resolution_id'] ?? null, true) !== $state['expected_resolution_id']) { throw new \DomainException('该退货已有后续处理，请读取当前剩余数量再确认'); }
        $date = FinanceValue::date($data['actual_date'] ?? null); $month = $ledger->postingMonth($date);
        if ($date < $returned['business_date']) { throw new \DomainException('供应商认可日期不能早于实际退离'); }
        if (($data['supplier_confirmed'] ?? null) !== 1) { throw new \DomainException('请明确核实供应商本次实际认可内容'); }
        $confirmation = FinanceValue::text($data['supplier_confirmation'] ?? null, 1000); $reason = FinanceValue::text($data['reason'] ?? null, 1000);
        $quantity = FinancePurchaseSettlement::quantity($data['accepted_quantity'] ?? null, true);
        $unsettled = FinancePurchaseSettlement::quantity($data['unsettled_quantity'] ?? null, true); $settled = bcsub($quantity, $unsettled, 4);
        if (bccomp($quantity, $state['remaining_quantity'], 4) > 0 || bccomp($state['remaining_quantity'], '0', 4) <= 0) { throw new \DomainException('认可量不能超过尚未处理的实际退货量，额外补偿请走采购价格调整'); }
        if (bccomp($settled, '0', 4) < 0 || bccomp($unsettled, $state['unsettled_available'], 4) > 0 || bccomp($settled, $state['settled_available'], 4) > 0) { throw new \DomainException('请按本次实际认可量明确划分未结算量与已结算量，不能超过各自剩余量'); }
        $unsettledAmount = FinanceValue::money($data['unsettled_amount'] ?? null, true); $credit = FinanceValue::money($data['credit_amount'] ?? null, true);
        if ((bccomp($unsettled, '0', 4) === 0 && bccomp($unsettledAmount, '0', 2) !== 0) || (bccomp($settled, '0', 4) === 0 && bccomp($credit, '0', 2) !== 0)) {
            throw new \DomainException('没有对应认可数量的部分不能填写金额，额外补偿请走价格调整');
        }
        $snapshot = array_merge(FinanceValue::decode($returned['snapshot']), ['type' => 'purchase_return_acceptance', 'return_line_id' => $line,
            'return_document_id' => (int)$returned['document_id'], 'subject_id' => $vendor,
            'subject_name' => Db::name('vendor')->where('tenant_id', $tenant)->where('id', $vendor)->value('supplier_name'),
            'actual_date' => $date, 'accepted_quantity' => $quantity, 'unsettled_quantity' => $unsettled, 'settled_quantity' => $settled,
            'unsettled_amount' => $unsettledAmount, 'credit_amount' => $credit, 'amount' => bcadd($unsettledAmount, $credit, 2),
            'disputed_quantity' => bcsub($state['remaining_quantity'], $quantity, 4), 'previous_resolution_id' => $state['expected_resolution_id'],
            'supplier_confirmation' => $confirmation, 'reason' => $reason, 'source_reference' => '采购退货明细 ' . $line . ' 供应商认可',
            'confirmed_by' => FinanceAccess::actor(), 'confirmed_at' => time()]);
        $financial = FinanceSupplierCredits::recognizeChange($ledger, (int)$document['id'], 'payable', bcsub('0', $credit, 2), null, $snapshot, $data);
        $id = (int)Db::name('finance_purchase_return_resolution')->insertGetId(['tenant_id' => $tenant, 'document_id' => $document['id'],
            'return_line_id' => $line, 'arrival_line_id' => $arrival['id'], 'kind' => 'accepted', 'business_date' => $date, 'quantity' => $quantity,
            'unsettled_quantity' => $unsettled, 'unsettled_amount' => $unsettledAmount, 'credit_amount' => $credit,
            'snapshot' => FinanceValue::json($snapshot + $financial + ['credit_allocations' => $data['credit_allocations'] ?? []]), 'create_time' => time()]);
        $cost = FinancePurchaseCosts::revalue($arrival, $document, $snapshot);
        return $snapshot + $financial + ['resolution_id' => $id, 'posting_months' => [$month],
            'lines' => [array_merge(FinanceValue::decode($arrival['snapshot']), $cost, ['arrival_line_id' => (int)$arrival['id'], 'accepted_quantity' => $quantity])]];
    }
}
