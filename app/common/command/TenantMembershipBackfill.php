<?php

declare(strict_types=1);

namespace app\common\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * Fixed zero-I/O preflight for the retired tenant membership backfill.
 */
class TenantMembershipBackfill extends Command
{
    protected function configure(): void
    {
        $this->setName('tenant-membership:backfill')
            ->setDescription('Run the fixed zero-I/O tenant membership preflight');
    }

    protected function execute(Input $input, Output $output): int
    {
        $output->writeln('preflight source=none mapping_entries=0 proposed_writes=0 writes=0');
        return 0;
    }
}
