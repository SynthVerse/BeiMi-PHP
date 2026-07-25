<?php

namespace app\api\jxc\logic;

use app\common\model\jxc\Customer;
use app\common\model\jxc\Vendor;
use app\common\model\jxc\ReceivableFlow;
use app\common\model\jxc\PayableFlow;

class FinanceService
{
    /**
     * 增加客户应收（销售出单时调用）
     */
    public static function addReceivable(
        int $customerId,
        string $amount,
        int $orderId,
        string $orderType,
        string $orderSn,
        string $remark = ''
    ): bool {
        $tenantId = (int)(request()->tenantId ?? 0);
        if ($tenantId <= 0) return false;
        if (bccomp($amount, '0', 2) <= 0) {
            return true; // 金额为0时不记录
        }

        $customer = Customer::where('id', $customerId)->where('tenant_id', $tenantId)->lock(true)->find();
        if (!$customer) {
            return false;
        }

        $beforeAmount = (string)$customer->order_receivable;
        $afterAmount = bcadd($beforeAmount, $amount, 2);

        // 更新客户应收和累计销售
        Customer::where('id', $customerId)->where('tenant_id', $tenantId)->update([
            'order_receivable' => $afterAmount,
            'order_money' => bcadd((string)$customer->order_money, $amount, 2),
            'update_time' => time(),
        ]);

        // 写入应收流水
        ReceivableFlow::create([
            'tenant_id'     => (int)(request()->tenantId ?? 0),
            'customer_id'   => $customerId,
            'order_id'      => $orderId,
            'order_type'    => $orderType,
            'order_sn'      => $orderSn,
            'flow_type'     => ReceivableFlow::TYPE_SALES_ADD,
            'amount'        => $amount,
            'before_amount' => $beforeAmount,
            'after_amount'  => $afterAmount,
            'admin_id'      => (int)(request()->adminId ?? 0),
            'remark'        => $remark ?: '销售应收-' . $orderSn,
            'create_time'   => time(),
        ]);

        return true;
    }

    /**
     * 减少客户应收（收款或退货时调用）
     */
    public static function reduceReceivable(
        int $customerId,
        string $amount,
        int $orderId,
        string $orderType,
        string $orderSn,
        int $flowType = ReceivableFlow::TYPE_PAYMENT,
        string $remark = ''
    ): bool {
        $tenantId = (int)(request()->tenantId ?? 0);
        if ($tenantId <= 0) return false;
        if (bccomp($amount, '0', 2) <= 0) {
            return true;
        }

        $customer = Customer::where('id', $customerId)->where('tenant_id', $tenantId)->lock(true)->find();
        if (!$customer) {
            return false;
        }

        $beforeAmount = (string)$customer->order_receivable;
        $afterAmount = bcsub($beforeAmount, $amount, 2);

        $update = ['order_receivable' => $afterAmount, 'update_time' => time()];
        if ($flowType === ReceivableFlow::TYPE_RETURN_REDUCE) {
            $update['order_money'] = bcsub((string)$customer->order_money, $amount, 2);
        } else {
            $update['order_pay_money'] = bcadd((string)$customer->order_pay_money, $amount, 2);
        }
        Customer::where('id', $customerId)->where('tenant_id', $tenantId)->update($update);

        ReceivableFlow::create([
            'tenant_id'     => (int)(request()->tenantId ?? 0),
            'customer_id'   => $customerId,
            'order_id'      => $orderId,
            'order_type'    => $orderType,
            'order_sn'      => $orderSn,
            'flow_type'     => $flowType,
            'amount'        => $amount,
            'before_amount' => $beforeAmount,
            'after_amount'  => $afterAmount,
            'admin_id'      => (int)(request()->adminId ?? 0),
            'remark'        => $remark ?: '应收减少-' . $orderSn,
            'create_time'   => time(),
        ]);

        return true;
    }

