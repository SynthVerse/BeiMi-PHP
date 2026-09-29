<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 财务域待办适配器。只投影权威业务事实，不创建第二套待办状态。 */
final class TodoFinanceSourceProvider
{
    /** @param array<int,string|int>|null $after @return array{count:int,after_count:int,items:array<int,array<string,mixed>>} */
    public static function query(string $source, int $limit, ?array $after, array $filters = [], array $excludedIdentities = []): array
    {
        $buffer = new TodoItemBuffer($source, 'finance', $limit, $after, $filters, $excludedIdentities);
        match ($source) {
            'S05' => self::documents($buffer),
            'S06' => self::salesSettlement($buffer),
            'S07' => self::purchaseSettlement($buffer),
            'S08' => self::dueBalances($buffer, ['receivable'], '应收款已到期', 'receivable'),
            'S09' => self::dueBalances($buffer, ['payable', 'expense_payable', 'salary', 'reimbursement', 'customer_refund', 'equipment'], '应付款项已到期', 'payable'),
            'S10' => self::purchaseDifferences($buffer),
            'S11' => self::purchaseReturns($buffer),
            'S12' => self::inventoryCounts($buffer),
            'S13' => self::inventoryExceptions($buffer),
            'S14' => self::recurringExpenses($buffer),
            'S15' => self::expenseEstimates($buffer),
            'S16' => self::deferredAmortizations($buffer),
            'S17' => self::unclaimedFunds($buffer),
            'S18' => self::fundReconciliations($buffer),
            'S19' => self::statementDisputes($buffer),
            'S20' => self::activation($buffer),
            'S21' => self::periods($buffer),
            'S22' => self::printRecovery($buffer),
            default => null,
        };
        return $buffer->finish();
    }

    private static function documents(TodoItemBuffer $buffer): void
    {
        self::chunks('finance_document', static fn($query) => $query->where('status', 'pending'),
            static function (array $row) use ($buffer): void {
                $type = (string)$row['type'];
                if (!self::canConfirm($type)) { return; }
                $policy = FinanceDocumentPolicy::TYPES[$type];
                $payload = FinanceValue::decode((string)$row['payload']);
                $businessDate = self::firstDate($payload, ['actual_date', 'business_date', 'obligation_date']);
                $buffer->add(self::item('finance-document:' . $row['id'], 'finance_document', (int)$row['id'],
                    $policy['title'] . '待确认', (string)($payload['subject_name'] ?? $payload['source_reference'] ?? ''),
                    $businessDate, null, '单据已提交，尚未确认', '审核确认', 'finance_document',
                    ['type' => $type, 'id' => (int)$row['id']]));
            });
    }

    private static function salesSettlement(TodoItemBuffer $buffer): void
    {
        if (!FinanceAccess::has('settlement.bill') && !FinanceAccess::owner()) { return; }
        self::chunks('sales_order', static fn($query) => $query->where('source_type', 'customer_report')
            ->whereIn('settlement_status', ['pending', 'pending_weight_review']), static function (array $row) use ($buffer): void {
                if ((string)$row['settlement_status'] === 'pending_weight_review' && !FinanceAccess::owner()) { return; }
                if ((string)$row['settlement_status'] === 'pending' && !FinanceAccess::has('settlement.bill')) { return; }
                $date = !empty($row['datetimesingle']) ? date('Y-m-d', (int)$row['datetimesingle']) : null;
                $buffer->add(self::item('sales-settlement:' . $row['id'], 'sales_order', (int)$row['id'],
                    '销售单 ' . (string)$row['order_sn'] . ' 待结算', (string)$row['customer_name'], $date, null,
                    (string)$row['settlement_status'], '处理销售结算', 'sales_settlement',
                    ['order_id' => (int)$row['id'], 'customer_id' => (int)$row['customer_id']]));
            });
    }

    private static function purchaseSettlement(TodoItemBuffer $buffer): void
    {
        if (!self::any(['finance.purchase.prepare', 'finance.purchase.confirm'])) { return; }
        $coverage = FinancePurchaseCoverage::sql(); $last = 0;
        do {
            $rows = Db::name('finance_purchase_arrival_line')->alias('a')
                ->leftJoin([$coverage => 'c'], 'c.arrival_line_id=a.id')
                ->where('a.tenant_id', FinanceAccess::tenant())->where('a.id', '>', $last)
                ->whereRaw('a.actual_quantity>COALESCE(c.quantity,0)')
                ->field('a.*,a.actual_quantity-COALESCE(c.quantity,0) AS pending_quantity')->order('a.id')->limit(500)->select()->toArray();
            foreach (TodoQueryBudget::candidates($rows) as $row) {
                $last = (int)$row['id']; $snapshot = FinanceValue::decode((string)$row['snapshot']);
                $buffer->add(self::item('purchase-arrival:' . $row['id'], 'finance_purchase_arrival_line', (int)$row['id'],
                    '采购到货待结算', (string)($snapshot['subject_name'] ?? $snapshot['source_reference'] ?? ('供应商 #' . $row['vendor_id'])),
                    (string)$row['business_date'], null, '仍有到货数量未结算', '处理采购结算', 'purchase_settlement',
                    ['vendor_id' => (int)$row['vendor_id'], 'arrival_line_id' => (int)$row['id']],
                    ['value' => bcadd((string)$row['pending_quantity'], '0', 4), 'unit' => (string)($snapshot['base_unit_name'] ?? '')]));
            }
        } while (count($rows) === 500);
    }

