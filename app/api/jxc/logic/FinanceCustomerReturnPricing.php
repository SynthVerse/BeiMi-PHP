<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 退回数量和原成交身份分开保存；贷项通过原销售金额更正另行确认。 */
final class FinanceCustomerReturnPricing
{
    public static function choices(int $customer): array
    {
        $replaced = Db::name('finance_correction')->where('tenant_id', FinanceAccess::tenant())->field('original_document_id')->buildSql();
        $choices = [];
        foreach (Db::name('finance_customer_return')->where('tenant_id', FinanceAccess::tenant())->where('customer_id', $customer)->whereRaw('document_id NOT IN ' . $replaced)->order('id desc')->limit(1001)->select()->toArray() as $row) {
            $fact = FinanceValue::decode($row['snapshot']);
            if (empty($fact['delivery_item_id'])) { continue; }
            $choices[] = array_intersect_key($fact, array_flip(['delivery_item_id', 'quantity_facts', 'actual_return_weight', 'original_pricing', 'return_pricing_quantity', 'disposition', 'actual_date', 'goods_name', 'sku_name']))
                + ['return_document_id' => (int)$row['document_id']];
        }
        if (count($choices) > 1000) { throw new \DomainException('退货来源超过核对容量，请先归档历史'); }
        return $choices;
    }

    public static function groups(int $order, int $sku, int $customer): array
    {
        $result = [];
        foreach (FinanceDeliveries::rows($customer, '1900-01-01', date('Y-m-d'), true) as $group) {
            if (($group['fact_version'] ?? 1) !== 2 || $group['order_id'] !== $order || $group['sku_id'] !== $sku) { continue; }
            $pricing = null;
            if ($group['coverage_document_id']) {
                $raw = Db::name('finance_document')->where('tenant_id', FinanceAccess::tenant())->where('id', $group['coverage_document_id'])->where('status', 'confirmed')->value('confirmed_result');
                foreach ($raw ? FinanceValue::decode($raw)['lines'] ?? [] : [] as $line) {
                    if ((int)$line['delivery_item_id'] === $group['delivery_item_id']) { $pricing = array_intersect_key($line, array_flip(['price_unit_id', 'price_unit_name', 'price', 'settlement_quantity', 'amount', 'net_amount', 'return_adjustments', 'pricing_units'])); break; }
                }
            }
            $result[] = $group + ['original_pricing' => $pricing];
        }
        return $result;
    }

    public static function facts(array $source, array $input, string $baseQuantity): array
    {
        $groups = $source['delivery_groups'];
        if (!$groups) { return []; }
        $id = FinanceValue::id($input['delivery_item_id'] ?? null);
        $byId = array_column($groups, null, 'delivery_item_id');
        if (!isset($byId[$id])) { throw new \DomainException('请选择原销售的实际交付子组'); }
        $group = $byId[$id]; $facts = []; $available = array_column($group['quantity_facts'], null, 'unit_id');
        $historicUnits = array_column($group['original_pricing']['pricing_units'] ?? [], null, 'id');
        $inputFacts = $input['quantity_facts'] ?? [];
        if (!is_array($inputFacts) || !array_is_list($inputFacts) || count($inputFacts) > 50) { throw new \DomainException('退回计数事实格式无效'); }
        foreach ($inputFacts as $fact) {
            if (!is_array($fact)) { throw new \DomainException('退回计数事实格式无效'); }
            $unitId = FinanceValue::id($fact['unit_id'] ?? null);
            if (!isset($available[$unitId]) || isset($facts[$unitId])) { throw new \DomainException('退回计数须关联原交付的数量单位，不能猜测或重复'); }
            $unit = TradeQuantityService::unit($unitId);
            $facts[$unitId] = ['unit_id' => $unitId, 'unit_name' => $available[$unitId]['unit_name'],
                'quantity' => FinanceSalesQuantity::quantity($fact['quantity'] ?? null, (int)($unit['quantity_precision'] ?? $historicUnits[$unitId]['precision'] ?? 0)), 'source' => 'counted'];
        }
        $baseUnit = TradeQuantityService::unit((int)$group['inventory_base_unit_id']);
        if (!$baseUnit && isset($historicUnits[$group['inventory_base_unit_id']])) {
            $saved = $historicUnits[$group['inventory_base_unit_id']];
            $baseUnit = ['measure_type' => $saved['kind'], 'jin_factor' => $saved['jin_factor']];
        }
        if (!$baseUnit) { throw new \DomainException('原库存单位依据缺失，请核对原交付快照后验收'); }
        $weight = $input['actual_return_weight'] ?? null;
        if (($baseUnit['measure_type'] ?? '') === 'weight') { $weight = bcmul($baseQuantity, $baseUnit['jin_factor'], 4); }
        elseif ($weight !== null && $weight !== '') { $weight = FinanceSalesQuantity::quantity($weight); }
        else { $weight = null; }
        if (($baseUnit['measure_type'] ?? '') === 'count') {
            $baseId = (int)$group['inventory_base_unit_id'];
            if (!isset($facts[$baseId]) || bccomp($facts[$baseId]['quantity'], $baseQuantity, 4) !== 0) { throw new \DomainException('退回库存基础量必须与实际清点数量一致'); }
        }
        $replaced = Db::name('finance_correction')->where('tenant_id', FinanceAccess::tenant())->field('original_document_id')->buildSql();
        $returnedBase = '0.0000'; $returned = [];
        foreach (Db::name('finance_customer_return')->where('tenant_id', FinanceAccess::tenant())->where('sales_order_id', $source['original_sales_order_id'])->where('sku_id', $source['sku_id'])
            ->whereRaw('document_id NOT IN ' . $replaced)->lock(true)->select()->toArray() as $row) {
            $old = FinanceValue::decode($row['snapshot']);
            if ((int)($old['delivery_item_id'] ?? 0) !== $id) { continue; }
            $returnedBase = bcadd($returnedBase, $row['quantity'], 4);
            foreach ($old['quantity_facts'] ?? [] as $fact) { $returned[$fact['unit_id']] = bcadd($returned[$fact['unit_id']] ?? '0', $fact['quantity'], 4); }
        }
        if (bccomp(bcadd($returnedBase, $baseQuantity, 4), $group['inventory_base_quantity'], 4) > 0) { throw new \DomainException('累计退回库存量超过该交付子组'); }
        foreach ($facts as $unitId => $fact) {
            if (bccomp(bcadd($returned[$unitId] ?? '0', $fact['quantity'], 4), $available[$unitId]['quantity'], 4) > 0) { throw new \DomainException('累计退回计数超过该交付子组实交数量'); }
        }
        $pricing = $group['original_pricing']; $pricingQuantity = null;
        if ($pricing) {
            $priceUnit = $historicUnits[$pricing['price_unit_id']] ?? null;
            $pricingQuantity = ($priceUnit['kind'] ?? '') === 'weight' && $weight !== null
                ? bcdiv($weight, $priceUnit['jin_factor'], 4) : ($facts[$pricing['price_unit_id']]['quantity'] ?? null);
        }
        return ['delivery_item_id' => $id, 'quantity_facts' => array_values($facts), 'actual_return_weight' => $weight,
            'original_sales_document_id' => $group['coverage_document_id'], 'original_pricing' => $pricing, 'return_pricing_quantity' => $pricingQuantity];
    }

