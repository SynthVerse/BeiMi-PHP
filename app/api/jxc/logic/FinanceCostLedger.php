<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 由已授权的实物/采购确认在同一事务内调用；不提供任意调成本的 HTTP 入口。 */
final class FinanceCostLedger
{
    public function __construct(private readonly int $tenantId)
    {
        if ($tenantId <= 0) { throw new \DomainException('成本核算必须指定门店'); }
    }

    public function recordWithinTransaction(array $event): array
    {
        $pdo = Db::connect()->getPdo();
        if ($this->tenantId !== FinanceAccess::tenant() || !$pdo || !$pdo->inTransaction()) { throw new \DomainException('成本变化须与本门店业务在同一事务内确认'); }
        $ledger = new FinanceLedger($this->tenantId); $ledger->lockBook();
        $reference = FinanceValue::text($event['reference'] ?? null, 160);
        $sku = FinanceValue::id($event['sku_id'] ?? null); $warehouse = FinanceValue::id($event['warehouse_id'] ?? null);
        $date = FinanceValue::date($event['business_date'] ?? null);
        $fingerprint = hash('sha256', FinanceValue::json($event));
        $old = $this->query('finance_cost_event')->where('reference', $reference)->lock(true)->find();
        if ($old) {
            if (!hash_equals($old['fingerprint'], $fingerprint)) { throw new \DomainException('同一成本事件标识不能提交不同内容'); }
            return FinanceValue::decode($old['result']);
        }
        $ledger->postingMonth($date);
        $stored = $this->load($sku); $before = $this->withOpening($stored, $sku);
        $this->persist($stored, $before, []);
        $replayed = in_array($event['type'], ['count', 'count_reverse'], true) || (!in_array($event['type'], ['adjust', 'reestimate'], true)
            && $this->query('finance_cost_event')->where('sku_id', $sku)->whereIn('event_type', ['receive', 'issue', 'restore', 'transfer', 'reclassify', 'excluded_loss'])
                ->where('business_date', '>', $date)->lock(true)->find());
        $result = $replayed ? $this->replay($sku, $event) : $this->applyEvent($before, $event);
        $effectTotals = $result['effect_totals'] ?? null; unset($result['effect_totals']);
        $after = $result['state']; unset($result['state']);
        $result += ['reference' => $reference, 'business_date' => $date, 'balance' => FinanceCostAllocation::balance($after, $warehouse, $sku)];
        $id = (int)$this->query('finance_cost_event')->insertGetId(['tenant_id' => $this->tenantId, 'reference' => $reference,
            'fingerprint' => $fingerprint, 'event_type' => $event['type'], 'sku_id' => $sku, 'document_id' => FinanceValue::id($event['document_id'] ?? 0, true),
            'business_date' => $date, 'snapshot' => FinanceValue::json($event), 'result' => FinanceValue::json($result),
            'actor' => FinanceValue::json(FinanceAccess::actor()), 'create_time' => time()]);
        if ($effectTotals !== null) { $this->replayEffects($ledger, $id, $sku, $effectTotals); }
        else { $this->effects($ledger, $id, $event, $before, $after); }
        $this->persist($before, $after, $event['snapshot'] ?? []);
        return $result;
    }

    /** 确认记录和预览共用同一单据成本影响口径，未知金额保留已知部分。 */
    public function documentImpacts(int $document, array $pendingSkus = []): array
    {
        return $this->readConsistently(function () use ($document, $pendingSkus): array {
            $ids = $this->query('finance_cost_event')->where('document_id', $document)->column('id');
            if (!$ids) { return []; }
            $rows = $this->query('finance_cost_effect')->whereIn('event_id', $ids)
                ->field('warehouse_id,sku_id,bucket,posting_month,SUM(quantity_delta) AS quantity_delta,SUM(value_delta) AS value_delta')
                ->group('warehouse_id,sku_id,bucket,posting_month')->order('posting_month,warehouse_id,sku_id,bucket')->select()->toArray();
            foreach ($rows as &$row) {
                $row['warehouse_id'] = (int)$row['warehouse_id']; $row['sku_id'] = (int)$row['sku_id'];
                $row['quantity_delta'] = bcadd((string)$row['quantity_delta'], '0', 12);
                $row['value_delta'] = $row['value_delta'] === null ? null : bcadd((string)$row['value_delta'], '0', 6);
                $row['cost_pending'] = isset($pendingSkus[$row['sku_id']]); $row['known_value_delta'] = $row['value_delta'];
                if ($row['cost_pending']) { $row['value_delta'] = null; }
            } unset($row);
            return $rows;
        });
    }

