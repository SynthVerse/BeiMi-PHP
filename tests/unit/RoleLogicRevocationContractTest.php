<?php

declare(strict_types=1);

namespace tests\unit;

use app\tenantapi\logic\auth\RoleLogic;
use PHPUnit\Framework\TestCase;

final class RoleLogicRevocationContractTest extends TestCase
{
    public function test_empty_menu_list_revokes_historical_settlement_permission_only_after_commit_then_cache_invalidation(): void
    {
        $source = file_get_contents('app/tenantapi/logic/auth/RoleLogic.php');
        self::assertNotFalse($source);
        $edit = self::methodBody($source, 'edit');

        $deleteOffset = strpos($edit, "TenantSystemRoleMenu::where(['role_id' => \$roleId])->delete()");
        $callbackOffset = strpos($edit, 'self::runEditCallbacks(');

        self::assertNotFalse($deleteOffset);
        self::assertNotFalse($callbackOffset);
        self::assertLessThan($deleteOffset, $callbackOffset, 'old role-menu rows must be deleted inside the write callback');
        self::assertStringNotContainsString('if (!empty($menuId))', $edit);
    }

    public function test_role_menu_rows_support_empty_revocation_and_nonempty_replacement(): void
    {
        self::assertSame([], $this->callPrivate('roleMenuRows', 7, []));
        self::assertSame([
            ['role_id' => 7, 'menu_id' => 101],
            ['role_id' => 7, 'menu_id' => 202],
        ], $this->callPrivate('roleMenuRows', 7, [101, 202]));
    }

    public function test_write_failure_rolls_back_without_constructing_or_clearing_cache(): void
    {
        $calls = [];
        $result = $this->callPrivate('runEditCallbacks',
            static function () use (&$calls): void { $calls[] = 'write'; throw new \RuntimeException('write failed'); },
            static function () use (&$calls): void { $calls[] = 'commit'; },
            static function () use (&$calls): void { $calls[] = 'rollback'; },
            static function () use (&$calls): void { $calls[] = 'cache'; }
        );

        self::assertSame(['write', 'rollback'], $calls);
        self::assertFalse($result['success']);
        self::assertFalse($result['committed']);
    }

    public function test_commit_failure_rolls_back_without_constructing_or_clearing_cache(): void
    {
        $calls = [];
        $result = $this->callPrivate('runEditCallbacks',
            static function () use (&$calls): void { $calls[] = 'write'; },
            static function () use (&$calls): void { $calls[] = 'commit'; throw new \RuntimeException('commit failed'); },
            static function () use (&$calls): void { $calls[] = 'rollback'; },
            static function () use (&$calls): void { $calls[] = 'cache'; }
        );

        self::assertSame(['write', 'commit', 'rollback'], $calls);
        self::assertFalse($result['success']);
        self::assertFalse($result['committed']);
    }

    public function test_cache_cleanup_occurs_after_commit_and_failure_does_not_change_business_success(): void
    {
        $calls = [];
        $success = $this->callPrivate('runEditCallbacks',
            static function () use (&$calls): void { $calls[] = 'write'; },
            static function () use (&$calls): void { $calls[] = 'commit'; },
            static function () use (&$calls): void { $calls[] = 'rollback'; },
            static function () use (&$calls): void { $calls[] = 'cache'; }
        );
        self::assertSame(['write', 'commit', 'cache'], $calls);
        self::assertTrue($success['success']);
        self::assertTrue($success['committed']);

        $calls = [];
        $cacheFailure = $this->callPrivate('runEditCallbacks',
            static function () use (&$calls): void { $calls[] = 'write'; },
            static function () use (&$calls): void { $calls[] = 'commit'; },
            static function () use (&$calls): void { $calls[] = 'rollback'; },
            static function () use (&$calls): void { $calls[] = 'cache'; throw new \RuntimeException('cache failed'); }
        );
        self::assertSame(['write', 'commit', 'cache'], $calls);
        self::assertTrue($cacheFailure['success']);
        self::assertTrue($cacheFailure['committed']);
        self::assertSame('', $cacheFailure['message']);
    }

    public function test_delete_path_uses_the_same_atomic_write_commit_cache_sequence(): void
    {
        $source = file_get_contents('app/tenantapi/logic/auth/RoleLogic.php');
        self::assertNotFalse($source);
        $delete = self::methodBody($source, 'delete');

        self::assertStringContainsString('Db::startTrans()', $delete);
        self::assertStringContainsString('self::runEditCallbacks(', $delete);
        self::assertStringContainsString("->where('tenant_id', \$tenantId)", $delete);
        self::assertStringContainsString('->lock(true)', $delete);
        self::assertStringContainsString('$role->delete()', $delete);
        self::assertStringContainsString("TenantSystemRoleMenu::where('role_id', \$id)->delete()", $delete);
        self::assertLessThan(
            strpos($delete, 'TenantSessionAuthorityService::clearTenantAuthorizationCache($tenantId)'),
            strpos($delete, 'Db::commit()'),
            'delete cache invalidation must be in the post-commit callback'
        );
    }

    private function callPrivate(string $method, mixed ...$arguments): mixed
    {
        $reflection = new \ReflectionMethod(RoleLogic::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invoke(null, ...$arguments);
    }

    private static function methodBody(string $source, string $method): string
    {
        $start = strpos($source, 'function ' . $method);
        self::assertNotFalse($start);
        $open = strpos($source, '{', $start);
        self::assertNotFalse($open);
        $depth = 0;
        for ($index = $open, $length = strlen($source); $index < $length; $index++) {
            if ($source[$index] === '{') {
                $depth++;
            } elseif ($source[$index] === '}' && --$depth === 0) {
                return substr($source, $open, $index - $open + 1);
            }
        }
        self::fail('RoleLogic::edit is not closed.');
    }
}
