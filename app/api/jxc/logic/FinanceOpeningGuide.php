<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 门店首次引导的事实读取。候选不代替确认，也不在读取中写入业务资料。 */
final class FinanceOpeningGuide
{
    public const CARDS = [
        'account' => ['经营资金', '店里还有可用的钱吗？包括现金和经营用账户里的钱，之后收付从这里累加或扣减。'],
        'inventory' => ['现有库存', '仓库里还有哪些货？核对现在剩余数量和历史成本，采购过多少不等于现在还有多少。'],
        'receivable' => ['客户欠款', '还有客户欠店里的钱吗？核对已经做完生意但没收齐的金额。'],
        'payable' => ['欠供应商货款', '还有货款没付给供应商吗？接着付旧账，不重新记采购或入库。'],
        'advance' => ['客户预存', '客户提前给的钱，还有没用来抵货款的吗？这笔到账已经包含在经营资金中。'],
        'customer_refund' => ['应退客户款', '已答应退给客户、但还没实际退的钱有吗？'],
        'supplier_refund' => ['供应商待退款', '供应商已认可、还没退给店里的钱有吗？没有认可的争议要保留待核实。'],
        'expense_payable' => ['费用待付款', '水电、租金、运输等旧费用，还有没付的吗？'],
        'salary' => ['工资待发', '员工以前的工资，还有没发完的吗？按员工和所属月份核对。'],
        'reimbursement' => ['员工垫付', '员工替店里付的钱，还有没报销的吗？'],
        'deferred' => ['分月费用', '已支付或确认的费用，还有属于今后月份的吗？核对未计入金额与剩余服务计划。'],
        'expense_refund' => ['费用待退款', '以前的费用已经同意退款，但钱还没收到吗？'],
        'equipment' => ['设备未付款', '已买的设备，还有多少钱没付？核对已付、取消部分和剩余额度。'],
        'equipment_refund' => ['设备待退款', '设备退款已经说定，但还有没到账的吗？'],
        'transit' => ['转账未到账', '自己的账户之间，有钱转出后还没到账或退回吗？'],
        'unclaimed' => ['不明到账', '有没有已经到账，但还不知道是谁付的钱？这笔到账已经包含在账户余额中。'],
        'recovery' => ['旧坏账追讨', '以前已记作收不回的欠款，还有在追讨的吗？与普通客户欠款分开。'],
        'excluded' => ['其他未处理事项', '除了以上项目，还有没处理完的旧账吗？描述真实事项，未知和未支持的事项须先核清。'],
        'capital' => ['老板投入与取回', '老板投入、取回的经营资金，有需要接续的余额或未完记录吗？约定归还的钱应列为借款。'],
        'loan' => ['尚未还清的借款', '店里还有借来的钱没还吗？只核对剩余本金，利息另按费用处理。'],
        'supplier_advance' => ['供应商预付款', '提前付给供应商的钱，还有没抵货款或没退回的吗？承接余额不会再次付款。'],
    ];

    private const BUSINESS_TABLES = [
        'sales_order', 'sales_order_item', 'sales_return_order', 'sales_return_order_item', 'sales_settlement',
        'customer_report', 'customer_report_item', 'supply_order', 'supply_order_item', 'purchase_plan', 'purchase_plan_item',
        'purchase_batch', 'purchase_batch_item', 'purchase_arrival', 'purchase_arrival_line', 'purchase_return_order',
        'purchase_return_order_item', 'stock_flow', 'stock_transfer', 'stock_transfer_item', 'inventory_check',
        'customer_receipt', 'supplier_payment', 'customer_payment', 'fulfillment_task', 'fulfillment_change', 'pending_goods',
    ];
    // 档案、发布规则和操作日志不是经营事实；有业务草稿则仍须核清，不能静默删除。
    private const FINANCE_METADATA = [
        'finance_preparation', 'finance_account', 'finance_setup_action', 'finance_opening_book', 'finance_opening_item',
        'finance_opening_item_detail', 'finance_command', 'finance_evidence', 'finance_report_export', 'finance_report_export_attempt',
        'finance_purchase_rule', 'finance_purchase_rule_book', 'finance_sales_rule', 'finance_sales_pricing_preference',
        'finance_expense_category', 'finance_transaction_identity',
    ];