    /** @param array<int,string> $categories */
    private static function dueBalances(TodoItemBuffer $buffer, array $categories, string $title, string $target): void
    {
        if ($target === 'receivable') {
            if (!self::any(['finance.receivable.view', 'finance.receivable.prepare', 'finance.receipt.prepare', 'finance.receipt.confirm'])) { return; }
        } else {
            $categories = array_values(array_filter($categories, static fn(string $category): bool => self::canPreparePayable($category)));
            if (!$categories) { return; }
        }
        $tenant = FinanceAccess::tenant(); $changes = Db::name('finance_entry')->where('tenant_id', $tenant)
            ->where('metric', 'balance')->field('source_ref,SUM(amount) AS delta')->group('source_ref')->buildSql();
        $latestDue = FinanceDueDates::latestSql($tenant);
        foreach (['o' => 'finance_opening_source', 'n' => 'finance_source'] as $kind => $table) {
            $last = 0;
            do {
                $baseDue = $kind === 'o' ? "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(s.source_snapshot,'$.due_date')),'null')" : 's.due_date';
                $businessDate = $kind === 'o' ? 's.activation_date' : 's.business_date';
                $rows = Db::name($table)->alias('s')->leftJoin([$changes => 'b'], "b.source_ref=CONCAT('{$kind}:',s.id)")
                    ->leftJoin([$latestDue => 'd'], "d.source_ref=CONCAT('{$kind}:',s.id)")
                    ->where('s.tenant_id', $tenant)->where('s.id', '>', $last)->whereIn('s.category', $categories)
                    ->whereRaw('s.amount+COALESCE(b.delta,0)>0')
                    ->whereRaw("CASE WHEN d.id IS NULL THEN {$baseDue} ELSE d.new_due_date END<=?", [date('Y-m-d')])
                    ->field("s.*,s.amount+COALESCE(b.delta,0) AS balance,CASE WHEN d.id IS NULL THEN {$baseDue} ELSE d.new_due_date END AS current_due_date,{$businessDate} AS current_business_date")
                    ->order('s.id')->limit(500)->select()->toArray();
                foreach (TodoQueryBudget::candidates($rows) as $row) {
                    $last = (int)$row['id']; $snapshot = FinanceValue::decode((string)($row[$kind === 'o' ? 'source_snapshot' : 'snapshot'] ?? ''));
                    $reference = $kind . ':' . $row['id'];
                    $params = ['source' => $reference, 'subject_id' => (int)$row['subject_id'], 'category' => (string)$row['category']];
                    if ($target === 'payable') {
                        $payableTarget = self::payableTarget((string)$row['category']);
                        $params += ['action' => $payableTarget['action'], 'subject_kind' => $payableTarget['subject_kind']];
                    }
                    $buffer->add(self::item($target . '-due:' . $reference, $table, (int)$row['id'], $title,
                        (string)($snapshot['subject_name'] ?? $snapshot['source_reference'] ?? ('对象 #' . $row['subject_id'])),
                        (string)$row['current_business_date'], (string)$row['current_due_date'], '到期且仍有未结余额',
                        $target === 'receivable' ? '跟进应收' : '处理应付', $target,
                        $params,
                        ['value' => bcadd((string)$row['balance'], '0', 2), 'unit' => '元']));
                }
            } while (count($rows) === 500);
        }
    }

    private static function purchaseDifferences(TodoItemBuffer $buffer): void
    {
        if (!self::any(['finance.purchase.prepare', 'finance.purchase.confirm'])) { return; }
        $tenant = FinanceAccess::tenant();
        $latest = Db::name('finance_purchase_difference_review')->where('tenant_id', $tenant)
            ->field('arrival_line_id,MAX(id) AS id')->group('arrival_line_id')->buildSql();
        $last = 0;
        do {
            $rows = Db::name('finance_purchase_arrival_line')->alias('a')
                ->leftJoin([$latest => 'latest_review'], 'latest_review.arrival_line_id=a.id')
                ->leftJoin('finance_purchase_difference_review r', 'r.id=latest_review.id')
                ->where('a.tenant_id', $tenant)->where('a.id', '>', $last)
                ->whereRaw('(r.id IS NULL OR r.resolved<>1)')->field('a.*')->order('a.id')->limit(500)->select()->toArray();
            foreach (TodoQueryBudget::candidates($rows) as $row) {
                $last = (int)$row['id']; $snapshot = FinanceValue::decode((string)$row['snapshot']);
                $difference = $snapshot['arrival_difference'] ?? $snapshot['difference'] ?? null;
                if ($difference === null || (is_numeric($difference) && bccomp((string)$difference, '0', 4) === 0)) { continue; }
                $buffer->add(self::item('purchase-difference:' . $row['id'], 'finance_purchase_arrival_line', (int)$row['id'],
                    '采购到货差待复核', (string)($snapshot['goods_name'] ?? $snapshot['source_reference'] ?? ''),
                    (string)$row['business_date'], null, '报量与实收存在未解决差异', '复核到货差', 'purchase_difference',
                    ['arrival_line_id' => (int)$row['id'], 'vendor_id' => (int)$row['vendor_id']]));
            }
        } while (count($rows) === 500);
    }

