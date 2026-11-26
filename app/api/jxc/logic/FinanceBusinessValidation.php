<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 核对收付款的少量共用规则；options 与正式领域校验使用同一份定义。 */
final class FinanceBusinessValidation
{
    private const TYPES = ['receipt', 'customer_refund', 'advance_refund', 'receipt_return', 'advance_allocate', 'customer_credit_allocate', 'receivable_due', 'payable_due', 'supplier_payment', 'supplier_refund', 'supplier_credit_allocate', 'supplier_advance', 'supplier_advance_allocate', 'supplier_advance_refund', 'capital_in', 'capital_out', 'loan_receipt', 'loan_repayment', 'bad_debt', 'recovery_termination', 'salary_payment', 'reimbursement_payment', 'expense_refund', 'equipment_payment', 'equipment_refund', 'recovery_receipt'];
    private const CONFIRM_ACTIONS = ['record', 'confirm', 'correct'];
    private const REASON_LIMIT = 1000;
    private const EVIDENCE_LIMIT = 12;
    private const OPTIONAL_SUPPLIER_SOURCES = ['payable'];

    public static function rules(string $type, bool $canUseException): array
    {
        if (!in_array($type, self::TYPES, true)) { return []; }
        $mayRequireEvidence = FinanceDocumentPolicy::TYPES[$type]['direction'] !== 'none' && $type !== 'receipt';
        $evidence = ['required_actions' => $mayRequireEvidence ? self::CONFIRM_ACTIONS : [],
            'max_files' => self::EVIDENCE_LIMIT, 'can_use_exception' => $type !== 'receipt' && $canUseException];
        if ($type === 'supplier_payment') { $evidence['optional_when_all_source_categories'] = self::OPTIONAL_SUPPLIER_SOURCES; }
        return ['reason' => ['required_actions' => self::CONFIRM_ACTIONS, 'max_length' => self::REASON_LIMIT], 'evidence' => $evidence];
    }

    public static function requiresElectronicScreenshot(string $type, array $resolvedSourceCategories): bool
    {
        if ($type === 'receipt') { return false; }
        return $type !== 'supplier_payment' || !$resolvedSourceCategories || array_diff($resolvedSourceCategories, self::OPTIONAL_SUPPLIER_SOURCES) !== [];
    }

    public static function validate(string $type, string $action, array $payload, int $tenant): void
    {
        $rules = self::rules($type, FinanceAccess::owner());
        if (!$rules || !in_array($action, self::CONFIRM_ACTIONS, true)) { return; }
        $accountType = null;
        if ($type !== 'supplier_payment' && $rules['evidence']['required_actions'] && (is_int($payload['account_id'] ?? null) || is_string($payload['account_id'] ?? null))) {
            $accountType = Db::name('finance_account')->where('tenant_id', $tenant)->where('id', (int)$payload['account_id'])->where('is_enabled', 1)->value('account_type');
        }
        $errors = self::errors($type, $action, $payload, $accountType, $rules['evidence']['can_use_exception']);
        if ($errors) { throw FinanceBusinessException::validation($errors); }
    }

    public static function errors(string $type, string $action, array $payload, ?string $accountType, bool $canUseException): array
    {
        $rules = self::rules($type, $canUseException);
        if (!$rules || !in_array($action, self::CONFIRM_ACTIONS, true)) { return []; }
        $errors = self::textErrors('reason', $payload['reason'] ?? '', self::REASON_LIMIT, true, '用途与核对说明');
        if (FinanceDocumentPolicy::TYPES[$type]['direction'] !== 'none') {
            // 供应商付款会在核销时取得真实来源类别，再决定是否需要截图。
            $required = $type !== 'supplier_payment' && $accountType !== null && $accountType !== 'cash'
                && in_array($action, $rules['evidence']['required_actions'], true);
            $errors = array_merge($errors, self::evidenceErrors($payload, $required, $canUseException));
        }
        return $errors;
    }

    public static function textErrors(string $field, mixed $value, int $limit, bool $required, string $label): array
    {
        if (!is_string($value)) { return [['field' => $field, 'code' => 'invalid', 'message' => $label . '须填写有效文字']]; }
        if ($required && trim($value) === '') { return [['field' => $field, 'code' => 'required', 'message' => '请填写' . $label]]; }
        if (mb_strlen(trim($value)) > $limit) { return [['field' => $field, 'code' => 'max_length', 'message' => $label . '不能超过' . $limit . '字']]; }
        return [];
    }

    public static function text(string $field, mixed $value, int $limit, bool $required, string $label): string
    {
        $errors = self::textErrors($field, $value, $limit, $required, $label);
        if ($errors) { throw FinanceBusinessException::validation($errors); }
        return trim($value);
    }

    public static function evidenceErrors(array $payload, bool $required, bool $canUseException): array
    {
        $ids = $payload['evidence_ids'] ?? [];
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > self::EVIDENCE_LIMIT) {
            return [['field' => 'evidence_ids', 'code' => 'invalid', 'message' => '收付款截图须为最多12张有效截图']];
        }
        $reason = $payload['missing_evidence_reason'] ?? '';
        $alternative = $payload['alternative_evidence'] ?? '';
        $errors = array_merge(self::textErrors('missing_evidence_reason', $reason, 1000, false, '截图永久无法取得的原因'),
            self::textErrors('alternative_evidence', $alternative, 1000, false, '其他资金核验依据'));
        if ($required && !$ids) {
            $usingException = $canUseException && ((is_string($reason) && trim($reason) !== '') || (is_string($alternative) && trim($alternative) !== ''));
            if ($usingException) {
                if (is_string($reason) && trim($reason) === '') { $errors[] = ['field' => 'missing_evidence_reason', 'code' => 'required', 'message' => '请说明截图永久无法取得的原因']; }
                if (is_string($alternative) && trim($alternative) === '') { $errors[] = ['field' => 'alternative_evidence', 'code' => 'required', 'message' => '请填写其他资金核验依据']; }
            } else { $errors[] = ['field' => 'evidence_ids', 'code' => 'required', 'message' => '电子收付款须上传截图；永久无法取得时仅最高权限人员可凭其他资金依据例外确认']; }
        }
        return $errors;
    }
}
