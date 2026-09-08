<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 门店账套锁内保留原对象和更正后对象的来源身份，防止同一费用再次登记。 */
final class FinanceExpenseIdentities
{
    public static function claim(int $vendor, string $reference, int $bill, int $document): void
    {
        $tenant = FinanceAccess::tenant();
        $existing = Db::name('finance_expense_identity')->where('tenant_id', $tenant)->where('vendor_id', $vendor)->where('source_reference', $reference)->lock(true)->find();
        if ($existing) {
            if ((int)$existing['bill_id'] !== $bill) { throw new \DomainException('该对象的费用来源已登记，请核对原费用，不能合并或重复登记'); }
            return;
        }
        Db::name('finance_expense_identity')->insert(['tenant_id' => $tenant, 'vendor_id' => $vendor, 'source_reference' => $reference, 'bill_id' => $bill, 'document_id' => $document]);
    }
}
