<?php

declare(strict_types=1);
namespace app\api\jxc\logic;
use think\facade\Db;
use app\common\model\jxc\PayableFlow;

/** 待定退货的实物、供方认可、钱款分别推进；活跃账套沿用原财务事实。 */
final class PendingGoodsReturnService
{
    private static function q(string $table) { return Db::name($table)->where('tenant_id', (int)request()->tenantId); }

    public static function facts(int $return): array
    {
        $facts = self::q('pending_goods_return_fact')->where('pending_return_id', $return)->order('id')->select()->toArray();
        foreach ($facts as &$fact) { $fact += self::state($fact); $fact['history'] = self::history($fact); }
        return $facts;
    }

    private static function history(array $fact): array
    {
        if (!$fact['finance_line_id']) {
            return self::q('pending_goods_return_resolution')->where('fact_id', $fact['id'])
                ->field('id,kind,quantity,amount,note,create_time')->order('id')->select()->toArray();
        }
        $history = []; $credits = [];
        foreach (self::q('finance_purchase_return_resolution')->where('return_line_id', $fact['finance_line_id'])->order('id')->select()->toArray() as $row) {
            $snapshot = FinanceValue::decode($row['snapshot']);
            $credits = array_merge($credits, $snapshot['created_sources'] ?? []);
            $history[] = ['id' => $row['id'], 'kind' => $row['kind'], 'quantity' => $row['quantity'],
                'amount' => $snapshot['amount'] ?? null, 'note' => $snapshot['supplier_confirmation'] ?? $snapshot['reason'] ?? '',
                'create_time' => $row['create_time'], 'document_id' => $row['document_id'],
                'document_type' => $row['kind'] === 'accepted' ? 'purchase_return_acceptance' : 'purchase_return_resolution'];
        }
        if ($credits) {
            $entries = self::q('finance_entry')->whereIn('source_ref', array_unique($credits))->where('metric', 'balance')->order('id')->select()->toArray();
            foreach ($entries as $entry) {
                $document = self::q('finance_document')->where('id', $entry['document_id'])->find();
                if (!$document) { continue; }
                $payload = FinanceValue::decode($document['payload']);
                $history[] = ['id' => 'money-' . $entry['id'], 'kind' => bccomp($entry['amount'], '0', 2) > 0 ? 'money_adjusted' : ($document['type'] === 'supplier_refund' ? 'refund' : 'offset'),
                    'quantity' => '0.0000', 'amount' => ltrim($entry['amount'], '-'), 'note' => $payload['reason'] ?? '',
                    'create_time' => $entry['create_time'], 'document_id' => $document['id'], 'document_type' => $document['type']];
            }
        }
        return $history;
    }

    private static function state(array $fact): array
    {
        $accepted = '0.0000'; $back = '0.0000'; $resolved = '0.0000'; $amount = '0.00'; $paid = '0.00'; $credits = [];
        if ($fact['finance_line_id']) {
            $rows = self::q('finance_purchase_return_resolution')->where('return_line_id', $fact['finance_line_id'])->select()->toArray();
            foreach ($rows as $row) {
                $resolved = bcadd($resolved, $row['quantity'], 4);
                if ($row['kind'] === 'returned') { $back = bcadd($back, $row['quantity'], 4); }
                if ($row['kind'] === 'accepted') {
                    $accepted = bcadd($accepted, $row['quantity'], 4);
                    foreach (FinanceValue::decode($row['snapshot'])['created_sources'] ?? [] as $reference) {
                        $credit = (new FinanceLedger((int)request()->tenantId))->source($reference);
                        if ($credit['category'] === 'supplier_refund') { $amount = bcadd($amount, $credit['balance'], 2); $credits[] = $credit; }
                    }
                }
            }
        } else {
            foreach (self::q('pending_goods_return_resolution')->where('fact_id', $fact['id'])->order('id')->select()->toArray() as $row) {
                if ($row['kind'] === 'accepted') { $accepted = bcadd($accepted, $row['quantity'], 4); $resolved = bcadd($resolved, $row['quantity'], 4); $amount = bcadd($amount, $row['amount'], 2); }
                if ($row['kind'] === 'returned') { $back = bcadd($back, $row['quantity'], 4); $resolved = bcadd($resolved, $row['quantity'], 4); }
                if ($row['kind'] === 'loss') { $resolved = bcadd($resolved, $row['quantity'], 4); }
                if (in_array($row['kind'], ['offset', 'refund'], true)) { $paid = bcadd($paid, $row['amount'], 2); }
            }
        }
        $unresolved = bcsub($fact['quantity'], $resolved, 4); $money = bcsub($amount, $paid, 2);
        return ['accepted_qty' => $accepted, 'back_qty' => $back, 'unresolved_qty' => $unresolved, 'money_remaining' => $money, 'credits' => $credits,
            'next_step' => bccomp($unresolved, '0', 4) > 0 ? 'supplier' : (bccomp($money, '0', 2) > 0 ? 'money' : 'complete')];
    }

