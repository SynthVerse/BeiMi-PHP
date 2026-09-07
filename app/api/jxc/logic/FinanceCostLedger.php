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
        $result = match ($event['type'] ?? '') {
            'receive' => FinanceCostAllocation::receive($before, FinanceValue::text($event['origin'] ?? null, 160), $warehouse, $sku, $event['quantity'] ?? '', $event['amount'] ?? null),
            'issue' => FinanceCostAllocation::issue($before, $warehouse, $sku, $event['quantity'] ?? '', $event['bucket'] ?? '', $event['target_reference'] ?? ''),
            'restore' => FinanceCostAllocation::restore($before, $warehouse, $sku, $event['quantity'] ?? '', $event['bucket'] ?? '', $event['target_reference'] ?? '',
                isset($event['to_warehouse_id']) ? FinanceValue::id($event['to_warehouse_id']) : null),
            'transfer' => FinanceCostAllocation::transfer($before, $warehouse, FinanceValue::id($event['to_warehouse_id'] ?? null), $sku, $event['quantity'] ?? '', $reference),
            'adjust' => FinanceCostAllocation::adjust($before, FinanceValue::text($event['origin'] ?? null, 160), $event['amount'] ?? ''),
            default => throw new \DomainException('成本事件类型无效'),
        };
        $after = $result['state']; unset($result['state']);
        $result += ['reference' => $reference, 'business_date' => $date, 'balance' => FinanceCostAllocation::balance($after, $warehouse, $sku)];
        $id = (int)$this->query('finance_cost_event')->insertGetId(['tenant_id' => $this->tenantId, 'reference' => $reference,
            'fingerprint' => $fingerprint, 'event_type' => $event['type'], 'sku_id' => $sku, 'document_id' => FinanceValue::id($event['document_id'] ?? 0, true),
            'business_date' => $date, 'snapshot' => FinanceValue::json($event), 'result' => FinanceValue::json($result),
            'actor' => FinanceValue::json(FinanceAccess::actor()), 'create_time' => time()]);
        $this->effects($ledger, $id, $event, $before, $after);
        $this->persist($before, $after, $event['snapshot'] ?? []);
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
        $beforeRows = self::effectRows($before); $afterRows = self::effectRows($after);
        foreach ($afterRows + $beforeRows as $key => $position) {
            $old = $beforeRows[$key] ?? ['quantity' => '0', 'value' => '0'];
            if (!isset($afterRows[$key])) { $position['quantity'] = '0'; $position['value'] = '0'; }
            $quantity = bcsub($position['quantity'], $old['quantity'], 12); $value = bcsub($position['value'], $old['value'], 6);
            if (bccomp($quantity, '0', 12) === 0 && bccomp($value, '0', 6) === 0) { continue; }
            $date = $event['business_date'];
            if ($position['bucket'] !== 'inventory' && (in_array($event['type'], ['receive', 'adjust', 'transfer'], true)
                || ($event['type'] === 'restore' && $position['reference'] !== $event['target_reference']))) {
                $date = $this->query('finance_cost_effect')->where('sku_id', $position['sku_id'])->where('warehouse_id', $position['warehouse_id'])
                    ->where('bucket', $position['bucket'])->where('reference', $position['reference'])->order('id')->value('business_date') ?: $date;
            }
            $this->query('finance_cost_effect')->insert(['tenant_id' => $this->tenantId, 'event_id' => $eventId, 'origin_key' => $position['origin'],
                'warehouse_id' => $position['warehouse_id'], 'sku_id' => $position['sku_id'], 'bucket' => $position['bucket'], 'reference' => $position['reference'],
                'quantity_delta' => $quantity, 'value_delta' => $value, 'business_date' => $date, 'posting_month' => $ledger->postingMonth($date)]);
        }
    }

    /** 缺成本也保存数量去向及原日期；补齐时冲回待补份额，不重复计算实物数量。 */
    private static function effectRows(array $state): array
    {
        $rows = $state['positions'];
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