    private ?array $schema = null;
    private array $candidateCache = [];
    public function __construct(private readonly int $tenantId) {}

    /** 只在准备行和库存锁内用于零启用；读取失败直接失败，不能把失败解释为空白。 */
    public function zeroConflicts(): array
    {
        $conflicts = [];
        foreach ($this->businessTables() as $table) {
            $count = (int)Db::name($table)->where('tenant_id', $this->tenantId)->count();
            if ($count) { $conflicts[] = ['entity_type' => 'existing_business', 'entity_id' => null, 'entity_name' => $this->tableLabel($table), 'source_table' => $table, 'count' => $count]; }
        }
        foreach (['finance_opening_item', 'finance_opening_source'] as $table) {
            if (isset($this->columns()[$table])) {
                $count = (int)Db::name($table)->where('tenant_id', $this->tenantId)->count();
                if ($count) { $conflicts[] = ['entity_type' => 'opening_item', 'entity_id' => null, 'entity_name' => '已录入的期初资料', 'source_table' => $table, 'count' => $count]; }
            }
        }
        foreach ($this->balanceCandidates() as $category => $candidates) {
            foreach ($candidates as $candidate) {
                // 仅创建账户档案不会形成余额，也不阻断真正空白店。
                if ($category === 'account') { continue; }
                $conflicts[] = $candidate + ['category' => $category, 'count' => 1];
            }
        }
        return $conflicts;
    }

    /** 旧经营继续办理与“不能零起步”是两种边界；财务或期初草稿不开放旧业务旁路。 */
    public function legacyOperatingAllowed(): bool
    {
        $columns = $this->columns();
        foreach (['stock_flow', 'purchase_arrival', 'receivable_flow', 'payable_flow'] as $table) {
            if (isset($columns[$table]) && in_array('tenant_id', $columns[$table], true)
                && Db::name($table)->where('tenant_id', $this->tenantId)->find()) { return true; }
        }
        foreach (['sales_order', 'supply_order', 'sales_return_order', 'purchase_return_order'] as $table) {
            if (isset($columns[$table]) && in_array('status', $columns[$table], true)
                && Db::name($table)->where('tenant_id', $this->tenantId)->where('status', 1)->find()) { return true; }
        }
        foreach (['warehouse_sku_balance' => 'on_hand_qty', 'goods' => 'stock', 'customer' => 'order_receivable', 'vendor' => 'order_payable'] as $table => $field) {
            if (in_array($field, $columns[$table] ?? [], true)
                && Db::name($table)->where('tenant_id', $this->tenantId)->where($field, '<>', 0)->find()) { return true; }
        }
        return false;
    }

