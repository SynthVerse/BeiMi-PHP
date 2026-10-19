<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 删除资格取决于引用事实；所有写入必须持有 finance_preparation 门店锁。 */
final class FinanceAccountLifecycle
{
    private const ACCOUNT_CATEGORIES = ['account', 'transit', 'unclaimed'];
    private const ACCOUNT_KEYS = ['account_id', 'source_account_id', 'target_account_id', 'fund_account_id'];
    private array $blocked = [];
    private array $draftIds = [];
    private array $draftItems = [];
    private array $book;

    public function __construct(private readonly int $tenantId)
    {
        $this->book = Db::name('finance_opening_book')->where('tenant_id', $tenantId)->find() ?: ['status' => 'draft', 'version' => 0, 'reviews' => '{}'];
        $this->readReferences();
    }

    public function openingVersion(): int { return (int)$this->book['version']; }

    /** 与删除共享门店锁；允许尚未填写的字段，但不能保存已不存在或跨店的账户引用。 */
    public static function assertDraftAccounts(int $tenantId, string $type, array $payload): void
    {
        $ids = [];
        $collect = static function (array $data) use (&$collect, &$ids): void {
            foreach ($data as $key => $value) {
                if (in_array($key, self::ACCOUNT_KEYS, true) && (is_int($value) || is_string($value)) && (int)$value > 0) { $ids[(int)$value] = true; }
                elseif (is_array($value)) { $collect($value); }
            }
        };
        $collect($payload);
        if ((FinanceDocumentPolicy::TYPES[$type]['subject'] ?? '') === 'account' && (int)($payload['subject_id'] ?? 0) > 0) { $ids[(int)$payload['subject_id']] = true; }
        if ($ids && (int)Db::name('finance_account')->where('tenant_id', $tenantId)->whereIn('id', array_keys($ids))->count() !== count($ids)) {
            throw new \DomainException('资金账户已删除、不存在或不属于当前门店，请重新选择账户');
        }
    }

    public function project(array $account, bool $canManage = true): array
    {
        $id = (int)$account['id'];
        $reason = $this->blocked[$id] ?? '';
        if ($reason === '' && isset($this->draftIds[$id]) && $this->book['status'] !== 'draft') { $reason = '期初正在等待确认，请先退回草稿再删除账户'; }
        return $account + ['can_delete' => $canManage && $reason === '', 'can_disable' => $canManage && (bool)$account['is_enabled'],
            'deletion_blocked_reason' => $reason, 'has_opening_draft' => isset($this->draftIds[$id]), 'opening_version' => $this->openingVersion()];
    }

    public function remove(array $account, bool $removeOpeningDraft, ?int $openingVersion, array $actor): array
    {
        $id = (int)$account['id']; $metadata = $this->project($account);
        if (!$metadata['can_delete']) { throw new \DomainException($metadata['deletion_blocked_reason'] . '；账户可停用并保留历史'); }
        $removedDraft = $metadata['has_opening_draft'];
        if ($removedDraft) {
            if (!$removeOpeningDraft) { throw new \DomainException('该账户有期初草稿，请明确确认同时移除草稿后再删除'); }
            if ($openingVersion !== $this->openingVersion()) { throw new \DomainException('期初资料已更新，请重新加载后核对'); }
            $itemIds = $this->draftItems[$id] ?? [];
            if ($itemIds) {
                Db::name('finance_opening_item_detail')->where('tenant_id', $this->tenantId)->whereIn('opening_item_id', $itemIds)->delete();
                Db::name('finance_opening_item')->where('tenant_id', $this->tenantId)->whereIn('id', $itemIds)->delete();
            }
            $reviews = FinanceValue::decode($this->book['reviews']);
            unset($reviews['account']);
            foreach ($reviews['_editors'] ?? [] as $key => $drafts) {
                $form = $drafts['account']['form'] ?? [];
                if ((int)($form['subject_id'] ?? 0) === $id || in_array((int)($form['id'] ?? 0), $itemIds, true)) { unset($reviews['_editors'][$key]['account']); }
            }
            $this->book['version'] = $this->openingVersion() + 1;
            Db::name('finance_opening_book')->where('tenant_id', $this->tenantId)->update(['reviews' => FinanceValue::json($reviews), 'version' => $this->openingVersion(),
                'last_modified_by' => FinanceValue::json($actor), 'submitted_hash' => '', 'update_time' => time()]);
        }
        Db::name('finance_account')->where('tenant_id', $this->tenantId)->where('id', $id)->delete();
        return ['tenant_id' => $this->tenantId, 'id' => $id, 'removed_account_ids' => [$id], 'opening_version' => $this->openingVersion(), 'opening_draft_removed' => $removedDraft];
    }

    /** 审计记录只用于撤销旧客户端缓存，不把创建/改名日志误判为业务引用。 */
    public static function removedIds(int $tenantId): array
    {
        $ids = [];
        foreach (Db::name('finance_setup_action')->where('tenant_id', $tenantId)->where('action_type', 'account.remove')->field('result_data')->cursor() as $row) {
            foreach (FinanceValue::decode($row['result_data'])['removed_account_ids'] ?? [] as $id) { $ids[(int)$id] = true; }
        }
        return array_keys($ids);
    }

