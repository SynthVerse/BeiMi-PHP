<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 只在 FinanceSetupLogic 的门店事务锁内写入；期初凭据与正式确认原子提交。 */
final class FinanceOpeningService
{
    public const CATEGORIES = [
        'account' => '资金账户余额', 'receivable' => '客户应收', 'payable' => '采购应付',
        'expense_payable' => '费用应付', 'salary' => '工资待付', 'reimbursement' => '员工垫付待付',
        'advance' => '客户预收', 'customer_refund' => '客户应退款', 'supplier_refund' => '供应商退款待收',
        'expense_refund' => '费用退款待收', 'equipment_refund' => '设备退款待收', 'deferred' => '待摊费用',
        'transit' => '账户互转在途', 'unclaimed' => '待认领收款', 'inventory' => '库存数量与价值',
        'recovery' => '坏账追偿备查', 'equipment' => '设备剩余付款额度', 'excluded' => '本期范围外业务',
    ];
    public function __construct(private readonly int $tenantId, private readonly array $actor) {}
    private bool $lockStock = false;

    public function snapshot(): array
    {
        $book = $this->book();
        if ($book['status'] === 'active') {
            return json_decode($book['confirmed_snapshot'], true, 512, JSON_THROW_ON_ERROR);
        }
        $items = $this->items();
        $preparation = Db::name('finance_preparation')->where('tenant_id', $this->tenantId)->find() ?: [];
        $reviews = json_decode($book['reviews'], true, 512, JSON_THROW_ON_ERROR);
        $categories = [];
        foreach (self::CATEGORIES as $key => $title) {
            $rows = array_values(array_filter($items, static fn(array $item): bool => $item['category'] === $key));
            $total = '0.00';
            $unknown = 0;
            foreach ($rows as $row) {
                if ($row['amount'] === null) { $unknown++; } else { $total = bcadd($total, $row['amount'], 2); }
            }
            $categories[] = [
                'key' => $key, 'title' => $title, 'supported' => FinanceOpeningCategory::supported($key),
                'review' => $reviews[$key] ?? ['state' => 'unknown', 'evidence' => ''],
                'count' => count($rows), 'total' => $unknown ? null : $total, 'unknown_count' => $unknown,
            ];
        }
        return [
            'tenant_id' => $this->tenantId, 'status' => $book['status'], 'version' => (int)$book['version'],
            'activation_date' => $preparation['activation_date'] ?? null,
            'preparation_version' => (int)($preparation['version'] ?? 0),
            'categories' => $categories, 'items' => $items,
            'blockers' => $this->blockers($preparation, $categories, $items),
            'activation_available' => in_array($this->tenantId, (array)config('finance.activation_tenant_ids', []), true),
            'created_by' => json_decode($book['created_by'], true),
            'last_modified_by' => json_decode($book['last_modified_by'], true),
            'create_time' => (int)$book['create_time'], 'update_time' => (int)$book['update_time'],
            'confirmed_at' => 0,
        ];
    }

    public function subjects(array $params): array
    {
        $category = self::text($params['category'] ?? '', 32);
        if (!FinanceOpeningCategory::supported($category)) { throw new \DomainException('该类别暂不支持明细录入'); }
        $keyword = self::text($params['keyword'] ?? '', 60);
        $page = max(1, self::id($params['page'] ?? 1));
        if ($category === 'inventory') {
            $rows = FinanceOpeningAssets::inventorySubjects($this->tenantId, $keyword, $page);
            return ['tenant_id' => $this->tenantId, 'lists' => $rows, 'page' => $page, 'has_more' => count($rows) === 20];
        }
        [$table, $name] = FinanceOpeningCategory::subject($category);
        $query = Db::name($table)->where('tenant_id', $this->tenantId);
        if ($table === 'customer') { $query->where('parent_id', 0); }
        $keyword = self::text($params['keyword'] ?? '', 60);
        if ($keyword !== '') { $query->whereLike($name, '%' . addcslashes($keyword, '%_\\') . '%'); }
        $page = max(1, self::id($params['page'] ?? 1));
        $rows = $query->field('id,' . $name . ' AS name')->order('id')->page($page, 20)->select()->toArray();
        return ['tenant_id' => $this->tenantId, 'lists' => $rows, 'page' => $page, 'has_more' => count($rows) === 20];
    }

