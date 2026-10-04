<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 退坏货不回可售；只将原销售成本转入现有实物损耗待核链。 */
final class FinanceCustomerReturnLoss
{
    public static function register(array $source, int $document, string $quantity, string $date, string $reason, string $disposition): array
    {
        if ($source['cost_basis'] !== 'sale') { return ['loss_incident_document_id' => null, 'cost_basis_pending' => 'pre_cutoff_return_disposition']; }
        $warehouse = (int)$source['original_warehouse_id']; $reference = 'inventory-loss:' . $document;
        $snapshot = ['type' => 'inventory_loss', 'source_type' => 'customer_return', 'return_document_id' => $document,
            'warehouse_id' => $warehouse, 'warehouse_name' => $source['original_warehouse_name'], 'sku_id' => $source['sku_id'], 'goods_id' => $source['goods_id'],
            'goods_name' => $source['goods_name'], 'sku_name' => $source['sku_name'], 'base_unit_name' => $source['base_unit_name'],
            'quantity' => $quantity, 'actual_date' => $date, 'source_reference' => 'customer-return:' . $document, 'reason' => $reason,
            'responsibility' => '退回实物已隔离，责任待核', 'resolution_pending' => true, 'disposition' => $disposition,
            'confirmed_by' => FinanceAccess::actor(), 'confirmed_at' => time()];
        $cost = new FinanceCostLedger(FinanceAccess::tenant());
        $cost->recordWithinTransaction(['reference' => 'customer-return-pending:' . $document, 'type' => 'reclassify', 'warehouse_id' => $warehouse,
            'sku_id' => $source['sku_id'], 'quantity' => $quantity, 'document_id' => $document, 'business_date' => $date,
            'bucket' => 'sale', 'target_reference' => 'sales_order:' . $source['original_sales_order_id'], 'to_bucket' => 'pending', 'to_reference' => $reference, 'snapshot' => $snapshot]);
        Db::name('finance_inventory_loss')->insert(['tenant_id' => FinanceAccess::tenant(), 'document_id' => $document, 'warehouse_id' => $warehouse,
            'sku_id' => $source['sku_id'], 'quantity' => $quantity, 'business_date' => $date, 'source_reference' => $snapshot['source_reference'], 'snapshot' => FinanceValue::json($snapshot), 'create_time' => time()]);
        $destination = $cost->destination($warehouse, (int)$source['sku_id'], 'pending', $reference);
        return ['loss_incident_document_id' => $document, 'pending_return_cost' => $destination['cost'], 'known_pending_return_cost' => $destination['known_cost'],
            'return_loss_route' => '/sub-finance/inventory/loss?type=inventory_loss_resolution&incident_document_id=' . $document];
    }

    public static function reverse(array $fact, int $replacement, int $original): void
    {
        if (empty($fact['loss_incident_document_id'])) { return; }
        if (Db::name('finance_inventory_loss_resolution')->where('tenant_id', FinanceAccess::tenant())->where('incident_document_id', $original)->count()) {
            throw new \DomainException('退回实物已有损失结案，须先核清已结成本，不能直接撤销验收');
        }
        (new FinanceCostLedger(FinanceAccess::tenant()))->recordWithinTransaction(['reference' => 'customer-return-pending-reversal:' . $replacement, 'type' => 'reclassify',
            'warehouse_id' => (int)$fact['original_warehouse_id'], 'sku_id' => (int)$fact['sku_id'], 'quantity' => $fact['quantity'], 'document_id' => $replacement,
            'business_date' => $fact['actual_date'], 'bucket' => 'pending', 'target_reference' => 'inventory-loss:' . $original,
            'to_bucket' => 'sale', 'to_reference' => 'sales_order:' . $fact['original_sales_order_id'], 'snapshot' => ['corrects_return_document_id' => $original]]);
    }
}
