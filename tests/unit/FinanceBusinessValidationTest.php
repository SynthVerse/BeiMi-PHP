<?php

declare(strict_types=1);

use app\api\jxc\logic\FinanceBusinessValidation;
use app\api\jxc\logic\FinanceBusinessLogic;
use app\api\jxc\logic\FinanceAccess;
use PHPUnit\Framework\TestCase;

final class FinanceBusinessValidationTest extends TestCase
{
    public function test_all_receipt_payment_types_publish_the_same_rules_used_by_validation(): void
    {
        $types = ['receipt', 'customer_refund', 'advance_refund', 'receipt_return', 'advance_allocate', 'customer_credit_allocate', 'receivable_due', 'payable_due', 'supplier_payment', 'supplier_refund', 'supplier_credit_allocate', 'supplier_advance', 'supplier_advance_allocate', 'supplier_advance_refund', 'capital_in', 'capital_out', 'loan_receipt', 'loan_repayment', 'bad_debt', 'recovery_termination', 'salary_payment', 'reimbursement_payment', 'expense_refund', 'equipment_payment', 'equipment_refund', 'recovery_receipt'];
        foreach ($types as $type) {
            $rules = FinanceBusinessValidation::rules($type, false);
            self::assertSame(['record', 'confirm', 'correct'], $rules['reason']['required_actions'], $type);
            self::assertSame(1000, $rules['reason']['max_length']);
            self::assertSame(['reason'], array_column(FinanceBusinessValidation::errors($type, 'record', [], null, false), 'field'));
            self::assertSame([], FinanceBusinessValidation::errors($type, 'save', [], 'bank', false));
            self::assertSame([], FinanceBusinessValidation::errors($type, 'prepare', [], 'bank', false));
            self::assertSame([], FinanceBusinessValidation::errors($type, 'reverse', [], 'bank', false));
        }
        self::assertSame([], FinanceBusinessValidation::rules('inventory_count', true));
    }

    public function test_missing_reason_and_evidence_are_reported_together_without_generic_text(): void
    {
        $errors = FinanceBusinessValidation::errors('customer_refund', 'record', ['reason' => '  ', 'evidence_ids' => []], 'bank', true);
        self::assertSame(['reason', 'evidence_ids'], array_column($errors, 'field'));
        self::assertStringContainsString('用途与核对说明', $errors[0]['message']);
        self::assertStringContainsString('上传', $errors[1]['message']);
        $errors = FinanceBusinessValidation::errors('customer_refund', 'correct', ['reason' => str_repeat('中', 1001)], 'cash', false);
        self::assertSame('reason', $errors[0]['field']);
        self::assertStringContainsString('1000', $errors[0]['message']);
        self::assertSame([], FinanceBusinessValidation::errors('customer_refund', 'record', ['reason' => str_repeat('中', 1000)], 'cash', false));
    }

    public function test_evidence_alternative_keeps_owner_requirement_and_locates_missing_part(): void
    {
        $data = ['reason' => '实际退款', 'evidence_ids' => [], 'missing_evidence_reason' => '渠道无法取得', 'alternative_evidence' => '银行回单'];
        self::assertSame([], FinanceBusinessValidation::errors('customer_refund', 'record', $data, 'bank', true));
        self::assertSame(['evidence_ids'], array_column(FinanceBusinessValidation::errors('customer_refund', 'record', $data, 'bank', false), 'field'));
        $data['alternative_evidence'] = '';
        self::assertSame(['alternative_evidence'], array_column(FinanceBusinessValidation::errors('customer_refund', 'record', $data, 'bank', true), 'field'));
        self::assertSame([], FinanceBusinessValidation::errors('advance_allocate', 'record', ['reason' => '已核对'], 'bank', false));
        self::assertSame(['evidence_ids'], array_column(FinanceBusinessValidation::errors('receipt', 'record', ['reason' => '已核对', 'evidence_ids' => range(1, 13)], 'bank', true), 'field'));
    }