    private function applyEvent(array $before, array $event): array
    {
        $sku = FinanceValue::id($event['sku_id']); $warehouse = FinanceValue::id($event['warehouse_id']);
        if ($event['type'] === 'reclassify' && ($event['skip_count_reclassification'] ?? false)) { return ['state' => $before]; }
        return match ($event['type'] ?? '') {
            'count' => FinanceCostAllocation::inventoryCount($before, $event),
            'count_reverse' => ['state' => $before],
            'excluded_loss' => FinanceCostAllocation::recognizeExcludedLoss($before, FinanceValue::text($event['origin'] ?? null, 160), $warehouse, $sku, $event['quantity'] ?? '', $event['amount'] ?? null, $event['target_reference'] ?? ''),
            'reclassify' => FinanceCostAllocation::reclassify($before, $warehouse, $sku, $event['quantity'] ?? '', $event['bucket'] ?? '', $event['target_reference'] ?? '', $event['to_bucket'] ?? '', $event['to_reference'] ?? ''),
            'receive' => FinanceCostAllocation::receive($before, FinanceValue::text($event['origin'] ?? null, 160), $warehouse, $sku, $event['quantity'] ?? '', $event['amount'] ?? null),
            'issue' => FinanceCostAllocation::issue($before, $warehouse, $sku, $event['quantity'] ?? '', $event['bucket'] ?? '', $event['target_reference'] ?? ''),
            'restore' => FinanceCostAllocation::restore($before, $warehouse, $sku, $event['quantity'] ?? '', $event['bucket'] ?? '', $event['target_reference'] ?? '',
                isset($event['to_warehouse_id']) ? FinanceValue::id($event['to_warehouse_id']) : null),
            'transfer' => FinanceCostAllocation::transfer($before, $warehouse, FinanceValue::id($event['to_warehouse_id'] ?? null), $sku, $event['quantity'] ?? '', $event['reference']),
            'adjust' => FinanceCostAllocation::adjust($before, FinanceValue::text($event['origin'] ?? null, 160), $event['amount'] ?? ''),
            'reestimate' => FinanceCostAllocation::reviseEstimate($before, FinanceValue::text($event['origin'] ?? null, 160), $event['amount'] ?? '',
                is_bool($event['pending'] ?? null) ? $event['pending'] : throw new \DomainException('必须明确成本余量是否待确认')),
            default => throw new \DomainException('成本事件类型无效'),
        };
    }

    /** 实物按实际日期、同日按确认顺序重放；后续确认的成本沿重新分配后的来源去向补差。 */
    private function replay(int $sku, array $incoming): array
    {
        $events = [];
        foreach ($this->query('finance_cost_event')->where('sku_id', $sku)->order('id')->lock(true)->select()->toArray() as $row) {
            $events[] = FinanceValue::decode($row['snapshot']) + ['_journal_id' => (int)$row['id']];
        }
        $events[] = $incoming + ['_journal_id' => PHP_INT_MAX];
        $state = $this->withOpening(FinanceCostAllocation::empty(), $sku); $result = []; $totals = []; $firstDates = [];
        foreach (FinanceCostReplayOrder::ordered($events) as $event) {
            $applied = $this->applyEvent($state, $event);
            $rows = $this->effectChanges($event, $state, $applied['state'], static function (array $row) use (&$firstDates): ?string {
                return $firstDates[FinanceValue::json([$row['warehouse_id'], $row['sku_id'], $row['bucket'], $row['reference']])] ?? null;
            });
            foreach ($rows as $row) {
                $key = FinanceValue::json([$row['warehouse_id'], $row['sku_id'], $row['bucket'], $row['reference']]);
                $firstDates[$key] ??= $row['business_date']; self::sumEffect($totals, $row);
            }
            $state = $applied['state'];
            if ($event['reference'] === $incoming['reference']) { $result = $applied; }
        }
        $result['state'] = $state;
        $result['effect_totals'] = $totals;
        return $result;
    }

