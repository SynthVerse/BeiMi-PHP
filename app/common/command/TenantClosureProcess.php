<?php

declare(strict_types=1);

namespace app\common\command;

use app\common\service\jxc\TenantClosureService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

class TenantClosureProcess extends Command
{
    protected function configure(): void
    {
        $this->setName('tenant-closure:process')
            ->setDescription('Retry pending tenant closure cleanup and mark overdue backup purges')
            ->addOption('limit', null, Option::VALUE_OPTIONAL, '单次处理数量', '20');
    }

    protected function execute(Input $input, Output $output): int
    {
        $result = TenantClosureService::processPending((int)$input->getOption('limit'));
        $output->writeln(sprintf(
            '<info>processed=%d failed=%d backup_overdue=%d</info>',
            $result['processed'],
            $result['failed'],
            $result['backup_overdue']
        ));
        return $result['failed'] > 0 ? 1 : 0;
    }
}