    public static function resolveLegacy(array $pending, array $params): void
    {
        if (FinanceIntegration::active()) { throw new \DomainException('财务已启用，请从关联财务退货记录处理认可与款项'); }
        $fact = self::q('pending_goods_return_fact')->where('id', (int)($params['fact_id'] ?? 0))->lock(true)->find();
        $return = $fact ? self::q('pending_goods_return')->where('id', $fact['pending_return_id'])->lock(true)->find() : null;
        if (!$return || (int)$return['pending_goods_id'] !== (int)$pending['id'] || $fact['finance_line_id'] || $fact['closed']) { throw new \DomainException('退货事实不存在或已处理，请刷新'); }
        $state = self::state($fact); $quantity = '0.0000'; $amount = '0.00';
        $note = trim((string)($params['note'] ?? ''));
        if ($note === '' || mb_strlen($note) > 500) { throw new \DomainException('请填写本次确认依据或流水凭证'); }
        if (in_array($params['action'], ['accept_return', 'return_back', 'return_loss'], true)) {
            $zeroReply = $params['action'] === 'accept_return' && preg_match('/^0(?:\.0{1,4})?$/D', (string)($params['quantity'] ?? ''));
            $quantity = $zeroReply ? '0.0000' : PendingGoodsLogic::quantity($params['quantity'] ?? '');
            if (bccomp($quantity, $state['unresolved_qty'], 4) > 0) { throw new \DomainException('数量不能超过尚未认可的退货量'); }
            $kind = ['accept_return' => 'accepted', 'return_back' => 'returned', 'return_loss' => 'loss'][$params['action']];
            if ($kind === 'accepted') {
                $amount = self::money($params['amount'] ?? '');
                if ($zeroReply && bccomp($amount, '0', 2) !== 0) { throw new \DomainException('未认可数量时不能登记认可金额'); }
            } elseif ($kind === 'returned') {
                if (!StockService::inbound((int)$pending['warehouse_id'], (int)$pending['goods_id'], $quantity, (int)$fact['id'], 'pending-return-back', 'PGB-' . $fact['id'], $note, (int)$pending['sku_id'])) { throw new \DomainException('实际退回入库失败'); }
                self::holdBack($pending, $quantity);
            }
        } else {
            $kind = (string)($params['settlement_kind'] ?? ''); $amount = self::money($params['amount'] ?? '');
            if (!in_array($kind, ['offset', 'refund'], true) || bccomp($amount, '0', 2) <= 0 || bccomp($amount, $state['money_remaining'], 2) > 0) { throw new \DomainException('请选择实际抵扣或退款到账，金额不能超过未处理款项'); }
            $order = self::q('supply_order')->where('id', $return['supply_order_id'])->find();
            if ($kind === 'offset') {
                $vendor = self::q('vendor')->where('id', $order['supplier_id'])->lock(true)->find();
                if (!$vendor || bccomp($amount, $vendor['order_payable'], 2) > 0) { throw new \DomainException('当前供应商待付不足，请核对实际退款到账'); }
                if (!FinanceService::reducePayable((int)$order['supplier_id'], $amount, (int)$fact['legacy_order_id'], 'purchase-return', 'PGR-' . $fact['id'], $note, PayableFlow::TYPE_RETURN_REDUCE)) { throw new \DomainException('供应商应付抵扣失败'); }
            }
            // 未启用账套时退款只保存人工核实的到账事实；不伪造账户流水或再次冲减应付。
        }
        self::q('pending_goods_return_resolution')->insert(['tenant_id' => (int)request()->tenantId, 'fact_id' => $fact['id'], 'kind' => $kind,
            'quantity' => $quantity, 'amount' => $amount, 'note' => $note, 'operator_id' => (int)(request()->adminId ?? 0), 'create_time' => time()]);
        self::finishFact($fact);
    }

    private static function money(mixed $input): string
    {
        $value = (string)$input;
        if (!preg_match('/^\d{1,12}(?:\.\d{1,2})?$/D', $value)) { throw new \DomainException('金额最多保留两位小数'); }
        return bcadd($value, '0', 2);
    }

    private static function holdBack(array $pending, string $quantity): void
    {
        if (WarehouseSkuBalanceService::reserveWithinTransaction((int)$pending['warehouse_id'], (int)$pending['sku_id'], $quantity) === false) { throw new \DomainException('实际返回货物转待定失败'); }
        self::q('pending_goods')->where('id', $pending['id'])->update(['pending_qty' => bcadd($pending['pending_qty'], $quantity, 4),
            'return_qty' => bcsub($pending['return_qty'], $quantity, 4), 'version' => (int)$pending['version'] + 1, 'update_time' => time()]);
    }

