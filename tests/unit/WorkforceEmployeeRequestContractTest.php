<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Real Request -> Validator -> routed Controller -> WorkforceLogic.
 * A database-boundary recorder stops before executing the transaction callback.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class WorkforceEmployeeRequestContractTest extends TestCase
{
    public function test_explicit_json_unknown_mobile_reaches_persistence_with_null_mobile_and_stable_creation_identity(): void
    {
        [$response, $database] = $this->submit([
            'id' => 0, 'name' => ' 历史员工 ', 'mobile' => '', 'mobile_unknown' => 1,
            'creation_key' => ' historical-employee-request ', 'bind_user_id' => 0, 'permission_keys' => [],
        ]);

        self::assertCount(1, $database->transactions, $response['msg'] ?? '没有进入员工持久化边界');
        self::assertNull($database->transactions[0]['mobile']);
        self::assertSame('历史员工', $database->transactions[0]['name']);
        self::assertSame('historical-employee-request', $database->transactions[0]['creationKey']);
        self::assertSame([], $database->transactions[0]['permissionKeys']);
        self::assertSame(0, $database->transactions[0]['bindUserId']);
    }

    /** @dataProvider nonIntegerUnknownChoices */
    public function test_other_json_types_are_not_promoted_to_an_explicit_unknown_mobile($choice): void
    {
        [$response, $database] = $this->submit([
            'name' => '历史员工', 'mobile' => '', 'mobile_unknown' => $choice,
            'creation_key' => 'invalid-marker', 'bind_user_id' => 0, 'permission_keys' => [],
        ]);

        self::assertSame([], $database->transactions);
        self::assertSame(0, $response['code']);
        self::assertSame('员工姓名和手机号不能为空', $response['msg']);
    }

    public static function nonIntegerUnknownChoices(): array
    {
        return [['1'], [true], [1.0], [0], [false], [null], [[1]], ['true'], ['unknown']];
    }

    public function test_absent_unknown_marker_requires_mobile_and_existing_text_fields_keep_trim_filtering(): void
    {
        [$response, $database] = $this->submit(['name' => ' 员工甲 ', 'mobile' => ' 13800001234 ', 'bind_user_id' => 0, 'permission_keys' => []]);

        self::assertCount(1, $database->transactions, $response['msg'] ?? '没有进入员工持久化边界');
        self::assertSame('员工甲', $database->transactions[0]['name']);
        self::assertSame('13800001234', $database->transactions[0]['mobile']);
        self::assertArrayNotHasKey('mobile_unknown', $database->transactions[0]['params']);
    }

    public function test_explicit_unknown_mobile_cannot_bind_an_account_or_grant_permissions(): void
    {
        foreach ([['bind_user_id' => 8], ['permission_keys' => ['employee.manage']]] as $extra) {
            [$response, $database] = $this->submit(array_merge([
                'name' => '历史员工', 'mobile' => '', 'mobile_unknown' => 1,
                'creation_key' => 'forbidden-grants', 'bind_user_id' => 0, 'permission_keys' => [],
            ], $extra));

            self::assertSame([], $database->transactions);
            self::assertSame(0, $response['code']);
            self::assertSame('手机号未知的历史人员不能绑定账号或授予电子权限', $response['msg']);
        }
    }

    private function submit(array $payload): array
    {
        $request = (new \app\Request())->withServer(['REQUEST_METHOD' => 'POST'])->withPost(json_decode(json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, 512, JSON_THROW_ON_ERROR));
        $request->tenantId = 4;
        $request->adminInfo = ['root' => 1, 'tenant_id' => 4, 'admin_id' => 5];
        $app = \think\Container::getInstance();
        $app->instance('request', $request);
        $database = new WorkforceEmployeeRequestDatabaseBoundary();
        $app->instance('think\\DbManager', $database);
        $controller = new \app\api\controller\jxc\WorkforceController($app);

        return [$controller->saveEmployee()->getData(), $database];
    }
}

final class WorkforceEmployeeRequestDatabaseBoundary
{
    public array $transactions = [];

    public function transaction(Closure $action): void
    {
        $this->transactions[] = (new ReflectionFunction($action))->getStaticVariables();
        throw new RuntimeException('TEST: stop before database persistence');
    }
}
