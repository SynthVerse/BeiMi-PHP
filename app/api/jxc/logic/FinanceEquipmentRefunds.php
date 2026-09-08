<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 退款约定独立于可付额度；实际到账沿设备退款结清并冲减到账月费用。 */
final class FinanceEquipmentRefunds
{
    private static function purchase(int $document): array
    {
        return FinanceEquipment::options(0, ['original_equipment_document_id' => $document])['selected_equipment'];
    }

    private static function current(int $document): array
    {
        $row = Db::name('finance_equipment_refund_due')->where('tenant_id', FinanceAccess::tenant())->where('document_id', $document)->lock(true)->find();
        if (!$row) { throw new \DomainException('设备退款约定不存在或不属于本门店'); }
        $revision = Db::name('finance_equipment_refund_revision')->where('tenant_id', FinanceAccess::tenant())->where('refund_id', $row['id'])->order('id', 'desc')->lock(true)->find();
        $snapshot = FinanceValue::decode($revision ? $revision['snapshot'] : $row['snapshot']);
        $source = (new FinanceLedger(FinanceAccess::tenant()))->source($row['source_ref']);
        return ['row' => $row, 'snapshot' => $snapshot, 'source' => $source, 'remaining_amount' => $source['balance'],
            'received_amount' => bcsub($snapshot['amount'], $source['balance'], 2), 'expected_revision_id' => (int)($revision['id'] ?? 0)];
    }

    public static function options(int $vendor, array $params): array
    {
        if (empty($params['original_refund_document_id'])) {
            $result = FinanceEquipment::options($vendor, $params);
            if ($result['selected_equipment']) {
                $root = (int)$result['selected_equipment']['original_equipment_document_id'];
                $existing = Db::name('finance_equipment_refund_due')->where('tenant_id', FinanceAccess::tenant())->where('purchase_document_id', $root)->value('document_id');
                $result['existing_refund_document_id'] = (int)($existing ?? 0);
            }
            return $result + ['selected_refund' => null];
        }
        $current = self::current(FinanceValue::id($params['original_refund_document_id']));
        $selected = array_replace($current['snapshot'], ['original_refund_document_id' => (int)$current['row']['document_id'],
            'received_amount' => $current['received_amount'], 'remaining_amount' => $current['remaining_amount'], 'expected_revision_id' => $current['expected_revision_id']]);
        return ['sources' => [], 'has_more' => false, 'bills' => [], 'bill_has_more' => false, 'selected_refund' => $selected,
            'selected_equipment' => self::purchase((int)$current['row']['purchase_document_id']),
            'current_sources' => bccomp($current['remaining_amount'], '0', 2) > 0 ? [$current['source']] : []];
    }

    public static function confirm(FinanceLedger $ledger, array $document, array $data): array
    {
        FinanceAccess::require('', true); $tenant = FinanceAccess::tenant();
        $original = FinanceValue::id($data['original_equipment_document_id'] ?? null); $purchase = self::purchase($original);
        $equipment = $purchase['equipment']; $vendor = FinanceValue::id($data['subject_id'] ?? null);
        if ($vendor !== $equipment['subject_id']) { throw new \DomainException('退款须关联原设备供应商'); }
        if (Db::name('finance_equipment_refund_due')->where('tenant_id', $tenant)->where('purchase_document_id', $original)->lock(true)->find()) { throw new \DomainException('此设备已建立退款约定，请关联原退款调整总额，不能重复登记'); }
        $amount = FinanceValue::money($data['amount'] ?? null);
        if (bccomp($amount, $purchase['paid_amount'], 2) > 0) { throw new \DomainException('设备退款总额不能超过累计有效付款'); }
        $date = FinanceValue::date($data['actual_date'] ?? null); $month = $ledger->postingMonth($date);
        $result = ['type' => 'equipment_refund_due', 'subject_id' => $vendor, 'subject_name' => $equipment['subject_name'],
            'original_equipment_document_id' => $original, 'equipment' => $equipment, 'amount' => $amount,
            'paid_amount' => $purchase['paid_amount'], 'received_amount' => '0.00', 'remaining_amount' => $amount,
            'actual_date' => $date, 'posting_month' => $month, 'payment_composition' => self::payments($original)] + self::basis($data);
        $source = $ledger->createSource((int)$document['id'], 'equipment_refund', $vendor, $amount, $date, null, $result);
        Db::name('finance_equipment_refund_due')->insert(['tenant_id' => $tenant, 'purchase_document_id' => $original, 'document_id' => $document['id'],
            'vendor_id' => $vendor, 'source_ref' => $source, 'snapshot' => FinanceValue::json($result), 'create_time' => time()]);
        return $result + ['created_sources' => [$source]];
    }

