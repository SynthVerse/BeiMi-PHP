<?php

declare(strict_types=1);

namespace tests\unit;

use app\common\cache\TenantAdminAuthCache;
use PHPUnit\Framework\TestCase;

final class TenantAuthTenantScopeContractTest extends TestCase
{
    public function test_menu_all_entry_point_passes_the_authenticated_tenant_to_the_query(): void
    {
        $controller = self::source('app/tenantapi/controller/auth/MenuController.php');
        $logic = self::source('app/tenantapi/logic/auth/MenuLogic.php');

        self::assertStringContainsString('MenuLogic::getAllData($this->tenantId)', $controller);
        self::assertStringContainsString('function getAllData(int $tenantId): array', $logic);
        self::assertStringContainsString("->where('tenant_id', \$tenantId)", self::methodBody($logic, 'getAllData'));
    }

    public function test_all_auth_requires_and_queries_a_tenant(): void
    {
        $source = self::source('app/tenantapi/logic/auth/AuthLogic.php');
        $method = self::methodBody($source, 'getAllAuth');

        self::assertStringContainsString('function getAllAuth(int $tenantId): array', $source);
        self::assertMatchesRegularExpression('#if \(\$tenantId <= 0\) \{\s*return \[\];\s*\}#', $method);
        self::assertStringContainsString("->where('tenant_id', \$tenantId)", $method);
    }

    public function test_cache_keys_tags_and_middleware_are_tenant_scoped_and_fail_closed(): void
    {
        $cache = self::source('app/common/cache/TenantAdminAuthCache.php');
        $middleware = self::source('app/tenantapi/http/middleware/AuthMiddleware.php');
        $login = self::source('app/tenantapi/http/middleware/LoginMiddleware.php');
        $constructor = self::methodBody($cache, '__construct');

        self::assertStringContainsString('function __construct($adminId = \'\', ?int $tenantId = null)', $cache);
        self::assertStringContainsString("\$this->tagName = static::class . ':' . \$this->tenantId", $constructor);
        self::assertStringContainsString("\$this->prefix . \$this->tenantId . '_url_' . \$this->adminId", $constructor);
        self::assertMatchesRegularExpression('#if \(\$this->tenantId <= 0\) \{\s*return;\s*\}#', $constructor);
        self::assertMatchesRegularExpression(
            '#function deleteTag\(\): bool\s*\{\s*if \(\$this->tenantId <= 0\) \{\s*return false;\s*\}#',
            $cache
        );
        self::assertStringContainsString('new TenantAdminAuthCache($adminId, $tenantId)', $middleware);
        self::assertStringContainsString('if ($tenantId <= 0 || $adminId <= 0)', $middleware);
        self::assertStringContainsString('TenantSessionAuthorityService::resolve((string)$token)', $login);

        $reflection = new \ReflectionMethod(TenantAdminAuthCache::class, '__construct');
        self::assertCount(2, $reflection->getParameters());
        self::assertTrue($reflection->getParameters()[0]->isOptional());
    }

    private static function source(string $path): string
    {
        $source = file_get_contents($path);
        self::assertNotFalse($source);
        return $source;
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

        self::fail('Method is not closed.');
    }
}
