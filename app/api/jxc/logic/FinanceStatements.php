<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 对账只保存冻结快照与有依据的人工回复，不自动发送、不生成收付款。 */
final class FinanceStatements
{
    public static function action(string $action, array $params): array
    {
        FinanceAccess::require('finance.receivable.view');
        FinanceAccess::require('finance.receivable.prepare', $action === 'resolve');
        if (!in_array($action, ['generate', 'reply', 'resolve'], true)) { throw new \DomainException('对账操作无效'); }
        $tenant = FinanceAccess::tenant();
        if (FinanceValue::id($params['expected_tenant_id'] ?? 0) !== $tenant) { throw new \DomainException('门店已变化，请重新核实后操作'); }
        $key = FinanceValue::text($params['idempotency_key'] ?? '', 96);
        if (!preg_match('/^[a-zA-Z0-9_-]{16,96}$/D', $key)) { throw new \DomainException('提交标识无效'); }
        if ($action === 'reply' && ($params['response'] ?? '') === 'disputed') {
            foreach (is_array($params['items'] ?? null) ? $params['items'] : [] as $item) { if (is_array($item) && ($item['ledger_uncertain'] ?? null) === false) { FinanceAccess::require('finance.receivable.prepare', true); } }
        }
        $fingerprint = hash('sha256', FinanceValue::json(['statement_' . $action, $params, FinanceAccess::actor()['id'], FinanceAccess::actor()['type']]));
        return Db::transaction(function () use ($action, $params, $tenant, $key, $fingerprint) {
            (new FinanceLedger($tenant))->lockBook();
            $command = Db::name('finance_command')->where('tenant_id', $tenant)->where('idempotency_key', $key)->find();
            if ($command) {
                if ($command['fingerprint'] !== $fingerprint) { throw new \DomainException('同一提交标识不能用于不同对账内容'); }
                return FinanceValue::decode($command['result']);
            }
            if ($action === 'generate') { $id = self::generate($params); }
            else {
                $id = FinanceValue::id($params['id'] ?? 0); $statement = self::detail(['id' => $id]);
                if (FinanceValue::id($params['expected_version'] ?? null, true) !== $statement['version']) { throw new \DomainException('对账回复已变化，请重新读取后处理'); }
                if ($action === 'reply') { self::reply($statement, $params); } else { self::resolve($statement, $params); }
            }
            $result = self::detail(['id' => $id]);
            Db::name('finance_command')->insert(['tenant_id' => $tenant, 'idempotency_key' => $key, 'fingerprint' => $fingerprint, 'document_id' => 0,
                'action' => 'statement_' . $action, 'actor' => FinanceValue::json(FinanceAccess::actor()), 'result' => FinanceValue::json($result), 'create_time' => time()]);
            return $result;
        });
    }

    public static function lists(array $params): array
    {
        FinanceAccess::require('finance.receivable.view'); $tenant = FinanceAccess::tenant();
        $customer = FinanceValue::id($params['customer_id'] ?? 0); $identity = self::customer($customer);
        $page = FinanceValue::id($params['page'] ?? 1);
        $rows = Db::name('finance_statement')->where('tenant_id', $tenant)->where('customer_id', $customer)->order('id', 'desc')->limit(($page - 1) * 20, 21)->column('id');
        $more = count($rows) > 20; $results = [];
        foreach (array_slice($rows, 0, 20) as $id) { $row = self::detail(['id' => $id]); unset($row['snapshot'], $row['events'], $row['disputes']); $results[] = $row; }
        return ['tenant_id' => $tenant, 'customer' => $identity, 'lists' => $results, 'has_more' => $more, 'can_prepare' => FinanceAccess::has('finance.receivable.prepare')];
    }

