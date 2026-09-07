<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 从正式来源及追加分录读取客户往来；未约定付款日不算逾期。 */
final class FinanceCustomers
{
    public static function salesSources(array $params): array
    {
        FinanceAccess::require('settlement.bill');
        $detail = SalesSettlementLogic::detail(['id' => FinanceValue::id($params['order_id'] ?? 0)]);
        if ($detail === false) { throw new \DomainException(SalesSettlementLogic::getError()); }
        if (!$detail['finance']['active']) { throw new \DomainException('当前门店尚未启用新财务账套'); }
        return ['tenant_id' => FinanceAccess::tenant(), 'order_id' => $detail['order_id']] +
            (new FinanceLedger(FinanceAccess::tenant()))->sourcePage(['receivable'], $detail['customer_id'],
                FinanceValue::id($params['page'] ?? 1), ($params['role'] ?? '') === 'opening');
    }

    public static function overdue(int $customerId): array
    {
        $tenant = FinanceAccess::tenant(); $amount = '0.00'; $earliest = null; $count = 0;
        $changes = Db::name('finance_entry')->where('tenant_id', $tenant)->where('metric', 'balance')
            ->field('source_ref,SUM(amount) AS delta')->group('source_ref')->buildSql();
        foreach (['o' => 'finance_opening_source', 'n' => 'finance_source'] as $kind => $table) {
            $due = $kind === 'o' ? "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(s.source_snapshot,'$.due_date')),'null')" : 's.due_date';
            $due = "CASE WHEN d.id IS NULL THEN {$due} ELSE d.new_due_date END";
            $row = Db::name($table)->alias('s')->leftJoin([$changes => 'b'], "b.source_ref=CONCAT('{$kind}:',s.id)")
                ->leftJoin([FinanceDueDates::latestSql($tenant) => 'd'], "d.source_ref=CONCAT('{$kind}:',s.id)")
                ->where('s.tenant_id', $tenant)->where('s.category', 'receivable')->where('s.subject_id', $customerId)
                ->whereRaw('s.amount+COALESCE(b.delta,0)>0')->whereRaw("{$due} IS NOT NULL AND {$due}<?", [date('Y-m-d')])
                ->field("COALESCE(SUM(s.amount+COALESCE(b.delta,0)),0) AS balance,MIN({$due}) AS earliest,COUNT(*) AS total")->find();
            $amount = bcadd($amount, (string)$row['balance'], 2); $count += (int)$row['total'];
            if ($row['earliest'] && (!$earliest || $row['earliest'] < $earliest)) { $earliest = $row['earliest']; }
        }
        return ['amount' => $amount, 'earliest_due_date' => $earliest, 'count' => $count,
            'days' => $earliest ? (int)(new \DateTimeImmutable($earliest))->diff(new \DateTimeImmutable(date('Y-m-d')))->days : 0];
    }

    public static function salesContext(array $order): array
    {
        if (!FinanceIntegration::active()) { return ['active' => false]; }
        $tenant = FinanceAccess::tenant();
        $version = Db::name('finance_sales_version')->where('tenant_id', $tenant)->where('order_id', $order['id'])
            ->where('version', (int)$order['settlement_version'])->find();
        $ledger = new FinanceLedger($tenant); $customer = (int)$order['customer_id'];
        $payload = $version ? FinanceValue::decode((string)Db::name('finance_document')->where('tenant_id', $tenant)->where('id', $version['document_id'])->value('payload')) : [];
        return ['active' => true, 'source_ref' => $version['source_ref'] ?? '', 'due_date' => $version['due_date'] ?? null,
            'precision_rules' => FinanceSalesPrecision::rules($customer), 'precision' => $payload['precision'] ?? null, 'automatic_rounding_difference' => $payload['automatic_rounding_difference'] ?? null,
            'can_override_precision' => FinanceAccess::has('finance.sales.precision_override'), 'can_round_sales' => FinanceAccess::has('finance.sales.rounding'),
            'tenant_id' => $tenant, 'operator_id' => FinanceAccess::operator(), 'can_manage_terms' => FinanceAccess::owner(),
            'can_override_due' => FinanceAccess::has('finance.sales.due_override'),
            'terms' => FinanceSalesRules::terms($customer, date('Y-m-d', (int)$order['datetimesingle'])),
            'needs_opening_link' => (int)$order['settlement_version'] > 0 && !$version,
            'receivable_balance' => $ledger->categoryBalance('receivable', $customer), 'overdue' => self::overdue($customer)];
    }
}
