<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use think\facade\Db;

/** 门店锁串行确认与期间关闭，命令结果和所有正式影响在同一事务提交。 */
final class FinanceBusinessLogic extends BaseLogic
{
    public static function periodAction(string $action, array $params): array|false
    {
        self::clearError();
        try {
            FinanceAccess::require('', true);
            return Db::transaction(static function () use ($action, $params): array {
                $ledger = new FinanceLedger(FinanceAccess::tenant()); $ledger->lockBook();
                return FinancePeriods::execute($ledger, $action, $params);
            });
        } catch (\DomainException $error) { self::setError($error->getMessage()); return false; }
    }

    public static function monthlyReport(array $params): array|false
    {
        self::clearError();
        try {
            FinanceReports::authorize($params);
            return Db::transaction(static function () use ($params): array {
                $ledger = new FinanceLedger(FinanceAccess::tenant()); $ledger->lockBook();
                return FinanceReportPeriods::read($ledger, $params);
            });
        } catch (\DomainException $error) { self::setError($error->getMessage()); return false; }
    }

    public static function reportTrace(array $params): array|false
    {
        self::clearError();
        try {
            FinanceReports::authorize($params);
            return Db::transaction(static function () use ($params): array {
                $ledger = new FinanceLedger(FinanceAccess::tenant()); $ledger->lockBook();
                return FinanceReportTrace::read($ledger, $params);
            });
        } catch (\DomainException $error) { self::setError($error->getMessage()); return false; }
    }

    public static function reportExport(string $action, array $params): array|false
    {
        self::clearError();
        try {
            return Db::transaction(static function () use ($action, $params): array {
                $ledger = new FinanceLedger(FinanceAccess::tenant()); $ledger->lockBook();
                $result = FinanceReportExports::execute($ledger, $action, $params);
                FinanceReportExports::auditAttempt($action, $params, $result);
                return $result;
            });
        } catch (\Throwable $error) {
            // 失败发生在事务回滚之后，避免拒权或文件生成失败的审计一起被撤销。
            FinanceReportExports::auditAttempt($action, $params, null, $error instanceof \DomainException ? $error->getMessage() : '导出处理异常');
            if (!$error instanceof \DomainException) { throw $error; }
            self::setError($error->getMessage()); return false;
        }
    }

    public static function closingChecklist(array $params): array|false
    {
        self::clearError();
        try {
            FinanceAccess::require('', true);
            return Db::transaction(static function () use ($params): array {
                $ledger = new FinanceLedger(FinanceAccess::tenant()); $ledger->lockBook();
                return FinancePeriodChecklist::collect($ledger, $params);
            });
        } catch (\DomainException $error) { self::setError($error->getMessage()); return false; }
    }