    public function execute(string $action, array $data, int $version): array
    {
        if ($action === 'confirm') { FinanceOpeningAssets::lockInventory($this->tenantId); $this->lockStock = true; }
        $book = $this->book();
        if ((int)$book['version'] !== $version) { throw new \DomainException('期初资料已更新，请重新加载后核对'); }
        if ($book['status'] === 'active') { throw new \DomainException('期初已确认，不允许覆盖或删除历史'); }
        if (!in_array($action, ['confirm', 'reopen'], true) && $book['status'] !== 'draft') {
            throw new \DomainException('期初正在等待确认，请先退回草稿再修改');
        }
        $before = $this->snapshot();
        switch ($action) {
            case 'item':
                $this->saveItem($data);
                $reviews = json_decode($book['reviews'], true);
                unset($reviews[self::text($data['category'], 32)]);
                $book['reviews'] = self::json($reviews);
                break;
            case 'remove':
                $id = self::id($data['id'] ?? 0);
                $removed = Db::name('finance_opening_item')->where('tenant_id', $this->tenantId)->where('id', $id)->find();
                if (!Db::name('finance_opening_item')->where('tenant_id', $this->tenantId)->where('id', $id)->delete()) {
                    throw new \DomainException('期初明细不存在或不属于当前门店');
                }
                Db::name('finance_opening_item_detail')->where('tenant_id', $this->tenantId)->where('opening_item_id', $id)->delete();
                $reviews = json_decode($book['reviews'], true);
                unset($reviews[$removed['category']]);
                $book['reviews'] = self::json($reviews);
                break;
            case 'review':
                $key = self::text($data['category'] ?? '', 32);
                $state = self::text($data['state'] ?? '', 16);
                $allowed = FinanceOpeningCategory::supported($key) ? ['unknown', 'none', 'complete'] : ['unknown', 'none', 'unresolved'];
                if (!isset(self::CATEGORIES[$key]) || !in_array($state, $allowed, true)) {
                    throw new \DomainException('请选择有效的类别核对状态');
                }
                $evidence = self::text($data['evidence'] ?? '', 1000);
                if ($state !== 'unknown' && $evidence === '') { throw new \DomainException('请填写核对依据或尚待解决的事项'); }
                $reviews = json_decode($book['reviews'], true, 512, JSON_THROW_ON_ERROR);
                $reviews[$key] = ['state' => $state, 'evidence' => $evidence];
                $book['reviews'] = self::json($reviews);
                break;
            case 'submit':
                $this->requireReady($before);
                $book['status'] = 'pending';
                $book['submitted_hash'] = $this->basisHash($before);
                break;
            case 'reopen':
                if ($book['status'] !== 'pending') { throw new \DomainException('只有待确认资料可以退回草稿'); }
                $book['status'] = 'draft'; $book['submitted_hash'] = '';
                break;
            case 'confirm':
                if ($book['status'] !== 'pending') { throw new \DomainException('请先提交期初资料，再确认启用'); }
                $this->requireReady($before);
                if (!$before['activation_available']) { throw new \DomainException('该门店尚未完成财务链路与迁移验收，暂不能正式启用'); }
                if (!hash_equals($book['submitted_hash'], $this->basisHash($before))) {
                    throw new \DomainException('待确认的日期、账户或来源已变化，请退回草稿重新核对提交');
                }
                foreach ($before['items'] as $item) {
                    Db::name('finance_opening_source')->insert([
                        'tenant_id' => $this->tenantId, 'opening_item_id' => $item['id'], 'category' => $item['category'],
                        'subject_id' => $item['subject_id'], 'amount' => $item['amount'],
                        'activation_date' => $before['activation_date'], 'source_snapshot' => self::json($item), 'create_time' => time(),
                    ]);
                }
                $book['status'] = 'active'; $book['confirmed_at'] = time();
                break;
            default: throw new \DomainException('不支持的期初操作');
        }
        $book['version'] = $version + 1;
        $book['update_time'] = time();
        $book['last_modified_by'] = self::json($this->actor);
        if (!$book['create_time']) { $book['create_time'] = time(); $book['created_by'] = self::json($this->actor); }
        if ($action === 'confirm') {
            $confirmed = array_merge($before, [
                'status' => 'active', 'version' => $book['version'], 'confirmed_at' => $book['confirmed_at'],
                'confirmed_by' => $this->actor, 'last_modified_by' => $this->actor, 'update_time' => $book['update_time'],
            ]);
            $book['confirmed_snapshot'] = self::json($confirmed);
        }
        if ($version > 0) {
            Db::name('finance_opening_book')->where('tenant_id', $this->tenantId)->update($book);
        } else { Db::name('finance_opening_book')->insert($book); }
        if ($action === 'confirm') {
            $confirmed['cost_bootstrap'] = FinanceCostBootstrap::withinTransaction($this->tenantId, $before['activation_date']);
            Db::name('finance_opening_book')->where('tenant_id', $this->tenantId)->update(['confirmed_snapshot' => self::json($confirmed)]);
        }
        return [$before, $this->snapshot()];
    }