    /**
     * 按单据回滚应收（删除/作废单据时调用）
     */
    public static function rollbackReceivable(int $orderId, string $orderType): bool
    {
        $tenantId = (int)(request()->tenantId ?? 0);
        if ($tenantId <= 0) return false;
        $flows = ReceivableFlow::where('order_id', $orderId)
            ->where('order_type', $orderType)
            ->where('tenant_id', $tenantId)
            ->lock(true)
            ->select();

        $isReturn = $orderType === 'sales-return';
        $sourceType = $isReturn ? ReceivableFlow::TYPE_RETURN_REDUCE : ReceivableFlow::TYPE_SALES_ADD;
        $rollbackType = $isReturn ? ReceivableFlow::TYPE_RETURN_ROLLBACK : ReceivableFlow::TYPE_ORDER_ROLLBACK;
        $netByCustomer = [];
        foreach ($flows as $flow) {
            $customerId = (int)$flow->customer_id;
            if (!isset($netByCustomer[$customerId])) {
                $netByCustomer[$customerId] = [
                    'source' => '0.00',
                    'rollback' => '0.00',
                    'order_sn' => (string)$flow->order_sn,
                ];
            }
            if ((int)$flow->flow_type === $sourceType) {
                $netByCustomer[$customerId]['source'] = bcadd(
                    $netByCustomer[$customerId]['source'],
                    (string)$flow->amount,
                    2
                );
            } elseif ((int)$flow->flow_type === $rollbackType) {
                $netByCustomer[$customerId]['rollback'] = bcadd(
                    $netByCustomer[$customerId]['rollback'],
                    (string)$flow->amount,
                    2
                );
            }
        }
        ksort($netByCustomer, SORT_NUMERIC);
        foreach ($netByCustomer as $customerId => $totals) {
            if (bccomp($totals['rollback'], $totals['source'], 2) > 0) {
                throw new \RuntimeException('应收回滚补偿超过原始流水');
            }
            $remaining = bcsub($totals['source'], $totals['rollback'], 2);
            if (bccomp($remaining, '0', 2) === 0) {
                continue;
            }
            $customer = Customer::where('id', (int)$customerId)->where('tenant_id', $tenantId)->lock(true)->find();
            if (!$customer) return false;
            $beforeAmount = (string)$customer->order_receivable;
            $afterAmount = $isReturn
                ? bcadd($beforeAmount, $remaining, 2)
                : bcsub($beforeAmount, $remaining, 2);
            $orderMoney = $isReturn
                ? bcadd((string)$customer->order_money, $remaining, 2)
                : bcsub((string)$customer->order_money, $remaining, 2);
            Customer::where('id', (int)$customerId)->where('tenant_id', $tenantId)->update([
                'order_receivable' => $afterAmount,
                'order_money' => $orderMoney,
                'update_time' => time(),
            ]);
            ReceivableFlow::create([
                'tenant_id' => $tenantId,
                'customer_id' => (int)$customerId,
                'order_id' => $orderId,
                'order_type' => $orderType,
                'order_sn' => $totals['order_sn'],
                'flow_type' => $rollbackType,
                'amount' => $remaining,
                'before_amount' => $beforeAmount,
                'after_amount' => $afterAmount,
                'admin_id' => (int)(request()->adminId ?? 0),
                'remark' => '回滚应收-' . $orderType,
                'create_time' => time(),
            ]);
        }

        return true;
    }

    /**
     * 增加供应商应付（进货入单时调用）
     */
    public static function addPayable(
        int $supplierId,
        string $amount,
        int $orderId,
        string $orderType,
        string $orderSn,
        string $remark = ''
    ): bool {
        $tenantId = (int)(request()->tenantId ?? 0);
        if ($tenantId <= 0) {
            return false;
        }
        if (bccomp($amount, '0', 2) <= 0) {
            return true;
        }

        $vendor = Vendor::where('id', $supplierId)->where('tenant_id', $tenantId)->lock(true)->find();
        if (!$vendor) {
            return false;
        }

        $beforeAmount = (string)($vendor->order_payable ?? '0.00');
        $afterAmount = bcadd($beforeAmount, $amount, 2);

        Vendor::where('id', $supplierId)->where('tenant_id', $tenantId)->update([
            'order_payable' => $afterAmount,
            'order_money' => bcadd((string)$vendor->order_money, $amount, 2),
            'update_time' => time(),
        ]);

        PayableFlow::create([
            'tenant_id'     => (int)(request()->tenantId ?? 0),
            'supplier_id'   => $supplierId,
            'order_id'      => $orderId,
            'order_type'    => $orderType,
            'order_sn'      => $orderSn,
            'flow_type'     => PayableFlow::TYPE_SUPPLY_ADD,
            'amount'        => $amount,
            'before_amount' => $beforeAmount,
            'after_amount'  => $afterAmount,
            'admin_id'      => (int)(request()->adminId ?? 0),
            'remark'        => $remark ?: '进货应付-' . $orderSn,
            'create_time'   => time(),
        ]);

        return true;
    }

    /**
     * 减少供应商应付（付款时调用）
     */
    public static function reducePayable(
        int $supplierId,
        string $amount,
        int $orderId,
        string $orderType,
        string $orderSn,
        string $remark = '',
        int $flowType = PayableFlow::TYPE_PAYMENT
    ): bool {
        $tenantId = (int)(request()->tenantId ?? 0);
        if ($tenantId <= 0) {
            return false;
        }
        if (bccomp($amount, '0', 2) <= 0) {
            return true;
        }

        $vendor = Vendor::where('id', $supplierId)->where('tenant_id', $tenantId)->lock(true)->find();
        if (!$vendor) {
            return false;
        }

        $beforeAmount = (string)($vendor->order_payable ?? '0.00');
        $afterAmount = bcsub($beforeAmount, $amount, 2);

        $update = [
            'order_payable' => $afterAmount,
            'update_time' => time(),
        ];
        if ($flowType === PayableFlow::TYPE_RETURN_REDUCE) {
            $update['order_money'] = bcsub((string)($vendor->order_money ?? '0.00'), $amount, 2);
        } else {
            $update['order_paid_money'] = bcadd((string)($vendor->order_paid_money ?? '0.00'), $amount, 2);
        }
        Vendor::where('id', $supplierId)->where('tenant_id', $tenantId)->update($update);

        PayableFlow::create([
            'tenant_id'     => $tenantId,
            'supplier_id'   => $supplierId,
            'order_id'      => $orderId,
            'order_type'    => $orderType,
            'order_sn'      => $orderSn,
            'flow_type'     => $flowType,
            'amount'        => $amount,
            'before_amount' => $beforeAmount,
            'after_amount'  => $afterAmount,
            'admin_id'      => (int)(request()->adminId ?? 0),
            'remark'        => $remark ?: '应付减少-' . $orderSn,
            'create_time'   => time(),
        ]);

        return true;
    }