    private static function purchaseReturns(TodoItemBuffer $buffer): void
    {
        if (!self::any(['finance.purchase.prepare', 'finance.purchase.confirm'])) { return; }
        $tenant = FinanceAccess::tenant();
        $resolved = Db::name('finance_purchase_return_resolution')->where('tenant_id', $tenant)
            ->field('return_line_id,SUM(quantity) AS quantity')->group('return_line_id')->buildSql();
        $last = 0;
        do {
            $rows = Db::name('finance_purchase_return_line')->alias('r')
                ->leftJoin([$resolved => 'resolved'], 'resolved.return_line_id=r.id')
                ->where('r.tenant_id', $tenant)->where('r.id', '>', $last)
                ->whereRaw('r.quantity>COALESCE(resolved.quantity,0)')
                ->field('r.*,r.quantity-COALESCE(resolved.quantity,0) AS remaining_quantity')
                ->order('r.id')->limit(500)->select()->toArray();
            foreach (TodoQueryBudget::candidates($rows) as $row) {
                $last = (int)$row['id']; $snapshot = FinanceValue::decode((string)$row['snapshot']);
                $buffer->add(self::item('purchase-return:' . $row['id'], 'finance_purchase_return_line', (int)$row['id'],
                    '采购退货待认可或处置', (string)($snapshot['goods_name'] ?? $snapshot['source_reference'] ?? ''),
                    (string)$row['business_date'], null, '退离数量尚未完全认可或处置', '处理退货', 'purchase_return',
                    ['return_line_id' => (int)$row['id'], 'vendor_id' => (int)$row['vendor_id']],
                    ['value' => (string)$row['remaining_quantity'], 'unit' => (string)($snapshot['base_unit_name'] ?? '')]));
            }
        } while (count($rows) === 500);
    }

    private static function inventoryCounts(TodoItemBuffer $buffer): void
    {
        if (!FinanceAccess::has('finance.inventory.confirm')) { return; }
        self::chunks('finance_inventory_count', static fn($query) => $query->where('status', 'pending'),
            static function (array $row) use ($buffer): void {
                $documentId = (int)$row['result_document_id'];
                if ($documentId <= 0) { return; }
                $snapshot = FinanceValue::decode((string)$row['snapshot']);
                $item = self::item('finance-document:' . $documentId, 'finance_inventory_count', (int)$row['id'],
                    '盘点结果待确认', (string)($snapshot['warehouse_name'] ?? ('仓库 #' . $row['warehouse_id'])),
                    self::dateFromTime((int)$row['create_time']), null, '实盘已提交，库存差异尚未确认', '确认盘点',
                    'finance_document', ['type' => 'inventory_count', 'id' => $documentId]);
                $item['dedupe_key'] = 'finance-document:' . $documentId;
                $buffer->add($item);
            });
        self::chunks('finance_inventory_count', static fn($query) => $query->where('status', 'confirmed'),
            static function (array $row) use ($buffer): void {
                $document = Db::name('finance_document')->where('tenant_id', FinanceAccess::tenant())
                    ->where('id', (int)$row['result_document_id'])->where('status', 'confirmed')->find();
                if (!$document) { return; }
                $result = FinanceValue::decode((string)$document['confirmed_result']);
                $lines = array_values(array_filter(FinanceInventoryCountReviews::lines((int)$document['id'], $result),
                    static fn(array $line): bool => !$line['resolved']));
                if (!$lines) { return; }
                $buffer->add(self::item('inventory-count-review:' . $document['id'], 'finance_inventory_count', (int)$row['id'],
                    '盘点差额仍待核实', (string)($result['warehouse_name'] ?? ('仓库 #' . $row['warehouse_id'])),
                    (string)($result['actual_date'] ?? self::dateFromTime((int)$row['create_time'])), null,
                    '仍有 ' . count($lines) . ' 项盘点差额未结案', '核实盘点差额', 'inventory_count_review',
                    ['document_id' => (int)$document['id']], ['value' => (string)count($lines), 'unit' => '项']));
            });
    }

