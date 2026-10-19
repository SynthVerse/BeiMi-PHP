<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Exercise the real request filter and routed controller without opening a database.
 * The process-local dispatch recorder stops at the persistence boundary.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class FinanceOpeningRequestContractTest extends TestCase
{
    public function test_unselected_subject_can_be_saved_as_an_editor_before_creating_an_account(): void
    {
        $result = $this->submit([
            'editing_draft' => true,
            'category' => 'account',
            'expected_tenant_id' => 4,
            'expected_version' => 3,
            'idempotency_key' => 'opening-request-editor',
            'form' => ['subject_id' => 0, 'amount' => '123.45', 'entry_contract' => 2, 'details' => []],
        ]);

        self::assertSame('editor', $result['action']);
        self::assertSame(2, $result['params']['form']['entry_contract']);
        self::assertSame('123.45', $result['params']['form']['amount']);
    }

    public function test_owner_entry_contract_survives_request_filtering_with_unknown_amount(): void
    {
        $result = $this->submit([
            'category' => 'account',
            'subject_id' => 1,
            'amount' => '',
            'entry_contract' => 2,
            'expected_tenant_id' => 4,
            'expected_version' => 3,
            'idempotency_key' => 'opening-request-owner',
            'details' => [],
        ]);

        self::assertSame('item', $result['action']);
        self::assertSame(2, $result['params']['entry_contract']);
        self::assertSame('', $result['params']['amount']);
        self::assertArrayNotHasKey('source_reference', $result['params']);
        self::assertArrayNotHasKey('evidence', $result['params']);
    }

    /** @dataProvider editingDraftFlags */
    public function test_only_explicit_editor_flags_select_the_editor_action($flag, string $action): void
    {
        self::assertSame($action, $this->submit(['editing_draft' => $flag])['action']);
    }

    public static function editingDraftFlags(): array
    {
        return [
            'json true' => [true, 'editor'],
            'json false' => [false, 'item'],
            'text true' => ['true', 'editor'],
            'text one' => ['1', 'editor'],
            'integer one' => [1, 'editor'],
            'text false' => ['false', 'item'],
            'text zero' => ['0', 'item'],
            'integer zero' => [0, 'item'],
            'arbitrary text' => ['not-a-boolean', 'item'],
            'null' => [null, 'item'],
            'array' => [[true], 'item'],
        ];
    }

    public function test_other_input_still_uses_the_project_string_filter(): void
    {
        $result = $this->submit([
            'category' => ' account ',
            'form' => ['subject_name' => ' cash ', 'entry_contract' => 2],
        ]);

        self::assertSame('account', $result['params']['category']);
        self::assertSame('cash', $result['params']['form']['subject_name']);
    }

    /** @dataProvider invalidOwnerContracts */
    public function test_non_integer_owner_contracts_are_not_promoted_to_contract_two($contract): void
    {
        $result = $this->submit(['entry_contract' => $contract, 'form' => ['entry_contract' => $contract]]);

        self::assertSame($contract, $result['params']['entry_contract']);
        self::assertSame($contract, $result['params']['form']['entry_contract']);
        self::assertNotSame(2, $result['params']['entry_contract']);
    }

    public static function invalidOwnerContracts(): array
    {
        return [
            'text two' => ['2'],
            'other version' => [3],
            'partial number' => ['2other'],
            'boolean' => [true],
            'float' => [2.0],
            'array' => [[2]],
            'null' => [null],
        ];
    }

    /** @dataProvider editorClearCommands */
    public function test_discard_commands_use_the_editor_route(string $flag): void
    {
        $result = $this->submit(['editing_draft' => true, 'category' => 'account', $flag => true, 'form' => []]);

        self::assertSame('editor', $result['action']);
        self::assertNotEmpty($result['params'][$flag]);
        self::assertSame([], $result['params']['form']);
    }

    public static function editorClearCommands(): array
    {
        return [['clear'], ['clear_all']];
    }

    /** @dataProvider invalidEditorForms */
    public function test_non_array_editor_forms_reach_domain_validation_without_nested_assignment($form): void
    {
        $result = $this->submit(['editing_draft' => true, 'form' => $form]);

        self::assertSame('editor', $result['action']);
        self::assertArrayHasKey('form', $result['params']);
        self::assertIsNotArray($result['params']['form']);
    }

    public static function invalidEditorForms(): array
    {
        return [['invalid-form'], [null], [true]];
    }

    private function submit(array $payload): array
    {
        class_alias(FinanceOpeningRequestDispatch::class, \app\api\jxc\logic\FinanceSetupLogic::class);
        $request = (new \app\Request())->withPost(json_decode(json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, 512, JSON_THROW_ON_ERROR));
        $app = new \think\App(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
        $app->instance('request', $request);
        $controller = new \app\api\controller\jxc\FinanceSetupController($app);

        return $controller->saveOpeningItem()->getData()['data'];
    }
}

final class FinanceOpeningRequestDispatch
{
    public static function openingAction(string $action, array $params): array
    {
        return ['action' => $action, 'params' => $params];
    }
}
