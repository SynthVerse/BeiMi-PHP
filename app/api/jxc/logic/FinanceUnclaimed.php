<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 已核实到账与后续用途认领分开；认领只改变未结来源及对应损益。 */
final class FinanceUnclaimed
{
    public const CLAIM_TYPES = [
        'unclaimed_customer_claim' => 'receipt',
        'unclaimed_recovery_claim' => 'recovery_receipt',
        'unclaimed_supplier_refund_claim' => 'supplier_refund',
        'unclaimed_expense_refund_claim' => 'expense_refund',
        'unclaimed_equipment_refund_claim' => 'equipment_refund',
    ];

    public static function replacementType(string $originalType, array $data): string
    {
        $type = FinanceValue::text($data['replacement_type'] ?? $originalType, 40);
        if ($type === $originalType) { return $type; }
        if (!isset(self::CLAIM_TYPES[$originalType], self::CLAIM_TYPES[$type])) { throw new \DomainException('只有待认领用途认领可以关联改为另一合法认领用途'); }
        FinanceDocumentPolicy::authorize($type, true);
        return $type;
    }

    public static function reauthorizeCorrection(array $result): void
    {
        if (!isset(self::CLAIM_TYPES[$result['type'] ?? '']) || empty($result['corrects_document_id'])) { return; }
        $type = Db::name('finance_document')->where('tenant_id', FinanceAccess::tenant())->where('id', $result['corrects_document_id'])->value('type');
        if (!$type || !isset(self::CLAIM_TYPES[$type])) { throw new \DomainException('原认领更正关系无效，请核对原记录'); }
        FinanceDocumentPolicy::authorize($type, true);
    }

    public static function receipt(FinanceLedger $ledger, array $document, array $data, ?array $originalTransaction): array
    {
        if (($data['funds_verified'] ?? null) !== 1) { throw new \DomainException('金额、实际日期、账户及门店须全部核实；仅付款客户或用途可以未知'); }
        $account = $ledger->account(FinanceValue::id($data['account_id'] ?? null));
        $date = FinanceValue::date($data['actual_date'] ?? null); $month = $ledger->postingMonth($date);
        $amount = FinanceValue::money($data['amount'] ?? null); $reason = FinanceValue::text($data['reason'] ?? null, 1000);
        $money = (new FinanceMoney(FinanceAccess::tenant(), $ledger))->record((int)$document['id'], $document['type'], $data, 'in', $date, $amount, $month, $originalTransaction);
        $snapshot = ['type' => $document['type'], 'subject_id' => (int)$account['id'], 'subject_name' => $account['name'], 'account_id' => (int)$account['id'],
            'actual_date' => $date, 'amount' => $amount, 'posting_month' => $month, 'funds_verified' => 1, 'reason' => $reason, 'money' => $money];
        $source = $ledger->createSource((int)$document['id'], 'unclaimed', (int)$account['id'], $amount, $date, null, $snapshot);
        return $snapshot + ['unclaimed_source' => $source, 'created_sources' => [$source]];
    }

    public static function fund(FinanceLedger $ledger, string $reference): array
    {
        $fund = $ledger->source($reference);
        if ($fund['category'] !== 'unclaimed' || !$fund['business_date']) { throw new \DomainException('请选择本门店实际到账日期已核实的待认领来源'); }
        if (str_starts_with($reference, 'n:') && ($fund['snapshot']['funds_verified'] ?? null) !== 1) { throw new \DomainException('原到账资金事实尚未核实'); }
        $account = $ledger->account($fund['subject_id'], false);
        return $fund + ['account_id' => (int)$account['id'], 'account_name' => $account['name'], 'actual_date' => $fund['business_date'],
            'source_label' => $fund['snapshot']['source_reference'] ?? $fund['snapshot']['reason'] ?? $reference,
            'opening_item_id' => str_starts_with($reference, 'o:') ? (int)($fund['snapshot']['id'] ?? 0) : null];
    }

