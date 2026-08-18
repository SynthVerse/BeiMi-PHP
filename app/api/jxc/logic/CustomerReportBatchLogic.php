<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use think\facade\Db;

/** 门店报货批次状态机：连续录入、开始处理、补报归属和手动结束。 */
class CustomerReportBatchLogic extends BaseLogic
{
    /** @return array<string,mixed>|false */
    public static function start(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('report.create')) {
            return false;
        }
        $tenantId = self::tenantId();
        $key = trim((string)($params['idempotency_key'] ?? ''));
        $deliveryDate = self::date((string)($params['delivery_date'] ?? ''));
        if ($tenantId <= 0 || $key === '' || strlen($key) > 96 || $deliveryDate === false) {
            self::setError('新建报货批次需要有效送货日期和幂等键');
            return false;
        }
        $fingerprint = hash('sha256', $deliveryDate);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return Db::transaction(static function () use ($tenantId, $key, $deliveryDate, $fingerprint) {
                    $existing = Db::name('customer_report_batch')->where('tenant_id', $tenantId)
                        ->where('idempotency_key', $key)->find();
                    if ($existing) {
                        if ((string)$existing['request_fingerprint'] !== $fingerprint) {
                            self::setError('幂等键已用于不同的报货批次');
                            return false;
                        }
                        return self::detailById((int)$existing['id']);
                    }
                    $now = time();
                    $id = (int)Db::name('customer_report_batch')->insertGetId([
                        'tenant_id' => $tenantId,
                        'batch_no' => 'CRB' . date('YmdHis') . random_int(1000, 9999),
                        'delivery_date' => $deliveryDate,
                        'status' => 'open',
                        'idempotency_key' => $key,
                        'request_fingerprint' => $fingerprint,
                        'version' => 1,
                        'create_time' => $now,
                        'update_time' => $now,
                    ]);
                    return self::detailById($id);
                });
            } catch (\Throwable $exception) {
                $existing = Db::name('customer_report_batch')->where('tenant_id', $tenantId)
                    ->where('idempotency_key', $key)->find();
                if ($existing) {
                    if ((string)$existing['request_fingerprint'] === $fingerprint) {
                        return self::detailById((int)$existing['id']);
                    }
                    self::setError('幂等键已用于不同的报货批次');
                    return false;
                }
                if (!self::retryable($exception) || $attempt === 2) {
                    break;
                }
                usleep(($attempt + 1) * 20_000);
            }
        }
        if (!self::hasError()) { self::setError('新建报货批次失败'); }
        return false;
    }

    /** @return array<string,mixed>|false */
    public static function process(array $params): array|false
    {
        return self::transition($params, 'processing');
    }

    /** @return array<string,mixed>|false */
    public static function end(array $params): array|false
    {
        return self::transition($params, 'ended');
    }

    /** @return array<string,mixed>|false */
    public static function detail(array $params): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('report.view')) {
            return false;
        }
        return self::detailById((int)($params['id'] ?? 0));
    }

    /** @return array<string,mixed>|false */
    private static function transition(array $params, string $target): array|false
    {
        self::clearError();
        if (!WorkforceLogic::requirePermission('report.edit')) {
            return false;
        }
        $id = (int)($params['id'] ?? 0);
        $version = (int)($params['version'] ?? 0);
        try {
            return self::transactionWithRetry(static function () use ($id, $version, $target) {
                $batch = Db::name('customer_report_batch')->where('tenant_id', self::tenantId())
                    ->where('id', $id)->lock(true)->find();
                if (!$batch) {
                    self::setError('报货批次不存在或不属于当前门店');
                    return false;
                }
                $currentVersion = (int)$batch['version'];
                if ((string)$batch['status'] === $target) {
                    if ($version === $currentVersion || $version === $currentVersion - 1) {
                        return self::detailById($id);
                    }
                    self::setError('报货批次版本冲突，请刷新后重试');
                    return false;
                }
                if ((string)$batch['status'] === 'ended' || $currentVersion !== $version) {
                    self::setError('报货批次版本冲突或状态不可变更');
                    return false;
                }
                if ($target === 'ended' && (string)$batch['status'] !== 'processing') {
                    self::setError('只有处理中的报货批次可以结束');
                    return false;
                }
                if ($target === 'processing') {
                    if ((string)$batch['status'] !== 'open') {
                        self::setError('只有录入中的批次可以开始处理');
                        return false;
                    }
                    $reportCount = Db::name('customer_report')->where('tenant_id', self::tenantId())
                        ->where('batch_id', $id)->lock(true)->count();
                    if ($reportCount === 0) {
                        self::setError('空报货批次不能开始处理');
                        return false;
                    }
                }
                $now = time();
                $operatorId = self::operatorId();
                $changes = ['status' => $target, 'version' => $version + 1, 'update_time' => $now];
                if ($target === 'processing') {
                    $changes += ['processing_by' => $operatorId, 'processing_time' => $now];
                } else {
                    $changes += ['ended_by' => $operatorId, 'ended_time' => $now];
                }
                $updated = Db::name('customer_report_batch')->where('tenant_id', self::tenantId())
                    ->where('id', $id)->where('version', $version)->update($changes);
                if ($updated !== 1) { throw new \RuntimeException('version_conflict'); }
                AuditService::logWithinTransaction(
                    AuditService::MODULE_CUSTOMER_REPORT_BATCH,
                    AuditService::ACTION_EDIT,
                    $id,
                    (string)$batch['batch_no'],
                    $batch,
                    array_replace($batch, $changes),
                    $target === 'processing' ? '报货批次开始处理' : '报货批次手动结束'
                );
                return self::detailById($id);
            });
        } catch (\Throwable) {
            if (!self::hasError()) { self::setError('报货批次状态变更失败'); }
            return false;
        }
    }

    /** @return array<string,mixed>|false */
    private static function detailById(int $id): array|false
    {
        $batch = Db::name('customer_report_batch')->where('tenant_id', self::tenantId())->where('id', $id)->find();
        if (!$batch) {
            self::setError('报货批次不存在或不属于当前门店');
            return false;
        }
        $batch['report_count'] = Db::name('customer_report')->where('tenant_id', self::tenantId())
            ->where('batch_id', $id)->count();
        $batch['supplement_count'] = Db::name('customer_report')->where('tenant_id', self::tenantId())
            ->where('batch_id', $id)->where('is_supplement', 1)->count();
        return $batch;
    }

    private static function date(string $value): string|false
    {
        $value = trim($value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : false;
    }

    private static function tenantId(): int { return (int)(request()->tenantId ?? 0); }
    private static function operatorId(): int { return (int)(request()->adminId ?? request()->userId ?? 0); }
    /** @template T @param callable():T $operation @return T */
    private static function transactionWithRetry(callable $operation): mixed
    {
        $lastException = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return Db::transaction($operation);
            } catch (\Throwable $exception) {
                $lastException = $exception;
                if (!self::retryable($exception) || $attempt === 2) {
                    throw $exception;
                }
                usleep(($attempt + 1) * 20_000);
            }
        }
        throw $lastException ?? new \RuntimeException('transaction_failed');
    }
    private static function retryable(\Throwable $exception): bool
    {
        return str_contains($exception->getMessage(), '1213') || str_contains($exception->getMessage(), '1205');
    }
}