    private function saveItem(array $data): void
    {
        $category = self::text($data['category'] ?? '', 32);
        if (!FinanceOpeningCategory::supported($category)) { throw new \DomainException('该类别的非零期初承接尚未开放，请记录为待解决事项'); }
        $id = self::id($data['id'] ?? 0);
        $subjectId = self::id($data['subject_id'] ?? 0);
        if (!$this->subject($category, $subjectId)) { throw new \DomainException('对象不存在、不属于当前门店或不是主客户'); }
        $amount = $data['amount'] ?? null;
        if ($amount !== null && $amount !== '') {
            if (!is_string($amount) || !preg_match('/^(0|[1-9][0-9]{0,11})(\.[0-9]{1,2})?$/D', $amount)) {
                throw new \DomainException('金额须为非负数，最多两位小数；未知金额请留空');
            }
            $amount = bcadd($amount, '0', 2);
            if (!in_array($category, ['account', 'inventory', 'equipment'], true) && bccomp($amount, '0', 2) <= 0) { throw new \DomainException('剩余余额必须大于零，无余额请在类别核对中说明'); }
        } else { $amount = null; }
        $mode = self::text($data['source_mode'] ?? '', 16);
        if (!in_array($mode, ['detail', 'summary'], true)) { throw new \DomainException('请标明历史明细或核实汇总'); }
        if ($mode === 'summary' && !FinanceOpeningCategory::allowsSummary($category)) {
            throw new \DomainException('该类型须保留逐笔合法来源，不支持汇总期初');
        }
        $details = FinanceOpeningCategory::normalizeDetails($category, $data['details'] ?? []);
        $reference = self::text($data['source_reference'] ?? '', 200);
        $evidence = self::text($data['evidence'] ?? '', 1000);
        if ($reference === '' || $evidence === '') { throw new \DomainException('请填写期初来源标识和核对依据，不补造历史交易'); }
        if (!array_key_exists('historical_date', $data) || !array_key_exists('due_date', $data)) {
            throw new \DomainException('请明确历史日期与付款日，未知时标记不详或未约定');
        }
        $row = [
            'tenant_id' => $this->tenantId, 'category' => $category, 'subject_id' => $subjectId, 'amount' => $amount,
            'historical_date' => self::date($data['historical_date']), 'due_date' => self::date($data['due_date']),
            'source_mode' => $mode, 'source_reference' => $reference, 'evidence' => $evidence,
        ];
        $duplicate = Db::name('finance_opening_item')->where('tenant_id', $this->tenantId)->where('category', $category)
            ->where('subject_id', $subjectId)->where('id', '<>', $id);
        if (!in_array($category, ['account', 'inventory'], true)) { $duplicate->where('source_reference', $reference); }
        if ($duplicate->count()) { throw new \DomainException('该账户或同一对象的期初来源已存在，请修改原明细'); }
        if ($category !== 'account') {
            foreach ($this->items() as $other) {
                if ($other['category'] !== $category || (int)$other['subject_id'] !== $subjectId || (int)$other['id'] === $id) { continue; }
                if (FinanceOpeningCategory::hasBenefitMonth($category) && ($other['details']['benefit_month'] ?? '') !== ($details['benefit_month'] ?? '')) { continue; }
                if ($mode === 'summary' || $other['source_mode'] === 'summary') {
                    throw new \DomainException('同一对象及受益月份不能混用汇总期初和其他明细，请核对后保留一种承接方式');
                }
            }
        }
        if ($id > 0) {
            $existing = Db::name('finance_opening_item')->where('tenant_id', $this->tenantId)->where('id', $id)->find();
            if (!$existing || $existing['category'] !== $category) { throw new \DomainException('期初明细不存在或类别不匹配'); }
            Db::name('finance_opening_item')->where('tenant_id', $this->tenantId)->where('id', $id)->update($row);
        } else { $id = (int)Db::name('finance_opening_item')->insertGetId($row); }
        Db::name('finance_opening_item_detail')->where('tenant_id', $this->tenantId)->where('opening_item_id', $id)->delete();
        if ($details) {
            Db::name('finance_opening_item_detail')->insert(['tenant_id' => $this->tenantId, 'opening_item_id' => $id, 'details' => self::json($details)]);
        }
    }