    public function test_context_rejection_is_structured_and_does_not_leak_into_next_error(): void
    {
        $request = request();
        $prior = [$request->tenantId ?? null, $request->adminId ?? null, $request->jxcFromUserToken ?? null];
        try {
            $request->tenantId = 884260; $request->adminId = 99; $request->jxcFromUserToken = false;
            self::assertFalse(FinanceBusinessLogic::action('record', ['expected_tenant_id' => 884261]));
            self::assertSame('FINANCE_CONTEXT_CHANGED', FinanceBusinessLogic::getReturnData()['error_code'] ?? null);
            self::assertFalse(FinanceBusinessLogic::action('unknown', []));
            self::assertSame('FINANCE_ACTION_FORBIDDEN', FinanceBusinessLogic::getReturnData()['error_code'] ?? null);
        } finally { [$request->tenantId, $request->adminId, $request->jxcFromUserToken] = $prior; }
    }

    public function test_true_permission_denial_has_an_explicit_code(): void
    {
        $request = request(); $prior = [$request->tenantId ?? null, $request->adminId ?? null, $request->adminInfo ?? null, $request->jxcFromUserToken ?? null];
        try {
            $request->tenantId = 884260; $request->adminId = 99; $request->adminInfo = ['root' => 0, 'tenant_id' => 884260]; $request->jxcFromUserToken = false;
            try { FinanceAccess::require('', true); self::fail('must reject'); }
            catch (\DomainException $error) {
                self::assertTrue(method_exists($error, 'details'), '真实拒绝必须携带分类');
                self::assertSame('FINANCE_ACTION_FORBIDDEN', $error->details()['error_code']);
            }
        } finally { [$request->tenantId, $request->adminId, $request->adminInfo, $request->jxcFromUserToken] = $prior; }
    }

    public function test_read_permission_denial_is_distinct_from_action_denial(): void
    {
        $request = request(); $prior = [$request->tenantId ?? null, $request->adminId ?? null, $request->adminInfo ?? null, $request->jxcFromUserToken ?? null];
        try {
            $request->tenantId = 884260; $request->adminId = 99; $request->adminInfo = ['root' => 0, 'tenant_id' => 884260]; $request->jxcFromUserToken = false;
            self::assertFalse(FinanceBusinessLogic::options(['type' => 'customer_refund']));
            self::assertSame('FINANCE_ACCESS_DENIED', FinanceBusinessLogic::getReturnData()['error_code'] ?? null);
        } finally { [$request->tenantId, $request->adminId, $request->adminInfo, $request->jxcFromUserToken] = $prior; }
    }

    /** @dataProvider salaryPermissionFailures */
    public function test_salary_authorization_distinguishes_view_loss_from_confirm_denial(array $permissions, string $expectedCode): void
    {
        $request = request();
        $prior = [$request->tenantId ?? null, $request->adminId ?? null, $request->adminInfo ?? null, $request->jxcFromUserToken ?? null, $request->todoPermissionSnapshot ?? null];
        try {
            $request->tenantId = 884260; $request->adminId = 99; $request->adminInfo = ['root' => 0, 'tenant_id' => 884260]; $request->jxcFromUserToken = false;
            $request->todoPermissionSnapshot = ['tenant_id' => 884260, 'operator_id' => 99, 'identity' => 'tenant_admin', 'owner' => false, 'permissions' => $permissions];
            try { \app\api\jxc\logic\FinanceDocumentPolicy::authorize('salary_payment', true); self::fail('must reject'); }
            catch (\app\api\jxc\logic\FinanceBusinessException $error) { self::assertSame($expectedCode, $error->details()['error_code']); }
        } finally { [$request->tenantId, $request->adminId, $request->adminInfo, $request->jxcFromUserToken, $request->todoPermissionSnapshot] = $prior; }
    }

    public static function salaryPermissionFailures(): array
    {
        return ['view permission revoked' => [[], 'FINANCE_ACCESS_DENIED'], 'view allowed but confirm denied' => [['finance.salary.view'], 'FINANCE_ACTION_FORBIDDEN']];
    }
}