    public static function action(string $action, array $params): array|false
    {
        self::clearError();
        try {
            if (!in_array($action, ['save', 'prepare', 'submit', 'reopen', 'confirm', 'record', 'correct', 'reverse_duplicate', 'reverse'], true)) { throw new \DomainException('不支持的单据动作'); }
            $tenantId = FinanceAccess::tenant();
            if ($tenantId <= 0 || FinanceAccess::operator() <= 0) { throw new \DomainException('请先登录并选择门店'); }
            if (FinanceValue::id($params['expected_tenant_id'] ?? 0) !== $tenantId) { throw new \DomainException('当前门店已变化，请重新进入'); }
            $id = FinanceValue::id($params['id'] ?? 0, true);
            $version = FinanceValue::id($params['expected_version'] ?? null, true);
            $key = FinanceValue::text($params['idempotency_key'] ?? '', 96);
            if (!preg_match('/^[a-zA-Z0-9_-]{16,96}$/D', $key)) { throw new \DomainException('提交标识无效'); }
            $fingerprint = hash('sha256', FinanceValue::json([$action, $params, FinanceAccess::actor()['id'], FinanceAccess::actor()['type']]));
            return Db::transaction(static function () use ($action, $params, $tenantId, $id, $version, $key, $fingerprint): array {
                Db::name('finance_preparation')->duplicate(['tenant_id'])->insert(['tenant_id' => $tenantId]);
                Db::name('finance_preparation')->where('tenant_id', $tenantId)->lock(true)->find();
                $existing = Db::name('finance_command')->where('tenant_id', $tenantId)->where('idempotency_key', $key)->find();
                if ($existing) {
                    $result = FinanceValue::decode($existing['result']);
                    FinanceDocumentPolicy::authorize($result['type'], in_array($action, ['confirm', 'record', 'correct', 'reverse_duplicate', 'reverse'], true));
                    FinanceUnclaimed::reauthorizeCorrection($result['confirmed_result']);
                    if ($result['type'] === 'sales_batch' && $result['status'] === 'confirmed') { FinanceSalesBatches::reauthorize($result['confirmed_result']); }
                    if ($result['type'] === 'purchase_settlement' && $result['status'] === 'confirmed') { FinancePurchaseBatches::reauthorize($result['confirmed_result']); }
                    if ($result['type'] === 'purchase_difference' && $result['status'] === 'confirmed') { FinancePurchaseReviews::reauthorize($result['confirmed_result']); }
                    if (!hash_equals($existing['fingerprint'], $fingerprint)) { throw new \DomainException('同一提交标识不能用于不同内容或操作人'); }
                    unset($result['output']);
                    if ($result['type'] === 'sales_batch' && $result['status'] === 'confirmed' && FinanceAccess::has('settlement.view')) { $result['output'] = FinanceSalesOutput::document(['id' => $result['id']]) + ['offline_generation_allowed' => true]; }
                    return $result;
                }
                $document = $id ? Db::name('finance_document')->where('tenant_id', $tenantId)->where('id', $id)->find() : null;
                if ($id && !$document) { throw new \DomainException('单据不存在或不属于当前门店'); }
                $type = $document['type'] ?? FinanceValue::text($params['type'] ?? '', 40);
                FinanceDocumentPolicy::authorize($type, in_array($action, ['confirm', 'record', 'correct', 'reverse_duplicate', 'reverse'], true));
                if ($document && (int)$document['version'] !== $version) { throw new \DomainException('草稿已被修改，请核对最新版本'); }
                if (!$document && ($version !== 0 || !in_array($action, ['save', 'prepare', 'record'], true))) { throw new \DomainException('请先保存有效草稿'); }
                $original = in_array($action, ['correct', 'reverse_duplicate', 'reverse'], true) ? $document : null;
                if ($original && $original['status'] !== 'confirmed') { throw new \DomainException('仅已确认记录可关联更正'); }
                if ($original && $action === 'correct' && isset($params['payload']['replacement_type'])) {
                    $type = FinanceUnclaimed::replacementType($type, $params['payload']);
                }
                if ($document && $document['status'] === 'confirmed' && !$original) { throw new \DomainException('已确认单据不可覆盖或删除，请使用关联更正'); }
                if ($original) { $document = null; }
                if (in_array($action, ['save', 'prepare', 'record', 'correct', 'reverse_duplicate', 'reverse'], true)) {
                    if ($document && $document['status'] !== 'draft') { throw new \DomainException('请先退回草稿再修改'); }
                    $payload = in_array($action, ['reverse_duplicate', 'reverse'], true) ? FinanceValue::decode($original['payload']) : ($params['payload'] ?? null);
                    if (is_array($payload)) { $payload = FinanceCustomerReturns::input($type, FinanceInventoryCounts::input($type, $payload)); }
                    if (!is_array($payload) || strlen(FinanceValue::json($payload)) > 65536) { throw new \DomainException('草稿内容格式无效或超过容量限制'); }
                    $document = array_merge($document ?? ['tenant_id' => $tenantId, 'type' => $type, 'created_by' => FinanceValue::json(FinanceAccess::actor()),
                        'create_time' => time(), 'confirmed_at' => 0, 'confirmed_by' => '{}', 'confirmed_result' => '{}'],
                        ['payload' => FinanceValue::json($payload), 'status' => $action === 'save' ? 'draft' : 'pending']);
                } elseif ($action === 'submit') {
                    if ($document['status'] !== 'draft') { throw new \DomainException('只有草稿可以提交'); }
                    $document['status'] = 'pending';
                } elseif ($action === 'reopen') {
                    if ($document['status'] !== 'pending') { throw new \DomainException('只有待确认单据可以退回草稿'); }
                    $document['status'] = 'draft';
                }
                if (!$id || $original) { $document['id'] = (int)Db::name('finance_document')->insertGetId($document + ['version' => 1, 'last_modified_by' => FinanceValue::json(FinanceAccess::actor()), 'update_time' => time()]); }
                FinanceInventoryCounts::prepare($document, $action);
                if (in_array($action, ['confirm', 'record', 'correct', 'reverse_duplicate', 'reverse'], true)) {
                    if ($document['status'] !== 'pending') { throw new \DomainException('请先提交草稿再确认'); }
                    $ledger = new FinanceLedger($tenantId); $ledger->lockBook();
                    $affectedCustomers = [];
                    foreach ([$document, $original] as $affected) {
                        if (!$affected || FinanceDocumentPolicy::TYPES[$affected['type']]['subject'] !== 'customer') { continue; }
                        $customerId = (int)(FinanceValue::decode($affected['payload'])['subject_id'] ?? 0);
                        if ($customerId > 0) { $affectedCustomers[] = $customerId; }
                    }
                    $affectedCustomers = array_values(array_unique($affectedCustomers));
                    if ($affectedCustomers) { FinanceOverdue::captureWithinTransaction($tenantId, $affectedCustomers); }
                    if ($type === 'sales_batch' && in_array($action, ['reverse', 'reverse_duplicate'], true)) { throw new \DomainException('销售结算应使用关联金额更正，不能直接反向已发生的销售'); }
                    $result = $type === 'sales_batch' ? FinanceSalesBatches::confirm($ledger, $document, $original, (string)($params['correction_reason'] ?? '')) : ($original ? (new FinanceCorrections($tenantId, $ledger))->replace($original, $document, (string)($params['correction_reason'] ?? ''),
                        $action === 'reverse_duplicate' ? FinanceValue::id($params['duplicate_of'] ?? 0) : 0, $action === 'reverse')
                        : (new FinancePayments($tenantId, $ledger))->confirm($document));
                    $document['confirmed_result'] = FinanceValue::json($result);
                    $document['status'] = 'confirmed'; $document['confirmed_by'] = FinanceValue::json(FinanceAccess::actor()); $document['confirmed_at'] = time();
                }
                $document['version'] = $original ? 1 : $version + 1; $document['last_modified_by'] = FinanceValue::json(FinanceAccess::actor()); $document['update_time'] = time();
                Db::name('finance_document')->where('tenant_id', $tenantId)->where('id', $document['id'])->update($document);
                if (!empty($affectedCustomers)) { FinanceOverdue::captureWithinTransaction($tenantId, $affectedCustomers, (int)$document['id']); }
                $result = self::present($document);
                if (isset($result['output'])) { $result['output']['offline_generation_allowed'] = true; }
                Db::name('finance_command')->insert(['tenant_id' => $tenantId, 'idempotency_key' => $key, 'fingerprint' => $fingerprint,
                    'document_id' => $document['id'], 'action' => $action, 'actor' => FinanceValue::json(FinanceAccess::actor()),
                    'result' => FinanceValue::json($result), 'create_time' => time()]);
                return $result;
            });
        } catch (\DomainException $error) { self::setError($error->getMessage()); return false; }
    }

