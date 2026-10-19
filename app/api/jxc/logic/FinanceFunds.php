<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 投入、借款和采购预付分别保留来源；本金的结清不成为经营损益。 */
final class FinanceFunds
{
    public const TYPES = ['capital_in', 'capital_out', 'loan_receipt', 'loan_repayment', 'supplier_advance', 'supplier_advance_allocate', 'supplier_advance_refund'];

    public static function supports(string $type): bool { return in_array($type, self::TYPES, true); }

    public static function options(FinanceLedger $ledger, array $params): array
    {
        $type = FinanceValue::text($params['type'] ?? '', 40);
        $subject = FinanceValue::id($params['subject_id'] ?? 0, true);
        $categories = match ($type) {
            'capital_out' => ['capital'], 'loan_repayment' => ['loan'], 'supplier_advance_refund' => ['supplier_advance'],
            'supplier_advance_allocate' => ($params['role'] ?? '') === 'fund' ? ['supplier_advance'] : ['payable'], default => [],
        };
        if ($type === 'loan_repayment' && ($params['role'] ?? '') === 'interest') { $categories = ['expense_payable']; $subject = 0; }
        return $ledger->sourcePage($categories, $subject, max(1, FinanceValue::id($params['page'] ?? 1)));
    }

    public static function confirm(FinanceLedger $ledger, array $document, array $data, ?array $originalTransaction = null, int $correctingDocument = 0): array
    {
        $type = $document['type']; FinanceAccess::require('', true);
        $tenant = FinanceAccess::tenant(); $id = (int)$document['id'];
        $date = FinanceValue::date($data['actual_date'] ?? null); $month = $ledger->postingMonth($date);
        if ($correctingDocument) {
            $originalMonth = Db::name('finance_entry')->where('tenant_id', $tenant)->where('document_id', $correctingDocument)->where('metric', 'cash')->value('posting_month');
            if ($originalMonth && Db::name('finance_period')->where('tenant_id', $tenant)->where('month', $originalMonth)->find()) { $month = $ledger->postingMonth(date('Y-m-d')); }
        }
        $reason = FinanceValue::text($data['reason'] ?? '', 1000);
        $vendor = str_starts_with($type, 'supplier_advance');
        $subject = $vendor ? FinanceValue::id($data['subject_id'] ?? 0) : 0;
        $name = $vendor ? Db::name('vendor')->where('tenant_id', $tenant)->where('id', $subject)->value('supplier_name') : FinanceValue::text($data['counterparty'] ?? '', 100);
        if (!$name) { throw new \DomainException('供应商不存在或不属于本门店'); }
        $created = []; $principal = '0.00'; $interest = '0.00'; $earnings = '0.00'; $sourceRef = '';
        if ($type === 'supplier_advance_allocate') {
            $sourceRef = FinanceValue::text($data['fund_source'] ?? '', 40);
            $fund = $ledger->source($sourceRef);
            if ($fund['category'] !== 'supplier_advance' || $fund['subject_id'] !== $subject) { throw new \DomainException('请选择同供应商的预付款来源'); }
            if ($fund['business_date'] && $date < $fund['business_date']) { throw new \DomainException('抵扣不能早于原预付款实际发生日'); }
            $lines = $data['allocations'] ?? [];
            if (!is_array($lines) || !$lines) { throw new \DomainException('请明确选择抵扣的采购应付与金额'); }
            foreach ($lines as $line) {
                if (!is_array($line)) { throw new \DomainException('预付款抵扣组成无效'); }
                $ref = FinanceValue::text($line['source'] ?? '', 40); $payable = $ledger->source($ref);
                $available = bcsub($payable['balance'], FinanceStatements::disputedAmount($ref), 2);
                if (bccomp(FinanceValue::money($line['amount'] ?? null), $available, 2) > 0) { throw new \DomainException('应付存在争议或余量已变化，请重新核对'); }
            }
            $principal = $ledger->allocate($id, $lines, ['payable'], $subject, $date, $month);
            $allocatedEntries = Db::name('finance_entry')->where('tenant_id', $tenant)->where('document_id', $id)->where('purpose', 'allocation')->order('id')->select()->toArray();
            foreach ($allocatedEntries as $allocation) {
                $ledger->add($id, 'balance', $subject, (string)$allocation['amount'], $allocation['business_date'], $allocation['posting_month'], 'supplier_advance_use', $sourceRef, $allocation['effective_date'],
                    ['reason' => $reason, 'allocation_entry_id' => (int)$allocation['id']]);
            }
            return ['type' => $type, 'subject_id' => $subject, 'subject_name' => $name, 'allocated_amount' => $principal,
                'fund_source' => $sourceRef, 'remaining' => $ledger->source($sourceRef)['balance'], 'reason' => $reason, 'created_sources' => []];
        }
        $amount = FinanceValue::money($data['amount'] ?? null);
        $capitalReturn = $type === 'capital_out' ? FinanceValue::money($data['capital_return_amount'] ?? $amount, true) : '0.00';
        if ($type === 'capital_out') {
            $earnings = FinanceValue::money($data['earnings_amount'] ?? '0', true);
            if (bccomp(bcadd($capitalReturn, $earnings, 2), $amount, 2) !== 0) { throw new \DomainException('取回总额必须等于原投入本金与经营结余取回的合计'); }
        }
        if (in_array($type, ['loan_repayment', 'supplier_advance_refund'], true) || ($type === 'capital_out' && bccomp($capitalReturn, '0', 2) > 0)) {
            $sourceRef = FinanceValue::text($data['fund_source'] ?? '', 40); $source = $ledger->source($sourceRef);
            $category = ['capital_out' => 'capital', 'loan_repayment' => 'loan', 'supplier_advance_refund' => 'supplier_advance'][$type];
            if ($source['category'] !== $category || $source['subject_id'] !== $subject) { throw new \DomainException('原资金来源类型或对象不匹配'); }
            if ($source['business_date'] && $date < $source['business_date']) { throw new \DomainException('结清不能早于原资金实际发生日'); }
            if (!$vendor && ($source['snapshot']['counterparty'] ?? $source['subject_name']) !== $name) { throw new \DomainException('投入人或出借人与原来源不一致'); }
            $interest = $type === 'loan_repayment' ? FinanceValue::money($data['interest_amount'] ?? '0', true) : '0.00';
            $principal = match ($type) { 'loan_repayment' => FinanceValue::money($data['principal_amount'] ?? null, true), 'capital_out' => $capitalReturn, default => $amount };
            if ($type === 'loan_repayment' && bccomp(bcadd($principal, $interest, 2), $amount, 2) !== 0) { throw new \DomainException('实际付款必须等于本次本金与利息合计'); }
            $ledger->add($id, 'balance', $subject, '-' . $principal, $date, $month, $type, $sourceRef, null, ['reason' => $reason]);
        }
        $direction = in_array($type, ['capital_in', 'loan_receipt', 'supplier_advance_refund'], true) ? 'in' : 'out';
        $money = (new FinanceMoney($tenant, $ledger))->record($id, $type, $data, $direction, $date, $amount, $month, $originalTransaction);
        if (in_array($type, ['capital_in', 'loan_receipt', 'supplier_advance'], true)) {
            $category = ['capital_in' => 'capital', 'loan_receipt' => 'loan', 'supplier_advance' => 'supplier_advance'][$type];
            $due = $type === 'loan_receipt' ? FinanceValue::date($data['due_date'] ?? null, true) : null;
            if ($due && $due < $date) { throw new \DomainException('约定还款日不能早于借款到账日'); }
            if ($type === 'capital_in' && ($data['repayment_agreed'] ?? 0)) { throw new \DomainException('约定归还的本金请登记借款到账'); }
            $created[] = $ledger->createSource($id, $category, $subject, $amount, $date, $due,
                ['subject_name' => $name, 'counterparty' => $name, 'reason' => $reason, 'posting_month' => $month, 'transaction_id' => $money['transaction_id']]);
        }
        if (bccomp($interest, '0', 2) > 0) {
            FinanceValue::text($data['interest_basis'] ?? '', 1000);
            $interestMode = FinanceValue::text($data['interest_mode'] ?? '', 24);
            if ($interestMode === 'existing_payable') {
                $interestSource = $ledger->source(FinanceValue::text($data['interest_source'] ?? '', 40));
                $interestSubject = FinanceValue::id($data['interest_subject_id'] ?? 0);
                if ($interestSource['category'] !== 'expense_payable' || $interestSource['subject_id'] !== $interestSubject || ($data['interest_payee_verified'] ?? 0) !== 1) { throw new \DomainException('请选择已确认的利息费用待付来源，并核实收款对象与原借款的关系'); }
                if ($interestSource['business_date'] && $date < $interestSource['business_date']) { throw new \DomainException('结清利息不能早于原费用发生日'); }
                if (bccomp($interest, bcsub($interestSource['balance'], FinanceStatements::disputedAmount($interestSource['reference']), 2), 2) > 0) { throw new \DomainException('利息支付超过原费用无争议待付余额'); }
                $ledger->allocate($id, [['source' => $interestSource['reference'], 'amount' => $interest]], ['expense_payable'], $interestSubject, $date, $month);
            } elseif ($interestMode === 'new_expense') {
                $benefitMonth = FinanceValue::text($data['interest_benefit_month'] ?? '', 7);
                if (!preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D', $benefitMonth)) { throw new \DomainException('请核实利息实际所属月份'); }
                FinanceValue::date($benefitMonth . '-01');
                $activation = (string)Db::name('finance_preparation')->where('tenant_id', $tenant)->value('activation_date');
                if ($benefitMonth < substr($activation, 0, 7)) { throw new \DomainException('启用前利息请使用已核定的期初费用待付来源，不能新增本期费用'); }
                if ($benefitMonth > substr($date, 0, 7)) { throw new \DomainException('尚未受益的以后月份利息请按待摊费用计划处理，不能当期全部记费用'); }
                $interestMonth = $ledger->postingMonth(max($benefitMonth . '-01', $activation));
                $ledger->add($id, 'expense', $subject, $interest, $date, $interestMonth, 'loan_interest', '', null,
                    ['category' => 'loan_interest', 'category_name' => '借款利息', 'parent' => 'other', 'fund_source' => $sourceRef, 'basis' => $data['interest_basis'], 'benefit_month' => $benefitMonth]);
            } else { throw new \DomainException('请明确利息是新发生费用，还是结清已经确认的费用待付'); }
        }
        return ['type' => $type, 'subject_id' => $subject, 'subject_name' => $name, 'principal_amount' => $principal, 'interest_amount' => $interest, 'earnings_amount' => $earnings,
            'fund_source' => $sourceRef, 'interest_source' => $data['interest_source'] ?? '', 'interest_mode' => $data['interest_mode'] ?? '', 'remaining' => $sourceRef ? $ledger->source($sourceRef)['balance'] : null,
            'reason' => $reason, 'money' => $money, 'created_sources' => $created];
    }
}
