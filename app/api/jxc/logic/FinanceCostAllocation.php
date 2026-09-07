<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

/** 成本数量份额保留12位、价值6位；来源成本改变时沿已保存去向追加差额。 */
final class FinanceCostAllocation
{
    public static function empty(): array { return ['origins' => [], 'positions' => [], 'shortages' => []]; }

    public static function receive(array $state, string $origin, int $warehouse, int $sku, string $quantity, ?string $amount): array
    {
        $quantity = self::quantity($quantity); self::dimension($warehouse, $sku); FinanceValue::text($origin, 160);
        if (str_starts_with($origin, 'pending-return:')) { throw new \DomainException('待补成本占位不能作为独立采购来源入库'); }
        if (isset($state['origins'][$origin])) { throw new \DomainException('成本来源已经存在，不能重复入库计价'); }
        $amount = $amount === null ? null : FinanceValue::money($amount, true);
        $state['origins'][$origin] = ['quantity' => $quantity, 'amount' => $amount, 'sku_id' => $sku];
        $offsets = self::receiveShare($state, $origin, $warehouse, $sku, $quantity, bcadd($amount ?? '0', '0', 6));
        return ['state' => $state, 'offsets' => $offsets, 'pending' => $amount === null];
    }

    public static function issue(array $state, int $warehouse, int $sku, string $quantity, string $bucket, string $reference): array
    {
        if (!in_array($bucket, ['sale', 'loss', 'return', 'pending'], true)) { throw new \DomainException('请指定销售、损耗、采购退货或待核实的真实成本去向'); }
        return self::move($state, $warehouse, $warehouse, $sku, self::quantity($quantity), $bucket, FinanceValue::text($reference, 160), true);
    }

    public static function transfer(array $state, int $from, int $to, int $sku, string $quantity, string $reference): array
    {
        if ($from === $to) { throw new \DomainException('调拨须选择不同核算仓库'); } self::dimension($to, $sku); FinanceValue::text($reference, 160);
        return self::move($state, $from, $to, $sku, self::quantity($quantity), 'inventory', '', false);
    }

    /** 真实退回或实交更正按原去向还原；不使用退回当天的新平均价。 */
    public static function restore(array $state, int $warehouse, int $sku, string $quantity, string $bucket, string $reference, ?int $toWarehouse = null): array
    {
        self::dimension($warehouse, $sku); $quantity = self::quantity($quantity); FinanceValue::text($reference, 160);
        $toWarehouse ??= $warehouse; self::dimension($toWarehouse, $sku);
        if (!in_array($bucket, ['sale', 'loss', 'return', 'pending'], true)) { throw new \DomainException('请指定需要还原的原始成本去向'); }
        $weights = []; $total = '0.000000000000';
        foreach ($state['positions'] as $key => $position) {
            if ($position['warehouse_id'] === $warehouse && $position['sku_id'] === $sku && $position['bucket'] === $bucket && $position['reference'] === $reference && bccomp($position['quantity'], '0', 12) > 0) {
                $weights[$key] = $position['quantity']; $total = bcadd($total, $position['quantity'], 12);
            }
        }
        $shortageKey = FinanceValue::json([$warehouse, $sku, $bucket, $reference]);
        $unpriced = $state['shortages'][$shortageKey]['quantity'] ?? '0';
        if (bccomp($unpriced, '0', 12) > 0) { $weights['shortage'] = $unpriced; $total = bcadd($total, $unpriced, 12); }
        if (bccomp($quantity, $total, 12) > 0) { throw new \DomainException('退回数量超过原去向尚可还原的数量'); }
        $parts = self::quantityShares($quantity, $weights, $total); $unpricedReturn = $parts['shortage'] ?? '0'; unset($parts['shortage']);
        if (bccomp($unpricedReturn, '0', 12) > 0) {
            $state['shortages'][$shortageKey]['quantity'] = bcsub($unpriced, $unpricedReturn, 12);
            if (bccomp($state['shortages'][$shortageKey]['quantity'], '0', 12) === 0) { unset($state['shortages'][$shortageKey]); }
        }
        $cost = '0.000000'; $pending = bccomp($unpricedReturn, '0', 12) > 0; $movements = []; $offsets = [];
        if ($toWarehouse !== $warehouse && bccomp($unpricedReturn, '0', 12) > 0) {
            // 原仓的实物缺口仍在；实际退回仓先持有未知成本份额，取得来源后按其届时去向补价。
            $placeholder = 'pending-return:' . hash('sha256', FinanceValue::json([$warehouse, $sku, $bucket, $reference, $toWarehouse]));
            $state['origins'][$placeholder] = ['quantity' => bcadd($state['origins'][$placeholder]['quantity'] ?? '0', $unpricedReturn, 12), 'amount' => null, 'sku_id' => $sku];
            $fundingKey = FinanceValue::json([$warehouse, $sku, 'funding', $placeholder]);
            $state['shortages'][$fundingKey] = ['warehouse_id' => $warehouse, 'sku_id' => $sku, 'bucket' => 'funding', 'reference' => $placeholder,
                'quantity' => bcadd($state['shortages'][$fundingKey]['quantity'] ?? '0', $unpricedReturn, 12)];
            $offsets = self::receiveShare($state, $placeholder, $toWarehouse, $sku, $unpricedReturn, '0.000000');
        }
        foreach ($parts as $key => $take) {
            if (bccomp($take, '0', 12) === 0) { continue; }
            $position = $state['positions'][$key]; $value = self::takeValue($position['value'], $take, $position['quantity']);
            $state['positions'][$key]['quantity'] = bcsub($position['quantity'], $take, 12);
            $state['positions'][$key]['value'] = bcsub($position['value'], $value, 6);
            $unknown = $state['origins'][$position['origin']]['amount'] === null; $pending = $pending || $unknown; $cost = bcadd($cost, $value, 6);
            $movements[] = ['origin' => $position['origin'], 'quantity' => $take, 'value' => $value, 'pending' => $unknown, 'warehouse_id' => $warehouse, 'bucket' => $bucket, 'reference' => $reference];
            array_push($offsets, ...self::receiveShare($state, $position['origin'], $toWarehouse, $sku, $take, $value));
        }
        return ['state' => $state, 'known_cost' => $cost, 'cost' => $pending ? null : $cost, 'pending' => $pending, 'movements' => $movements, 'offsets' => $offsets, 'unpriced_return' => bcadd($unpricedReturn, '0', 12)];
    }