    public static function detail(array $params): array|false
    {
        self::clearError();
        try {
            $document = Db::name('finance_document')->where('tenant_id', FinanceAccess::tenant())->where('id', FinanceValue::id($params['id'] ?? 0))->find();
            if (!$document) { throw new \DomainException('单据不存在或不属于当前门店'); }
            $policy = FinanceDocumentPolicy::read($document['type']);
            if (in_array($document['type'], ['receivable_due', 'payable_due'], true) && $document['status'] !== 'confirmed') {
                $payload = FinanceValue::decode($document['payload']);
                if (!empty($payload['source'])) {
                    $source = (new FinanceLedger(FinanceAccess::tenant()))->source(FinanceValue::text($payload['source'], 40));
                    if (!in_array($source['category'], $policy['sources'], true) || $source['subject_id'] !== FinanceValue::id($payload['subject_id'] ?? 0)) { throw new \DomainException('来源业务类型或往来主体不匹配，不能读取关联明细'); }
                    $document['current_source'] = $source;
                }
            }
            return self::present($document) + ['replacement_document_id' => (int)(Db::name('finance_correction')->where('tenant_id', FinanceAccess::tenant())->where('original_document_id', $document['id'])->value('replacement_document_id') ?: 0)];
        } catch (\DomainException $error) { self::setError($error->getMessage()); return false; }
    }