    /** 财务实际退离调用：必须明确核对原来源；在扣实物前仅解除本次退离量的持有。 */
    public static function financeDeparture(int $pendingReturn, array $arrival, int $warehouse, string $quantity, int $line, array $data): void
    {
        if (!WorkforceLogic::requirePermission('purchase.plan.manage')) { throw new \DomainException('无权处理待定货物'); }
        $reference = self::q('pending_goods_return')->where('id', $pendingReturn)->find();
        $basis = $reference ? self::q('pending_goods')->where('id', $reference['pending_goods_id'])->find() : null;
        if ($basis) { WarehouseSkuBalanceService::lockBalanceWithinTransaction((int)$basis['warehouse_id'], (int)$basis['sku_id']); }
        $pending = $basis ? self::q('pending_goods')->where('id', $basis['id'])->lock(true)->find() : null;
        $return = self::q('pending_goods_return')->where('id', $pendingReturn)->lock(true)->find();
        $supply = $return ? self::q('supply_order')->where('id', $return['supply_order_id'])->find() : null;
        if (!$pending || !$supply || $return['status'] !== 'planned' || (int)$supply['supplier_id'] !== (int)$arrival['vendor_id'] || (int)$arrival['sku_id'] !== (int)$pending['sku_id']
            || $warehouse !== (int)$pending['warehouse_id'] || (int)$arrival['warehouse_id'] !== $warehouse || ($data['pending_source_confirmed'] ?? 0) !== 1) { throw new \DomainException('请明确核对待退货物的供应商、仓库与原到货来源'); }
        if (bccomp($quantity, $return['remaining_qty'], 4) > 0) { throw new \DomainException('实际退离量超过待退数量'); }
        if (WarehouseSkuBalanceService::releaseWithinTransaction($warehouse, (int)$pending['sku_id'], $quantity) === false) { throw new \DomainException('待退锁定量不一致'); }
        $remaining = bcsub($return['remaining_qty'], $quantity, 4);
        self::q('pending_goods_return')->where('id', $pendingReturn)->update(['remaining_qty' => $remaining, 'status' => bccomp($remaining, '0', 4) === 0 ? 'awaiting_supplier' : 'planned', 'update_time' => time()]);
        self::q('pending_goods_return_fact')->insert(['tenant_id' => (int)request()->tenantId, 'pending_return_id' => $pendingReturn, 'finance_line_id' => $line, 'quantity' => $quantity, 'create_time' => time()]);
        self::q('pending_goods')->where('id', $pending['id'])->update(['version' => (int)$pending['version'] + 1, 'update_time' => time()]);
    }

    public static function financeBack(int $line, int $warehouse, string $quantity): void
    {
        $fact = self::q('pending_goods_return_fact')->where('finance_line_id', $line)->find();
        if (!$fact) { return; }
        $return = self::q('pending_goods_return')->where('id', $fact['pending_return_id'])->find();
        $pending = self::q('pending_goods')->where('id', $return['pending_goods_id'])->lock(true)->find();
        if ((int)$pending['warehouse_id'] !== $warehouse) { throw new \DomainException('待定来源退回需回原仓库，确认留库后再办理调拨'); }
        self::holdBack($pending, $quantity);
    }

    public static function syncFinancial(): void
    {
        $prefix = (string)config('database.connections.mysql.prefix', 'la_');
        if (!Db::query('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$prefix . 'pending_goods_return_fact'])) { return; }
        // 财务单据事务已持有账套锁；所有余额变化完成后再计算待办，部分认可或未退款保持开放。
        foreach (self::q('pending_goods_return_fact')->whereNotNull('finance_line_id')->order('id')->select()->toArray() as $fact) { self::finishFact($fact); }
    }

    private static function finishFact(array $fact): void
    {
        $state = self::state($fact);
        $close = $state['next_step'] === 'complete';
        if ((bool)$fact['closed'] === $close) { return; }
        $return = self::q('pending_goods_return')->where('id', $fact['pending_return_id'])->find();
        $basis = self::q('pending_goods')->where('id', $return['pending_goods_id'])->find();
        WarehouseSkuBalanceService::lockBalanceWithinTransaction((int)$basis['warehouse_id'], (int)$basis['sku_id']);
        $pending = self::q('pending_goods')->where('id', $basis['id'])->lock(true)->find();
        $locked = self::q('pending_goods_return_fact')->where('id', $fact['id'])->lock(true)->find();
        if ((bool)$locked['closed'] === $close) { return; }
        $completed = bcsub($fact['quantity'], $state['back_qty'], 4);
        self::q('pending_goods')->where('id', $pending['id'])->update(['return_qty' => $close ? bcsub($pending['return_qty'], $completed, 4) : bcadd($pending['return_qty'], $completed, 4),
            'returned_qty' => $close ? bcadd($pending['returned_qty'], $completed, 4) : bcsub($pending['returned_qty'], $completed, 4), 'version' => (int)$pending['version'] + 1, 'update_time' => time()]);
        self::q('pending_goods_return_fact')->where('id', $fact['id'])->update(['closed' => (int)$close]);
        if (!$close) {
            self::q('pending_goods_return')->where('id', $return['id'])->update(['status' => bccomp($return['remaining_qty'], '0', 4) > 0 ? 'planned' : 'awaiting_supplier', 'update_time' => time()]);
        }
        if (bccomp($return['remaining_qty'], '0', 4) === 0 && self::q('pending_goods_return_fact')->where('pending_return_id', $return['id'])->where('closed', 0)->count() === 0) {
            self::q('pending_goods_return')->where('id', $return['id'])->update(['status' => 'complete', 'update_time' => time()]);
        }
    }
}