    private static function move(array $state, int $from, int $to, int $sku, string $quantity, string $bucket, string $reference, bool $allowNegative): array
    {
        self::dimension($from, $sku); $weights = []; $total = '0.000000000000';
        foreach ($state['positions'] as $key => $position) {
            if ($position['warehouse_id'] === $from && $position['sku_id'] === $sku && $position['bucket'] === 'inventory' && bccomp($position['quantity'], '0', 12) > 0) {
                $weights[$key] = $position['quantity']; $total = bcadd($total, $position['quantity'], 12);
            }
        }
        $taken = self::minimum($quantity, $total); $shortage = bcsub($quantity, $taken, 12);
        if (!$allowNegative && bccomp($shortage, '0', 12) > 0) { throw new \DomainException('调拨数量超过来源仓库可用的成本数量'); }
        $allocations = self::quantityShares($taken, $weights, $total); $cost = '0.000000'; $pending = bccomp($shortage, '0', 12) > 0; $movements = [];
        foreach ($allocations as $key => $take) {
            if (bccomp($take, '0', 12) === 0) { continue; }
            $position = $state['positions'][$key]; $part = self::takeValue($position['value'], $take, $position['quantity']);
            $state['positions'][$key]['quantity'] = bcsub($position['quantity'], $take, 12);
            $state['positions'][$key]['value'] = bcsub($position['value'], $part, 6);
            if ($bucket === 'inventory') {
                self::receiveShare($state, $position['origin'], $to, $sku, $take, $part);
            } else { self::put($state, $position['origin'], $to, $sku, $bucket, $reference, $take, $part); }
            $unknown = $state['origins'][$position['origin']]['amount'] === null;
            $pending = $pending || $unknown; $cost = bcadd($cost, $part, 6);
            $movements[] = ['origin' => $position['origin'], 'quantity' => $take, 'value' => $part, 'pending' => $unknown, 'warehouse_id' => $to, 'bucket' => $bucket, 'reference' => $reference];
        }
        if (bccomp($shortage, '0', 12) > 0) {
            $key = FinanceValue::json([$from, $sku, $bucket, $reference]);
            $state['shortages'][$key] = ['warehouse_id' => $from, 'sku_id' => $sku, 'bucket' => $bucket, 'reference' => $reference,
                'quantity' => bcadd($state['shortages'][$key]['quantity'] ?? '0', $shortage, 12)];
        }
        return ['state' => $state, 'known_cost' => $cost, 'cost' => $pending ? null : $cost, 'pending' => $pending, 'shortage' => $shortage, 'movements' => $movements];
    }

    /** 分次结算补入已知价值；原到货尚有未知余量时，全部混合去向仍标记待确认。 */
    public static function reviseEstimate(array $state, string $origin, string $knownAmount, bool $pending): array
    {
        $result = self::adjust($state, $origin, $knownAmount);
        if ($pending) { $result['state']['origins'][$origin]['amount'] = null; }
        $result['pending'] = $pending;
        return $result;
    }