    public static function adjustment(array $source, array $input, ?array $before, string $price, string $quantity, string $mode): array
    {
        $adjustments = $input['return_adjustments'] ?? ($before['return_adjustments'] ?? []);
        if (!is_array($adjustments) || !array_is_list($adjustments) || count($adjustments) > 100) { throw new \DomainException('退货金额关联格式无效'); }
        if (!$adjustments) { return ['return_adjustments' => [], 'return_amount' => '0.00', 'return_raw_amount' => '0.000000']; }
        if (!$before || (int)$input['price_unit_id'] !== (int)$before['price_unit_id'] || bccomp($price, $before['price'], 2) !== 0 || bccomp($quantity, $before['settlement_quantity'], 4) !== 0) {
            throw new \DomainException('退货冲减须保留原有效成交单位、单价和交付计价量');
        }
        $result = []; $seen = []; $total = '0.00'; $rawTotal = '0.000000'; $totalQuantity = '0.0000';
        foreach ($adjustments as $part) {
            if (!is_array($part)) { throw new \DomainException('退货金额关联格式无效'); }
            $id = FinanceValue::id($part['return_document_id'] ?? null);
            if (isset($seen[$id])) { throw new \DomainException('同次退货不能重复冲减'); } $seen[$id] = true;
            FinanceCustomerReturnCorrections::assertCurrent($id);
            $row = Db::name('finance_customer_return')->where('tenant_id', FinanceAccess::tenant())->where('document_id', $id)->lock(true)->find();
            if (!$row || (int)$row['sales_order_id'] !== (int)$source['order_id'] || (int)$row['sku_id'] !== (int)$source['sku_id']) { throw new \DomainException('退货不属于原销售商品'); }
            $fact = FinanceValue::decode($row['snapshot']);
            if ((int)($fact['delivery_item_id'] ?? 0) !== (int)$source['delivery_item_id']) { throw new \DomainException('退货必须匹配原覆盖交付子组'); }
            $unit = array_column($before['pricing_units'], null, 'id')[$before['price_unit_id']] ?? null;
            $available = $unit && $unit['kind'] === 'weight' && ($fact['actual_return_weight'] ?? null) !== null
                ? bcdiv($fact['actual_return_weight'], $unit['jin_factor'], 4)
                : (array_column($fact['quantity_facts'] ?? [], 'quantity', 'unit_id')[$before['price_unit_id']] ?? null);
            if ($available === null) { throw new \DomainException('退货缺少原计价单位的实际数量，不能按今日价或均重推算'); }
            $returned = FinanceSalesQuantity::quantity($part['quantity'] ?? null, (int)($unit['precision'] ?? 0));
            if (bccomp($returned, $available, 4) !== 0) { throw new \DomainException('本次须完整引用该笔退货计价量，不能重复或猜分'); }
            $calculated = FinanceSalesPrecision::amount($returned, $price, $mode); $amount = $calculated['amount'];
            $result[] = ['return_document_id' => $id, 'quantity' => $returned, 'price_unit_id' => (int)$before['price_unit_id'], 'price_unit_name' => $before['price_unit_name'], 'price' => $price, 'amount' => $amount];
            $total = bcadd($total, $amount, 2); $rawTotal = bcadd($rawTotal, $calculated['raw_amount'], 6); $totalQuantity = bcadd($totalQuantity, $returned, 4);
        }
        if (bccomp($totalQuantity, $quantity, 4) > 0) { throw new \DomainException('累计退货冲减数量超过原成交计价量'); }
        return ['return_adjustments' => $result, 'return_amount' => $total, 'return_raw_amount' => $rawTotal];
    }
}
