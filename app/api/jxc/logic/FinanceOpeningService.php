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
        'capital' => '经营投入剩余额度', 'loan' => '借款剩余本金', 'supplier_advance' => '供应商预付款',
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
        $blockerDetails = $this->blockerDetails($preparation, $categories, $items);
        $unfinished = [];
        foreach (($reviews['_editors'] ?? []) as $drafts) {
            foreach ($drafts as $category => $draft) { $unfinished[$category] = ($unfinished[$category] ?? 0) + 1; }
        }
        foreach ($unfinished as $category => $count) { $blockerDetails[] = $this->blocker('opening_editor_unfinished', self::CATEGORIES[$category] . '还有未完成输入，请保存为准备资料或明确放弃', $category, 'opening_category'); }

        return [
            'tenant_id' => $this->tenantId, 'status' => $book['status'], 'version' => (int)$book['version'],
            'activation_date' => $preparation['activation_date'] ?? null,
            'preparation_version' => (int)($preparation['version'] ?? 0),
            'categories' => $categories, 'items' => $items,
            'blockers' => array_column($blockerDetails, 'message'),
            'blocker_details' => $blockerDetails,
            'activation_available' => self::isActivationAvailableForTenant($this->tenantId),
            'created_by' => json_decode($book['created_by'], true),
            'last_modified_by' => json_decode($book['last_modified_by'], true),
            'create_time' => (int)$book['create_time'], 'update_time' => (int)$book['update_time'],
            'confirmed_at' => 0,
            'guide' => $reviews['_guide'] ?? [],
            'unfinished_editor_counts' => $unfinished,
            'editor_drafts' => $reviews['_editors'][$this->actor['type'] . ':' . $this->actor['id']] ?? [],
        ];
    }

    public static function isActivationAvailableForTenant(int $tenantId): bool
    {
        if (config('finance.activation_mode', 'allowlist') === 'all') {
            return true;
        }

        return in_array($tenantId, (array)config('finance.activation_tenant_ids', []), true);
    }

    public function subjects(array $params): array
    {
        $category = self::text($params['category'] ?? '', 32);
        if (!FinanceOpeningCategory::supported($category)) { throw new \DomainException('该类别暂不支持明细录入'); }
        if (in_array($category, ['capital', 'loan'], true)) { return ['tenant_id' => $this->tenantId, 'lists' => [], 'page' => 1, 'has_more' => false, 'subject_input' => 'text']; }
        $keyword = self::text($params['keyword'] ?? '', 60);
        $page = max(1, self::id($params['page'] ?? 1));
        if ($category === 'inventory') {
            $selection = self::text($params['selection'] ?? '', 16);
            if ($selection === 'warehouse') {
                $rows = Db::name('warehouse')->where('tenant_id', $this->tenantId)->whereLike('name', '%' . addcslashes($keyword, '%_\\') . '%')->field('id,name')->order('id')->page($page, 20)->select()->toArray();
                return ['tenant_id' => $this->tenantId, 'lists' => $rows, 'page' => $page, 'has_more' => count($rows) === 20];
            }
            if ($selection === 'sku') {
                $query = Db::name('goods_sku')->alias('s')->join('goods g', 'g.id=s.goods_id AND g.tenant_id=s.tenant_id')->where('s.tenant_id', $this->tenantId);
                if (!empty($params['goods_id'])) { $query->where('s.goods_id', self::id($params['goods_id'])); }
                $rows = $query->whereLike('s.sku_name|g.name', '%' . addcslashes($keyword, '%_\\') . '%')->field('s.id,s.goods_id,s.sku_name AS name,s.base_unit_id,s.base_unit_name')->order('s.id')->page($page, 20)->select()->toArray();
                return ['tenant_id' => $this->tenantId, 'lists' => $rows, 'page' => $page, 'has_more' => count($rows) === 20];
            }
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
        if ($action === 'confirm' || ($action === 'start' && ($data['mode'] ?? '') === 'zero')) { FinanceOpeningAssets::lockInventory($this->tenantId); $this->lockStock = true; }
        $book = $this->book();
        if ((int)$book['version'] !== $version) { throw new \DomainException('期初资料已更新，请重新加载后核对'); }
        if ($book['status'] === 'active') { throw new \DomainException('期初已确认，不允许覆盖或删除历史'); }
        if (!in_array($action, ['confirm', 'reopen'], true) && $book['status'] !== 'draft') {
            throw new \DomainException('期初正在等待确认，请先退回草稿再修改');
        }
        $before = $this->snapshot();
        $auditBefore = $before;
        switch ($action) {
            case 'start':
                \app\common\service\jxc\DefaultDataInitService::repairMissingForTenant($this->tenantId);
                $mode = self::text($data['mode'] ?? '', 16);
                if (!in_array($mode, ['zero', 'carryover'], true)) { throw new \DomainException('请选择从零开始记账或接续已有经营'); }
                $reviews = json_decode($book['reviews'], true, 512, JSON_THROW_ON_ERROR);
                $date = $mode === 'zero' ? date('Y-m-d') : self::date($data['activation_date'] ?? null);
                if (!$date || $date < '2000-01-01' || $date > date('Y-m-d')) { throw new \DomainException('请选择今天或之前的有效财务启用日期'); }
                if ($mode === 'zero') {
                    if (!self::isActivationAvailableForTenant($this->tenantId)) { throw new \DomainException('该门店暂不能正式启用财务，请联系负责人'); }
                    $conflicts = (new FinanceOpeningGuide($this->tenantId))->zeroConflicts();
                    if ($conflicts || $before['items'] || array_filter($reviews, static fn(mixed $review, string $key): bool => $key !== '_guide', ARRAY_FILTER_USE_BOTH)) { throw new \DomainException('这家店已有需要接续的业务、余额或期初资料，请改为接续已有经营；系统不会清空资料'); }
                    $reviews = ['_guide' => ['mode' => 'zero', 'current_card' => null, 'origin' => 'system_zero_initialization', 'started_by' => $this->actor, 'started_at' => time()]];
                    $book['status'] = 'active'; $book['confirmed_at'] = time();
                } else {
                    $previousDate = $before['activation_date'];
                    if ($previousDate && $previousDate !== $date) {
                        // 日期变化保留明细和事实声明，但撤销旧截点下的核对完成状态。
                        foreach (self::CATEGORIES as $key => $title) {
                            if (!isset($reviews[$key])) { continue; }
                            $reviews[$key]['state'] = 'unknown';
                            $reviews[$key]['choice'] = ($reviews[$key]['choice'] ?? '') === 'none' ? null : ($reviews[$key]['choice'] ?? null);
                        }
                    }
                    $reviews['_guide'] = array_merge($reviews['_guide'] ?? [], ['mode' => 'carryover', 'current_card' => $previousDate === $date ? ($reviews['_guide']['current_card'] ?? 'account') : 'account', 'started_by' => $this->actor, 'started_at' => time()]);
                }
                $sameCutoff = $mode === 'carryover' && $before['activation_date'] === $date;
                $prepared = Db::name('finance_preparation')->where('tenant_id', $this->tenantId)->find();
                Db::name('finance_preparation')->where('tenant_id', $this->tenantId)->update(['activation_date' => $date,
                    'version' => (int)$before['preparation_version'] + 1, 'inventory_cost_reviewed' => $sameCutoff ? (int)$prepared['inventory_cost_reviewed'] : ($mode === 'zero' ? 1 : 0),
                    'legacy_settlement_reviewed' => $sameCutoff ? (int)$prepared['legacy_settlement_reviewed'] : ($mode === 'zero' ? 1 : 0), 'excluded_business_reviewed' => $sameCutoff ? (int)$prepared['excluded_business_reviewed'] : ($mode === 'zero' ? 1 : 0),
                    'operator_id' => (int)$this->actor['id'], 'update_time' => time()]);
                $book['reviews'] = self::json($reviews);
                break;
            case 'guide_card':
                $book['reviews'] = self::json($this->saveGuideCard($data, $before, $book));
                break;
            case 'item':
                $this->saveItem($data);
                $reviews = json_decode($book['reviews'], true);
                unset($reviews[self::text($data['category'], 32)]);
                unset($reviews['_editors'][$this->actor['type'] . ':' . $this->actor['id']][$data['category']]);
                $book['reviews'] = self::json($reviews);
                break;
            case 'editor':
                $category = self::text($data['category'] ?? '', 32);
                if (!FinanceOpeningCategory::supported($category)) { throw new \DomainException('请选择可填写的准备事项'); }
                $form = $data['form'] ?? null;
                if (!is_array($form) || strlen(self::json($form)) > 64000) { throw new \DomainException('未完成资料格式不正确或过大'); }
                $reviews = json_decode($book['reviews'], true);
                $actorKey = $this->actor['type'] . ':' . $this->actor['id'];
                if (!empty($data['clear_all'])) {
                    if (!FinanceAccess::owner()) { throw new \DomainException('仅负责人可放弃本项其他人员的未完成输入'); }
                    foreach ($reviews['_editors'] ?? [] as $key => $drafts) { unset($reviews['_editors'][$key][$category]); }
                } elseif (!empty($data['clear'])) { unset($reviews['_editors'][$actorKey][$category]); }
                else {
                    $form = array_intersect_key($form, array_flip(['id', 'subject_id', 'subject_name', 'amount', 'source_mode', 'source_reference', 'historical_date', 'due_date', 'evidence', 'details', 'entry_contract']));
                    $accountReferences = $form;
                    if (in_array($category, ['account', 'transit', 'unclaimed'], true)) { $accountReferences['account_id'] = $form['subject_id'] ?? 0; }
                    FinanceAccountLifecycle::assertDraftAccounts($this->tenantId, '', $accountReferences);
                    $form['details'] = FinanceOpeningCategory::normalizeDetails($category, $form['details'] ?? []);
                    $reviews['_editors'][$actorKey][$category] = ['form' => $form, 'saved_at' => time()];
                    unset($reviews[$category]);
                }
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
                foreach ($reviews['_editors'] ?? [] as $actorKey => $drafts) { if ((int)($drafts[$removed['category']]['form']['id'] ?? 0) === $id) { unset($reviews['_editors'][$actorKey][$removed['category']]); } }
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
                if (($before['guide']['mode'] ?? '') === 'carryover') { $this->requireGuideReady($before); }
                $this->requireReady($before);
                $book['status'] = 'pending';
                $book['submitted_hash'] = $this->basisHash($before);
                if (($before['guide']['mode'] ?? '') === 'carryover') {
                    $reviews = json_decode($book['reviews'], true, 512, JSON_THROW_ON_ERROR);
                    $reviews['_guide']['submitted_business_basis'] = (new FinanceOpeningGuide($this->tenantId))->businessBasis();
                    $book['reviews'] = self::json($reviews);
                }
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
                if (($before['guide']['mode'] ?? '') === 'carryover') {
                    $this->requireGuideReady($before);
                    if (!hash_equals((string)($before['guide']['submitted_business_basis'] ?? ''), (new FinanceOpeningGuide($this->tenantId))->businessBasis())) { throw new \DomainException('准备期间经营资料已变化，请退回草稿重新核对后再启用'); }
                }
                foreach ($before['items'] as &$item) {
                    if ($item['category'] === 'inventory' && (int)$item['subject_id'] === 0 && FinanceOpeningInventory::isFirst($item['details'])) { $item = FinanceOpeningInventory::confirm($this->tenantId, $item); }
                    Db::name('finance_opening_source')->insert([
                        'tenant_id' => $this->tenantId, 'opening_item_id' => $item['id'], 'category' => $item['category'],
                        'subject_id' => $item['subject_id'], 'amount' => $item['amount'],
                        'activation_date' => $before['activation_date'], 'source_snapshot' => self::json($item), 'create_time' => time(),
                    ]);
                }
                unset($item);
                FinancePurchaseLegacySources::finalizeOpening($this->tenantId);
                $book['status'] = 'active'; $book['confirmed_at'] = time();
                break;
            default: throw new \DomainException('不支持的期初操作');
        }
        $book['version'] = $version + 1;
        $book['update_time'] = time();
        $book['last_modified_by'] = self::json($this->actor);
        if (!$book['create_time']) { $book['create_time'] = time(); $book['created_by'] = self::json($this->actor); }
        if ($action === 'confirm' || ($action === 'start' && $book['status'] === 'active')) {
            if ($action === 'start') {
                $before = array_merge($before, ['activation_date' => $date, 'preparation_version' => (int)$before['preparation_version'] + 1,
                    'items' => [], 'blockers' => [], 'blocker_details' => [], 'guide' => $reviews['_guide']]);
                foreach ($before['categories'] as &$category) { $category['review'] = ['state' => 'system_zero', 'evidence' => '', 'origin' => 'system_zero_initialization']; }
                unset($category);
            }
            $confirmed = array_merge($before, [
                'status' => 'active', 'version' => $book['version'], 'confirmed_at' => $book['confirmed_at'],
                'confirmed_by' => $this->actor, 'last_modified_by' => $this->actor, 'update_time' => $book['update_time'],
                'created_by' => json_decode($book['created_by'], true), 'create_time' => (int)$book['create_time'],
            ]);
            $book['confirmed_snapshot'] = self::json($confirmed);
        }
        if ($version > 0 || Db::name('finance_opening_book')->where('tenant_id', $this->tenantId)->count()) {
            Db::name('finance_opening_book')->where('tenant_id', $this->tenantId)->update($book);
        } else { Db::name('finance_opening_book')->insert($book); }
        if ($action === 'confirm' || ($action === 'start' && $book['status'] === 'active')) {
            $confirmed['cost_bootstrap'] = FinanceCostBootstrap::withinTransaction($this->tenantId, $before['activation_date']);
            Db::name('finance_opening_book')->where('tenant_id', $this->tenantId)->update(['confirmed_snapshot' => self::json($confirmed)]);
        }
        return [$auditBefore, $this->snapshot()];
    }

    private function saveGuideCard(array $data, array $snapshot, array $book): array
    {
        $key = self::text($data['category'] ?? '', 32);
        $choice = self::text($data['choice'] ?? '', 16);
        $evidence = self::text($data['evidence'] ?? '', 1000);
        if (!isset(FinanceOpeningGuide::CARDS[$key]) || !in_array($choice, ['has', 'none', 'unknown', 'complete'], true)) { throw new \DomainException('请选择当前事项有、没有或暂不确定'); }
        $reviews = json_decode($book['reviews'], true, 512, JSON_THROW_ON_ERROR);
        if (($reviews['_guide']['mode'] ?? '') !== 'carryover') { throw new \DomainException('请先选择接续已有经营并保存启用日期'); }
        $guide = new FinanceOpeningGuide($this->tenantId);
        $candidates = $guide->candidates($snapshot)[$key] ?? [];
        $rows = array_values(array_filter($snapshot['items'], static fn(array $item): bool => $item['category'] === $key));
        if ($choice === 'none' && ($candidates || $rows || !empty($reviews[$key]['declared_has']))) { throw new \DomainException('这项已有资料或已声明存在，不能标记没有；请核对并保留真实来源'); }
        if ($choice === 'complete') {
            if (!FinanceOpeningCategory::supported($key)) { throw new \DomainException('该事项的后续处理能力尚未开放，须保留待补充，不能启用'); }
            if (!$rows) { throw new \DomainException('请先录入并核对该事项的真实余额或来源'); }
            $this->requireCandidateCoverage($key, $candidates, $rows);
            foreach ($snapshot['blocker_details'] as $detail) {
                if ($detail['category'] === $key && !in_array($detail['code'], ['opening_category_review_required', 'opening_guide_candidate_changed'], true)) { throw new \DomainException($detail['message']); }
            }
        }
        $state = match ($choice) { 'none' => 'none', 'complete' => 'complete', 'unknown' => 'unresolved', default => FinanceOpeningCategory::supported($key) ? 'unknown' : 'unresolved' };
        $reviews[$key] = ['state' => $state, 'choice' => $choice, 'evidence' => $evidence,
            'declared_has' => !FinanceOpeningCategory::supported($key) && ($choice === 'has' || !empty($reviews[$key]['declared_has'])),
            'confirmed_by' => $this->actor, 'confirmed_at' => time(), 'origin' => 'guide_explicit_confirmation',
            'candidate_basis' => $guide->candidateBasis($snapshot, $key)];
        $keys = array_keys(FinanceOpeningGuide::CARDS);
        $position = array_search($key, $keys, true);
        $reviews['_guide']['current_card'] = $choice === 'has' ? $key : ($keys[$position + 1] ?? null);
        $reviews['_guide']['updated_at'] = time();
        $ready = static fn(string $category): int => in_array($reviews[$category]['state'] ?? '', ['none', 'complete'], true) ? 1 : 0;
        $preparation = Db::name('finance_preparation')->where('tenant_id', $this->tenantId)->find();
        Db::name('finance_preparation')->where('tenant_id', $this->tenantId)->update([
            'inventory_cost_reviewed' => $ready('inventory'), 'legacy_settlement_reviewed' => $ready('receivable') && $ready('payable') && !$guide->settlementBlockers() ? 1 : 0,
            'excluded_business_reviewed' => $ready('excluded'), 'version' => (int)$preparation['version'] + 1,
            'operator_id' => (int)$this->actor['id'], 'update_time' => time(),
        ]);
        return $reviews;
    }

    private function requireCandidateCoverage(string $key, array $candidates, array $rows): void
    {
        $subjects = array_map('intval', array_column($rows, 'subject_id'));
        foreach ($candidates as $candidate) {
            if ($candidate['entity_type'] === 'opening_item') { continue; }
            if ($candidate['entity_type'] === 'legacy_inventory') {
                $stocks = Db::name('warehouse_sku_balance')->where('tenant_id', $this->tenantId)->where('goods_id', $candidate['entity_id'])->column('id');
                if (!array_intersect(array_map('intval', $stocks), $subjects)) { throw new \DomainException('原系统商品库存尚未对应到核算仓库和规格，请先核实库存来源'); }
            } elseif (!in_array((int)$candidate['subject_id'], $subjects, true)) { throw new \DomainException(FinanceOpeningGuide::CARDS[$key][0] . '仍有原系统资料尚未核对承接'); }
        }
    }

    private function requireGuideReady(array $snapshot): void
    {
        $reviews = array_column($snapshot['categories'], 'review', 'key');
        $guide = new FinanceOpeningGuide($this->tenantId);
        $candidates = $guide->candidates($snapshot);
        foreach (FinanceOpeningGuide::CARDS as $key => [$title]) {
            $review = $reviews[$key] ?? [];
            if (!in_array($review['state'] ?? '', ['none', 'complete'], true)) { throw new \DomainException($title . '尚未核清，请返回这项继续补充'); }
            if ($review['state'] === 'none' && ($candidates[$key] ?? [])) { throw new \DomainException($title . '已有待核对资料，不能按没有启用'); }
            if (!hash_equals((string)($review['candidate_basis'] ?? ''), $guide->candidateBasis($snapshot, $key))) { throw new \DomainException($title . '的候选资料已变化，请重新核对'); }
            if ($review['state'] === 'complete') { $this->requireCandidateCoverage($key, $candidates[$key] ?? [], array_values(array_filter($snapshot['items'], static fn(array $item): bool => $item['category'] === $key))); }
        }
    }

    private function saveItem(array $data): void
    {
        $category = self::text($data['category'] ?? '', 32);
        if (!FinanceOpeningCategory::supported($category)) { throw new \DomainException('该类别的非零期初承接尚未开放，请记录为待解决事项'); }
        $id = self::id($data['id'] ?? 0);
        $existing = $id ? Db::name('finance_opening_item')->where('tenant_id', $this->tenantId)->where('id', $id)->find() : null;
        if ($id && (!$existing || $existing['category'] !== $category)) { throw new \DomainException('期初明细不存在或类别不匹配'); }
        $ownerEntry = ($data['entry_contract'] ?? null) === 2;
        $subjectId = self::id($data['subject_id'] ?? 0);
        if ($ownerEntry && $category === 'inventory' && $subjectId === 0 && FinanceOpeningInventory::isFirst($data['details'] ?? [])) {
            if (!FinanceOpeningInventory::subject($this->tenantId, $data['details'])) { throw new \DomainException('库存商品、规格或仓库不一致，请重新选择并核对'); }
            $balance = Db::name('warehouse_sku_balance')->where('tenant_id', $this->tenantId)->where('warehouse_id', (int)($data['details']['warehouse_id'] ?? 0))->where('sku_id', (int)($data['details']['sku_id'] ?? 0))->find();
            if ($balance) { $subjectId = (int)$balance['id']; $data['details']['inventory_entry'] = ''; }
        }
        if (!($category === 'inventory' && $subjectId === 0 && FinanceOpeningInventory::isFirst($data['details'] ?? []) ? FinanceOpeningInventory::subject($this->tenantId, $data['details']) : $this->subject($category, $subjectId))) { throw new \DomainException('对象不存在、不属于当前门店或不是主客户'); }
        if ($category === 'inventory' && $subjectId > 0) {
            $balance = Db::name('warehouse_sku_balance')->where('tenant_id', $this->tenantId)->where('id', $subjectId)->find();
            if (FinanceOpeningInventory::isFirst($data['details'] ?? []) || (!empty($data['details']['warehouse_id']) && (int)$data['details']['warehouse_id'] !== (int)$balance['warehouse_id']) || (!empty($data['details']['sku_id']) && (int)$data['details']['sku_id'] !== (int)$balance['sku_id']) || (!empty($data['details']['goods_id']) && (int)$data['details']['goods_id'] !== (int)$balance['goods_id'])) { throw new \DomainException('库存对象与仓库、规格不一致，请同时核对商品'); }
        }
        $amount = $data['amount'] ?? null;
        if ($amount !== null && $amount !== '') {
            if (!is_string($amount) || !preg_match('/^(0|[1-9][0-9]{0,11})(\.[0-9]{1,2})?$/D', $amount)) {
                throw new \DomainException('金额须为非负数，最多两位小数；未知金额请留空');
            }
            $amount = bcadd($amount, '0', 2);
            if (!in_array($category, ['account', 'inventory', 'equipment'], true) && bccomp($amount, '0', 2) <= 0) { throw new \DomainException('剩余余额必须大于零，无余额请在类别核对中说明'); }
        } else { $amount = null; }
        $mode = self::text($data['source_mode'] ?? ($ownerEntry ? FinanceOpeningCategory::metadata($category)['default_source_mode'] : ''), 16);
        if (!in_array($mode, ['detail', 'summary'], true)) { throw new \DomainException('请标明历史明细或核实汇总'); }
        if ($mode === 'summary' && !FinanceOpeningCategory::allowsSummary($category)) {
            throw new \DomainException('该类型须保留逐笔合法来源，不支持汇总期初');
        }
        $details = FinanceOpeningCategory::normalizeDetails($category, $data['details'] ?? []);
        if ($category === 'deferred' && !empty($details['payable_item_id'])) {
            $payable = Db::name('finance_opening_item')->where('tenant_id', $this->tenantId)->where('id', (int)$details['payable_item_id'])->where('category', 'expense_payable')->where('subject_id', $subjectId)->find();
            if (!$payable) { throw new \DomainException('请选择同一收款方的真实费用待付款资料'); }
            $details['payable_reference'] = $payable['source_reference'];
        }
        if (in_array($category, ['capital', 'loan'], true) && $subjectId !== 0) { throw new \DomainException('经营资金对象应填写真实姓名，不使用客户或供应商编号'); }
        $reference = self::text($data['source_reference'] ?? '', 200);
        $evidence = self::text($data['evidence'] ?? '', 1000);
        if (!$ownerEntry && ($reference === '' || $evidence === '')) { throw new \DomainException('请填写期初来源标识和核对依据，不补造历史交易'); }
        if ($ownerEntry) {
            $previousDetails = $existing ? json_decode((string)Db::name('finance_opening_item_detail')->where('tenant_id', $this->tenantId)->where('opening_item_id', $id)->value('details'), true) : [];
            // 记录录入方式，不代替老板宣称已查账；稳定身份随原记录及幂等结果保留。
            $reference = $existing['source_reference'] ?? ($reference !== '' ? $reference : '首次手填-' . bin2hex(random_bytes(12)));
            $details['_capture'] = $previousDetails['_capture'] ?? ['contract' => 2, 'origin' => 'owner_manual', 'actor' => $this->actor, 'captured_at' => time()];
        }
        if (!$ownerEntry && (!array_key_exists('historical_date', $data) || !array_key_exists('due_date', $data))) {
            throw new \DomainException('请明确历史日期与付款日，未知时标记不详或未约定');
        }
        $row = [
            'tenant_id' => $this->tenantId, 'category' => $category, 'subject_id' => $subjectId, 'amount' => $amount,
            'historical_date' => self::date($data['historical_date'] ?? null), 'due_date' => self::date($data['due_date'] ?? null),
            'source_mode' => $mode, 'source_reference' => $reference, 'evidence' => $evidence,
        ];
        $duplicate = Db::name('finance_opening_item')->where('tenant_id', $this->tenantId)->where('category', $category)
            ->where('subject_id', $subjectId)->where('id', '<>', $id);
        if (!in_array($category, ['account', 'inventory'], true)) { $duplicate->where('source_reference', $reference); }
        if (!($category === 'inventory' && $subjectId === 0) && $duplicate->count()) { throw new \DomainException('该账户或同一对象的期初来源已存在，请修改原明细'); }
        if ($category === 'inventory' && $subjectId === 0) {
            foreach ($this->items() as $other) {
                if ($other['category'] === 'inventory' && (int)$other['id'] !== $id && (string)($other['details']['warehouse_id'] ?? '') === (string)($details['warehouse_id'] ?? '') && (string)($other['details']['sku_id'] ?? '') === (string)($details['sku_id'] ?? '')) { throw new \DomainException('同仓同规格已登记，请修改原库存资料'); }
            }
        }
        if (!in_array($category, ['account', 'inventory'], true)) {
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

    /**
     * 保留 blockers 的文本兼容契约，同时为客户端提供受限且可验证的处理目标。
     * 客户端只能按 entity_type 映射到已知页面，不能消费服务端传入的任意路由。
     */
    private function blockerDetails(array $preparation, array $categories, array $items): array
    {
        $details = [];
        $date = $preparation['activation_date'] ?? null;
        if (!$date) { $details[] = $this->blocker('opening_activation_date_required', '请先保存财务启用日期', null, 'preparation'); }
        foreach (['inventory_cost_reviewed', 'legacy_settlement_reviewed', 'excluded_business_reviewed'] as $flag) {
            if (empty($preparation[$flag])) { $details[] = $this->blocker('opening_preparation_incomplete', '启用准备中仍有未完成的旧账核对', null, 'preparation'); break; }
        }
        foreach ($categories as $category) {
            $state = $category['review']['state'];
            if (in_array($state, ['unknown', 'unresolved'], true)) { $details[] = $this->blocker('opening_category_review_required', $category['title'] . '尚未核清或承接', $category['key'], 'opening_category'); }
            if ($state === 'none' && $category['count'] > 0) { $details[] = $this->blocker('opening_category_none_with_items', $category['title'] . '标记无余额但仍有明细', $category['key'], 'opening_category'); }
            if ($state === 'complete' && $category['count'] === 0) { $details[] = $this->blocker('opening_category_complete_without_items', $category['title'] . '尚未录入明细，无余额请明确核对', $category['key'], 'opening_category'); }
        }
        foreach ($items as $item) {
            $label = self::CATEGORIES[$item['category']] . ' #' . $item['id'];
            if ($item['amount'] === null) { $details[] = $this->blocker('opening_item_amount_unresolved', $label . '金额尚未核实', $item['category'], 'opening_item', (int)$item['id']); }
            if (!$item['subject_name']) { $details[] = $this->blocker('opening_item_subject_missing', $label . '对象已失效', $item['category'], 'opening_item', (int)$item['id']); }
            if ($date && $item['historical_date'] && $item['historical_date'] >= $date) { $details[] = $this->blocker('opening_item_historical_date_invalid', $label . '历史日期必须早于启用日期', $item['category'], 'opening_item', (int)$item['id']); }
            foreach (FinanceOpeningCategory::metadata($item['category'])['detail_fields'] as $field) {
                if ($field['key'] === 'origin_reference' && ($item['details']['_capture']['contract'] ?? null) === 2) { continue; }
                if ($item['category'] === 'deferred' && ($item['details']['remaining_only'] ?? '') === 'confirmed' && ($item['details']['_capture']['contract'] ?? null) === 2 && in_array($field['key'], ['original_amount', 'amortized_amount', 'paid_amount', 'original_service_start', 'benefited_until'], true)) { continue; }
                if ($item['category'] === 'equipment' && ($item['details']['opening_balance_only'] ?? '') === 'confirmed' && ($item['details']['_capture']['contract'] ?? null) === 2 && in_array($field['key'], ['original_amount', 'price_adjustment', 'paid_amount', 'cancelled_amount'], true)) { continue; }
                $value = $item['details'][$field['key']] ?? '';
                if (($field['required'] ?? true) && ($value === '' || $value === [])) { $details[] = $this->blocker('opening_item_detail_unresolved', $label . $field['label'] . '尚未核实', $item['category'], 'opening_item', (int)$item['id']); }
                if ($field['key'] === 'benefit_month' && $value !== '' && $date && $value > (new \DateTimeImmutable($date))->modify('-1 day')->format('Y-m')) {
                    $details[] = $this->blocker('opening_item_benefit_month_invalid', $label . '原受益月份不能晚于期初截点', $item['category'], 'opening_item', (int)$item['id']);
                }
            }
            foreach (FinanceOpeningAssets::blockers($this->tenantId, $item, $date) as $error) { $details[] = $this->blocker('opening_item_composition_invalid', $label . $error, $item['category'], 'opening_item', (int)$item['id']); }
        }
        $accountIds = array_column(array_filter($items, static fn(array $item): bool => $item['category'] === 'account'), 'subject_id');
        foreach (Db::name('finance_account')->where('tenant_id', $this->tenantId)->column('id') as $id) {
            if (!in_array((int)$id, array_map('intval', $accountIds), true)) { $details[] = $this->blocker('opening_account_subject_missing', '资金账户 #' . $id . '缺少核实余额，零余额也须录入', 'account', 'opening_category'); }
        }
        $stockIds = array_map('intval', array_column(array_filter($items, static fn(array $item): bool => $item['category'] === 'inventory'), 'subject_id'));
        $stocks = FinanceOpeningAssets::stockAtCutoff($this->tenantId, $date, $this->lockStock);
        $stockNames = FinanceOpeningAssets::inventoryNames($this->tenantId, array_column($stocks, 'id'));
        foreach ($stocks as $stock) {
            if (bccomp($stock['cutoff_qty'], '0', 4) !== 0 && !in_array((int)$stock['id'], $stockIds, true)) {
                $details[] = $this->blocker('opening_inventory_subject_missing', '存在截点实物库存 #' . $stock['id'] . '，须完成仓库与 SKU 历史成本承接后启用', 'inventory', 'opening_subject', (int)$stock['id'], $stockNames[(int)$stock['id']] ?? ('库存 #' . $stock['id']));
            }
        }
        foreach (FinanceOpeningAssets::payableBlockers($items) as $error) {
            $details[] = $this->blocker('opening_cross_category_composition_invalid', $error, 'deferred', 'opening_category');
        }
        $book = $this->book();
        $reviews = json_decode($book['reviews'], true, 512, JSON_THROW_ON_ERROR);
        if (($reviews['_guide']['mode'] ?? '') === 'carryover') {
            $guide = new FinanceOpeningGuide($this->tenantId);
            $basisSnapshot = ['activation_date' => $date, 'items' => $items];
            foreach ($categories as $category) {
                $review = $category['review'];
                if (in_array($review['state'], ['none', 'complete'], true) && !hash_equals((string)($review['candidate_basis'] ?? ''), $guide->candidateBasis($basisSnapshot, $category['key']))) { $details[] = $this->blocker('opening_guide_candidate_changed', FinanceOpeningGuide::CARDS[$category['key']][0] . '资料已变化，请重新核对', $category['key'], 'opening_category'); }
            }
            $details = array_merge($details, $guide->settlementBlockers());
        }
        return $details;
    }

    private function blocker(string $code, string $message, ?string $category = null, ?string $entityType = null, ?int $entityId = null, ?string $entityName = null): array
    {
        return ['code' => $code, 'message' => $message, 'category' => $category,
            'entity_type' => $entityType, 'entity_id' => $entityId, 'entity_name' => $entityName];
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
            if ($item['category'] === 'inventory' && (int)$item['subject_id'] === 0 && FinanceOpeningInventory::isFirst($item['details'])) { $subject = FinanceOpeningInventory::subject($this->tenantId, $item['details'], $this->lockStock); $item['subject_snapshot'] = $subject; $item['subject_name'] = $subject['name'] ?? null; }
            if (in_array($item['category'], ['capital', 'loan'], true)) { $item['subject_name'] = $item['details']['counterparty'] ?? ''; $item['subject_snapshot'] = ['id' => 0, 'name' => $item['subject_name']]; }
        }
        return $items;
    }

    private function subject(string $category, int $id): ?array
    {
        if (in_array($category, ['capital', 'loan'], true)) { return $id === 0 ? ['id' => 0, 'name' => '资金对象'] : null; }
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
        $reviews = json_decode($this->book()['reviews'], true);
        foreach (($reviews['_editors'] ?? []) as $drafts) { if (array_filter($drafts)) { throw new \DomainException('仍有未完成的编辑资料，请保存为正式准备资料或明确放弃后再提交'); } }
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
