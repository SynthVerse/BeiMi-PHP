<?php

declare(strict_types=1);

namespace tests\unit;

use app\common\service\goods\GoodsAliasService;
use PHPUnit\Framework\TestCase;

final class GoodsAliasNormalizationTest extends TestCase
{
    public function test_normalize_removes_ascii_and_unicode_separator_whitespace(): void
    {
        self::assertSame('鳜鱼', GoodsAliasService::normalize(" \t鳜　鱼\r\n"));
    }
}
