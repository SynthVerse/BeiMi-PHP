<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 旧业务入口与新账套共用准备行锁；仅启用门店切换财务记账路径。 */
final class FinanceIntegration
{
    public static function active(): bool
    {
        return self::installed() && Db::name('finance_opening_book')->where('tenant_id', FinanceAccess::tenant())->value('status') === 'active';
    }

    public static function lock(): bool
    {
        if (!self::installed()) { return false; }
        Db::name('finance_preparation')->duplicate(['tenant_id'])->insert(['tenant_id' => FinanceAccess::tenant()]);
        Db::name('finance_preparation')->where('tenant_id', FinanceAccess::tenant())->lock(true)->find();
        return Db::name('finance_opening_book')->where('tenant_id', FinanceAccess::tenant())->lock(true)->value('status') === 'active';
    }

    private static function installed(): bool
    {
        $table = Db::name('finance_opening_book')->getTable();
        // 旧门店尚未运行新迁移时保留旧流程；连接错误不能被当成未启用。
        return Db::query('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1', [$table]) !== [];
    }
}