    public static function detail(array $params): array
    {
        FinanceAccess::require('finance.receivable.view'); $tenant = FinanceAccess::tenant();
        $row = Db::name('finance_statement')->where('tenant_id', $tenant)->where('id', FinanceValue::id($params['id'] ?? 0))->find();
        if (!$row || !FinanceIntegration::active()) { throw new \DomainException('对账单不存在或财务账套尚未启用'); }
        $events = Db::name('finance_statement_event')->where('tenant_id', $tenant)->where('statement_id', $row['id'])->order('id')->select()->toArray();
        $state = 'unanswered';
        foreach ($events as &$event) { $event['payload'] = FinanceValue::decode($event['payload']); $event['actor'] = FinanceValue::decode($event['actor']); if ($event['kind'] === 'reply') { $state = $event['payload']['response']; } }
        $disputes = self::openDisputes([], (int)$row['id']);
        if ($disputes) { $state = 'disputed'; }
        elseif ($state === 'disputed') { $state = 'awaiting_reconfirmation'; }
        $row['id'] = (int)$row['id']; $row['previous_id'] = (int)$row['previous_id']; $row['customer_id'] = (int)$row['customer_id'];
        $row['snapshot'] = FinanceValue::decode($row['snapshot']); $row['actor'] = FinanceValue::decode($row['actor']);
        return $row + ['version' => count($events), 'events' => $events, 'state' => $state, 'disputes' => $disputes,
            'can_prepare' => FinanceAccess::has('finance.receivable.prepare'), 'can_resolve' => FinanceAccess::owner()];
    }

    /** 用于坏账确认与月结核对；外部争议也不能视为无争议债务。 */
    public static function openDisputes(array $sources = [], int $statementId = 0): array
    {
        $tenant = FinanceAccess::tenant();
        $latest = Db::name('finance_statement_resolution')->where('tenant_id', $tenant)->field('MAX(id) AS id')->group('dispute_id')->buildSql();
        $resolution = Db::name('finance_statement_resolution')->where('tenant_id', $tenant)->whereRaw('id IN ' . $latest)->buildSql();
        $query = Db::name('finance_statement_dispute')->alias('d')->leftJoin([$resolution => 'r'], 'r.dispute_id=d.id AND r.tenant_id=d.tenant_id')
            ->where('d.tenant_id', $tenant)->whereRaw("(r.resolution IS NULL OR r.resolution<>'resolved')");
        if ($sources) { $query->whereIn('d.source_ref', $sources); }
        if ($statementId) { $query->where('d.statement_id', $statementId); }
        $rows = $query->field('d.*,r.resolution')->order('d.id')->select()->toArray();
        foreach ($rows as &$row) { $row['id'] = (int)$row['id']; $row['ledger_uncertain'] = $row['resolution'] === 'ledger_verified' ? false : (bool)$row['ledger_uncertain']; }
        return $rows;
    }

    private static function customer(int $id): array
    {
        $row = Db::name('customer')->where('tenant_id', FinanceAccess::tenant())->where('id', $id)->where('parent_id', 0)->field('id,customer_name')->find();
        if (!$row || !FinanceIntegration::active()) { throw new \DomainException('请选择本门店的主客户及已启用的财务账套'); }
        return $row;
    }

    private static function generate(array $params): int
    {
        $customer = self::customer(FinanceValue::id($params['customer_id'] ?? 0));
        $from = FinanceValue::date($params['date_from'] ?? date('Y-m-01')); $to = FinanceValue::date($params['date_to'] ?? date('Y-m-d'));
        $previous = FinanceValue::id($params['previous_id'] ?? 0, true);
        if ($previous && self::detail(['id' => $previous])['customer_id'] !== (int)$customer['id']) { throw new \DomainException('新版本只能关联同一主客户的旧对账单'); }
        $snapshot = FinanceStatementSnapshot::capture((int)$customer['id'], $from, $to) + ['customer' => $customer];
        return (int)Db::name('finance_statement')->insertGetId(['tenant_id' => FinanceAccess::tenant(), 'customer_id' => $customer['id'], 'previous_id' => $previous,
            'date_from' => $from, 'date_to' => $to, 'snapshot' => FinanceValue::json($snapshot), 'actor' => FinanceValue::json(FinanceAccess::actor()), 'create_time' => time()]);
    }