    public function balance(int $warehouse, int $sku): array
    {
        return $this->readConsistently(fn(): array => FinanceCostAllocation::balance($this->withOpening($this->load($sku), $sku), $warehouse, $sku));
    }

    public function destination(int $warehouse, int $sku, string $bucket, string $reference): array
    {
        return $this->readConsistently(fn(): array => $this->destinationSnapshot($warehouse, $sku, $bucket, $reference));
    }

    private function destinationSnapshot(int $warehouse, int $sku, string $bucket, string $reference): array
    {
        $state = $this->load($sku); $quantity = '0.000000000000'; $value = '0.000000'; $unknown = '0.000000000000';
        foreach ($state['positions'] as $position) {
            if ($position['warehouse_id'] !== $warehouse || $position['bucket'] !== $bucket || $position['reference'] !== $reference) { continue; }
            $quantity = bcadd($quantity, $position['quantity'], 12); $value = bcadd($value, $position['value'], 6);
            if ($state['origins'][$position['origin']]['amount'] === null) { $unknown = bcadd($unknown, $position['quantity'], 12); }
        }
        $shortage = $state['shortages'][FinanceValue::json([$warehouse, $sku, $bucket, $reference])]['quantity'] ?? '0';
        $pending = bccomp(bcadd($unknown, $shortage, 12), '0', 12) > 0;
        return ['quantity' => bcadd($quantity, $shortage, 12), 'known_cost' => $value, 'cost' => $pending ? null : $value,
            'pending' => $pending, 'pending_quantity' => bcadd($unknown, $shortage, 12)];
    }

    public function events(int $sku, int $afterId = 0): array
    {
        $rows = $this->query('finance_cost_event')->where('sku_id', $sku)->where('id', '>', $afterId)->order('id')->limit(100)->select()->toArray();
        return array_map(static fn(array $row): array => ['id' => (int)$row['id'], 'reference' => $row['reference'], 'type' => $row['event_type'],
            'business_date' => $row['business_date'], 'snapshot' => FinanceValue::decode($row['snapshot']), 'result' => FinanceValue::decode($row['result'])], $rows);
    }

    public function postings(int $sku, int $afterId = 0): array
    {
        return $this->query('finance_cost_effect')->where('sku_id', $sku)->where('id', '>', $afterId)->order('id')->limit(200)->select()->toArray();
    }

    private function load(int $sku): array
    {
        $state = FinanceCostAllocation::empty();
        foreach ($this->query('finance_cost_origin')->where('sku_id', $sku)->order('id')->lock(true)->select()->toArray() as $row) {
            $state['origins'][$row['origin_key']] = ['quantity' => $row['quantity'], 'amount' => $row['current_amount'], 'sku_id' => (int)$row['sku_id']];
        }
        foreach ($this->query('finance_cost_position')->where('sku_id', $sku)->order('id')->lock(true)->select()->toArray() as $row) {
            $key = FinanceValue::json([$row['origin_key'], (int)$row['warehouse_id'], $sku, $row['bucket'], $row['reference']]);
            $state['positions'][$key] = ['origin' => $row['origin_key'], 'warehouse_id' => (int)$row['warehouse_id'], 'sku_id' => $sku,
                'bucket' => $row['bucket'], 'reference' => $row['reference'], 'quantity' => $row['quantity'], 'value' => $row['value']];
        }
        foreach ($this->query('finance_cost_shortage')->where('sku_id', $sku)->order('id')->lock(true)->select()->toArray() as $row) {
            $key = FinanceValue::json([(int)$row['warehouse_id'], $sku, $row['bucket'], $row['reference']]);
            $state['shortages'][$key] = ['warehouse_id' => (int)$row['warehouse_id'], 'sku_id' => $sku, 'bucket' => $row['bucket'], 'reference' => $row['reference'], 'quantity' => $row['quantity']];
        }
        return $state;
    }

    private function withOpening(array $state, int $sku): array
    {
        foreach ($this->query('finance_opening_source')->where('category', 'inventory')->order('id')->lock(true)->select()->toArray() as $row) {
            $snapshot = FinanceValue::decode($row['source_snapshot']); $subject = $snapshot['subject_snapshot'] ?? [];
            if ((int)($subject['sku_id'] ?? 0) !== $sku || isset($state['origins']['opening:' . $row['id']])) { continue; }
            if (bccomp($snapshot['details']['quantity'], '0', 4) === 0 && bccomp($row['amount'], '0', 2) === 0) { continue; }
            $state = FinanceCostAllocation::receive($state, 'opening:' . $row['id'], (int)$subject['warehouse_id'], $sku,
                $snapshot['details']['quantity'], $row['amount'])['state'];
        }
        return $state;
    }

