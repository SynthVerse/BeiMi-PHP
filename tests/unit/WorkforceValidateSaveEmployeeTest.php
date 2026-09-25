<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\validate\WorkforceValidate;
use PHPUnit\Framework\TestCase;

final class WorkforceValidateSaveEmployeeTest extends TestCase
{
    public function test_new_paper_employee_with_zero_id_is_valid_for_save(): void
    {
        $validate = new WorkforceValidate();

        self::assertTrue($validate->scene('saveEmployee')->check([
            'id' => 0,
            'name' => 'test-paper-employee',
            'mobile' => '13900000000',
            'bind_user_id' => 0,
            'is_enabled' => 1,
            'permission_keys' => [],
        ]), $validate->getError());
    }

    public function test_existing_employee_with_positive_id_is_valid_for_save(): void
    {
        $validate = new WorkforceValidate();

        self::assertTrue($validate->scene('saveEmployee')->check([
            'id' => 1,
            'name' => 'test-paper-employee',
            'mobile' => '13900000000',
            'bind_user_id' => 0,
            'is_enabled' => 1,
            'permission_keys' => [],
        ]), $validate->getError());
    }

    public function test_save_employee_rejects_a_negative_id(): void
    {
        $validate = new WorkforceValidate();

        self::assertFalse($validate->scene('saveEmployee')->check([
            'id' => -1,
            'name' => 'test-paper-employee',
            'mobile' => '13900000000',
            'bind_user_id' => 0,
            'is_enabled' => 1,
            'permission_keys' => [],
        ]));
    }
}