    public static function adjust(array $state, string $origin, string $newAmount): array
    {
        if (str_starts_with($origin, 'pending-return:')) { throw new \DomainException('待补成本须由原仓实际来源补齐，不能直接改写占位成本'); }
        $source = $state['origins'][$origin] ?? throw new \DomainException('原成本来源不存在'); $newAmount = FinanceValue::money($newAmount, true);
        $weights = []; $total = '0.000000000000';
        foreach ($state['positions'] as $key => $position) {
            if ($position['origin'] === $origin && bccomp($position['quantity'], '0', 12) > 0) { $weights[$key] = $position['quantity']; $total = bcadd($total, $position['quantity'], 12); }
        }
        if (bccomp($total, $source['quantity'], 12) !== 0) { throw new \DomainException('成本来源去向数量不守恒，请核对来源'); }
        $left = bcadd($newAmount, '0', 6); $leftQuantity = $total; $changes = ['inventory' => '0.000000', 'sale' => '0.000000', 'loss' => '0.000000', 'return' => '0.000000']; $details = [];
        foreach ($weights as $key => $quantity) {
            $position = $state['positions'][$key]; $value = self::takeValue($left, $quantity, $leftQuantity); $delta = bcsub($value, $position['value'], 6);
            $state['positions'][$key]['value'] = $value; $left = bcsub($left, $value, 6); $leftQuantity = bcsub($leftQuantity, $quantity, 12);
            $changes[$position['bucket']] = bcadd($changes[$position['bucket']] ?? '0', $delta, 6);
            $details[] = $position + ['new_value' => $value, 'delta' => $delta];
        }
        $state['origins'][$origin]['amount'] = $newAmount;
        return ['state' => $state, 'previous_amount' => $source['amount'], 'amount' => $newAmount, 'changes' => $changes, 'details' => $details];
    }

    public static function balance(array $state, int $warehouse, int $sku): array
    {
        self::dimension($warehouse, $sku); $quantity = '0.000000000000'; $value = '0.000000'; $unknown = '0.000000000000'; $shortage = '0.000000000000';
        foreach ($state['positions'] as $position) {
            if ($position['warehouse_id'] !== $warehouse || $position['sku_id'] !== $sku || $position['bucket'] !== 'inventory') { continue; }
            $quantity = bcadd($quantity, $position['quantity'], 12); $value = bcadd($value, $position['value'], 6);
            if ($state['origins'][$position['origin']]['amount'] === null) { $unknown = bcadd($unknown, $position['quantity'], 12); }
        }
        foreach ($state['shortages'] as $row) { if ($row['warehouse_id'] === $warehouse && $row['sku_id'] === $sku) { $shortage = bcadd($shortage, $row['quantity'], 12); } }
        $quantity = bcsub($quantity, $shortage, 12); $pending = bccomp($unknown, '0', 12) > 0 || bccomp($shortage, '0', 12) > 0;
        return ['quantity' => $quantity, 'known_value' => $value, 'value' => $pending ? null : $value, 'pending' => $pending, 'pending_quantity' => $unknown, 'negative_quantity' => $shortage,
            'unit_cost' => $pending || bccomp($quantity, '0', 12) <= 0 ? null : bcdiv($value, $quantity, 12)];
    }

    private static function put(array &$state, string $origin, int $warehouse, int $sku, string $bucket, string $reference, string $quantity, string $value): void
    {
        $key = FinanceValue::json([$origin, $warehouse, $sku, $bucket, $reference]); $old = $state['positions'][$key] ?? ['quantity' => '0', 'value' => '0'];
        $state['positions'][$key] = ['origin' => $origin, 'warehouse_id' => $warehouse, 'sku_id' => $sku, 'bucket' => $bucket, 'reference' => $reference,
            'quantity' => bcadd($old['quantity'], $quantity, 12), 'value' => bcadd($old['value'], $value, 6)];
    }

    /** 入库、调入及实物退回共用一处补缺与尾差规则。 */
    private static function receiveShare(array &$state, string $origin, int $warehouse, int $sku, string $quantity, string $value): array
    {
        $remaining = $quantity; $offsets = []; $unknown = $state['origins'][$origin]['amount'] === null;
        foreach ($state['shortages'] as $key => $shortage) {
            if ($shortage['warehouse_id'] !== $warehouse || $shortage['sku_id'] !== $sku || bccomp($remaining, '0', 12) <= 0) { continue; }
            $take = self::minimum($remaining, $shortage['quantity']); $part = self::takeValue($value, $take, $remaining);
            if ($shortage['bucket'] === 'funding') {
                array_push($offsets, ...self::fundReturnedShare($state, $shortage['reference'], $origin, $take, $part));
            } else {
                self::put($state, $origin, $warehouse, $sku, $shortage['bucket'], $shortage['reference'], $take, $part);
                $offsets[] = ['origin' => $origin, 'warehouse_id' => $warehouse, 'sku_id' => $sku, 'bucket' => $shortage['bucket'], 'reference' => $shortage['reference'],
                    'quantity' => $take, 'value' => $part, 'cost' => $unknown ? null : $part, 'pending' => $unknown];
            }
            $remaining = bcsub($remaining, $take, 12); $value = bcsub($value, $part, 6);
            $state['shortages'][$key]['quantity'] = bcsub($shortage['quantity'], $take, 12);
            if (bccomp($state['shortages'][$key]['quantity'], '0', 12) === 0) { unset($state['shortages'][$key]); }
        }
        if (bccomp($remaining, '0', 12) > 0) { self::put($state, $origin, $warehouse, $sku, 'inventory', '', $remaining, $value); }
        return $offsets;
    }

