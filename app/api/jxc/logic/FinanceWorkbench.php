<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 财务首页只投影当前门店、当前权限可见的权威业务来源，不复制待办状态。 */
final class FinanceWorkbench
{
    public static function read(array $params = []): array
    {
        $tenant = FinanceAccess::tenant();
        $active = Db::name('finance_opening_book')->where('tenant_id', $tenant)->value('status') === 'active';
        $access = [
            'receivable' => self::hasAny(['finance.receivable.view', 'finance.receivable.prepare', 'finance.receipt.prepare', 'finance.receipt.confirm', 'finance.refund.prepare', 'finance.recovery.prepare']),
            'payable' => FinanceAccess::has('finance.payable.view'),
            'sales_settlement' => FinanceAccess::has('settlement.view'),
        ];
        $overview = ['active' => $active, 'as_of' => date('Y-m-d H:i:s')]; $todos = [];
        if ($access['receivable']) {
            $receivable = $active ? self::balances(['receivable']) : ['amount' => '0.00', 'count' => 0];
            $overdue = ['amount' => '0.00', 'count' => 0, 'earliest_due_date' => null];
            if ($active && FinanceAccess::has('finance.receivable.view')) {
                FinanceOverdue::capture($tenant);
                $latest = Db::name('finance_overdue_event')->where('tenant_id', $tenant)->field('MAX(id) AS id')->group('source_ref')->buildSql();
                $rows = Db::name('finance_overdue_event')->where('tenant_id', $tenant)->whereRaw('id IN ' . $latest)->where('state', 'open')->order('due_date,id')->select()->toArray();
                foreach ($rows as $row) {
                    $overdue['amount'] = bcadd($overdue['amount'], $row['balance'], 2); $overdue['count']++;
                    $overdue['earliest_due_date'] ??= $row['due_date'];
                    $todos[] = ['kind' => 'receivable_overdue', 'id' => (int)$row['id'], 'title' => ($row['customer_name'] ?: '客户') . '有逾期应收',
                        'description' => $row['balance'] . ' 元 · 到期 ' . $row['due_date'], 'business_date' => $row['due_date'],
                        'target' => ['source' => $row['source_ref'], 'customer_id' => (int)$row['customer_id']]];
                }
            }
            $overview['receivable'] = ['can_view' => true, 'unpaid_amount' => $receivable['amount'], 'unpaid_count' => $receivable['count'],
                'overdue_amount' => $overdue['amount'], 'overdue_count' => $overdue['count'], 'earliest_due_date' => $overdue['earliest_due_date']];
        } else { $overview['receivable'] = ['can_view' => false]; }
        if ($access['payable']) {
            $payable = $active ? self::balances(['payable', 'expense_payable']) : ['amount' => '0.00', 'count' => 0];
            $arrivals = $active ? self::pendingArrivals() : [];
            foreach ($arrivals as $row) {
                $snapshot = FinanceValue::decode($row['snapshot']);
                $todos[] = ['kind' => 'purchase_pending', 'id' => (int)$row['id'], 'title' => ($snapshot['subject_name'] ?? '供应商') . '有待结算到货',
                    'description' => ($snapshot['goods_name'] ?? '到货') . ' · 待结算 ' . bcadd($row['pending_quantity'], '0', 4), 'business_date' => $row['business_date'],
                    'target' => ['vendor_id' => (int)$row['vendor_id'], 'arrival_line_id' => (int)$row['id']]];
            }
            $overview['payable'] = ['can_view' => true, 'unpaid_amount' => $payable['amount'], 'unpaid_count' => $payable['count'], 'pending_arrival_count' => count($arrivals)];
        } else { $overview['payable'] = ['can_view' => false]; }
        if ($access['sales_settlement']) {
            $deliveries = $active ? FinanceDeliveries::pendingAll() : [];
            foreach ($deliveries as $row) {
                $todos[] = ['kind' => 'sales_pending', 'id' => (int)$row['delivery_item_id'], 'title' => ($row['delivery_customer_name'] ?: '客户') . '有交付待结算',
                    'description' => $row['goods_name'] . ' · 待确认 ' . $row['pending_weight'], 'business_date' => $row['date'],
                    'target' => ['customer_id' => (int)$row['customer_id'], 'delivery_item_id' => (int)$row['delivery_item_id'], 'date' => $row['date']]];
            }
            $overview['sales_settlement'] = ['can_view' => true, 'pending_delivery_count' => count($deliveries)];
        } else { $overview['sales_settlement'] = ['can_view' => false]; }
        usort($todos, static fn(array $left, array $right): int => [$left['business_date'] ?: '9999-12-31', $left['kind'], $left['id']] <=> [$right['business_date'] ?: '9999-12-31', $right['kind'], $right['id']]);
        foreach ($todos as &$todo) { $todo['render_key'] = $todo['kind'] . ':' . $todo['id']; } unset($todo);
        $full = array_key_exists('page', $params); $page = max(1, FinanceValue::id($params['page'] ?? 1)); $pageSize = $full ? 20 : 3; $offset = $full ? ($page - 1) * $pageSize : 0;
        return ['overview' => $overview, 'todos' => array_slice($todos, $offset, $pageSize), 'todo_count' => count($todos),
            'has_more' => $offset + $pageSize < count($todos), 'page' => $page];
    }

    private static function balances(array $categories): array
    {
        $tenant = FinanceAccess::tenant(); $changes = Db::name('finance_entry')->where('tenant_id', $tenant)->where('metric', 'balance')->field('source_ref,SUM(amount) AS delta')->group('source_ref')->buildSql();
        $amount = '0.00'; $count = 0;
        foreach (['o' => 'finance_opening_source', 'n' => 'finance_source'] as $kind => $table) {
            $row = Db::name($table)->alias('s')->leftJoin([$changes => 'b'], "b.source_ref=CONCAT('{$kind}:',s.id)")
                ->where('s.tenant_id', $tenant)->whereIn('s.category', $categories)->whereRaw('s.amount+COALESCE(b.delta,0)>0')
                ->field('COALESCE(SUM(s.amount+COALESCE(b.delta,0)),0) AS amount,COUNT(*) AS count')->find();
            $amount = bcadd($amount, (string)$row['amount'], 2); $count += (int)$row['count'];
        }
        return ['amount' => $amount, 'count' => $count];
    }

    private static function pendingArrivals(): array
    {
        $coverage = FinancePurchaseCoverage::sql();
        $rows = Db::name('finance_purchase_arrival_line')->alias('a')->leftJoin([$coverage => 'c'], 'c.arrival_line_id=a.id')
            ->where('a.tenant_id', FinanceAccess::tenant())->whereRaw('a.actual_quantity>COALESCE(c.quantity,0)')
            ->field('a.id,a.vendor_id,a.business_date,a.snapshot,a.actual_quantity-COALESCE(c.quantity,0) AS pending_quantity')->order('a.business_date,a.id')->limit(5001)->select()->toArray();
        if (count($rows) > 5000) { throw new \DomainException('待结算到货超过工作台容量，请进入供应商结算按供应商处理'); }
        return $rows;
    }

    private static function hasAny(array $permissions): bool
    {
        foreach ($permissions as $permission) { if (FinanceAccess::has($permission)) { return true; } }
        return false;
    }
}