    private static function inventoryExceptions(TodoItemBuffer $buffer): void
    {
        if (FinanceAccess::has('inventory.negative.manage')) {
            self::chunks('negative_inventory_todo', static fn($query) => $query->where('status', 'open'),
                static function (array $row) use ($buffer): void {
                    $buffer->add(self::item('negative-inventory:' . $row['id'], 'negative_inventory_todo', (int)$row['id'],
                        '负库存异常待处理', '负库存来源 #' . $row['attribution_id'], self::dateFromTime((int)$row['create_time']),
                        null, (string)$row['status'], '处理负库存', 'negative_inventory', ['attribution_id' => (int)$row['attribution_id']]));
                });
        }
        if (!self::any(['finance.inventory.prepare', 'finance.inventory.confirm'])) { return; }
        self::chunks('finance_inventory_loss', static fn($query) => $query, static function (array $row) use ($buffer): void {
            $resolved = (string)Db::name('finance_inventory_loss_resolution')->where('tenant_id', FinanceAccess::tenant())
                ->where('incident_document_id', (int)$row['document_id'])->sum('quantity');
            $remaining = bcsub((string)$row['quantity'], $resolved ?: '0', 4);
            if (bccomp($remaining, '0', 4) <= 0) { return; }
            $buffer->add(self::item('inventory-loss-doc:' . $row['document_id'], 'finance_inventory_loss', (int)$row['id'],
                '库存损耗待核实', (string)$row['source_reference'], (string)$row['business_date'], null,
                '损耗数量尚未完全处置', '核实损耗', 'inventory_loss', ['document_id' => (int)$row['document_id']],
                ['value' => $remaining, 'unit' => '数量']));
        });
        self::unknownCosts($buffer);
    }

    private static function unknownCosts(TodoItemBuffer $buffer): void
    {
        self::chunks('finance_cost_origin', static fn($query) => $query->whereNull('current_amount'),
            static function (array $row) use ($buffer): void {
                $originKey = (string)$row['origin_key'];
                if (FinanceInventoryCountCorrections::cancelledGainOrigin($originKey)) { return; }
                $snapshot = FinanceValue::decode((string)$row['snapshot']);
                $source = (array)($snapshot['source'] ?? []);
                $target = ''; $params = [];
                if (($source['cost_basis_pending'] ?? null) === 'pre_cutoff_sales_return' && (int)($source['stock_flow_id'] ?? 0) > 0) {
                    $target = 'cost_pending';
                    $params['stock_flow_id'] = (int)$source['stock_flow_id'];
                } elseif (preg_match('/^inventory-count-gain:(\d+):/', $originKey, $match)) {
                    $target = 'inventory_count_review'; $params = ['document_id' => (int)$match[1]];
                } elseif (preg_match('/^purchase-arrival:(\d+)$/D', $originKey, $match)) {
                    $arrival = Db::name('finance_purchase_arrival_line')->where('tenant_id', FinanceAccess::tenant())
                        ->where('id', (int)$match[1])->field('id,vendor_id')->find();
                    if ($arrival) { $target = 'purchase_settlement'; $params = ['arrival_line_id' => (int)$arrival['id'], 'vendor_id' => (int)$arrival['vendor_id']]; }
                }
                if ($target === '') { return; }
                $buffer->add(self::item('cost-pending:' . $row['origin_key'], 'finance_cost_origin', (int)$row['id'],
                    '来源成本待核实', 'SKU #' . $row['sku_id'], null, null,
                    '正式库存来源尚无可用成本依据', '核实来源成本', $target, $params));
            });
    }

    private static function recurringExpenses(TodoItemBuffer $buffer): void
    {
        if (!self::any(['finance.expense.prepare', 'finance.expense.confirm'])) { return; }
        self::chunks('finance_recurring_expense_plan', static fn($query) => $query, static function (array $row) use ($buffer): void {
            $plan = FinanceRecurringPlanChanges::project(FinanceValue::decode((string)$row['snapshot']), (int)$row['id']);
            foreach ($plan['scheduled_months'] as $month) {
                if ($month > date('Y-m')) { continue; }
                $done = Db::name('finance_recurring_expense_month')->where('tenant_id', FinanceAccess::tenant())
                    ->where('plan_id', (int)$row['id'])->where('benefit_month', $month)->find();
                if ($done) { continue; }
                $buffer->add(self::item('recurring:' . $row['id'] . ':' . $month, 'finance_recurring_expense_plan', (int)$row['id'],
                    '周期费用 ' . $month . ' 待核实', (string)$row['source_reference'], $month . '-01', $month . '-01',
                    '计划月份已到且尚未登记发生或不发生', '核实本期费用', 'recurring_expense',
                    ['plan_id' => (int)$row['id'], 'month' => $month, 'document_id' => (int)$row['document_id']]));
            }
        });
    }

