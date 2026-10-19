<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Keep the real request filter and routed controller, stopping before persistence.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class FinanceBusinessRequestContractTest extends TestCase
{
    /** @dataProvider booleanReminderChoices */
    public function test_json_reminder_choices_reach_the_domain_as_booleans(bool $choice): void
    {
        $result = $this->submit(['action' => 'save', 'type' => 'capital_in', 'continue_later' => $choice]);

        self::assertSame('save', $result['action']);
        self::assertSame($choice, $result['params']['continue_later']);
    }

    public static function booleanReminderChoices(): array
    {
        return [[true], [false]];
    }

    /** @dataProvider invalidReminderChoices */
    public function test_non_boolean_choices_remain_invalid_for_the_domain($choice): void
    {
        $result = $this->submit(['action' => 'save', 'continue_later' => $choice]);

        self::assertSame($choice, $result['params']['continue_later']);
        self::assertIsNotBool($result['params']['continue_later']);
    }

    public static function invalidReminderChoices(): array
    {
        return [
            'text true' => ['true'],
            'text false' => ['false'],
            'text one' => ['1'],
            'integer one' => [1],
            'integer zero' => [0],
            'arbitrary text' => ['not-a-boolean'],
            'null' => [null],
            'array' => [[true]],
        ];
    }

    public function test_absent_choice_is_not_added_and_other_fields_keep_project_filtering(): void
    {
        $result = $this->submit(['action' => ' save ', 'details' => ['note' => ' pending review ']]);

        self::assertSame('save', $result['action']);
        self::assertArrayNotHasKey('continue_later', $result['params']);
        self::assertSame('pending review', $result['params']['details']['note']);
    }

    private function submit(array $payload): array
    {
        class_alias(FinanceBusinessRequestDispatch::class, \app\api\jxc\logic\FinanceBusinessLogic::class);
        $request = (new \app\Request())->withPost(json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
        $app = new \think\App(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
        $app->instance('request', $request);
        $controller = new \app\api\controller\jxc\FinanceBusinessController($app);

        return $controller->action()->getData()['data'];
    }
}

final class FinanceBusinessRequestDispatch
{
    public static function action(string $action, array $params): array
    {
        return ['action' => $action, 'params' => $params];
    }
}
