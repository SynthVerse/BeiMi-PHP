<?php

declare(strict_types=1);

namespace tests\unit;

use app\common\service\goods\GoodsAliasService;
use PHPUnit\Framework\TestCase;

final class GoodsAliasNormalizationTest extends TestCase
{
    public function test_normalize_removes_ascii_and_unicode_separator_whitespace(): void
    {
        $whitespace = "\u{0009}\u{000A}\u{000B}\u{000C}\u{000D}\u{0020}"
            . "\u{0085}\u{00A0}\u{1680}"
            . "\u{2000}\u{2001}\u{2002}\u{2003}\u{2004}\u{2005}"
            . "\u{2006}\u{2007}\u{2008}\u{2009}\u{200A}"
            . "\u{2028}\u{2029}\u{202F}\u{205F}\u{3000}";

        self::assertSame(
            '鳜鱼',
            GoodsAliasService::normalize($whitespace . '鳜' . $whitespace . '鱼' . $whitespace)
        );
    }
}
