<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 设备购置只建立付款上限；费用沿实际设备付款和退款登记。 */
final class FinanceEquipment
{
    public static function confirm(FinanceLedger $ledger, array $document, array $data): array
    {
        FinanceAccess::require('', true); $tenant = FinanceAccess::tenant(); $vendor = FinanceValue::id($data['subject_id'] ?? null);
        $name = Db::name('vendor')->where('tenant_id', $tenant)->where('id', $vendor)->value('supplier_name');
        if ($name === null) { throw new \DomainException('请选择本门店设备供应商'); }
        if (($data['equipment_verified'] ?? null) !== 1) { throw new \DomainException('请核实设备购置依据、供应商和总价'); }
        $date = FinanceValue::date($data['actual_date'] ?? null); $month = $ledger->postingMonth($date);
        $amount = FinanceValue::money($data['amount'] ?? null); $reference = FinanceValue::text($data['source_reference'] ?? null, 160);
        if (Db::name('finance_equipment_purchase')->where('tenant_id', $tenant)->where('vendor_id', $vendor)->where('source_reference', $reference)->lock(true)->find()) { throw new \DomainException('本供应商此设备购置来源已登记，请读取原记录'); }
        $snapshot = ['type' => 'equipment_purchase', 'subject_id' => $vendor, 'subject_name' => $name, 'source_reference' => $reference,
            'equipment_name' => FinanceValue::text($data['equipment_name'] ?? null, 160), 'actual_date' => $date, 'posting_month' => $month,
            'amount' => $amount, 'cancelled_amount' => '0.00', 'reason' => FinanceValue::text($data['reason'] ?? null, 1000),
            'confirmation_basis' => FinanceValue::text($data['confirmation_basis'] ?? null, 1000), 'equipment_verified' => 1,
            'confirmed_by' => FinanceAccess::actor(), 'confirmed_at' => time()] + FinanceExpenses::material($data, 'equipment_purchase');
        $source = $ledger->createSource((int)$document['id'], 'equipment', $vendor, $amount, $date, null, $snapshot);
        $id = (int)Db::name('finance_equipment_purchase')->insertGetId(['tenant_id' => $tenant, 'document_id' => $document['id'], 'vendor_id' => $vendor,
            'source_reference' => $reference, 'source_ref' => $source, 'snapshot' => FinanceValue::json($snapshot), 'create_time' => time()]);
        return $snapshot + ['purchase_id' => $id, 'paid_amount' => '0.00', 'remaining_amount' => $amount, 'created_sources' => [$source]];
    }

    private static function current(int $documentId): array
    {
        $row = Db::name('finance_equipment_purchase')->where('tenant_id', FinanceAccess::tenant())->where('document_id', $documentId)->lock(true)->find();
        if (!$row) { throw new \DomainException('设备购置不存在或不属于本门店'); }
        $revision = Db::name('finance_equipment_revision')->where('tenant_id', FinanceAccess::tenant())->where('purchase_id', $row['id'])->order('id', 'desc')->lock(true)->find();
        $equipment = $revision ? FinanceValue::decode($revision['snapshot'])['equipment'] : FinanceValue::decode($row['snapshot']);
        $source = (new FinanceLedger(FinanceAccess::tenant()))->source($row['source_ref']);
        $paid = bcsub(bcsub($equipment['amount'], $equipment['cancelled_amount'], 2), $source['balance'], 2);
        return ['row' => $row, 'equipment' => $equipment, 'source' => $source, 'paid_amount' => $paid, 'remaining_amount' => $source['balance'], 'expected_revision_id' => (int)($revision['id'] ?? 0)];
    }

