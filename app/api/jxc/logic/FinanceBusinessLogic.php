<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use think\facade\Db;

/** 门店锁串行确认与期间关闭，命令结果和所有正式影响在同一事务提交。 */
final class FinanceBusinessLogic extends BaseLogic
{
    public static function action(string $action, array $params): array|false
    {
        self::clearError();
        try {
            if (!in_array($action, ['save', 'prepare', 'submit', 'reopen', 'confirm', 'record', 'correct', 'reverse_duplicate'], true)) { throw new \DomainException('不支持的单据动作'); }
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
                    FinanceDocumentPolicy::authorize($result['type'], in_array($action, ['confirm', 'record', 'correct', 'reverse_duplicate'], true));
                    if (!hash_equals($existing['fingerprint'], $fingerprint)) { throw new \DomainException('同一提交标识不能用于不同内容或操作人'); }
                    return $result;
                }
                $document = $id ? Db::name('finance_document')->where('tenant_id', $tenantId)->where('id', $id)->find() : null;
                if ($id && !$document) { throw new \DomainException('单据不存在或不属于当前门店'); }
                $type = $document['type'] ?? FinanceValue::text($params['type'] ?? '', 40);
                FinanceDocumentPolicy::authorize($type, in_array($action, ['confirm', 'record', 'correct', 'reverse_duplicate'], true));
                if ($document && (int)$document['version'] !== $version) { throw new \DomainException('草稿已被修改，请核对最新版本'); }
                if (!$document && ($version !== 0 || !in_array($action, ['save', 'prepare', 'record'], true))) { throw new \DomainException('请先保存有效草稿'); }
                $original = in_array($action, ['correct', 'reverse_duplicate'], true) ? $document : null;
                if ($original && $original['status'] !== 'confirmed') { throw new \DomainException('仅已确认记录可关联更正'); }
                if ($document && $document['status'] === 'confirmed' && !$original) { throw new \DomainException('已确认单据不可覆盖或删除，请使用关联更正'); }
                if ($original) { $document = null; }
                if (in_array($action, ['save', 'prepare', 'record', 'correct', 'reverse_duplicate'], true)) {
                    if ($document && $document['status'] !== 'draft') { throw new \DomainException('请先退回草稿再修改'); }
                    $payload = $action === 'reverse_duplicate' ? FinanceValue::decode($original['payload']) : ($params['payload'] ?? null);
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
                if (in_array($action, ['confirm', 'record', 'correct', 'reverse_duplicate'], true)) {
                    if ($document['status'] !== 'pending') { throw new \DomainException('请先提交草稿再确认'); }
                    $ledger = new FinanceLedger($tenantId); $ledger->lockBook();
                    $result = $original ? (new FinanceCorrections($tenantId, $ledger))->replace($original, $document, (string)($params['correction_reason'] ?? ''),
                        $action === 'reverse_duplicate' ? FinanceValue::id($params['duplicate_of'] ?? 0) : 0)
                        : (new FinancePayments($tenantId, $ledger))->confirm($document);
                    $document['confirmed_result'] = FinanceValue::json($result);
                    $document['status'] = 'confirmed'; $document['confirmed_by'] = FinanceValue::json(FinanceAccess::actor()); $document['confirmed_at'] = time();
                }
                $document['version'] = $original ? 1 : $version + 1; $document['last_modified_by'] = FinanceValue::json(FinanceAccess::actor()); $document['update_time'] = time();
                Db::name('finance_document')->where('tenant_id', $tenantId)->where('id', $document['id'])->update($document);
                $result = self::present($document);
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
            FinanceDocumentPolicy::authorize($document['type']);
            return self::present($document) + ['replacement_document_id' => (int)(Db::name('finance_correction')->where('tenant_id', FinanceAccess::tenant())->where('original_document_id', $document['id'])->value('replacement_document_id') ?: 0)];
        } catch (\DomainException $error) { self::setError($error->getMessage()); return false; }
    }

