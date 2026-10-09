<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\validate\WorkforceValidate;
use PHPUnit\Framework\TestCase;

final class WorkforceValidateSaveProcessTest extends TestCase
{
    public function test_new_process_with_zero_id_is_valid_for_save(): void
    {
        $validate = new WorkforceValidate();

        self::assertTrue($validate->scene('saveProcess')->check([
            'id' => 0,
            'name' => '采购',
            'trigger_type' => 'inventory_shortage',
            'keywords' => [],
            'sort' => 10,
            'is_enabled' => 1,
        ]), $validate->getError());
    }

    public function test_existing_process_with_positive_id_is_valid_for_save(): void
    {
        $validate = new WorkforceValidate();

        self::assertTrue($validate->scene('saveProcess')->check([
            'id' => 1,
            'name' => '采购',
            'trigger_type' => 'inventory_shortage',
            'keywords' => [],
            'sort' => 10,
            'is_enabled' => 1,
        ]), $validate->getError());
    }

    public function test_save_process_rejects_a_negative_id(): void
    {
        $validate = new WorkforceValidate();

        self::assertFalse($validate->scene('saveProcess')->check([
            'id' => -1,
            'name' => '采购',
            'trigger_type' => 'inventory_shortage',
            'keywords' => [],
            'sort' => 10,
            'is_enabled' => 1,
        ]));
    }
}