    public static function options(array $params): array|false
    {
        self::clearError();
        try {
            $type = FinanceValue::text($params['type'] ?? '', 40); $policy = FinanceDocumentPolicy::read($type);
            $ledger = new FinanceLedger(FinanceAccess::tenant());
            $accounts = Db::name('finance_account')->where('tenant_id', FinanceAccess::tenant())->where('is_enabled', 1)->order('id')->field('id,name,account_type')->select()->toArray();
            $subjectId = FinanceValue::id($params['subject_id'] ?? 0, true);
            $categories = $type === 'advance_allocate' && ($params['role'] ?? '') === 'fund' ? ['advance'] : $policy['sources'];
            if ($type === 'supplier_credit_allocate' && ($params['role'] ?? '') === 'fund') { $categories = ['supplier_refund']; }
            $page = max(1, FinanceValue::id($params['page'] ?? 1));
            $sources = match ($type) {
                'account_reconcile', 'cash_shortage' => FinanceReconciliations::options($ledger, $params),
                'transit_reconcile' => FinanceTransitReviews::options($ledger, $params),
                'unclaimed_receipt', 'unclaimed_customer_claim', 'unclaimed_recovery_claim', 'unclaimed_supplier_refund_claim', 'unclaimed_expense_refund_claim', 'unclaimed_equipment_refund_claim' => FinanceUnclaimed::options($ledger, $type, $params),
                'account_transfer_out', 'account_transfer_arrival', 'account_transfer_return' => FinanceAccountTransfers::options($ledger, $params),
                'equipment_purchase', 'equipment_adjustment' => FinanceEquipment::options($subjectId, $params),
                'equipment_refund_due', 'equipment_refund_adjustment' => FinanceEquipmentRefunds::options($subjectId, $params),
                'salary_adjustment' => FinanceSalaries::adjustmentOptions($subjectId, $params),
                'salary_expense' => FinanceSalaries::options($params),
                'employee_expense_adjustment' => FinanceEmployeeExpenses::options($subjectId, $params),
                'employee_expense' => !empty($params['original_expense_document_id']) ? FinanceEmployeeExpenses::outstanding(FinanceValue::id($params['original_expense_document_id'])) : FinanceExpenseCategories::options(),
                'expense_recurring_plan', 'expense_recurring_none', 'expense_recurring_correct' => FinanceRecurringExpenses::options($subjectId, $params),
                'deferred_amortization' => FinanceDeferredExpenses::options($ledger, $subjectId, $params),
                'deferred_expense' => FinanceExpenseCategories::options(),
                'expense_adjustment', 'expense_estimate_final' => FinanceExpenseAdjustments::options($subjectId, $params),
                'expense' => !empty($params['original_expense_document_id']) ? FinanceExpenseAdjustments::outstanding(FinanceValue::id($params['original_expense_document_id'])) : FinanceExpenseCategories::options(),
                'expense_category' => FinanceExpenseCategories::options(true),
                'legacy_return_cost' => FinanceLegacyReturnCosts::options($params),
                'inventory_count_start' => FinancePurchaseArrivals::options(0, $params),
                'inventory_count_review' => FinanceInventoryCountReviews::options($params),
                'customer_return_actual' => FinanceCustomerReturns::options($subjectId, $params),
                'inventory_count_correction' => FinanceInventoryCountCorrections::options($params),
                'inventory_count_correction_cancel' => FinanceInventoryCountCorrections::cancellationOptions($params),
                'inventory_count', 'inventory_count_cancel' => FinanceInventoryCounts::options($params),
                'inventory_loss' => FinancePurchaseArrivals::options(0, $params),
                'inventory_loss_resolution' => FinanceInventoryLosses::options($params),
                'purchase_arrival_loss' => FinancePurchaseReviews::options($subjectId, $params, true),
                'purchase_difference' => FinancePurchaseReviews::options($subjectId, $params),
                'purchase_return_resolution' => FinancePurchaseReturnResolutions::options($subjectId, $params),
                'purchase_return_acceptance' => ($params['role'] ?? '') === 'credit' ? $ledger->sourcePage($categories, $subjectId, $page) : FinancePurchaseReturnAcceptances::options($subjectId, $params),
                'purchase_return_actual' => FinancePurchaseReturns::options($subjectId, $params),
                'purchase_extra_adjustment' => ($params['role'] ?? '') === 'credit' ? $ledger->sourcePage($categories, $subjectId, $page)
                    : (($params['role'] ?? '') === 'arrival' ? FinancePurchaseExtraCosts::options($params) : FinancePurchaseExtraAdjustments::options($subjectId, $params)),
                'purchase_extra_cost' => FinancePurchaseExtraCosts::options($params),
                'purchase_adjustment' => ($params['role'] ?? '') === 'credit' ? $ledger->sourcePage($categories, $subjectId, $page) : FinancePurchaseAdjustments::options($subjectId, $params),
                'purchase_arrival' => FinancePurchaseArrivals::options($subjectId, $params),
                'purchase_settlement' => FinancePurchaseBatches::options($subjectId, $params),
                'purchase_rules' => FinancePurchaseRuleBook::options($params),
                default => $ledger->sourcePage($categories, $subjectId, $page),
            };
            if ($type === 'sales_batch' && ($params['role'] ?? '') !== 'credit') {
                $sources += FinanceSalesBatches::options($subjectId, FinanceValue::date($params['date_from'] ?? date('Y-m-01')), FinanceValue::date($params['date_to'] ?? date('Y-m-d')));
            }
            if ($type === 'receipt_return') {
                $sources = ['sources' => [], 'has_more' => false, 'receipt_choices' => []];
                if (!empty($params['receipt_id'])) {
                    $returned = (new FinanceReceiptReturns(FinanceAccess::tenant(), $ledger))->options(FinanceValue::id($params['receipt_id']));
                    if ($returned['subject_id'] !== $subjectId) { throw new \DomainException('原收款不属于所选客户'); }
                    $sources += ['receipt' => $returned]; $sources['sources'] = $returned['sources'];
                } elseif ($subjectId) {
                    $replaced = Db::name('finance_correction')->where('tenant_id', FinanceAccess::tenant())->field('original_document_id')->buildSql();
                    $receipts = Db::name('finance_document')->where('tenant_id', FinanceAccess::tenant())->where('type', 'receipt')->where('status', 'confirmed')
                        ->whereRaw('id NOT IN ' . $replaced)->whereRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.subject_id')) AS UNSIGNED)=?", [$subjectId])
                        ->whereRaw("JSON_EXTRACT(confirmed_result,'$.money.transaction_id') IS NOT NULL")
                        ->order('id', 'desc')->limit(($page - 1) * 20, 21)->select()->toArray();
                    $sources['has_more'] = count($receipts) > 20;
                    foreach (array_slice($receipts, 0, 20) as $receipt) { $result = FinanceValue::decode($receipt['confirmed_result']); $sources['receipt_choices'][] = ['id' => (int)$receipt['id'], 'amount' => $result['money']['amount'], 'actual_date' => $result['money']['actual_date']]; }
                }
            }
            if (!empty($params['source']) && $type !== 'receipt_return') {
                $selected = $ledger->source(FinanceValue::text($params['source'], 40));
                if (!in_array($selected['category'], $categories, true) || $selected['subject_id'] !== $subjectId || ($type !== 'deferred_amortization' && bccomp($selected['balance'], '0', 2) <= 0)) { throw new \DomainException('指定来源已结清或不属于本对象和业务类型'); }
                $sources['selected_source'] = $selected;
            }
            if ($type === 'supplier_payment') {
                foreach ($sources['sources'] as &$source) {
                    $source['disputed_amount'] = FinanceStatements::disputedAmount($source['reference']);
                    $available = bcsub($source['balance'], $source['disputed_amount'], 2);
                    $source['available_payment'] = bccomp($available, '0', 2) > 0 ? $available : '0.00';
                }
                unset($source);
                if (isset($sources['selected_source'])) {
                    $selected = &$sources['selected_source']; $selected['disputed_amount'] = FinanceStatements::disputedAmount($selected['reference']);
                    $available = bcsub($selected['balance'], $selected['disputed_amount'], 2);
                    $selected['available_payment'] = bccomp($available, '0', 2) > 0 ? $available : '0.00';
                }
            }
            return ['tenant_id' => FinanceAccess::tenant(), 'type' => $type, 'policy' => $policy,
                'active' => Db::name('finance_opening_book')->where('tenant_id', FinanceAccess::tenant())->value('status') === 'active',
                'can_confirm' => FinanceAccess::owner() || (!$policy['owner'] && FinanceAccess::has($policy['confirm'])),
                'can_prepare' => str_starts_with($type, 'inventory_count') ? FinanceAccess::has($policy['prepare']) : (in_array($type, ['salary_expense', 'salary_payment', 'salary_adjustment'], true) ? FinanceAccess::has('finance.salary.prepare') : ($type !== 'sales_batch' || FinanceAccess::has('settlement.bill'))),
                'can_return_receipt' => $type === 'receipt' && FinanceAccess::has('finance.refund.prepare'),
                'accounts' => $accounts] + $sources;
        } catch (\DomainException $error) { self::setError($error->getMessage()); return false; }
    }