    private function persist(array $before, array $after, array $snapshot): void
    {
        foreach ($after['origins'] as $key => $origin) {
            if (!isset($before['origins'][$key])) {
                $this->query('finance_cost_origin')->insert(['tenant_id' => $this->tenantId, 'origin_key' => $key, 'sku_id' => $origin['sku_id'],
                    'quantity' => $origin['quantity'], 'initial_amount' => $origin['amount'], 'current_amount' => $origin['amount'],
                    'snapshot' => FinanceValue::json(['initial_quantity' => $origin['quantity'], 'source' => $snapshot])]);
            } elseif ($before['origins'][$key] !== $origin) {
                if (!str_starts_with($key, 'pending-return:') && $before['origins'][$key]['quantity'] !== $origin['quantity']) { throw new \DomainException('正式入库来源数量不能被成本调整覆盖'); }
                $this->query('finance_cost_origin')->where('origin_key', $key)->update(['current_amount' => $origin['amount'], 'quantity' => $origin['quantity']]);
            }
        }
        foreach (array_diff_key($before['origins'], $after['origins']) as $key => $_) {
            if (!str_starts_with($key, 'pending-return:')) { throw new \DomainException('成本重放不能丢弃正式入库来源'); }
            $this->query('finance_cost_origin')->where('origin_key', $key)->delete();
        }
        foreach (['positions' => 'finance_cost_position', 'shortages' => 'finance_cost_shortage'] as $kind => $table) {
            foreach ($after[$kind] as $key => $row) {
                if (($before[$kind][$key] ?? null) === $row) { continue; }
                $data = $row; if ($kind === 'positions') { $data['origin_key'] = $data['origin']; unset($data['origin']); }
                if (isset($before[$kind][$key])) { $this->query($table)->where('position_key', hash('sha256', $key))->update($data); }
                else { $this->query($table)->insert($data + ['tenant_id' => $this->tenantId, 'position_key' => hash('sha256', $key)]); }
            }
            foreach (array_diff_key($before[$kind], $after[$kind]) as $key => $_) { $this->query($table)->where('position_key', hash('sha256', $key))->delete(); }
        }
    }

    private function effects(FinanceLedger $ledger, int $eventId, array $event, array $before, array $after): void
    {
        $rows = $this->effectChanges($event, $before, $after, fn(array $position): ?string => $this->query('finance_cost_effect')
            ->where('sku_id', $position['sku_id'])->where('warehouse_id', $position['warehouse_id'])->where('bucket', $position['bucket'])
            ->where('reference', $position['reference'])->order('id')->value('business_date'));
        foreach ($rows as $row) { $this->writeEffect($ledger, $eventId, $row); }
    }

    private function effectChanges(array $event, array $before, array $after, callable $originalDate): array
    {
        $rows = [];
        $beforeRows = self::effectRows($before); $afterRows = self::effectRows($after);
        foreach ($afterRows + $beforeRows as $key => $position) {
            $old = $beforeRows[$key] ?? ['quantity' => '0', 'value' => '0'];
            if (!isset($afterRows[$key])) { $position['quantity'] = '0'; $position['value'] = '0'; }
            $quantity = bcsub($position['quantity'], $old['quantity'], 12); $value = bcsub($position['value'], $old['value'], 6);
            if (bccomp($quantity, '0', 12) === 0 && bccomp($value, '0', 6) === 0) { continue; }
            $date = $event['business_date'];
            if ($position['bucket'] !== 'inventory' && (in_array($event['type'], ['receive', 'adjust', 'reestimate', 'transfer'], true)
                || ($event['type'] === 'restore' && $position['reference'] !== $event['target_reference']))) {
                $date = $originalDate($position) ?: $date;
            }
            $rows[] = ['origin_key' => $position['origin'],
                'warehouse_id' => $position['warehouse_id'], 'sku_id' => $position['sku_id'], 'bucket' => $position['bucket'], 'reference' => $position['reference'],
                'quantity_delta' => $quantity, 'value_delta' => $value, 'business_date' => $date];
        }
        return $rows;
    }