    public static function options(array $params): array|false
    {
        self::clearError();
        try {
            $type = FinanceValue::text($params['type'] ?? '', 40); $policy = FinanceDocumentPolicy::authorize($type);
            $ledger = new FinanceLedger(FinanceAccess::tenant());
            $accounts = Db::name('finance_account')->where('tenant_id', FinanceAccess::tenant())->where('is_enabled', 1)->order('id')->field('id,name,account_type')->select()->toArray();
            $subjectId = FinanceValue::id($params['subject_id'] ?? 0, true);
            $categories = $type === 'advance_allocate' && ($params['role'] ?? '') === 'fund' ? ['advance'] : $policy['sources'];
            $page = max(1, FinanceValue::id($params['page'] ?? 1));
            $sources = $ledger->sourcePage($categories, $subjectId, $page);
            return ['tenant_id' => FinanceAccess::tenant(), 'type' => $type, 'policy' => $policy,
                'active' => Db::name('finance_opening_book')->where('tenant_id', FinanceAccess::tenant())->value('status') === 'active',
                'can_confirm' => FinanceAccess::owner() || (!$policy['owner'] && FinanceAccess::has($policy['confirm'])),
                'accounts' => $accounts] + $sources;
        } catch (\DomainException $error) { self::setError($error->getMessage()); return false; }
    }

    private static function present(array $document): array
    {
        foreach (['payload', 'confirmed_result', 'created_by', 'last_modified_by', 'confirmed_by'] as $key) { $document[$key] = FinanceValue::decode($document[$key]); }
        $document['id'] = (int)$document['id']; $document['version'] = (int)$document['version'];
        return $document;
    }

    public static function catalog(): array
    {
        $types = [];
        foreach (FinanceDocumentPolicy::TYPES as $type => $policy) {
            try { FinanceDocumentPolicy::authorize($type); $types[] = ['type' => $type, 'title' => $policy['title']]; }
            catch (\DomainException) { continue; }
        }
        return ['tenant_id' => FinanceAccess::tenant(), 'types' => $types,
            'active' => Db::name('finance_opening_book')->where('tenant_id', FinanceAccess::tenant())->value('status') === 'active'];
    }

    public static function lists(array $params): array|false
    {
        self::clearError();
        try {
            $type = FinanceValue::text($params['type'] ?? '', 40); FinanceDocumentPolicy::authorize($type);
            $page = FinanceValue::id($params['page'] ?? 1);
            $rows = Db::name('finance_document')->where('tenant_id', FinanceAccess::tenant())->where('type', $type)->order('id', 'desc')->page($page, 20)->select()->toArray();
            return ['tenant_id' => FinanceAccess::tenant(), 'lists' => array_map(self::present(...), $rows), 'page' => $page, 'has_more' => count($rows) === 20];
        } catch (\DomainException $error) { self::setError($error->getMessage()); return false; }
    }

    public static function subjects(array $params): array|false
    {
        self::clearError();
        try {
            $policy = FinanceDocumentPolicy::authorize(FinanceValue::text($params['type'] ?? '', 40));
            $column = ['customer' => 'customer_name', 'vendor' => 'supplier_name', 'employee' => 'name'][$policy['subject']];
            $query = Db::name($policy['subject'])->where('tenant_id', FinanceAccess::tenant());
            if ($policy['subject'] === 'customer') { $query->where('parent_id', 0); }
            if (!empty($params['id'])) { $query->where('id', FinanceValue::id($params['id'])); }
            $keyword = FinanceValue::text($params['keyword'] ?? '', 60, false);
            if ($keyword !== '') { $query->whereLike($column, '%' . addcslashes($keyword, '%_\\') . '%'); }
            $page = FinanceValue::id($params['page'] ?? 1);
            $rows = $query->field('id,' . $column . ' AS name')->order('id')->page($page, 20)->select()->toArray();
            return ['tenant_id' => FinanceAccess::tenant(), 'lists' => $rows, 'has_more' => count($rows) === 20];
        } catch (\DomainException $error) { self::setError($error->getMessage()); return false; }
    }
}
