<?php

declare(strict_types=1);

namespace tests\unit;

use app\tenantapi\logic\auth\RoleLogic;
use app\tenantapi\validate\auth\RoleValidate;
use PHPUnit\Framework\TestCase;

final class RoleValidateMenuIdTest extends TestCase
{
    /** @dataProvider invalidMenuIds */
    public function test_invalid_menu_identifiers_are_rejected_before_database_coercion(mixed $menuId): void
    {
        $validate = new RoleValidate();
        self::assertSame('权限菜单不存在', $validate->checkMenus([$menuId], '', []));
    }

    public static function invalidMenuIds(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'numeric string' => ['1'],
            'decimal string' => ['1.5'],
            'decimal number' => [1.5],
            'alphanumeric' => ['12abc'],
            'scientific' => ['1e2'],
            'empty' => [''],
        ];
    }

    public function test_menu_validation_keeps_empty_list_valid_and_uses_unique_integer_ids(): void
    {
        $source = file_get_contents('app/tenantapi/validate/auth/RoleValidate.php');
        self::assertNotFalse($source);
        self::assertStringContainsString('!is_int($menuId) || $menuId <= 0', $source);
        self::assertStringContainsString('array_values(array_unique($ids))', $source);
        self::assertStringContainsString('if ($ids === [])', $source);
    }

    public function test_logic_rejects_numeric_strings_before_tenant_menu_query(): void
    {
        $method = new \ReflectionMethod(RoleLogic::class, 'tenantMenuIds');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('权限菜单不存在');
        $method->invoke(null, 1, ['1']);
    }

    public function test_logic_normalizes_authoritative_numeric_string_ids_before_strict_comparison(): void
    {
        $source = file_get_contents('app/tenantapi/logic/auth/RoleLogic.php');
        self::assertNotFalse($source);

        $normalizationOffset = strpos($source, '$scopedIds = self::normalizeAuthoritativeMenuIds($scopedIds);');
        $comparisonOffset = strpos($source, '$scopedIds !== $menuIds');

        self::assertNotFalse($normalizationOffset);
        self::assertNotFalse($comparisonOffset);
        self::assertLessThan($comparisonOffset, $normalizationOffset);

        $method = new \ReflectionMethod(RoleLogic::class, 'normalizeAuthoritativeMenuIds');
        self::assertSame([101, 202], $method->invoke(null, ['101', '202']));
    }
}