    private static function present(array $document): array
    {
        foreach (['payload', 'confirmed_result', 'created_by', 'last_modified_by', 'confirmed_by'] as $key) { $document[$key] = FinanceValue::decode($document[$key]); }
        $document['id'] = (int)$document['id']; $document['version'] = (int)$document['version'];
        $document = FinanceInventoryCounts::present($document);
        $document = FinanceInventoryCountReviews::present($document);
        $document = FinanceCustomerReturns::present($document);
        if ($document['type'] === 'sales_batch' && $document['status'] === 'confirmed' && FinanceAccess::has('settlement.view')) { $document['output'] = FinanceSalesOutput::document(['id' => $document['id']]); }
        return $document;
    }

    public static function catalog(): array
    {
        $types = [];
        foreach (FinanceDocumentPolicy::TYPES as $type => $policy) {
            try { FinanceDocumentPolicy::read($type); $types[] = ['type' => $type, 'title' => $policy['title'], 'subject' => $policy['subject'], 'can_prepare' => str_starts_with($type, 'inventory_count') ? FinanceAccess::has($policy['prepare']) : (in_array($type, ['salary_expense', 'salary_payment', 'salary_adjustment'], true) ? FinanceAccess::has('finance.salary.prepare') : ($type !== 'sales_batch' || FinanceAccess::has('settlement.bill')))]; }
            catch (\DomainException) { continue; }
        }
        return ['tenant_id' => FinanceAccess::tenant(), 'types' => $types,
            'active' => Db::name('finance_opening_book')->where('tenant_id', FinanceAccess::tenant())->value('status') === 'active'];
    }

