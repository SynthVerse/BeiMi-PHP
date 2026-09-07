<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 关联原结算追加价款差额，不覆盖原计费量、覆盖量和单价事实。 */
final class FinancePurchaseAdjustments
{
    public static function options(int $vendor, array $params): array
    {
        $page = FinanceValue::id($params['page'] ?? 1); $keyword = FinanceValue::text($params['keyword'] ?? '', 60, false);
        $query = Db::name('finance_purchase_settlement_line')->alias('s')->join('finance_purchase_arrival_line a', 'a.id=s.arrival_line_id AND a.tenant_id=s.tenant_id')
            ->where('s.tenant_id', FinanceAccess::tenant())->where('a.vendor_id', $vendor);
        if ($keyword !== '') { $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(s.snapshot,'$.goods_name')) LIKE ?", ['%' . $keyword . '%']); }
        $rows = $query->field('s.id')->order('s.id', 'desc')->limit(($page - 1) * 20, 21)->select()->toArray(); $choices = [];
        foreach (array_slice($rows, 0, 20) as $row) {
            $current = self::settlement((int)$row['id'], $vendor); $settlement = $current['row'];
            $choices[] = array_merge(FinanceValue::decode($settlement['snapshot']), ['settlement_line_id' => (int)$row['id'], 'subject_id' => $vendor,
                'settlement_document_id' => (int)$settlement['document_id'], 'current_amount' => $current['current_amount'], 'expected_adjustment_id' => $current['expected_adjustment_id']]);
        }
        return ['sources' => [], 'has_more' => false, 'settlements' => $choices, 'settlement_has_more' => count($rows) > 20];
    }

    public static function settlement(int $id, int $vendor): array
    {
        $row = Db::name('finance_purchase_settlement_line')->where('tenant_id', FinanceAccess::tenant())->where('id', $id)->lock(true)->find();
        if (!$row) { throw new \DomainException('原采购结算明细不存在'); }
        $arrival = Db::name('finance_purchase_arrival_line')->where('tenant_id', FinanceAccess::tenant())->where('vendor_id', $vendor)->where('id', $row['arrival_line_id'])->lock(true)->find();
        if (!$arrival) { throw new \DomainException('原结算不属于本门店供应商'); }
        $amount = (string)$row['amount']; $latest = 0;
        foreach (Db::name('finance_purchase_cost_change')->where('tenant_id', FinanceAccess::tenant())->where('settlement_line_id', $id)->where('kind', 'settlement_adjustment')->order('id')->lock(true)->select()->toArray() as $change) {
            $amount = bcadd($amount, $change['amount'], 2); $latest = (int)$change['id'];
        }
        return ['row' => $row, 'arrival' => $arrival, 'current_amount' => $amount, 'expected_adjustment_id' => $latest];
    }

    public static function confirm(FinanceLedger $ledger, array $document, array $data): array
    {
        FinanceAccess::require('', true); $tenant = FinanceAccess::tenant(); $id = (int)$document['id'];
        $vendor = FinanceValue::id($data['subject_id'] ?? null); $lineId = FinanceValue::id($data['settlement_line_id'] ?? null);
        $original = self::settlement($lineId, $vendor); $row = $original['row']; $arrival = $original['arrival'];
        if (FinanceValue::id($data['expected_adjustment_id'] ?? null, true) !== $original['expected_adjustment_id']) { throw new \DomainException('原采购金额已有后续调整，请读取当前金额再核对'); }
        $amount = FinanceValue::money($data['new_amount'] ?? null, true); $delta = bcsub($amount, $original['current_amount'], 2);
        if (bccomp($delta, '0', 2) === 0) { throw new \DomainException('本次采购金额没有变化'); }
        if (($data['supplier_confirmed'] ?? null) !== 1) { throw new \DomainException('采购价款调整须明确取得供应商确认'); }
        $reason = FinanceValue::text($data['reason'] ?? null, 1000); $confirmation = FinanceValue::text($data['supplier_confirmation'] ?? null, 1000);
        $line = FinanceValue::decode($row['snapshot']); $date = $arrival['business_date']; $month = $ledger->postingMonth($date);
        $snapshot = ['type' => 'purchase_adjustment', 'subject_id' => $vendor, 'subject_name' => $line['subject_name'],
            'settlement_line_id' => $lineId, 'settlement_document_id' => (int)$row['document_id'], 'arrival_line_id' => (int)$arrival['id'],
            'source_reference' => '采购结算明细 ' . $lineId . ' 价款调整', 'actual_date' => $date, 'reason' => $reason, 'supplier_confirmation' => $confirmation,
            'before_amount' => $original['current_amount'], 'new_amount' => $amount, 'amount_change' => $delta, 'previous_adjustment_id' => $original['expected_adjustment_id'],
            'confirmed_by' => FinanceAccess::actor(), 'confirmed_at' => time()];
        $created = []; $creditUsed = '0.00';
        if (bccomp($delta, '0', 2) > 0) {
            if (!empty($data['credit_allocations'])) { throw new \DomainException('采购调增不使用贷项，请移除原冲抵组成后核对'); }
            $due = $row['payable_source'] ? $ledger->source($row['payable_source'])['due_date'] : ($line['due_date'] ?? null);
            $created[] = $ledger->createSource($id, 'payable', $vendor, $delta, $date, $due, $snapshot);
        } else {
            if (($data['credit_reviewed'] ?? null) !== 1 || !is_array($data['credit_allocations'] ?? null)) { throw new \DomainException('采购调减须明确核对贷项冲抵组成及剩余应退款'); }
            $credit = $ledger->createSource($id, 'supplier_refund', $vendor, bcsub('0', $delta, 2), $date, null, $snapshot + ['credit_kind' => 'purchase_reduction']);
            $creditUsed = FinanceSupplierCredits::allocate($ledger, $id, $vendor, $credit, $data['credit_allocations'], $date); $created[] = $credit;
        }
        $adjustmentId = (int)Db::name('finance_purchase_cost_change')->insertGetId(['tenant_id' => $tenant, 'document_id' => $id,
            'arrival_line_id' => $arrival['id'], 'settlement_line_id' => $lineId, 'kind' => 'settlement_adjustment', 'amount' => $delta,
            'snapshot' => FinanceValue::json($snapshot + ['created_sources' => $created, 'credit_allocations' => $data['credit_allocations'] ?? []]), 'create_time' => time()]);
        $cost = FinancePurchaseCosts::revalue($arrival, $document, $snapshot);
        $costLine = array_merge(FinanceValue::decode($arrival['snapshot']), $cost, ['arrival_line_id' => (int)$arrival['id'], 'amount' => $delta, 'actual_date' => $date]);
        return $snapshot + ['adjustment_id' => $adjustmentId, 'created_sources' => $created, 'credit_used' => $creditUsed,
            'refund_remaining' => bccomp($delta, '0', 2) < 0 ? bcsub(bcsub('0', $delta, 2), $creditUsed, 2) : '0.00',
            'lines' => [$costLine], 'posting_months' => [$month]];
    }
}
