<?php

declare(strict_types=1);

namespace app\common\command;

use app\common\enum\user\UserTerminalEnum;
use app\common\model\tenant\Tenant;
use app\common\model\user\User;
use app\common\service\jxc\DefaultDataInitService;
use app\common\service\jxc\TenantProvisionService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

/**
 * JXC 默认基础数据补建命令
 *
 * 用法：
 *   php think jxc:init-defaults              # 为所有已存在租户补建默认数据
 *   php think jxc:init-defaults --with-wechat-users  # 同时为 tenant_id=0 的微信小程序用户补建租户
 *   php think jxc:init-defaults --dry-run    # 预演模式，不落库
 */
class JxcInitDefaults extends Command
{
    protected function configure(): void
    {
        $this->setName('jxc:init-defaults')
            ->setDescription('Initialize JXC default data (warehouse/customer/vendor/goods_unit) for tenants')
            ->addOption('with-wechat-users', null, Option::VALUE_NONE, '同时为 tenant_id=0 的微信小程序用户补建租户并初始化')
            ->addOption('dry-run', null, Option::VALUE_NONE, '预演模式，仅打印操作对象，不写入数据库');
    }

    protected function execute(Input $input, Output $output): int
    {
        $dryRun = (bool)$input->getOption('dry-run');
        $withWechat = (bool)$input->getOption('with-wechat-users');

        $output->writeln('<info>[jxc:init-defaults] start</info>');
        if ($dryRun) {
            $output->writeln('<comment>-- DRY RUN MODE: no data will be written --</comment>');
        }

        $tenantsProcessed = $this->processTenants($output, $dryRun);
        $usersProvisioned = 0;
        if ($withWechat) {
            $usersProvisioned = $this->processWechatUsers($output, $dryRun);
        }

        $output->writeln('<info>[jxc:init-defaults] done</info>');
        $output->writeln(sprintf(
            '  tenants_processed=%d, users_provisioned=%d',
            $tenantsProcessed,
            $usersProvisioned
        ));
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

    /**
     * 为 tenant_id=0 的微信小程序用户补建租户并初始化默认数据
     */
    private function processWechatUsers(Output $output, bool $dryRun): int
    {
        $users = User::where('tenant_id', 0)
            ->where('channel', UserTerminalEnum::WECHAT_MMP)
            ->field('id,sn,nickname,tenant_id')
            ->select();

        $count = 0;
        foreach ($users as $user) {
            $openid = Db::name('user_auth')
                ->where('user_id', $user->id)
                ->where('terminal', UserTerminalEnum::WECHAT_MMP)
                ->value('openid');

            $output->writeln(sprintf(
                '  user_id=%d nickname=%s openid=%s',
                (int)$user->id,
                (string)$user->nickname,
                $openid ?: '(none)'
            ));
            if ($dryRun) {
                $count++;
                continue;
            }

            Db::transaction(function () use ($user, $openid) {
                TenantProvisionService::provisionForWechatUser($user, $openid ?: null);
            });
            $count++;
        }

        return $count;
    }
}