    public static function options(int $vendor, array $params): array
    {
        $page = FinanceValue::id($params['page'] ?? 1); $query = Db::name('finance_equipment_purchase')->where('tenant_id', FinanceAccess::tenant());
        $exact = !empty($params['original_equipment_document_id']);
        if ($exact) { $query->where('document_id', FinanceValue::id($params['original_equipment_document_id'])); }
        else { $query->where('vendor_id', $vendor)->whereLike('source_reference', '%' . FinanceValue::text($params['keyword'] ?? '', 60, false) . '%'); }
        $rows = $query->order('id', 'desc')->limit(($page - 1) * 20, 21)->select()->toArray(); $bills = []; $sources = [];
        foreach (array_slice($rows, 0, 20) as $row) {
            $current = self::current((int)$row['document_id']);
            $bills[] = ['original_equipment_document_id' => (int)$row['document_id'], 'equipment' => $current['equipment'], 'paid_amount' => $current['paid_amount'],
                'remaining_amount' => $current['remaining_amount'], 'expected_revision_id' => $current['expected_revision_id']];
            if ($exact && bccomp($current['remaining_amount'], '0', 2) > 0) { $sources[] = $current['source']; }
        }
        if ($exact && !$bills) { throw new \DomainException('设备购置不存在或不属于本门店'); }
        return ['sources' => [], 'has_more' => false, 'bills' => $bills, 'bill_has_more' => count($rows) > 20,
            'selected_equipment' => $exact ? $bills[0] : null, 'current_sources' => $sources];
    }

    public static function adjust(FinanceLedger $ledger, array $document, array $data): array
    {
        FinanceAccess::require('', true); $tenant = FinanceAccess::tenant(); $id = (int)$document['id'];
        $original = FinanceValue::id($data['original_equipment_document_id'] ?? null); $current = self::current($original); $before = $current['equipment'];
        if (FinanceValue::id($data['subject_id'] ?? null) !== $before['subject_id']) { throw new \DomainException('设备额度调整须关联原供应商和购置记录'); }
        if (FinanceValue::id($data['expected_revision_id'] ?? null, true) !== $current['expected_revision_id']) { throw new \DomainException('设备已有后续额度调整，请重新读取'); }
        if (($data['adjustment_verified'] ?? null) !== 1) { throw new \DomainException('请核实购置、已付金额、本次调整及依据'); }
        $mode = $data['mode'] ?? ''; $equipment = $before;
        if ($mode === 'price') {
            $equipment['amount'] = FinanceValue::money($data['new_amount'] ?? null, true); $delta = bcsub($equipment['amount'], $before['amount'], 2);
        } elseif ($mode === 'cancel') {
            $cancel = FinanceValue::money($data['cancel_amount'] ?? null); $equipment['cancelled_amount'] = bcadd($before['cancelled_amount'], $cancel, 2); $delta = '-' . $cancel;
        } else { throw new \DomainException('请选择总价调整或取消未付款部分'); }
        if (bccomp($delta, '0', 2) === 0) { throw new \DomainException('设备额度未发生变化'); }
        $remaining = bcadd($current['remaining_amount'], $delta, 2);
        if (bccomp($remaining, '0', 2) < 0) { throw new \DomainException('调整或取消不能超过剩余可付额度；已付款部分返还须另行处理设备退款'); }
        $reason = FinanceValue::text($data['reason'] ?? null, 1000); $basis = FinanceValue::text($data['confirmation_basis'] ?? null, 1000);
        $date = date('Y-m-d'); $month = $ledger->postingMonth($date);
        $ledger->add($id, 'balance', $before['subject_id'], $delta, $before['actual_date'], $month, 'equipment_limit_adjustment', $current['source']['reference'], $date,
            ['original_equipment_document_id' => $original, 'mode' => $mode, 'reason' => $reason]);
        $result = ['type' => 'equipment_adjustment', 'subject_id' => $before['subject_id'], 'subject_name' => $before['subject_name'], 'original_equipment_document_id' => $original,
            'mode' => $mode, 'before_equipment' => $before, 'equipment' => $equipment, 'paid_amount' => $current['paid_amount'], 'remaining_amount' => $remaining,
            'previous_remaining_amount' => $current['remaining_amount'], 'amount_change' => $delta, 'reason' => $reason, 'confirmation_basis' => $basis,
            'effective_date' => $date, 'posting_month' => $month, 'previous_revision_id' => $current['expected_revision_id'], 'created_sources' => [],
            'confirmed_by' => FinanceAccess::actor(), 'confirmed_at' => time()];
        $revision = (int)Db::name('finance_equipment_revision')->insertGetId(['tenant_id' => $tenant, 'purchase_id' => $current['row']['id'], 'document_id' => $id,
            'previous_revision_id' => $current['expected_revision_id'], 'snapshot' => FinanceValue::json($result), 'create_time' => time()]);
        return $result + ['revision_id' => $revision];
    }
}