    public static function claim(FinanceLedger $ledger, array $document, array $data): array
    {
        $purpose = self::CLAIM_TYPES[$document['type']] ?? throw new \DomainException('不支持的认领用途');
        $policy = FinanceDocumentPolicy::authorize($purpose, true);
        if (($data['claim_verified'] ?? null) !== 1) { throw new \DomainException('请核实原到账、最终对象、用途及本次认领组成'); }
        $fund = self::fund($ledger, FinanceValue::text($data['unclaimed_source'] ?? null, 40));
        $amount = FinanceValue::money($data['amount'] ?? null); $reason = FinanceValue::text($data['reason'] ?? null, 1000);
        if (bccomp($amount, $fund['balance'], 2) > 0) { throw new \DomainException('本次认领超过原到账剩余待认领余额'); }
        $subject = FinanceValue::id($data['subject_id'] ?? null); $column = $policy['subject'] === 'customer' ? 'customer_name' : 'supplier_name';
        $query = Db::name($policy['subject'])->where('tenant_id', FinanceAccess::tenant())->where('id', $subject);
        if ($policy['subject'] === 'customer') { $query->where('parent_id', 0); }
        $name = $query->value($column); if ($name === null) { throw new \DomainException('认领对象不存在、不属于本门店或不是主客户'); }
        $date = $fund['business_date']; $activation = (string)Db::name('finance_preparation')->where('tenant_id', FinanceAccess::tenant())->value('activation_date');
        $month = $ledger->postingMonth(max($date, $activation));
        $lines = $data['allocations'] ?? []; if (!is_array($lines)) { throw new \DomainException('请明确本次认领的合法来源组成'); }
        $allocated = $ledger->allocate((int)$document['id'], $lines, $policy['sources'], $subject, $date, $month);
        $advance = FinanceValue::money($data['advance_amount'] ?? '0', true);
        if ($purpose !== 'receipt' && bccomp($advance, '0', 2) !== 0) { throw new \DomainException('此用途不能转为客户预收'); }
        if (bccomp(bcadd($allocated, $advance, 2), $amount, 2) !== 0) { throw new \DomainException('认领须等于所选核销金额加明确的客户预收，不能包含未定用途'); }
        $ledger->add((int)$document['id'], 'balance', $fund['subject_id'], '-' . $amount, $date, $month, 'unclaimed_use', $fund['reference'], null,
            ['claim_type' => $purpose, 'subject_id' => $subject, 'reason' => $reason]);
        $created = [];
        if (bccomp($advance, '0', 2) > 0) {
            $created[] = $ledger->createSource((int)$document['id'], 'advance', $subject, $advance, $date, null,
                ['subject_name' => $name, 'reason' => $reason, 'unclaimed_source' => $fund['reference']]);
        }
        if ($purpose === 'recovery_receipt') { $ledger->add((int)$document['id'], 'recovery_income', $subject, $amount, $date, $month, 'unclaimed_recovery', '', null, ['unclaimed_source' => $fund['reference']]); }
        if ($purpose === 'equipment_refund') { $ledger->add((int)$document['id'], 'expense', $subject, '-' . $amount, $date, $month, 'equipment', '', null, ['category' => 'equipment', 'type' => $purpose, 'unclaimed_source' => $fund['reference']]); }
        $allocations = Db::name('finance_entry')->where('tenant_id', FinanceAccess::tenant())->where('document_id', $document['id'])->where('purpose', 'allocation')->order('id')->select()->toArray();
        $allocations = array_map(static fn(array $entry): array => ['source' => $entry['source_ref'], 'amount' => bcsub('0', $entry['amount'], 2),
            'actual_date' => $date, 'effective_date' => $entry['effective_date'], 'posting_month' => $entry['posting_month']], $allocations);
        return ['type' => $document['type'], 'claim_type' => $purpose, 'subject_id' => $subject, 'subject_name' => $name, 'amount' => $amount,
            'allocations' => $allocations,
            'allocated_amount' => $allocated, 'advance_amount' => $advance, 'unclaimed_source' => $fund['reference'], 'fund' => $fund,
            'actual_date' => $date, 'posting_month' => $month, 'remaining_amount' => bcsub($fund['balance'], $amount, 2),
            'claim_verified' => 1, 'reason' => $reason, 'created_sources' => $created, 'new_cash_amount' => '0.00'];
    }

    public static function options(FinanceLedger $ledger, string $type, array $params): array
    {
        $page = FinanceValue::id($params['page'] ?? 1); $subject = FinanceValue::id($params['subject_id'] ?? 0, true);
        $fundPage = $ledger->sourcePage(['unclaimed'], FinanceValue::id($params['fund_account_id'] ?? 0, true), $page);
        $sources = $type === 'unclaimed_receipt' ? ['sources' => [], 'has_more' => false] : $ledger->sourcePage(FinanceDocumentPolicy::type($type)['sources'], $subject, $page);
        $funds = array_map(static fn(array $row): array => self::fund($ledger, $row['reference']), $fundPage['sources']);
        $claimTypes = [];
        foreach (self::CLAIM_TYPES as $claimType => $purpose) {
            try { $policy = FinanceDocumentPolicy::read($claimType); $claimTypes[] = ['type' => $claimType, 'title' => $policy['title']]; }
            catch (\DomainException) { continue; }
        }
        return $sources + ['funds' => $funds, 'fund_has_more' => $fundPage['has_more'],
            'claim_types' => $claimTypes,
            'selected_fund' => !empty($params['unclaimed_source']) ? self::fund($ledger, FinanceValue::text($params['unclaimed_source'], 40)) : null];
    }
}