    private static function fundReturnedShare(array &$state, string $placeholder, string $origin, string $quantity, string $value): array
    {
        $source = $state['origins'][$placeholder] ?? throw new \DomainException('跨仓退回的待补成本来源缺失');
        if (!str_starts_with($placeholder, 'pending-return:') || bccomp($source['quantity'], $quantity, 12) < 0) { throw new \DomainException('跨仓退回待补数量不一致'); }
        if ($placeholder === $origin) {
            // 未知成本库存回到最初缺货仓，正好补回自身的实物缺口，无须虚造采购成本。
            $state['origins'][$placeholder]['quantity'] = bcsub($source['quantity'], $quantity, 12);
            return [];
        }
        $weights = []; $total = '0.000000000000';
        foreach ($state['positions'] as $key => $position) {
            if ($position['origin'] === $placeholder && bccomp($position['quantity'], '0', 12) > 0) { $weights[$key] = $position['quantity']; $total = bcadd($total, $position['quantity'], 12); }
        }
        if (bccomp($total, $source['quantity'], 12) !== 0) { throw new \DomainException('跨仓退回的待补成本去向数量不守恒'); }
        $leftQuantity = $quantity; $leftValue = $value; $offsets = []; $unknown = $state['origins'][$origin]['amount'] === null;
        foreach (self::quantityShares($quantity, $weights, $total) as $key => $take) {
            if (bccomp($take, '0', 12) === 0) { continue; }
            $position = $state['positions'][$key]; $part = self::takeValue($leftValue, $take, $leftQuantity);
            $state['positions'][$key]['quantity'] = bcsub($position['quantity'], $take, 12);
            self::put($state, $origin, $position['warehouse_id'], $position['sku_id'], $position['bucket'], $position['reference'], $take, $part);
            $offsets[] = ['origin' => $origin, 'warehouse_id' => $position['warehouse_id'], 'sku_id' => $position['sku_id'], 'bucket' => $position['bucket'], 'reference' => $position['reference'],
                'quantity' => $take, 'value' => $part, 'cost' => $unknown ? null : $part, 'pending' => $unknown];
            $leftQuantity = bcsub($leftQuantity, $take, 12); $leftValue = bcsub($leftValue, $part, 6);
        }
        $state['origins'][$placeholder]['quantity'] = bcsub($source['quantity'], $quantity, 12);
        return $offsets;
    }

    private static function quantityShares(string $amount, array $weights, string $total): array
    {
        if (bccomp($total, '0', 12) === 0) { return []; } $parts = []; $left = $amount;
        foreach ($weights as $key => $weight) { $parts[$key] = bcdiv(bcmul($amount, $weight, 24), $total, 12); $left = bcsub($left, $parts[$key], 12); }
        foreach ($parts as $key => $part) { if (bccomp($left, '0', 12) <= 0) { break; } $tail = self::minimum($left, bcsub($weights[$key], $part, 12)); $parts[$key] = bcadd($part, $tail, 12); $left = bcsub($left, $tail, 12); }
        if (bccomp($left, '0', 12) !== 0) { throw new \DomainException('成本数量分摊尾差未闭合'); } return $parts;
    }

    private static function takeValue(string $value, string $quantity, string $total): string
    {
        return bccomp($quantity, $total, 12) === 0 ? bcadd($value, '0', 6) : bcdiv(bcmul($value, $quantity, 18), $total, 6);
    }

    private static function minimum(string $left, string $right): string { return bccomp($left, $right, 12) <= 0 ? $left : $right; }
    private static function dimension(int $warehouse, int $sku): void { if ($warehouse <= 0 || $sku <= 0) { throw new \DomainException('请选择合法核算仓库和SKU'); } }
    private static function quantity(string $value): string
    {
        if (!preg_match('/^(0|[1-9][0-9]{0,11})(\.[0-9]{1,4})?$/D', $value) || bccomp($value, '0', 4) <= 0) { throw new \DomainException('实物数量须为大于零且最多四位小数的数字'); }
        return bcadd($value, '0', 12);
    }
}