    private static function reply(array $statement, array $params): void
    {
        $response = FinanceValue::text($params['response'] ?? '', 24);
        if (!in_array($response, ['confirmed', 'partial', 'disputed'], true)) { throw new \DomainException('请选择客户全部确认、部分确认或提出异议'); }
        $date = FinanceValue::date($params['response_date'] ?? null);
        if ($date > date('Y-m-d') || $date < date('Y-m-d', (int)$statement['create_time'])) { throw new \DomainException('客户回复日期须在本版生成日至今天之间'); }
        $payload = ['response' => $response, 'respondent' => FinanceValue::text($params['respondent'] ?? '', 100), 'response_date' => $date,
            'evidence' => FinanceValue::text($params['evidence'] ?? '', 2000), 'items' => []];
        $items = $params['items'] ?? []; $seen = []; $sources = array_column($statement['snapshot']['sources'], null, 'reference');
        if (!is_array($items) || !array_is_list($items) || count($items) > 200 || ($response !== 'confirmed' && !$items) || ($response === 'confirmed' && $items)) { throw new \DomainException('部分确认或异议须明确逐项金额，全部确认不能混入部分明细'); }
        foreach ($items as $item) {
            if (!is_array($item)) { throw new \DomainException('回复组成无效'); }
            $ref = FinanceValue::text($item['source'] ?? '', 40); $amount = FinanceValue::money($item['amount'] ?? null);
            if (!isset($sources[$ref]) || isset($seen[$ref]) || bccomp($amount, $sources[$ref]['reply_limit'], 2) > 0) { throw new \DomainException('回复须选择本版不重复的来源，金额不得超过本版记录的业务金额'); }
            $seen[$ref] = true;
            $normalized = ['source' => $ref, 'amount' => $amount];
            if ($response === 'disputed') {
                if (!is_bool($item['ledger_uncertain'] ?? null)) { throw new \DomainException('请明确异议是否影响账内金额或归属'); }
                if (!$item['ledger_uncertain']) { FinanceAccess::require('finance.receivable.prepare', true); }
                $normalized += ['reason' => FinanceValue::text($item['reason'] ?? '', 1000), 'ledger_uncertain' => $item['ledger_uncertain']];
            }
            $payload['items'][] = $normalized;
        }
        $event = self::event($statement['id'], 'reply', $payload);
        if ($response === 'disputed') {
            foreach ($payload['items'] as $item) { Db::name('finance_statement_dispute')->insert(['tenant_id' => FinanceAccess::tenant(), 'statement_id' => $statement['id'], 'event_id' => $event,
                'source_ref' => $item['source'], 'amount' => $item['amount'], 'reason' => $item['reason'], 'ledger_uncertain' => (int)$item['ledger_uncertain']]); }
        }
    }

    private static function resolve(array $statement, array $params): void
    {
        $id = FinanceValue::id($params['dispute_id'] ?? 0); $resolution = FinanceValue::text($params['resolution'] ?? '', 24);
        if (!in_array($resolution, ['ledger_verified', 'resolved'], true) || !in_array($id, array_column($statement['disputes'], 'id'), true)) { throw new \DomainException('请选择本对账单尚未解决的异议及有效处理结果'); }
        $payload = ['dispute_id' => $id, 'resolution' => $resolution, 'reason' => FinanceValue::text($params['reason'] ?? '', 2000)];
        $event = self::event($statement['id'], 'resolve', $payload);
        Db::name('finance_statement_resolution')->insert(['tenant_id' => FinanceAccess::tenant(), 'dispute_id' => $id, 'event_id' => $event, 'resolution' => $resolution]);
    }

    private static function event(int $statement, string $kind, array $payload): int
    {
        return (int)Db::name('finance_statement_event')->insertGetId(['tenant_id' => FinanceAccess::tenant(), 'statement_id' => $statement, 'kind' => $kind,
            'payload' => FinanceValue::json($payload), 'actor' => FinanceValue::json(FinanceAccess::actor()), 'create_time' => time()]);
    }
}
