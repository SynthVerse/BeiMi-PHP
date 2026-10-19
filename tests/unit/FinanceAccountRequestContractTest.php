<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class FinanceAccountRequestContractTest extends TestCase
{
    /** @dataProvider draftRemovalChoices */
    public function test_explicit_draft_removal_choice_survives_real_request_filter(bool $choice): void
    {
        class_alias(FinanceAccountRemovalDispatch::class, \app\api\jxc\logic\FinanceSetupLogic::class);
        $request = (new \app\Request())->withPost(['id' => 5, 'expected_tenant_id' => 4, 'expected_version' => 1, 'idempotency_key' => 'account-remove-request', 'remove_opening_draft' => $choice]);
        $app = new \think\App(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
        $app->instance('request', $request);
        $controller = new \app\api\controller\jxc\FinanceSetupController($app);
        $params = $controller->removeAccount()->getData()['data'];
        self::assertSame($choice, $params['remove_opening_draft']);
        self::assertSame('5', $params['id']);
    }

    public static function draftRemovalChoices(): array { return [[true], [false]]; }
}

final class FinanceAccountRemovalDispatch
{
    public static function removeAccount(array $params): array { return $params; }
}
