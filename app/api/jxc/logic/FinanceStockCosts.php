<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 库存实物流水与成本去向在同一事务追加；调用方先锁财务准备行再锁库存。 */
final class FinanceStockCosts
{
    public static function flowWithinTransaction(int $id, array $flow, array $context = []): void
    {
        if (!FinanceIntegration::active() || bccomp($flow['quantity'], '0', 4) === 0) { return; }
        $reference = 'stock:' . $id; $date = date('Y-m-d', (int)$flow['create_time']);
        if (!empty($context['delivery_event_id'])) {
            $delivery = Db::name('fulfillment_delivery_event')->where('tenant_id', FinanceAccess::tenant())->where('id', $context['delivery_event_id'])->find();
            if (!$delivery) { throw new \DomainException('成本对应的实际交付事件不存在'); }
            $occurred = (int)(($delivery['actual_handoff_time'] ?? 0) ?: ($delivery['delivered_time'] ?: $delivery['create_time']));
            if ($occurred <= 0) { throw new \DomainException('交付或损耗缺少可核对的事实发生时间'); }
            $date = date('Y-m-d', $occurred);
        }
        $event = ['reference' => $reference, 'sku_id' => (int)$flow['sku_id'], 'warehouse_id' => (int)$flow['warehouse_id'],
            'business_date' => $date, 'quantity' => (string)$flow['quantity'], 'snapshot' => ['stock_flow_id' => $id, 'order_type' => $flow['order_type'], 'order_id' => (int)$flow['order_id']]];
        if (in_array($flow['order_type'], ['finance_inventory_loss', 'finance_purchase_arrival', 'finance_purchase_return', 'finance_purchase_return_back'], true)) {
            $event['business_date'] = FinanceValue::date($context['business_date'] ?? null);
            $event['document_id'] = FinanceValue::id($context['document_id'] ?? null);
        }
        if ($flow['order_type'] === 'finance_purchase_return_back') {
            $event['to_warehouse_id'] = $event['warehouse_id']; $event['warehouse_id'] = FinanceValue::id($context['original_warehouse_id'] ?? null);
            $event += ['type' => 'restore', 'bucket' => 'return', 'target_reference' => 'purchase-return:' . FinanceValue::id($context['return_line_id'] ?? null)];
        } elseif ($flow['order_type'] === 'sales_delivery_correction') {
            $event += ['type' => (int)$flow['flow_type'] === 1 ? 'restore' : 'issue', 'bucket' => 'sale', 'target_reference' => 'sales_order:' . $flow['order_id']];
        } elseif ($flow['order_type'] === 'sales-return') {
            $return = Db::name('sales_return_order')->where('tenant_id', FinanceAccess::tenant())->where('id', $flow['order_id'])->find();
            $source = $return ? Db::name('sales_order')->where('tenant_id', FinanceAccess::tenant())->where('id', $return['original_sales_order_id'])->find() : null;
            if (!$source) { throw new \DomainException('销售退回缺少本门店原销售来源'); }
            $event['to_warehouse_id'] = $event['warehouse_id']; $event['warehouse_id'] = (int)$source['warehouse_id'];
            $event += ['type' => 'restore', 'bucket' => 'sale', 'target_reference' => 'sales_order:' . $source['id']];
        } elseif ((int)$flow['flow_type'] === 1) {
            $event += ['type' => 'receive', 'origin' => $context['origin'] ?? $reference, 'amount' => $context['amount'] ?? null];
            $event['snapshot']['cost_basis'] = $context['cost_basis'] ?? '';
        } else {
            [$bucket, $destination] = match ($flow['order_type']) {
                'sales', 'sales_delivery' => ['sale', 'sales_order:' . $flow['order_id']],
                'delivery_transport_loss' => ['loss', 'delivery_loss:' . $flow['order_id']],
                'fulfillment_internal_loss' => ['loss', 'fulfillment_loss:' . $flow['order_id']],
                'fulfillment_pending' => ['pending', 'fulfillment_loss:' . $flow['order_id']],
                'finance_inventory_loss' => ['pending', 'inventory-loss:' . FinanceValue::id($context['document_id'] ?? null)],
                'purchase_return', 'supply_return', 'purchase-return' => ['return', 'purchase_return:' . $flow['order_id']],
                'finance_purchase_return' => ['return', 'purchase-return:' . FinanceValue::id($context['return_line_id'] ?? null)],
                default => throw new \DomainException('财务启用后出库须明确销售、损耗或采购退货来源'),
            };
            $event += ['type' => 'issue', 'bucket' => $bucket, 'target_reference' => $destination];
        }
        (new FinanceCostLedger(FinanceAccess::tenant()))->recordWithinTransaction($event);
    }

    public static function transferWithinTransaction(int $outboundFlow, int $inboundFlow, int $from, int $to, int $sku, string $quantity): void
    {
        if (!FinanceIntegration::active()) { return; }
        (new FinanceCostLedger(FinanceAccess::tenant()))->recordWithinTransaction(['reference' => 'stock-transfer:' . $outboundFlow, 'type' => 'transfer',
            'sku_id' => $sku, 'warehouse_id' => $from, 'to_warehouse_id' => $to, 'quantity' => $quantity, 'business_date' => date('Y-m-d'),
            'snapshot' => ['outbound_flow_id' => $outboundFlow, 'inbound_flow_id' => $inboundFlow]]);
    }
}