    private function blockers(array $preparation, array $categories, array $items): array
    {
        $errors = [];
        $date = $preparation['activation_date'] ?? null;
        if (!$date) { $errors[] = '请先保存财务启用日期'; }
        foreach (['inventory_cost_reviewed', 'legacy_settlement_reviewed', 'excluded_business_reviewed'] as $flag) {
            if (empty($preparation[$flag])) { $errors[] = '启用准备中仍有未完成的旧账核对'; break; }
        }
        foreach ($categories as $category) {
            $state = $category['review']['state'];
            if (in_array($state, ['unknown', 'unresolved'], true)) { $errors[] = $category['title'] . '尚未核清或承接'; }
            if ($state === 'none' && $category['count'] > 0) { $errors[] = $category['title'] . '标记无余额但仍有明细'; }
            if ($state === 'complete' && $category['count'] === 0) { $errors[] = $category['title'] . '尚未录入明细，无余额请明确核对'; }
        }
        foreach ($items as $item) {
            $label = self::CATEGORIES[$item['category']] . ' #' . $item['id'];
            if ($item['amount'] === null) { $errors[] = $label . '金额尚未核实'; }
            if (!$item['subject_name']) { $errors[] = $label . '对象已失效'; }
            if ($date && $item['historical_date'] && $item['historical_date'] >= $date) { $errors[] = $label . '历史日期必须早于启用日期'; }
            foreach (FinanceOpeningCategory::metadata($item['category'])['detail_fields'] as $field) {
                $value = $item['details'][$field['key']] ?? '';
                if (($field['required'] ?? true) && ($value === '' || $value === [])) { $errors[] = $label . $field['label'] . '尚未核实'; }
                if ($field['key'] === 'benefit_month' && $value !== '' && $date && $value > (new \DateTimeImmutable($date))->modify('-1 day')->format('Y-m')) {
                    $errors[] = $label . '原受益月份不能晚于期初截点';
                }
            }
            foreach (FinanceOpeningAssets::blockers($this->tenantId, $item, $date) as $error) { $errors[] = $label . $error; }
        }
        $accountIds = array_column(array_filter($items, static fn(array $item): bool => $item['category'] === 'account'), 'subject_id');
        foreach (Db::name('finance_account')->where('tenant_id', $this->tenantId)->column('id') as $id) {
            if (!in_array((int)$id, array_map('intval', $accountIds), true)) { $errors[] = '资金账户 #' . $id . '缺少核实余额，零余额也须录入'; }
        }
        $stockIds = array_map('intval', array_column(array_filter($items, static fn(array $item): bool => $item['category'] === 'inventory'), 'subject_id'));
        foreach (FinanceOpeningAssets::stockAtCutoff($this->tenantId, $date, $this->lockStock) as $stock) {
            if (bccomp($stock['cutoff_qty'], '0', 4) !== 0 && !in_array((int)$stock['id'], $stockIds, true)) { $errors[] = '存在截点实物库存 #' . $stock['id'] . '，须完成仓库与 SKU 历史成本承接后启用'; }
        }
        return array_merge($errors, FinanceOpeningAssets::payableBlockers($items));
    }

