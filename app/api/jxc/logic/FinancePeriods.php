<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 同一账套锁内复核并冻结月份；不创建余额或期间发生额分录。 */
final class FinancePeriods
{
    public static function execute(FinanceLedger $ledger, string $action, array $params): array
    {
        FinanceAccess::require('', true);
        if ($action === 'detail') {
            $month = FinanceValue::text($params['month'] ?? null, 7);
            $row = Db::name('finance_period')->where('tenant_id', FinanceAccess::tenant())->where('month', $month)->find();
            if (!$row) { throw new \DomainException('该月尚未结账或不属于本门店'); }
            return ['tenant_id' => FinanceAccess::tenant(), 'month' => $month, 'snapshot' => FinanceValue::decode($row['snapshot'])];
        }
        if ($action === 'history') {
            return ['tenant_id' => FinanceAccess::tenant(), 'periods' => array_map(static function (array $row): array {
                $snapshot = FinanceValue::decode($row['snapshot']);
                return ['month' => $row['month'], 'mode' => $snapshot['mode'] ?? $row['status'], 'closed_at' => (int)$row['closed_at'], 'closed_by' => FinanceValue::decode($row['closed_by']),
                    'verification' => $snapshot['reports']['profit']['verification'] ?? null, 'unresolved' => $snapshot['unresolved'] ?? [], 'reason' => $snapshot['reason'] ?? ''];
            }, Db::name('finance_period')->where('tenant_id', FinanceAccess::tenant())->order('month', 'desc')->select()->toArray())];
        }
        if (!in_array($action, ['preview', 'close'], true)) { throw new \DomainException('月结动作无效'); }
        $tenant = FinanceAccess::tenant(); $key = ''; $fingerprint = '';
        if ($action === 'close') {
            if (FinanceValue::id($params['expected_tenant_id'] ?? null) !== $tenant) { throw new \DomainException('当前门店已变化，请重新进入月结'); }
            $key = FinanceValue::text($params['idempotency_key'] ?? '', 96);
            if (!preg_match('/^[a-zA-Z0-9_-]{16,96}$/D', $key)) { throw new \DomainException('提交标识无效'); }
            $fingerprint = hash('sha256', FinanceValue::json(['period_close', $params, FinanceAccess::actor()]));
            $existing = Db::name('finance_command')->where('tenant_id', $tenant)->where('idempotency_key', $key)->find();
            if ($existing) {
                if (!hash_equals($existing['fingerprint'], $fingerprint)) { throw new \DomainException('同一提交标识不能用于不同内容或操作人'); }
                return FinanceValue::decode($existing['result']);
            }
        }
        $preview = self::preview($ledger, $params);
        if ($action === 'preview') { return $preview; }
        $mode = $params['mode'] ?? '';
        if (!in_array($mode, ['ordinary', 'estimated'], true)) { throw new \DomainException('请选择普通月结或暂估结账'); }
        if (!hash_equals($preview['fingerprint'], (string)($params['expected_fingerprint'] ?? ''))) { throw new \DomainException('月结数据已变化，请重新预览核对'); }
        if (!$preview['checklist'][$mode === 'ordinary' ? 'ordinary_ready' : 'estimated_ready']) { throw new \DomainException($mode === 'ordinary' ? '普通月结条件尚未满足，请处理检查清单' : '暂估结账不能绕过未结束月份、月份顺序或交付待确认金额'); }
        if ($mode === 'estimated' && ($params['acknowledge_unresolved'] ?? null) !== 1) { throw new \DomainException('请明确确认保留完整未决清单并锁定该月'); }
        $reason = FinanceValue::text($params['reason'] ?? null, 1000); $at = time();
        $snapshot = $preview['snapshot'] + ['version' => 1, 'mode' => $mode, 'reason' => $reason, 'closed_at' => $at, 'closed_by' => FinanceAccess::actor()];
        foreach ($snapshot['reports'] as &$report) { $report['closing_status'] = $mode === 'ordinary' ? 'closed' : 'estimated'; $report['closing_mode'] = $mode; $report['stage'] = false; } unset($report);
        Db::name('finance_period')->insert(['tenant_id' => $tenant, 'month' => $preview['month'], 'status' => $mode === 'ordinary' ? 'closed' : 'estimated',
            'snapshot' => FinanceValue::json($snapshot), 'closed_by' => FinanceValue::json(FinanceAccess::actor()), 'closed_at' => $at]);
        $result = ['tenant_id' => $tenant, 'month' => $preview['month'], 'snapshot' => $snapshot];
        Db::name('finance_command')->insert(['tenant_id' => $tenant, 'idempotency_key' => $key, 'fingerprint' => $fingerprint, 'document_id' => 0,
            'action' => 'period_close', 'actor' => FinanceValue::json(FinanceAccess::actor()), 'result' => FinanceValue::json($result), 'create_time' => $at]);
        return $result;
    }

    private static function preview(FinanceLedger $ledger, array $params): array
    {
        $checklist = FinancePeriodChecklist::collect($ledger, $params); $month = $checklist['month'];
        if ($checklist['closed']) { throw new \DomainException('该月已经结账，原快照不可覆盖'); }
        $reports = [];
        foreach (FinanceReports::TYPES as $kind => $_) { $reports[$kind] = FinanceReports::monthly($ledger, ['report' => $kind, 'month' => $month]); }
        $unresolved = array_values(array_filter($checklist['items'], static fn(array $item): bool => in_array($item['severity'], ['blocking', 'estimate'], true)));
        $snapshot = ['tenant_id' => FinanceAccess::tenant(), 'month' => $month, 'cutoff' => $checklist['actual_cutoff'], 'reports' => $reports,
            'checklist' => $checklist, 'unresolved' => $unresolved, 'balances' => FinanceReports::closingBalances($month)];
        // 检查时间不属于业务内容，等待确认本身不能让预览失效。
        $stable = $snapshot; unset($stable['checklist']['checked_at']);
        foreach ($stable['reports'] as &$report) { unset($report['generated_at']); } unset($report);
        return ['tenant_id' => FinanceAccess::tenant(), 'month' => $month, 'checklist' => $checklist, 'snapshot' => $snapshot, 'fingerprint' => hash('sha256', FinanceValue::json($stable))];
    }
}