    private static function expenseEstimates(TodoItemBuffer $buffer): void
    {
        if (!FinanceAccess::owner()) { return; }
        self::chunks('finance_expense_bill', static fn($query) => $query, static function (array $row) use ($buffer): void {
            $snapshot = FinanceValue::decode((string)$row['snapshot']);
            if (($snapshot['amount_status'] ?? 'final') !== 'estimated') { return; }
            $resolved = Db::name('finance_expense_estimate_resolution')->where('tenant_id', FinanceAccess::tenant())
                ->where('bill_id', (int)$row['id'])->column('category_id');
            $pending = array_values(array_filter((array)($snapshot['lines'] ?? []),
                static fn(array $line): bool => !in_array((int)($line['category_id'] ?? 0), array_map('intval', $resolved), true)));
            if (!$pending) { return; }
            $buffer->add(self::item('expense-estimate:' . $row['id'], 'finance_expense_bill', (int)$row['id'],
                '费用暂估待最终核实', (string)$row['source_reference'],
                self::firstDate($snapshot, ['actual_date', 'benefit_month']), null, '仍有 ' . count($pending) . ' 个费用类别未核实',
                '核实暂估', 'expense_estimate', ['document_id' => (int)$row['document_id'], 'vendor_id' => (int)$row['vendor_id']],
                ['value' => (string)count($pending), 'unit' => '项']));
        });
    }

    private static function deferredAmortizations(TodoItemBuffer $buffer): void
    {
        if (!self::any(['finance.expense.prepare', 'finance.expense.confirm'])) { return; }
        foreach (['o' => ['finance_opening_source', 'source_snapshot'], 'n' => ['finance_source', 'snapshot']] as $kind => [$table, $snapshotColumn]) {
            self::chunks($table, static fn($query) => $query->where('category', 'deferred'),
            static function (array $row) use ($buffer, $kind, $snapshotColumn): void {
                $snapshot = FinanceValue::decode((string)$row[$snapshotColumn]); $reference = $kind . ':' . $row['id'];
                $revision = Db::name('finance_deferred_plan_revision')->where('tenant_id', FinanceAccess::tenant())
                    ->where('source_ref', $reference)->order('id', 'desc')->find();
                if ($revision) { $snapshot = FinanceValue::decode((string)$revision['snapshot'])['plan']; }
                $months = FinanceDeferredAmortizations::months($reference);
                foreach ((array)($snapshot['details']['schedule'] ?? []) as $schedule) {
                    $month = (string)($schedule['month'] ?? '');
                    if ($month === '' || $month > date('Y-m')) { continue; }
                    if (($months[$month]['status'] ?? null) === 'confirmed') { continue; }
                    $buffer->add(self::item('deferred:' . $reference . ':' . $month, 'finance_source', (int)$row['id'],
                        '待摊费用 ' . $month . ' 待摊销', (string)($snapshot['source_reference'] ?? $reference),
                        $month . '-01', $month . '-01', '受益月份已到且尚未确认摊销', '确认摊销', 'deferred_amortization',
                        ['source' => $reference, 'month' => $month, 'subject_id' => (int)$row['subject_id']],
                        ['value' => (string)($schedule['amount'] ?? '0.00'), 'unit' => '元']));
                }
            });
        }
    }

    private static function unclaimedFunds(TodoItemBuffer $buffer): void
    {
        $claimType = match (true) {
            FinanceAccess::has('finance.receipt.prepare') => 'unclaimed_customer_claim',
            FinanceAccess::has('finance.recovery.prepare') => 'unclaimed_recovery_claim',
            FinanceAccess::has('finance.refund.prepare') => 'unclaimed_supplier_refund_claim',
            FinanceAccess::has('finance.equipment.prepare') => 'unclaimed_equipment_refund_claim',
            default => '',
        };
        if ($claimType === '') { return; }
        self::balanceCategory($buffer, ['unclaimed'], '待认领到账', 'unclaimed_fund', ['claim_type' => $claimType]);
    }

    private static function fundReconciliations(TodoItemBuffer $buffer): void
    {
        if (!FinanceAccess::owner()) { return; }
        $month = date('Y-m', strtotime('first day of last month'));
        $activation = (string)Db::name('finance_preparation')->where('tenant_id', FinanceAccess::tenant())->value('activation_date');
        if (Db::name('finance_opening_book')->where('tenant_id', FinanceAccess::tenant())->value('status') !== 'active'
            || $activation === '' || substr($activation, 0, 7) > $month) { return; }
        self::chunks('finance_account', static fn($query) => $query->where('is_enabled', 1),
            static function (array $row) use ($buffer, $month): void {
                $state = FinanceReconciliations::followup((int)$row['id'], $month);
                if ($state['state'] === 'matched') { return; }
                $buffer->add(self::item('account-reconcile:' . $row['id'] . ':' . $month, 'finance_account', (int)$row['id'],
                    $month . ' 账户余额待核对', (string)$row['name'], $month . '-01', date('Y-m-t', strtotime($month . '-01')),
                    '核对状态：' . (string)$state['state'], '核对账户', 'fund_reconciliation', ['account_id' => (int)$row['id'], 'month' => $month]));
            });
        $ledger = new FinanceLedger(FinanceAccess::tenant()); $page = 1;
        do {
            $options = FinanceTransitReviews::options($ledger, ['month' => $month, 'page' => $page]);
            foreach (TodoQueryBudget::candidates($options['transfers']) as $row) {
                if (in_array($row['state'], ['normal', 'settled', 'replaced'], true)) { continue; }
                $buffer->add(self::item('transit-reconcile:' . $row['transfer_source'] . ':' . $month, 'finance_source',
                    (int)substr((string)$row['transfer_source'], 2), $month . ' 在途资金待核对', (string)$row['source_reference'],
                    (string)$row['actual_date'], date('Y-m-t', strtotime($month . '-01')), '核对状态：' . (string)$row['state'],
                    '核对在途资金', 'transit_reconciliation', ['source' => (string)$row['transfer_source'], 'month' => $month],
                    ['value' => (string)$row['remaining_amount'], 'unit' => '元']));
            }
            $page++;
        } while (!empty($options['transfer_has_more']));
    }