    /** 捕获完整事实基线，期初确认前再次读取，检测准备期间新增/更改经营事实。 */
    public function businessBasis(): string
    {
        $basis = [];
        foreach ($this->businessTables() as $table) {
            $query = Db::name($table)->where('tenant_id', $this->tenantId);
            if (in_array('id', $this->columns()[$table], true)) { $query->order('id'); }
            $rows = $query->limit(20001)->select()->toArray();
            if (count($rows) > 20000) { throw new \DomainException('门店历史资料超过自动核对容量，请先分段核实来源'); }
            $basis[$table] = $rows;
        }
        $basis['balances'] = $this->balanceCandidates();
        return hash('sha256', json_encode($basis, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function candidates(array $snapshot): array
    {
        $result = $this->balanceCandidates($snapshot['activation_date'] ?? null);
        foreach ($snapshot['items'] as $item) {
            $result[$item['category']][] = $item + ['entity_type' => 'opening_item', 'entity_id' => (int)$item['id'], 'entity_name' => $item['subject_name']];
        }
        return $result;
    }

    public function candidateBasis(array $snapshot, string $category): string
    {
        $candidates = $this->balanceCandidates($snapshot['activation_date'] ?? null);
        return hash('sha256', json_encode($candidates[$category] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** 已交付但金额尚未核定的旧销售不能用“没有欠款”绕过承接。 */
    public function settlementBlockers(): array
    {
        $errors = [];
        if (in_array('settlement_status', $this->columns()['sales_order'] ?? [], true)) {
            $query = Db::name('sales_order')->where('tenant_id', $this->tenantId)->whereIn('settlement_status', ['pending', 'pending_weight_review']);
            foreach ($query->order('id')->limit(21)->select()->toArray() as $row) {
                $errors[] = ['code' => 'opening_legacy_settlement_unresolved', 'message' => '旧销售 #' . $row['id'] . ' 的结算数量或金额尚未核清', 'category' => 'receivable', 'entity_type' => 'sales_order', 'entity_id' => (int)$row['id'], 'entity_name' => $row['order_sn'] ?? null];
            }
        }
        $date = (string)Db::name('finance_preparation')->where('tenant_id', $this->tenantId)->value('activation_date');
        if ($date && isset($this->columns()['supply_order'])) {
            foreach (FinancePurchaseLegacySources::openingBlockers($this->tenantId, $date) as $detail) {
                $errors[] = ['code' => $detail['code'], 'message' => '历史采购 ' . $detail['name'] . '：' . $detail['message'],
                    'category' => $detail['category'], 'entity_type' => $detail['entity_type'], 'entity_id' => $detail['id'], 'entity_name' => $detail['name']];
            }
        }
        return $errors;
    }

    public function cards(array $snapshot, array $salaryAccess): array
    {
        $categories = array_column($snapshot['categories'], null, 'key');
        $candidates = $this->candidates($snapshot);
        $cards = [];
        foreach (self::CARDS as $key => [$title, $description]) {
            $category = $categories[$key] ?? ['review' => ['state' => 'unknown'], 'count' => 0];
            $review = $category['review'];
            $view = $key !== 'salary' || $salaryAccess['view'];
            $edit = $key !== 'salary' || $salaryAccess['prepare'];
            $rows = $candidates[$key] ?? [];
            if ($key === 'account' && $rows) {
                // 操作权限仅用于呈现，不能进入 candidateBasis/businessBasis 使核对依据随阅读者变化。
                $lifecycle = new FinanceAccountLifecycle($this->tenantId);
                $canManage = FinanceAccess::owner();
                $accounts = Db::name('finance_account')->where('tenant_id', $this->tenantId)->column('*', 'id');
                foreach ($rows as &$candidate) {
                    if (!isset($accounts[$candidate['subject_id']])) { continue; }
                    $metadata = $lifecycle->project($accounts[$candidate['subject_id']], $canManage);
                    $candidate += array_intersect_key($metadata, array_flip(['account_type', 'is_enabled', 'version', 'can_delete', 'can_disable', 'deletion_blocked_reason', 'has_opening_draft', 'opening_version']));
                }
                unset($candidate);
            }
            $choice = $review['choice'] ?? match ($review['state']) { 'none' => 'none', 'complete' => 'complete', 'unresolved' => 'unknown', default => null };
            $state = match ($review['state']) { 'none' => 'none', 'complete' => 'complete', 'unresolved' => 'pending_supplement', default => $choice === 'has' ? 'pending_input' : ($rows ? 'pending_review' : 'pending_choice') };
            $blockerDetails = array_values(array_filter($snapshot['blocker_details'] ?? [], static fn(array $row): bool => ($row['category'] ?? null) === $key));
            $blockers = array_column($blockerDetails, 'message');
            // 旧基线失效时即使曾核对过，也必须再次核对该卡。
            if (in_array($state, ['complete', 'none'], true) && $blockers) { $state = 'pending_review'; }
            $cards[] = ['key' => $key, 'category' => $key, 'title' => $title, 'description' => $description,
                'state' => $view ? $state : 'restricted', 'choice' => $view ? $choice : null,
                'candidate_count' => $view ? count($rows) : null, 'candidates' => $view ? $rows : [],
                'count' => $view ? (int)$category['count'] : null, 'can_view' => $view, 'can_edit' => $edit,
                'evidence' => $view ? ($review['evidence'] ?? '') : '',
                'blocked_reason' => $view ? implode('；', $blockers) : '需由有工资权限人员核对',
                'blocker_details' => $view ? $blockerDetails : [],
                'supported' => FinanceOpeningCategory::supported($key),
            ] + FinanceOpeningCategory::metadata($key);
        }
        return $cards;
    }

    private function balanceCandidates(?string $date = null): array
    {
        $cacheKey = $date ?? '';
        if (array_key_exists($cacheKey, $this->candidateCache)) { return $this->candidateCache[$cacheKey]; }
        $result = [];
        if (isset($this->columns()['finance_account'])) {
            foreach (Db::name('finance_account')->where('tenant_id', $this->tenantId)->order('id')->select()->toArray() as $row) {
                $result['account'][] = ['entity_type' => 'opening_subject', 'entity_id' => (int)$row['id'], 'subject_id' => (int)$row['id'], 'entity_name' => $row['name'], 'amount' => null, 'source_reference' => '资金账户 #' . $row['id']];
            }
        }
        if (isset($this->columns()['warehouse_sku_balance'])) {
            $stocks = FinanceOpeningAssets::stockAtCutoff($this->tenantId, $date);
            $names = FinanceOpeningAssets::inventoryNames($this->tenantId, array_column($stocks, 'id'));
            foreach ($stocks as $row) {
                if (bccomp((string)$row['cutoff_qty'], '0', 4) === 0 && bccomp((string)($row['reserved_qty'] ?? '0'), '0', 4) === 0) { continue; }
                $result['inventory'][] = ['entity_type' => 'opening_subject', 'entity_id' => (int)$row['id'], 'subject_id' => (int)$row['id'], 'entity_name' => $names[$row['id']] ?? ('库存 #' . $row['id']), 'amount' => null, 'quantity' => $row['cutoff_qty'], 'source_reference' => '截点库存 #' . $row['id']];
            }
        }
        foreach ([['customer', 'receivable', 'order_receivable', 'customer_name'], ['vendor', 'payable', 'order_payable', 'supplier_name'], ['goods', 'inventory', 'stock', 'name']] as [$table, $category, $field, $name]) {
            if (!in_array($field, $this->columns()[$table] ?? [], true)) { continue; }
            $rows = Db::name($table)->where('tenant_id', $this->tenantId)->where($field, '<>', 0)->order('id')->select()->toArray();
            foreach ($rows as $row) {
                $result[$category][] = ['entity_type' => $table === 'goods' ? 'legacy_inventory' : 'opening_subject', 'entity_id' => (int)$row['id'], 'subject_id' => $table === 'goods' ? null : (int)$row['id'],
                    'entity_name' => $row[$name], 'amount' => $table === 'goods' ? null : (string)$row[$field], 'quantity' => $table === 'goods' ? (string)$row[$field] : null, 'source_reference' => '原系统' . self::CARDS[$category][0] . ' #' . $row['id']];
            }
        }
        return $this->candidateCache[$cacheKey] = $result;
    }

    private function businessTables(): array
    {
        $tables = [];
        foreach ($this->columns() as $table => $columns) {
            if (!in_array('tenant_id', $columns, true)) { continue; }
            if (in_array($table, self::BUSINESS_TABLES, true) || (str_starts_with($table, 'finance_') && !in_array($table, self::FINANCE_METADATA, true) && !str_starts_with($table, 'finance_opening_') && !str_contains($table, '_rule') && !str_contains($table, '_preference'))) { $tables[] = $table; }
        }
        sort($tables);
        return $tables;
    }

    private function columns(): array
    {
        if ($this->schema !== null) { return $this->schema; }
        $prefix = (string)config('database.connections.mysql.prefix', 'la_');
        $rows = Db::query('SELECT TABLE_NAME AS table_name,COLUMN_NAME AS column_name FROM information_schema.columns WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION');
        $this->schema = [];
        foreach ($rows as $row) {
            if (!str_starts_with($row['table_name'], $prefix)) { continue; }
            $this->schema[substr($row['table_name'], strlen($prefix))][] = $row['column_name'];
        }
        return $this->schema;
    }

    private function tableLabel(string $table): string
    {
        return match (true) { str_contains($table, 'purchase') || str_contains($table, 'supply') => '已有采购、到货或退货资料', str_contains($table, 'sales') || str_contains($table, 'customer_report') => '已有销售、交付或客户报货资料', str_contains($table, 'stock') || str_contains($table, 'inventory') => '已有库存变动资料', str_starts_with($table, 'finance_') => '已有财务单据、余额或未完计划', default => '已有经营业务或草稿' };
    }
}