    public static function lists(array $params): array|false
    {
        self::clearError();
        try {
            $type = FinanceValue::text($params['type'] ?? '', 40); FinanceDocumentPolicy::read($type);
            $page = FinanceValue::id($params['page'] ?? 1);
            $query = Db::name('finance_document')->where('tenant_id', FinanceAccess::tenant())->where('type', $type);
            if (!empty($params['subject_id'])) { $query->whereRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.subject_id')) AS UNSIGNED)=?", [FinanceValue::id($params['subject_id'])]); }
            $rows = $query->order('id', 'desc')->page($page, 20)->select()->toArray();
            return ['tenant_id' => FinanceAccess::tenant(), 'lists' => array_map(self::present(...), $rows), 'page' => $page, 'has_more' => count($rows) === 20];
        } catch (\DomainException $error) { self::setError($error->getMessage()); return false; }
    }

    public static function subjects(array $params): array|false
    {
        self::clearError();
        try {
            $policy = FinanceDocumentPolicy::read(FinanceValue::text($params['type'] ?? '', 40));
            $subject = $policy['subject'] === 'account' ? (($params['role'] ?? '') === 'fee' ? 'vendor' : 'finance_account') : $policy['subject'];
            $column = ['customer' => 'customer_name', 'vendor' => 'supplier_name', 'employee' => 'name', 'finance_account' => 'name'][$subject];
            $query = Db::name($subject)->where('tenant_id', FinanceAccess::tenant());
            if ($subject === 'customer') { $query->where('parent_id', 0); }
            if ($subject === 'finance_account') { $query->where('is_enabled', 1); }
            if (!empty($params['id'])) { $query->where('id', FinanceValue::id($params['id'])); }
            $keyword = FinanceValue::text($params['keyword'] ?? '', 60, false);
            if ($keyword !== '') { $query->whereLike($column, '%' . addcslashes($keyword, '%_\\') . '%'); }
            $page = FinanceValue::id($params['page'] ?? 1);
            $rows = $query->field('id,' . $column . ' AS name')->order('id')->page($page, 20)->select()->toArray();
            return ['tenant_id' => FinanceAccess::tenant(), 'lists' => $rows, 'has_more' => count($rows) === 20];
        } catch (\DomainException $error) { self::setError($error->getMessage()); return false; }
    }
}
