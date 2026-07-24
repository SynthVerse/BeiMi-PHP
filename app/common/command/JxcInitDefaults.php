<?php

declare(strict_types=1);

namespace app\common\command;

use app\common\model\tenant\Tenant;
use app\common\service\jxc\DefaultDataInitService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * JXC 默认基础数据补建命令
 *
 * 用法：
 *   php think jxc:init-defaults              # 为所有已存在租户补建默认数据
 *   php think jxc:init-defaults --dry-run    # 预演模式，不落库
 */
class JxcInitDefaults extends Command
{
    protected function configure(): void
    {
        $this->setName('jxc:init-defaults')
            ->setDescription('Initialize JXC default data (warehouse/customer/vendor/goods_unit) for tenants')
            ->addOption('dry-run', null, Option::VALUE_NONE, '预演模式，仅打印操作对象，不写入数据库');
    }

    protected function execute(Input $input, Output $output): int
    {
        $dryRun = (bool)$input->getOption('dry-run');

        $output->writeln('<info>[jxc:init-defaults] start</info>');
        if ($dryRun) {
            $output->writeln('<comment>-- DRY RUN MODE: no data will be written --</comment>');
        }

        $tenantsProcessed = $this->processTenants($output, $dryRun);

        $output->writeln('<info>[jxc:init-defaults] done</info>');
        $output->writeln(sprintf('  tenants_processed=%d', $tenantsProcessed));
        return 0;
    }

    /**
     * 为所有已存在租户补建默认数据（幂等）
     */
    private function processTenants(Output $output, bool $dryRun): int
    {
        $tenantIds = Tenant::field('id')->select()->column('id');
        $count = 0;

        foreach ($tenantIds as $tid) {
            $tid = (int)$tid;
            if ($tid <= 0) {
                continue;
            }
            $already = DefaultDataInitService::hasInitialized($tid);
            $output->writeln(sprintf(
                '  tenant_id=%d already_initialized=%s',
                $tid,
                $already ? 'yes' : 'no'
            ));
            if ($dryRun) {
                $count++;
                continue;
            }
            DefaultDataInitService::initForTenant($tid);
            $count++;
        }

        return $count;
    }
}
