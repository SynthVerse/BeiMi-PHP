<?php

declare(strict_types=1);

namespace app\common\command;

use app\api\jxc\logic\FinanceOverdue;
use think\console\{Command, Input, Output};
use think\facade\Db;
use think\facade\Log;

/** 由部署后的定时任务调用；只同步已启用账套的内部待办。 */
final class FinanceRefreshOverdue extends Command
{
    protected function configure(): void
    {
        $this->setName('finance:refresh-overdue')->setDescription('同步已启用财务账套的内部逾期待办，不发送客户消息');
    }

    protected function execute(Input $input, Output $output): int
    {
        $after = 0; $count = 0; $failed = 0;
        try {
            do {
                $tenants = Db::name('finance_opening_book')->where('status', 'active')->where('tenant_id', '>', $after)->order('tenant_id')->limit(100)->column('tenant_id');
                foreach ($tenants as $tenant) {
                    $after = (int)$tenant;
                    try { FinanceOverdue::capture($after); $count++; }
                    catch (\Throwable $error) { $failed++; self::reportFailure($output, $after, $error); }
                }
            } while (count($tenants) === 100);
        } catch (\Throwable $error) { $failed++; self::reportFailure($output, 0, $error); }
        $output->writeln('已同步账套数：' . $count . '；失败数：' . $failed . '。失败项保留错误日志，下轮继续重试。');
        // 既有调度器对抛出的异常会永久停用整个任务；返回非零供CLI监测，但保留调度。
        return $failed ? 1 : 0;
    }

    private static function reportFailure(Output $output, int $tenant, \Throwable $error): void
    {
        Log::error('finance:refresh-overdue tenant=' . $tenant . ' ' . $error->getMessage());
        $output->writeln('<error>账套 #' . $tenant . ' 本轮同步失败，具体原因已写入服务端错误日志。</error>');
    }
}