    private function items(): array
    {
        $items = Db::name('finance_opening_item')->where('tenant_id', $this->tenantId)->order('id')->select()->toArray();
        $details = Db::name('finance_opening_item_detail')->where('tenant_id', $this->tenantId)->column('details', 'opening_item_id');
        foreach ($items as &$item) {
            $subject = $this->subject($item['category'], (int)$item['subject_id']);
            $item['subject_name'] = $subject['name'] ?? null;
            $item['subject_snapshot'] = $subject;
            $item['details'] = isset($details[$item['id']]) ? json_decode($details[$item['id']], true, 512, JSON_THROW_ON_ERROR) : [];
        }
        return $items;
    }

    private function subject(string $category, int $id): ?array
    {
        if ($category === 'inventory') {
            $date = Db::name('finance_preparation')->where('tenant_id', $this->tenantId)->value('activation_date');
            return FinanceOpeningAssets::inventorySubject($this->tenantId, $id, $date, $this->lockStock);
        }
        [$table, $name] = FinanceOpeningCategory::subject($category);
        $query = Db::name($table)->where('tenant_id', $this->tenantId)->where('id', $id);
        if ($table === 'customer') { $query->where('parent_id', 0); }
        $fields = 'id,' . $name . ' AS name';
        if ($table === 'finance_account') { $fields .= ',version,is_enabled,account_type'; }
        return $query->field($fields)->find();
    }

    private function book(): array
    {
        return Db::name('finance_opening_book')->where('tenant_id', $this->tenantId)->find() ?: [
            'tenant_id' => $this->tenantId, 'status' => 'draft', 'version' => 0, 'reviews' => '{}',
            'submitted_hash' => '', 'confirmed_snapshot' => '{}', 'created_by' => 'null', 'last_modified_by' => 'null',
            'create_time' => 0, 'update_time' => 0, 'confirmed_at' => 0,
        ];
    }

    private function requireReady(array $snapshot): void
    {
        if ($snapshot['blockers']) { throw new \DomainException(implode('；', array_slice($snapshot['blockers'], 0, 3))); }
    }

    private function basisHash(array $snapshot): string
    {
        return hash('sha256', self::json([$snapshot['preparation_version'], $snapshot['activation_date'], $snapshot['categories'], $snapshot['items']]));
    }

    private static function date(mixed $value): ?string
    {
        if ($value === null || $value === '') { return null; }
        $value = self::text($value, 10);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value || $value < '1900-01-01' || $value > '2099-12-31') {
            throw new \DomainException('日期无效，历史日期不详时请留空');
        }
        return $value;
    }

    private static function id(mixed $value): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^(0|[1-9][0-9]{0,9})$/D', (string)$value)) {
            throw new \DomainException('对象或分页标识不正确');
        }
        return (int)$value;
    }

    private static function text(mixed $value, int $limit): string
    {
        if (!is_string($value) || mb_strlen(trim($value)) > $limit) { throw new \DomainException('填写内容格式或长度不正确'); }
        return trim($value);
    }

    private static function json(array $data): string { return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); }
}
