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
        if (bccomp($amount, '0', 2) <= 0) {
            return true; // 金额为0时不记录
        }

        $customer = Customer::where('id', $customerId)->lock(true)->find();
        if (!$customer) {
            return false;
        }

        $beforeAmount = (string)$customer->order_receivable;
        $afterAmount = bcadd($beforeAmount, $amount, 2);

        // 更新客户应收和累计销售
        Customer::where('id', $customerId)->update([
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
        if (bccomp($amount, '0', 2) <= 0) {
            return true;
        }

        $customer = Customer::where('id', $customerId)->lock(true)->find();
        if (!$customer) {
            return false;
        }

        $beforeAmount = (string)$customer->order_receivable;
        $afterAmount = bcsub($beforeAmount, $amount, 2);

        Customer::where('id', $customerId)->update([
            'order_receivable' => $afterAmount,
            'order_pay_money' => bcadd((string)$customer->order_pay_money, $amount, 2),
            'update_time' => time(),
        ]);

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
        $flows = ReceivableFlow::where('order_id', $orderId)
            ->where('order_type', $orderType)
            ->select();

        foreach ($flows as $flow) {
            $customer = Customer::where('id', $flow->customer_id)->lock(true)->find();
            if (!$customer) continue;

            if ($flow->flow_type == ReceivableFlow::TYPE_SALES_ADD) {
                // 销售应收增加的流水 → 回减
                $beforeAmount = (string)$customer->order_receivable;
                $afterAmount = bcsub($beforeAmount, (string)$flow->amount, 2);
                Customer::where('id', $flow->customer_id)->update([
                    'order_receivable' => $afterAmount,
                    'order_money' => bcsub((string)$customer->order_money, (string)$flow->amount, 2),
                    'update_time' => time(),
                ]);
            }
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
        if (bccomp($amount, '0', 2) <= 0) {
            return true;
        }

        $vendor = Vendor::where('id', $supplierId)->lock(true)->find();
        if (!$vendor) {
            return false;
        }

        $beforeAmount = (string)($vendor->order_payable ?? '0.00');
        $afterAmount = bcadd($beforeAmount, $amount, 2);

        Vendor::where('id', $supplierId)->update([
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
        if (bccomp($amount, '0', 2) <= 0) {
            return true;
        }

        $vendor = Vendor::where('id', $supplierId)->lock(true)->find();
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
        Vendor::where('id', $supplierId)->update($update);

        PayableFlow::create([
            'tenant_id'     => (int)(request()->tenantId ?? 0),
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
        $flows = PayableFlow::where('order_id', $orderId)
            ->where('order_type', $orderType)
            ->select();

        foreach ($flows as $flow) {
            $supplierId = (int)$flow->supplier_id;
            $vendor = Vendor::where('id', $supplierId)->lock(true)->find();
            if (!$vendor) {
                return false;
            }

            if ($flow->flow_type == PayableFlow::TYPE_SUPPLY_ADD) {
                $beforeAmount = (string)($vendor->order_payable ?? '0.00');
                $afterAmount = bcsub($beforeAmount, (string)$flow->amount, 2);
                $updated = Vendor::where('id', $supplierId)->update([
                    'order_payable' => $afterAmount,
                    'order_money' => bcsub((string)($vendor->order_money ?? '0.00'), (string)$flow->amount, 2),
                    'update_time' => time(),
                ]);
                if ($updated === false) {
                    return false;
                }
                self::createPayableRollbackFlow($supplierId, $orderId, $orderType, (string)$flow->order_sn, PayableFlow::TYPE_PAYMENT, (string)$flow->amount, $beforeAmount, $afterAmount);
            } elseif ($flow->flow_type == PayableFlow::TYPE_PAYMENT) {
                $beforeAmount = (string)($vendor->order_payable ?? '0.00');
                $afterAmount = bcadd($beforeAmount, (string)$flow->amount, 2);
                $updated = Vendor::where('id', $supplierId)->update([
                    'order_payable' => $afterAmount,
                    'order_paid_money' => bcsub((string)($vendor->order_paid_money ?? '0.00'), (string)$flow->amount, 2),
                    'update_time' => time(),
                ]);
                if ($updated === false) {
                    return false;
                }
                self::createPayableRollbackFlow($supplierId, $orderId, $orderType, (string)$flow->order_sn, PayableFlow::TYPE_SUPPLY_ADD, (string)$flow->amount, $beforeAmount, $afterAmount);
            } elseif ($flow->flow_type == PayableFlow::TYPE_RETURN_REDUCE) {
                $beforeAmount = (string)($vendor->order_payable ?? '0.00');
                $afterAmount = bcadd($beforeAmount, (string)$flow->amount, 2);
                $updated = Vendor::where('id', $supplierId)->update([
                    'order_payable' => $afterAmount,
                    'order_money' => bcadd((string)($vendor->order_money ?? '0.00'), (string)$flow->amount, 2),
                    'update_time' => time(),
                ]);
                if ($updated === false) {
                    return false;
                }
                self::createPayableRollbackFlow($supplierId, $orderId, $orderType, (string)$flow->order_sn, PayableFlow::TYPE_SUPPLY_ADD, (string)$flow->amount, $beforeAmount, $afterAmount);
            }
        }

        return true;
    }

    protected static function createPayableRollbackFlow(
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
            'tenant_id'     => (int)(request()->tenantId ?? 0),
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
