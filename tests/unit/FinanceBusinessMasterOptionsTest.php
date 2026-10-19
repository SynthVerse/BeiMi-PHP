<?php

declare(strict_types=1);

use app\api\jxc\logic\FinanceBusinessLogic;
use app\api\jxc\logic\FinanceDocumentPolicy;
use app\api\jxc\logic\FinanceSetupLogic;
use PHPUnit\Framework\TestCase;

/**
 * Exercise public catalog/options and account authorization against an empty,
 * read-only database boundary. No database connection or write is performed.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class FinanceBusinessMasterOptionsTest extends TestCase
{
    protected function setUp(): void
    {
        $request = (new \app\Request())->withServer(['REQUEST_METHOD' => 'GET']);
        $request->tenantId = 4; $request->adminId = 5;
        $request->adminInfo = ['tenant_id' => 4, 'root' => 1];
        $app = \think\Container::getInstance();
        $app->instance('request', $request);
        $app->instance('think\\DbManager', new FinanceBusinessMasterEmptyDatabase());
    }

    /** @dataProvider accountTasks */
    public function test_empty_account_task_offers_same_page_account_creation(string $type): void
    {
        $options = FinanceBusinessLogic::options(['type' => $type]);

        self::assertNotFalse($options, FinanceBusinessLogic::getError());
        self::assertSame([], $options['accounts']);
        self::assertSame('account', $options['policy']['subject']);
        self::assertContains('finance_account', $options['master_create_kinds'], $type . ' 缺少本页新建资金账户入口');
        self::assertNotContains('account', $options['master_create_kinds']);
    }

    public static function accountTasks(): array
    {
        $tasks = [];
        foreach (FinanceDocumentPolicy::TYPES as $type => $policy) {
            if ($policy['subject'] === 'account') { $tasks[$type] = [$type]; }
        }
        return $tasks;
    }

    public function test_catalog_account_task_coverage_matches_every_declared_policy(): void
    {
        $catalog = FinanceBusinessLogic::catalog();
        $accountTasks = array_values(array_map(static fn(array $row): string => $row['type'], array_filter($catalog['types'], static fn(array $row): bool => $row['subject'] === 'account')));

        self::assertCount(80, $catalog['types']);
        self::assertSame(array_keys(self::accountTasks()), $accountTasks);
        self::assertContains('account_transfer_out', $accountTasks);
        self::assertContains('account_reconcile', $accountTasks);
    }

    public function test_prepare_permission_does_not_grant_account_creation_or_owner_confirmation(): void
    {
        request()->adminInfo = ['tenant_id' => 4, 'root' => 0];
        request()->todoPermissionSnapshot = ['tenant_id' => 4, 'operator_id' => 5, 'identity' => 'tenant_admin', 'owner' => false, 'permissions' => ['finance.transfer.prepare']];
        $options = FinanceBusinessLogic::options(['type' => 'account_transfer_out']);

        self::assertNotFalse($options, FinanceBusinessLogic::getError());
        self::assertTrue($options['can_prepare']);
        self::assertFalse($options['can_confirm']);
        self::assertSame([], $options['master_create_kinds']);
        self::assertFalse(FinanceSetupLogic::saveAccount(['name' => '越权账户', 'account_type' => 'cash']));
        self::assertStringContainsString('最高权限', FinanceSetupLogic::getError());
    }
}

final class FinanceBusinessMasterEmptyDatabase
{
    public function name(string $table): FinanceBusinessMasterEmptyQuery { return new FinanceBusinessMasterEmptyQuery($table); }
    public function query(string $sql): array { return []; }
}

final class FinanceBusinessMasterEmptyQuery
{
    public function __construct(private string $table) {}
    public function __call(string $name, array $arguments): self
    {
        if (!in_array($name, ['where', 'whereIn', 'whereRaw', 'order', 'field', 'alias', 'leftJoin', 'group'], true)) {
            throw new LogicException('Unexpected database operation: ' . $name);
        }
        return $this;
    }
    public function buildSql(): string { return '(SELECT 1 WHERE 0=1)'; }
    public function select(): \think\Collection { return new \think\Collection([]); }
    public function value(string $field) { return $this->table === 'finance_opening_book' && $field === 'status' ? 'draft' : null; }
}