    private static function statementDisputes(TodoItemBuffer $buffer): void
    {
        foreach ([
            ['finance_statement_dispute', 'finance_statement_resolution', 'finance_statement', 'customer', 'customer_id'],
            ['finance_supplier_statement_dispute', 'finance_supplier_statement_resolution', 'finance_supplier_statement', 'vendor', 'vendor_id'],
        ] as [$table, $resolution, $statementTable, $subjectKind, $subjectColumn]) {
            if ($subjectKind === 'customer' && !self::any(['finance.receivable.view', 'finance.receivable.prepare'])) { continue; }
            if ($subjectKind === 'vendor' && !FinanceAccess::has('finance.payable.view')) { continue; }
            self::chunks($table, static fn($query) => $query, static function (array $row) use ($buffer, $table, $resolution, $statementTable, $subjectKind, $subjectColumn): void {
                $latest = Db::name($resolution)->where('tenant_id', FinanceAccess::tenant())->where('dispute_id', (int)$row['id'])->order('id', 'desc')->find();
                if ($latest && in_array((string)$latest['resolution'], ['ledger_verified', 'resolved'], true)) { return; }
                $subjectId = (int)Db::name($statementTable)->where('tenant_id', FinanceAccess::tenant())
                    ->where('id', (int)$row['statement_id'])->value($subjectColumn);
                $buffer->add(self::item('statement-dispute:' . $subjectKind . ':' . $row['id'], $table, (int)$row['id'],
                    ($subjectKind === 'vendor' ? '供应商' : '客户') . '对账异议待处理', (string)$row['source_ref'], null, null,
                    (string)$row['reason'], '处理异议', 'statement_dispute',
                    ['dispute_id' => (int)$row['id'], 'statement_id' => (int)$row['statement_id'], 'subject_kind' => $subjectKind,
                        $subjectColumn => $subjectId],
                    ['value' => bcadd((string)$row['amount'], '0', 2), 'unit' => '元']));
            });
        }
    }

    private static function activation(TodoItemBuffer $buffer): void
    {
        if (!FinanceAccess::owner() && !FinanceAccess::has('finance.opening.prepare')) { return; }
        $book = Db::name('finance_opening_book')->where('tenant_id', FinanceAccess::tenant())->find();
        if ($book && (string)$book['status'] === 'active') { return; }
        $status = (string)($book['status'] ?? 'draft');
        $buffer->add(self::item('finance-activation:' . FinanceAccess::tenant(), 'finance_opening_book', FinanceAccess::tenant(),
            '财务账套尚未启用', '当前门店财务启用', null, null, '当前阶段：' . $status,
            '继续财务启用', 'finance_activation', ['status' => $status]));
    }

    private static function periods(TodoItemBuffer $buffer): void
    {
        if (!FinanceAccess::owner()) { return; }
        if (Db::name('finance_opening_book')->where('tenant_id', FinanceAccess::tenant())->value('status') !== 'active') { return; }
        $activation = (string)Db::name('finance_preparation')->where('tenant_id', FinanceAccess::tenant())->value('activation_date');
        $month = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $activation) ? substr($activation, 0, 7) : '';
        $closed = array_fill_keys(Db::name('finance_period')->where('tenant_id', FinanceAccess::tenant())->column('month'), true);
        while ($month !== '' && isset($closed[$month])) { $month = date('Y-m', strtotime($month . '-01 +1 month')); }
        if ($month !== '' && $month < date('Y-m')) {
            $buffer->add(self::item('finance-period:' . $month, 'finance_period', (int)str_replace('-', '', $month),
                $month . ' 月结待完成', '当前门店', $month . '-01', date('Y-m-t', strtotime($month . '-01')),
                '已结束月份尚未完成月结', '处理月结', 'finance_period', ['month' => $month]));
        }