    private function readReferences(): void
    {
        $prefix = (string)config('database.connections.mysql.prefix', 'la_');
        $schema = [];
        foreach (Db::query('SELECT TABLE_NAME AS table_name,COLUMN_NAME AS column_name FROM information_schema.columns WHERE TABLE_SCHEMA=DATABASE()') as $row) {
            if (str_starts_with($row['table_name'], $prefix . 'finance_')) { $schema[substr($row['table_name'], strlen($prefix))][] = $row['column_name']; }
        }
        // 覆盖资金事实、互转双方、核对及以后新增的结构化账户外键。
        foreach ($schema as $table => $columns) {
            $accountColumns = array_intersect(self::ACCOUNT_KEYS, $columns);
            if (!$accountColumns || !in_array('tenant_id', $columns, true)) { continue; }
            foreach (Db::name($table)->where('tenant_id', $this->tenantId)->field(implode(',', $accountColumns))->select()->toArray() as $row) {
                foreach ($row as $id) { $this->block((int)$id, '已有收付款、账户互转或账户核对记录'); }
            }
        }
        foreach (['finance_source', 'finance_opening_source'] as $table) {
            if (!isset($schema[$table])) { continue; }
            $snapshotField = $table === 'finance_opening_source' ? 'source_snapshot' : 'snapshot';
            foreach (Db::name($table)->where('tenant_id', $this->tenantId)->whereIn('category', self::ACCOUNT_CATEGORIES)->field('subject_id,' . $snapshotField)->cursor() as $row) {
                $this->block((int)$row['subject_id'], '已有确认的期初或资金来源记录');
                $this->blockJson(FinanceValue::decode($row[$snapshotField]), '已有确认的期初或资金来源记录');
            }
        }
        if (isset($schema['finance_entry'])) {
            foreach (Db::name('finance_entry')->where('tenant_id', $this->tenantId)->whereIn('metric', ['cash', 'transit'])->column('subject_id') as $id) { $this->block((int)$id, '已有资金或在途账务记录'); }
        }
        if (isset($schema['finance_document'])) {
            foreach (Db::name('finance_document')->where('tenant_id', $this->tenantId)->field('type,payload,confirmed_result')->cursor() as $row) {
                foreach (['payload', 'confirmed_result'] as $field) {
                    $data = FinanceValue::decode($row[$field]);
                    if ((FinanceDocumentPolicy::TYPES[$row['type']]['subject'] ?? '') === 'account') { $this->block((int)($data['subject_id'] ?? 0), '已有财务单据或未完成业务草稿'); }
                    $this->blockJson($data, '已有财务单据或未完成业务草稿');
                }
            }
        }
        // 月结快照可能保留零余额账户；零余额本身不代表从未使用。
        if (isset($schema['finance_period'])) {
            foreach (Db::name('finance_period')->where('tenant_id', $this->tenantId)->field('snapshot')->cursor() as $row) { $this->blockJson(FinanceValue::decode($row['snapshot']), '已有月结快照引用'); }
        }
        if ($this->book['status'] === 'active') { $this->blockJson(FinanceValue::decode($this->book['confirmed_snapshot'] ?? '{}'), '已有确认的期初记录'); }
        $items = Db::name('finance_opening_item')->where('tenant_id', $this->tenantId)->whereIn('category', self::ACCOUNT_CATEGORIES)->select()->toArray();
        $details = Db::name('finance_opening_item_detail')->where('tenant_id', $this->tenantId)->column('details', 'opening_item_id');
        foreach ($items as $item) {
            $id = (int)$item['subject_id'];
            if ($item['category'] === 'account' && $this->book['status'] !== 'active') { $this->draftIds[$id] = true; $this->draftItems[$id][] = (int)$item['id']; }
            else { $this->block($id, '已有期初在途、待认领或已确认余额引用'); }
            if (isset($details[$item['id']])) { $this->blockJson(FinanceValue::decode($details[$item['id']]), '已有其他期初资料引用此账户'); }
        }
        foreach (FinanceValue::decode($this->book['reviews'])['_editors'] ?? [] as $drafts) {
            foreach ($drafts as $category => $draft) {
                $form = $draft['form'] ?? [];
                if ($category === 'account') { $id = (int)($form['subject_id'] ?? 0); if ($id > 0) { $this->draftIds[$id] = true; } }
                else {
                    if (in_array($category, self::ACCOUNT_CATEGORIES, true)) { $this->block((int)($form['subject_id'] ?? 0), '已有其他期初事项的未完成输入引用此账户'); }
                    $this->blockJson($form, '已有其他期初事项的未完成输入引用此账户');
                }
            }
        }
    }

    private function block(int $id, string $reason): void { if ($id > 0) { $this->blocked[$id] ??= $reason; } }

    private function blockJson(array $data, string $reason): void
    {
        if (in_array($data['category'] ?? '', self::ACCOUNT_CATEGORIES, true)) { $this->block((int)($data['subject_id'] ?? 0), $reason); }
        foreach ($data as $key => $value) {
            if (in_array($key, self::ACCOUNT_KEYS, true) && (is_int($value) || is_string($value))) { $this->block((int)$value, $reason); }
            elseif (is_array($value)) { $this->blockJson($value, $reason); }
        }
    }
}
