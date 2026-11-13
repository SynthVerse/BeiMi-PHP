<?php

declare(strict_types=1);

use app\api\jxc\logic\{FinanceBusinessException, FinanceLedger, FinanceMoney};
use PHPUnit\Framework\TestCase;

/** 通过真实资金登记入口验证字段错误，持久化边界只提供账户与已存在交易夹具。 */
final class FinanceMoneyValidationTest extends TestCase
{
    private object $database;
    private array $identity;

    protected function setUp(): void
    {
        $this->database = \think\Container::getInstance()->make('think\DbManager');
        $request = request();
        $this->identity = [$request->tenantId ?? null, $request->adminId ?? null, $request->adminInfo ?? null, $request->jxcFromUserToken ?? null];
        $request->tenantId = 884260; $request->adminId = 99; $request->adminInfo = ['root' => 1, 'tenant_id' => 884260]; $request->jxcFromUserToken = false;
    }

    protected function tearDown(): void
    {
        \think\Container::getInstance()->instance('think\DbManager', $this->database);
        $request = request();
        [$request->tenantId, $request->adminId, $request->adminInfo, $request->jxcFromUserToken] = $this->identity;
    }

    /** @dataProvider invalidMoneyEvidence */
    public function test_money_validation_locates_the_field_without_writing(array $payload, string $accountType, string $field): void
    {
        $database = new FinanceMoneyValidationDatabase($accountType);
        \think\Container::getInstance()->instance('think\DbManager', $database);
        try {
            (new FinanceMoney(884260, new FinanceLedger(884260)))->record(1, 'customer_refund', $payload + ['account_id' => 8], 'out', '2026-11-12', '500.00', '2026-11');
            self::fail('invalid money facts must be rejected');
        } catch (FinanceBusinessException $error) {
            self::assertSame('FINANCE_VALIDATION_FAILED', $error->details()['error_code']);
            self::assertSame($field, $error->details()['field_errors'][0]['field']);
            self::assertSame(0, $database->writes);
        }
    }

    public static function invalidMoneyEvidence(): array
    {
        return [
            'electronic screenshot required' => [[], 'bank', 'evidence_ids'],
            'missing exception basis' => [['missing_evidence_reason' => '永久无法取得'], 'bank', 'alternative_evidence'],
            'unavailable screenshot' => [['evidence_ids' => [28]], 'cash', 'evidence_ids'],
            'invalid screenshot id' => [['evidence_ids' => ['invalid']], 'cash', 'evidence_ids'],
            'channel required for transaction' => [['transaction_no' => 'ABC'], 'cash', 'transaction_scope'],
            'channel length' => [['transaction_scope' => str_repeat('中', 161)], 'cash', 'transaction_scope'],
            'similar transaction justification' => [[], 'cash', 'duplicate_risk_reason'],
        ];
    }
}

final class FinanceMoneyValidationDatabase
{
    public int $writes = 0;
    public function __construct(public string $accountType) {}
    public function name(string $table): FinanceMoneyValidationQuery { return new FinanceMoneyValidationQuery($this, $table); }
}

final class FinanceMoneyValidationQuery
{
    public function __construct(private FinanceMoneyValidationDatabase $database, private string $table) {}
    public function where(...$arguments): self { return $this; }
    public function whereBetween(...$arguments): self { return $this; }
    public function find(): ?array
    {
        return $this->table === 'finance_account' ? ['id' => 8, 'name' => '测试账户', 'account_type' => $this->database->accountType, 'is_enabled' => 1] : null;
    }
    public function sum(string $column): int { return 0; }
    public function count(): int { return $this->table === 'finance_money_transaction' ? 1 : 0; }
    public function insert(array $row): int { $this->database->writes++; return 1; }
    public function insertGetId(array $row): int { $this->database->writes++; return 1; }
}
