<?php

declare(strict_types=1);

namespace tests\support;

require_once __DIR__ . '/PreparationIsolatedHttpFixture.php';

/** P09 公开契约保持不变；隔离生命周期由所有准备页共用。 */
final class ExpenseRefundHttpFixture extends PreparationIsolatedHttpFixture
{
    public static function acceptsContract(array $contract, array $expectedIds): bool
    {
        return ($contract['protocol'] ?? null) === PreparationHttpBridgeProcess::PROTOCOL
            && ($contract['runId'] ?? null) === self::RUN_ID
            && ($contract['cutoffDate'] ?? null) === self::CUTOFF_DATE
            && ($contract['category'] ?? null) === 'expense_refund'
            && ($contract['client'] ?? null) === 'docs/ui-previews/expense-refund-preparation-20261102/implementation/expense-refund-http-client.mjs'
            && ($contract['coreCheckIds'] ?? null) === $expectedIds;
    }

    public static function run(string $clientPath, string $nodeBinary, array &$evidence): array
    {
        return parent::runIsolated('expense_refund', $clientPath, $nodeBinary, $evidence);
    }
}