    /**
     * 按单据回滚应付（删除/作废进货单时调用）
     */
    public static function rollbackPayable(int $orderId, string $orderType): bool
    {
        $tenantId = (int)(request()->tenantId ?? 0);
        if ($tenantId <= 0) {
            return false;
        }
        $flows = PayableFlow::where('order_id', $orderId)
            ->where('order_type', $orderType)
            ->where('tenant_id', $tenantId)
            ->lock(true)
            ->select();

        $isReturn = $orderType === 'purchase-return';
        $sourceType = $isReturn ? PayableFlow::TYPE_RETURN_REDUCE : PayableFlow::TYPE_SUPPLY_ADD;
        $rollbackType = $isReturn ? PayableFlow::TYPE_RETURN_ROLLBACK : PayableFlow::TYPE_ORDER_ROLLBACK;
        $netBySupplier = [];
        foreach ($flows as $flow) {
            $supplierId = (int)$flow->supplier_id;
            if (!isset($netBySupplier[$supplierId])) {
                $netBySupplier[$supplierId] = [
                    'source' => '0.00',
                    'rollback' => '0.00',
                    'order_sn' => (string)$flow->order_sn,
                ];
            }
            if ((int)$flow->flow_type === $sourceType) {
                $netBySupplier[$supplierId]['source'] = bcadd(
                    $netBySupplier[$supplierId]['source'],
                    (string)$flow->amount,
                    2
                );
            } elseif ((int)$flow->flow_type === $rollbackType) {
                $netBySupplier[$supplierId]['rollback'] = bcadd(
                    $netBySupplier[$supplierId]['rollback'],
                    (string)$flow->amount,
                    2
                );
            }
        }

        ksort($netBySupplier, SORT_NUMERIC);
        foreach ($netBySupplier as $supplierId => $totals) {
            if (bccomp($totals['rollback'], $totals['source'], 2) > 0) {
                throw new \RuntimeException('应付回滚补偿超过原始流水');
            }
            $remaining = bcsub($totals['source'], $totals['rollback'], 2);
            if (bccomp($remaining, '0', 2) === 0) {
                continue;
            }
            $vendor = Vendor::where('id', (int)$supplierId)
                ->where('tenant_id', $tenantId)
                ->lock(true)
                ->find();
            if (!$vendor) {
                return false;
            }
            $beforeAmount = (string)($vendor->order_payable ?? '0.00');
            $afterAmount = $isReturn
                ? bcadd($beforeAmount, $remaining, 2)
                : bcsub($beforeAmount, $remaining, 2);
            $orderMoney = $isReturn
                ? bcadd((string)($vendor->order_money ?? '0.00'), $remaining, 2)
                : bcsub((string)($vendor->order_money ?? '0.00'), $remaining, 2);
            $updated = Vendor::where('id', (int)$supplierId)
                ->where('tenant_id', $tenantId)
                ->update([
                    'order_payable' => $afterAmount,
                    'order_money' => $orderMoney,
                    'update_time' => time(),
                ]);
            if ($updated === false) {
                return false;
            }
            self::createPayableRollbackFlow(
                $tenantId,
                (int)$supplierId,
                $orderId,
                $orderType,
                $totals['order_sn'],
                $rollbackType,
                $remaining,
                $beforeAmount,
                $afterAmount
            );
        }

        return true;
    }

    protected static function createPayableRollbackFlow(
        int $tenantId,
        int $supplierId,
        int $orderId,
        string $orderType,
        string $orderSn,
        int $flowType,
        string $amount,
        string $beforeAmount,
        string $afterAmount
    ): void {
        PayableFlow::create([
            'tenant_id'     => $tenantId,
            'supplier_id'   => $supplierId,
            'order_id'      => $orderId,
            'order_type'    => $orderType,
            'order_sn'      => $orderSn,
            'flow_type'     => $flowType,
            'amount'        => $amount,
            'before_amount' => $beforeAmount,
            'after_amount'  => $afterAmount,
            'admin_id'      => (int)(request()->adminId ?? 0),
            'remark'        => '回滚应付-' . $orderType,
            'create_time'   => time(),
        ]);
    }
}
