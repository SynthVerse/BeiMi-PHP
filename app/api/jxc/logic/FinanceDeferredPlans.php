<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 明确确认合同义务与逐月金额，不以付款或计划建立代替服务受益。 */
final class FinanceDeferredPlans
{
    public static function categories(array $source): array
    {
        $categories = array_column($source['snapshot']['lines'], null, 'category_id');
        foreach ($categories as &$category) { $category['remaining'] = $category['amount']; } unset($category);
        $used = Db::name('finance_deferred_amortization')->where('tenant_id', FinanceAccess::tenant())->where('source_ref', $source['reference'])->column('snapshot');
        foreach ($used as $snapshot) {
            foreach (FinanceValue::decode($snapshot)['lines'] as $line) {
                $category = &$categories[$line['category_id']]; $category['remaining'] = bcsub($category['remaining'], $line['amount'], 2); unset($category);
            }
        }
        return array_values($categories);
    }

    public static function monthlyLines(array $source, mixed $input, string $amount): array
    {
        if (!is_array($input) || !array_is_list($input) || !$input || count($input) > 100) { throw new \DomainException('请填写本月原计划类别的费用组成'); }
        $allowed = array_column(self::categories($source), null, 'category_id'); $sum = '0.00'; $seen = []; $lines = [];
        foreach ($input as $line) {
            if (!is_array($line)) { throw new \DomainException('本月费用组成格式无效'); }
            $id = FinanceValue::id($line['category_id'] ?? null); $version = FinanceValue::id($line['expected_category_version'] ?? null);
            if (!isset($allowed[$id]) || isset($seen[$id]) || $version !== $allowed[$id]['category_version']) { throw new \DomainException('请选择原计划类别和对应历史版本，每个类别只能出现一次'); }
            $part = FinanceValue::money($line['amount'] ?? null);
            if (bccomp($part, $allowed[$id]['remaining'], 2) > 0) { throw new \DomainException('本月金额超过原计划类别剩余待摊额度'); }
            $snapshot = $allowed[$id]; unset($snapshot['remaining']);
            $lines[] = array_replace($snapshot, ['amount' => $part, 'reason' => FinanceValue::text($line['reason'] ?? null, 1000)]);
            $sum = bcadd($sum, $part, 2); $seen[$id] = true;
        }
        if (bccomp($sum, $amount, 2) !== 0) { throw new \DomainException('本月类别组成合计须等于计划摊销金额'); }
        return $lines;
    }

    public static function details(array $data, string $amount, string $activation): array
    {
        if (($data['plan_verified'] ?? null) !== 1) { throw new \DomainException('请明确核实合同义务、服务期间与逐月待摊金额'); }
        $start = FinanceValue::text($data['service_start'] ?? null, 7); $end = FinanceValue::text($data['service_end'] ?? null, 7);
        FinanceValue::date($start . '-01'); FinanceValue::date($end . '-01');
        if ($start < substr($activation, 0, 7) || $start > $end) { throw new \DomainException('服务期间须在启用后，结束月份不能早于开始'); }
        $input = $data['schedule'] ?? null;
        if (!is_array($input) || !array_is_list($input) || !$input || count($input) > 120) { throw new \DomainException('请逐月填写一至120个月的待摊计划'); }
        $months = []; $sum = '0.00';
        foreach ($input as $row) {
            if (!is_array($row)) { throw new \DomainException('待摊计划行格式无效'); }
            $month = FinanceValue::text($row['month'] ?? null, 7); FinanceValue::date($month . '-01');
            if ($month < $start || $month > $end || isset($months[$month])) { throw new \DomainException('待摊月份重复或超出服务期间'); }
            $part = FinanceValue::money($row['amount'] ?? null); $months[$month] = ['month' => $month, 'amount' => $part]; $sum = bcadd($sum, $part, 2);
        }
        $cursor = new \DateTimeImmutable($start . '-01'); $count = 0;
        while ($cursor->format('Y-m') <= $end && $count++ < 121) {
            if (!isset($months[$cursor->format('Y-m')])) { throw new \DomainException('每个服务月份都须有已核实的待摊金额'); }
            $cursor = $cursor->modify('+1 month');
        }
        if ($count > 120 || bccomp($sum, $amount, 2) !== 0) { throw new \DomainException('逐月金额合计须等于合同费用总额，且不能超过120个月'); }
        ksort($months);
        return ['original_amount' => $amount, 'amortized_amount' => '0.00', 'paid_amount' => '0.00', 'unpaid_amount' => $amount,
            'original_service_start' => $start, 'benefited_until' => '', 'service_start' => $start, 'service_end' => $end, 'schedule' => array_values($months)];
    }
}