        // finance_period uses (tenant_id, month) as its key, so page by month instead of the generic id keyset.
        $lastMonth = '';
        do {
            $query = Db::name('finance_period')->where('tenant_id', FinanceAccess::tenant());
            if ($lastMonth !== '') { $query->where('month', '>', $lastMonth); }
            $rows = $query->order('month', 'asc')->limit(120)->select()->toArray();
            foreach (TodoQueryBudget::candidates($rows) as $row) {
                $lastMonth = (string)$row['month'];
                $snapshot = FinanceValue::decode((string)$row['snapshot']);
                if (empty($snapshot['unresolved'])) { continue; }
                $followups = FinancePeriodFollowups::read((string)$row['month']);
                foreach ($followups['items'] as $item) {
                    if (($item['current']['status'] ?? '') === 'resolved') { continue; }
                    $original = $item['original'];
                    $todo = self::item('period-followup:' . $row['month'] . ':' . $original['id'], 'finance_period',
                        (int)str_replace('-', '', (string)$row['month']), '历史月结遗留待跟进',
                        (string)($original['title'] ?? $original['reference'] ?? $row['month']),
                        (string)$row['month'] . '-01', null, '当前进度：' . (string)$item['current']['status'],
                        '查看历史遗留', 'finance_period', ['month' => (string)$row['month']]);
                    $todo['dedupe_key'] = self::historicalIdentity($original, (string)$row['month']);
                    $buffer->add($todo);
                }
            }
        } while (count($rows) === 120);
    }

    private static function printRecovery(TodoItemBuffer $buffer): void
    {
        if (!self::any(['settlement.view', 'settlement.bill'])) { return; }
        $owner = FinanceAccess::owner();
        $actor = FinanceAccess::actor();
        $identity = FinanceValue::json([(int)$actor['id'], (string)$actor['type']]);
        self::chunks('finance_sales_print', static function ($query) use ($owner, $identity) {
            $query->where('status', 'pending');
            if (!$owner) { $query->where('actor', $identity); }
            return $query;
        },
            static function (array $row) use ($buffer): void {
                $buffer->add(self::item('finance-print:' . $row['id'], 'finance_sales_print', (int)$row['id'],
                    '销售单打印结果待恢复核实', '销售结算 #' . $row['sales_id'], self::dateFromTime((int)$row['create_time']),
                    null, '服务端存在未回写完成的打印尝试', '核实打印结果', 'print_recovery',
                    ['document_id' => (int)$row['document_id'], 'print_log_id' => (int)$row['id']]));
            });
    }

    /** @param array<string,mixed> $original */
    private static function historicalIdentity(array $original, string $month): string
    {
        $details = (array)($original['details'] ?? []);
        return match ((string)($original['category'] ?? '')) {
            'pending_document' => 'finance-document:' . (int)($details['document_id'] ?? 0),
            'account_reconciliation' => 'account-reconcile:' . (int)($details['account_id'] ?? 0) . ':' . $month,
            'transit_reconciliation' => 'transit-reconcile:' . (string)($details['transfer_source'] ?? '') . ':' . $month,
            'recurring_expense' => 'recurring:' . (int)($details['plan_id'] ?? 0) . ':' . (string)($details['month'] ?? $month),
            'deferred_amortization' => 'deferred:' . (string)($details['source'] ?? '') . ':' . (string)($details['month'] ?? $month),
            'expense_estimate' => 'expense-estimate:' . explode(':', (string)($original['reference'] ?? '0'))[0],
            'purchase_difference' => 'purchase-difference:' . (int)($details['arrival_line_id'] ?? 0),
            'purchase_cost' => 'purchase-arrival:' . (int)($details['arrival_line_id'] ?? 0),
            'purchase_return' => 'purchase-return:' . (int)($details['return_line_id'] ?? 0),
            'inventory_count' => 'inventory-count-review:' . (int)($details['document_id'] ?? 0),
            'inventory_loss' => 'inventory-loss-doc:' . (int)($details['incident_document_id'] ?? 0),
            'statement_dispute' => 'statement-dispute:' . (string)($original['reference'] ?? ''),
            'unclaimed' => 'unclaimed_fund:' . (string)($original['reference'] ?? ''),
            'cost_pending' => 'cost-pending:' . (string)($original['reference'] ?? ''),
            default => 'period-followup:' . $month . ':' . (string)($original['id'] ?? ''),
        };
    }

    /** @param array<int,string> $categories */
    private static function balanceCategory(TodoItemBuffer $buffer, array $categories, string $title, string $target,
        array $targetParams = []): void
    {
        $tenant = FinanceAccess::tenant(); $changes = Db::name('finance_entry')->where('tenant_id', $tenant)
            ->where('metric', 'balance')->field('source_ref,SUM(amount) AS delta')->group('source_ref')->buildSql();
        foreach (['o' => 'finance_opening_source', 'n' => 'finance_source'] as $kind => $table) {
            $last = 0;
            do {
                $date = $kind === 'o' ? 's.activation_date' : 's.business_date';
                $rows = Db::name($table)->alias('s')->leftJoin([$changes => 'b'], "b.source_ref=CONCAT('{$kind}:',s.id)")
                    ->where('s.tenant_id', $tenant)->where('s.id', '>', $last)->whereIn('s.category', $categories)
                    ->whereRaw('s.amount+COALESCE(b.delta,0)>0')->field("s.*,s.amount+COALESCE(b.delta,0) AS balance,{$date} AS current_business_date")
                    ->order('s.id')->limit(500)->select()->toArray();
                foreach (TodoQueryBudget::candidates($rows) as $row) {
                    $last = (int)$row['id']; $snapshot = FinanceValue::decode((string)($row[$kind === 'o' ? 'source_snapshot' : 'snapshot'] ?? ''));
                    $reference = $kind . ':' . $row['id'];
                    $buffer->add(self::item($target . ':' . $reference, $table, (int)$row['id'], $title,
                        (string)($snapshot['source_reference'] ?? $snapshot['reason'] ?? $reference),
                        (string)$row['current_business_date'], null, '已核实资金事实仍有未认领余额', '认领资金', $target,
                        $targetParams + ['source' => $reference, 'account_id' => (int)$row['subject_id']],
                        ['value' => bcadd((string)$row['balance'], '0', 2), 'unit' => '元']));
                }
            } while (count($rows) === 500);
        }
    }

    /** @param callable(object):object $scope @param callable(array):void $consume */
    private static function chunks(string $table, callable $scope, callable $consume): void
    {
        $last = 0;
        do {
            $query = Db::name($table)->where('tenant_id', FinanceAccess::tenant())->where('id', '>', $last);
            $rows = $scope($query)->order('id')->limit(500)->select()->toArray();
            foreach (TodoQueryBudget::candidates($rows) as $row) { $last = (int)$row['id']; $consume($row); }
        } while (count($rows) === 500);
    }

    private static function canConfirm(string $type): bool
    {
        $policy = FinanceDocumentPolicy::TYPES[$type] ?? null;
        if (!$policy) { return false; }
        if (in_array($type, ['salary_payment', 'salary_expense', 'salary_adjustment'], true) && !FinanceAccess::has('finance.salary.view')) { return false; }
        return $policy['owner'] ? FinanceAccess::owner() : ($policy['confirm'] !== '' && FinanceAccess::has($policy['confirm']));
    }

    private static function canPreparePayable(string $category): bool
    {
        $target = self::payableTarget($category);
        if ($target === []) { return false; }
        if ($category === 'salary' && !FinanceAccess::has('finance.salary.view')) { return false; }
        return FinanceAccess::has((string)$target['permission']);
    }

    /** @return array{action:string,subject_kind:string,permission:string}|array{} */
    private static function payableTarget(string $category): array
    {
        return match ($category) {
            'payable', 'expense_payable' => ['action' => 'supplier_payment', 'subject_kind' => 'vendor', 'permission' => 'finance.payment.prepare'],
            'salary' => ['action' => 'salary_payment', 'subject_kind' => 'employee', 'permission' => 'finance.salary.prepare'],
            'reimbursement' => ['action' => 'reimbursement_payment', 'subject_kind' => 'employee', 'permission' => 'finance.reimbursement.prepare'],
            'customer_refund' => ['action' => 'customer_refund', 'subject_kind' => 'customer', 'permission' => 'finance.refund.prepare'],
            'equipment' => ['action' => 'equipment_payment', 'subject_kind' => 'vendor', 'permission' => 'finance.equipment.prepare'],
            default => [],
        };
    }

    /** @param array<int,string> $permissions */
    private static function any(array $permissions): bool
    {
        foreach ($permissions as $permission) { if (FinanceAccess::has($permission)) { return true; } }
        return false;
    }

    /** @param array<int,string> $keys */
    private static function firstDate(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = (string)($data[$key] ?? '');
            if (preg_match('/^\d{4}-\d{2}(?:-\d{2})?$/D', $value)) { return strlen($value) === 7 ? $value . '-01' : $value; }
        }
        return null;
    }

    private static function dateFromTime(int $time): ?string { return $time > 0 ? date('Y-m-d', $time) : null; }

    /** @return array<string,mixed> */
    private static function item(string $key, string $sourceType, int $sourceId, string $title, string $object,
        ?string $businessDate, ?string $dueDate, string $reason, string $action, string $targetType, array $params,
        ?array $remaining = null): array
    {
        $item = ['key' => $key, 'kind' => $sourceType, 'source' => ['type' => $sourceType, 'id' => $sourceId],
            'title' => $title, 'business_object' => $object, 'business_date' => $businessDate,
            'due_date' => $dueDate, 'reason' => $reason, 'action' => $action,
            'target' => ['type' => $targetType, 'params' => $params]];
        if ($remaining !== null) { $item['remaining'] = $remaining; }
        return $item;
    }
}
