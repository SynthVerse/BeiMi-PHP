<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\ProcurementTaskLogic;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ProcurementTaskCloseContractTest extends TestCase
{
    public function test_close_reason_accepts_reason_alias_in_logic_contract(): void
    {
        $source = file_get_contents((new ReflectionClass(ProcurementTaskLogic::class))->getFileName());

        self::assertStringContainsString("\$params['reason']", $source);
        self::assertMatchesRegularExpression('/close_reason\'\]\s*\?\?\s*\$params\[\'reason\'\]/', $source);
    }
}