    private function writeEffect(FinanceLedger $ledger, int $eventId, array $row): void
    {
        $this->query('finance_cost_effect')->insert($row + ['tenant_id' => $this->tenantId, 'event_id' => $eventId, 'posting_month' => $ledger->postingMonth($row['business_date'])]);
    }

    private static function sumEffect(array &$totals, array $row): void
    {
        $key = FinanceValue::json([$row['origin_key'], $row['warehouse_id'], $row['sku_id'], $row['bucket'], $row['reference'], $row['business_date']]);
        $old = $totals[$key] ?? ['quantity_delta' => '0', 'value_delta' => '0'];
        $totals[$key] = $row;
        $totals[$key]['quantity_delta'] = bcadd($old['quantity_delta'], $row['quantity_delta'], 12);
        $totals[$key]['value_delta'] = bcadd($old['value_delta'], $row['value_delta'], 6);
    }

    /** 对比按事件日期重建的累计分录，保持库存与其后续去向的期间一致。 */
    private function replayEffects(FinanceLedger $ledger, int $eventId, int $sku, array $expected): void
    {
        $actual = [];
        foreach ($this->query('finance_cost_effect')->where('sku_id', $sku)->order('id')->lock(true)->select()->toArray() as $row) {
            unset($row['id'], $row['tenant_id'], $row['event_id'], $row['posting_month']);
            $row['sku_id'] = (int)$row['sku_id']; $row['warehouse_id'] = (int)$row['warehouse_id']; self::sumEffect($actual, $row);
        }
        foreach ($expected + $actual as $key => $row) {
            $row['quantity_delta'] = bcsub($expected[$key]['quantity_delta'] ?? '0', $actual[$key]['quantity_delta'] ?? '0', 12);
            $row['value_delta'] = bcsub($expected[$key]['value_delta'] ?? '0', $actual[$key]['value_delta'] ?? '0', 6);
            if (bccomp($row['quantity_delta'], '0', 12) !== 0 || bccomp($row['value_delta'], '0', 6) !== 0) { $this->writeEffect($ledger, $eventId, $row); }
        }
    }

    /** 缺成本也保存数量去向及原日期；补齐时冲回待补份额，不重复计算实物数量。 */
    private static function effectRows(array $state): array
    {
        $rows = $state['positions'];
        foreach ($state['origins'] as $key => $origin) {
            if (!preg_match('/^inventory-count-gain:([1-9][0-9]*):([1-9][0-9]*):([1-9][0-9]*)$/D', $key, $match)) { continue; }
            // 盘盈增加资产并减少损耗；对方价值跟随该来源补价，之后调拨不会迁移原盘盈仓库。
            $rows['count-gain:' . $key] = ['origin' => $key, 'warehouse_id' => (int)$match[2], 'sku_id' => (int)$match[3],
                'bucket' => 'loss', 'reference' => 'inventory-count:' . $match[1] . ':' . $match[3],
                'quantity' => bcsub('0', $origin['quantity'], 12), 'value' => bcsub('0', $origin['amount'] ?? '0', 6)];
        }
        foreach ($state['shortages'] as $key => $shortage) {
            $rows['shortage:' . $key] = $shortage + ['origin' => '', 'value' => '0.000000'];
            $inventoryKey = 'negative:' . FinanceValue::json([$shortage['warehouse_id'], $shortage['sku_id']]);
            $rows[$inventoryKey] = ['origin' => '', 'warehouse_id' => $shortage['warehouse_id'], 'sku_id' => $shortage['sku_id'],
                'bucket' => 'inventory', 'reference' => '', 'value' => '0.000000',
                'quantity' => bcsub($rows[$inventoryKey]['quantity'] ?? '0', $shortage['quantity'], 12)];
        }
        return $rows;
    }

    private function query(string $table): \think\db\Query { return Db::name($table)->where('tenant_id', $this->tenantId); }

    private function readConsistently(callable $read): array
    {
        $run = function () use ($read): array {
            (new FinanceLedger($this->tenantId))->lockBook();
            return $read();
        };
        $pdo = Db::connect()->getPdo();
        return $pdo && $pdo->inTransaction() ? $run() : Db::transaction($run);
    }
}
