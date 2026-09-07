<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 只接收入库直接必要费用的明确分配，不把销售配送或异常支出资本化。 */
final class FinancePurchaseExtraCosts
{
    public static function options(array $params): array
    {
        $page = FinanceValue::id($params['page'] ?? 1); $keyword = FinanceValue::text($params['keyword'] ?? '', 60, false);
        $query = Db::name('finance_purchase_arrival_line')->where('tenant_id', FinanceAccess::tenant());
        if ($keyword !== '') { $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(snapshot,'$.goods_name')) LIKE ?", ['%' . $keyword . '%']); }
        $rows = $query->order('business_date desc,id desc')->limit(($page - 1) * 20, 21)->select()->toArray(); $arrivals = [];
        foreach (array_slice($rows, 0, 20) as $row) {
            $arrivals[] = array_merge(FinanceValue::decode($row['snapshot']), ['arrival_line_id' => (int)$row['id'], 'arrival_document_id' => (int)$row['document_id'],
                'subject_id' => (int)$row['vendor_id'], 'actual_date' => $row['business_date'], 'warehouse_id' => (int)$row['warehouse_id']]);
        }
        return ['sources' => [], 'has_more' => false, 'arrivals' => $arrivals, 'arrival_has_more' => count($rows) > 20];
    }

    public static function confirm(FinanceLedger $ledger, array $document, array $data): array
    {
        FinanceAccess::require('', true); $tenant = FinanceAccess::tenant(); $id = (int)$document['id'];
        $vendor = FinanceValue::id($data['subject_id'] ?? null);
        $name = Db::name('vendor')->where('tenant_id', $tenant)->where('id', $vendor)->value('supplier_name');
        if ($name === null) { throw new \DomainException('请选择本门店费用收款方'); }
        $kind = FinanceValue::text($data['cost_kind'] ?? null, 30);
        if (!in_array($kind, ['freight', 'loading', 'weighing', 'cold_chain'], true)) { throw new \DomainException('仅入库直接必要运输、装卸、过磅或冷链费用可计入采购成本'); }
        if (($data['necessary_confirmed'] ?? null) !== 1) { throw new \DomainException('请明确核实费用直接必要且可归属本次入库'); }
        $amount = FinanceValue::money($data['amount'] ?? null); $date = FinanceValue::date($data['actual_date'] ?? null); $month = $ledger->postingMonth($date);
        $due = FinanceValue::date($data['due_date'] ?? null, true);
        if ($due && $due < $date) { throw new \DomainException('费用付款日不能早于费用发生日期'); }
        $reference = FinanceValue::text($data['source_reference'] ?? null, 160);
        if (Db::name('finance_purchase_cost_bill')->where('tenant_id', $tenant)->where('vendor_id', $vendor)->where('source_reference', $reference)->lock(true)->find()) {
            throw new \DomainException('本费用收款方的这张账单已登记，请打开原记录核对或关联调整');
        }
        $reason = FinanceValue::text($data['reason'] ?? null, 1000); $basis = FinanceValue::text($data['attribution_basis'] ?? null, 1000);
        $input = $data['lines'] ?? null;
        if (!is_array($input) || !array_is_list($input) || !$input || count($input) > 200) { throw new \DomainException('请明确选择一至二百条到货及各自分配金额'); }
        $total = '0.00'; $seen = []; $lines = []; $months = [$month];
        $snapshot = ['type' => 'purchase_extra_cost', 'subject_id' => $vendor, 'subject_name' => $name, 'cost_kind' => $kind,
            'source_reference' => $reference, 'reason' => $reason, 'attribution_basis' => $basis, 'actual_date' => $date, 'due_date' => $due,
            'amount' => $amount, 'confirmed_by' => FinanceAccess::actor(), 'confirmed_at' => time()];
        foreach ($input as $item) {
            if (!is_array($item)) { throw new \DomainException('附加成本分配格式无效'); }
            $arrivalId = FinanceValue::id($item['arrival_line_id'] ?? null);
            if (isset($seen[$arrivalId])) { throw new \DomainException('同一次费用分配不能重复选择到货'); } $seen[$arrivalId] = true;
            $arrival = Db::name('finance_purchase_arrival_line')->where('tenant_id', $tenant)->where('id', $arrivalId)->lock(true)->find();
            if (!$arrival) { throw new \DomainException('费用关联到货不存在或不属于本门店'); }
            $allocated = FinanceValue::money($item['amount'] ?? null); $total = bcadd($total, $allocated, 2);
            $line = FinanceValue::decode($arrival['snapshot']) + ['arrival_line_id' => $arrivalId];
            $line['amount'] = $allocated; $line['warehouse_id'] = (int)$arrival['warehouse_id']; $line['actual_date'] = $arrival['business_date'];
            Db::name('finance_purchase_cost_change')->insert(['tenant_id' => $tenant, 'document_id' => $id, 'arrival_line_id' => $arrivalId,
                'kind' => 'additional_cost', 'amount' => $allocated, 'snapshot' => FinanceValue::json($snapshot + ['allocation' => $line]), 'create_time' => time()]);
            $lines[] = array_merge($line, FinancePurchaseCosts::revalue($arrival, $document, $snapshot + ['allocation' => $line]));
            $months[] = $ledger->postingMonth($arrival['business_date']);
        }
        if (bccomp($total, $amount, 2) !== 0) { throw new \DomainException('到货分配合计必须等于本次费用金额'); }
        Db::name('finance_purchase_cost_bill')->insert(['tenant_id' => $tenant, 'document_id' => $id, 'vendor_id' => $vendor,
            'source_reference' => $reference, 'amount' => $amount, 'snapshot' => FinanceValue::json($snapshot + ['lines' => $lines]), 'create_time' => time()]);
        $source = $ledger->createSource($id, 'expense_payable', $vendor, $amount, $date, $due, $snapshot + ['capitalized' => true, 'lines' => $lines]);
        return $snapshot + ['lines' => $lines, 'created_sources' => [$source], 'posting_months' => array_values(array_unique($months))];
    }
}