    private static function basis(array $data): array
    {
        if (($data['refund_verified'] ?? null) !== 1) { throw new \DomainException('请核实原设备、付款和退款约定'); }
        return ['reason' => FinanceValue::text($data['reason'] ?? null, 1000), 'confirmation_basis' => FinanceValue::text($data['confirmation_basis'] ?? null, 1000),
            'refund_verified' => 1, 'confirmed_by' => FinanceAccess::actor(), 'confirmed_at' => time()];
    }

    private static function payments(int $purchase): array
    {
        $tenant = FinanceAccess::tenant();
        $source = Db::name('finance_equipment_purchase')->where('tenant_id', $tenant)->where('document_id', $purchase)->value('source_ref');
        $entries = Db::name('finance_entry')->where('tenant_id', $tenant)->where('source_ref', $source)->where('purpose', 'allocation')->where('metric', 'balance')->order('id')->select()->toArray();
        $replaced = Db::name('finance_correction')->where('tenant_id', $tenant)->column('original_document_id'); $composition = [];
        foreach ($entries as $entry) {
            if (in_array((int)$entry['document_id'], array_map('intval', $replaced), true)) { continue; }
            $composition[] = ['document_id' => (int)$entry['document_id'], 'amount' => bcsub('0', $entry['amount'], 2), 'actual_date' => $entry['business_date']];
        }
        return $composition;
    }

    public static function adjust(FinanceLedger $ledger, array $document, array $data): array
    {
        FinanceAccess::require('', true); $tenant = FinanceAccess::tenant(); $original = FinanceValue::id($data['original_refund_document_id'] ?? null);
        $current = self::current($original); $before = $current['snapshot']; $purchase = self::purchase((int)$current['row']['purchase_document_id']);
        if (FinanceValue::id($data['subject_id'] ?? null) !== $before['subject_id']) { throw new \DomainException('退款调整不能更换原供应商'); }
        if (FinanceValue::id($data['expected_revision_id'] ?? null, true) !== $current['expected_revision_id']) { throw new \DomainException('设备退款约定已变化，请重新读取当前版本'); }
        $amount = FinanceValue::money($data['new_amount'] ?? null, true);
        if (bccomp($amount, $current['received_amount'], 2) < 0 || bccomp($amount, $purchase['paid_amount'], 2) > 0) { throw new \DomainException('设备退款总额不能低于已实际到账或超过累计有效付款'); }
        $delta = bcsub($amount, $before['amount'], 2); if (bccomp($delta, '0', 2) === 0) { throw new \DomainException('设备退款总额未变化'); }
        $basis = self::basis($data); $date = date('Y-m-d'); $month = $ledger->postingMonth($date);
        $ledger->add((int)$document['id'], 'balance', $before['subject_id'], $delta, $before['actual_date'], $month, 'equipment_refund_adjustment', $current['source']['reference'], $date, $basis);
        $result = array_replace($before, $basis, ['type' => 'equipment_refund_adjustment', 'original_refund_document_id' => $original,
            'amount' => $amount, 'previous_amount' => $before['amount'], 'amount_change' => $delta, 'paid_amount' => $purchase['paid_amount'],
            'received_amount' => $current['received_amount'], 'remaining_amount' => bcadd($current['remaining_amount'], $delta, 2),
            'posting_month' => $month, 'effective_date' => $date, 'payment_composition' => self::payments((int)$current['row']['purchase_document_id']), 'created_sources' => []]);
        $revision = (int)Db::name('finance_equipment_refund_revision')->insertGetId(['tenant_id' => $tenant, 'refund_id' => $current['row']['id'], 'document_id' => $document['id'],
            'previous_revision_id' => $current['expected_revision_id'], 'snapshot' => FinanceValue::json($result), 'create_time' => time()]);
        return $result + ['revision_id' => $revision];
    }

    /** 在付款反冲和替代均完成后复核，失败由调用方一并回滚。 */
    public static function protectPaymentCorrection(FinanceLedger $ledger, array $originalPayload, array $replacementPayload): void
    {
        $references = array_unique(array_merge(array_column($originalPayload['allocations'] ?? [], 'source'), array_column($replacementPayload['allocations'] ?? [], 'source')));
        foreach ($references as $reference) {
            $source = $ledger->source($reference);
            if (($source['snapshot']['type'] ?? '') !== 'equipment_purchase') { continue; }
            $refund = Db::name('finance_equipment_refund_due')->where('tenant_id', FinanceAccess::tenant())->where('purchase_document_id', $source['document_id'])->value('document_id');
            if (!$refund) { continue; }
            $current = self::current((int)$refund); $purchase = self::purchase((int)$source['document_id']);
            if (bccomp($current['snapshot']['amount'], $purchase['paid_amount'], 2) > 0) { throw new \DomainException('付款更正后不足以支持已确认设备退款，请先核实并关联调整退款约定'); }
        }
    }
}
