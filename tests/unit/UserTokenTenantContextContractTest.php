<?php

declare(strict_types=1);

namespace tests\unit;

use PHPUnit\Framework\TestCase;

final class UserTokenTenantContextContractTest extends TestCase
{
    public function testUserTokenJxcContextRequiresCurrentMembership(): void
    {
        $source = self::source('app/api/http/middleware/LoginMiddleware.php');

        self::assertStringContainsString('use app\\common\\service\\jxc\\StoreMembershipService;', $source);
        self::assertSame(2, substr_count($source, 'StoreMembershipService::requireCurrentMembership'));
        self::assertStringContainsString("if (\$candidateTenantId > 0 && StoreMembershipService::requireCurrentMembership((int)\$request->userId, \$candidateTenantId))", $source);
        self::assertStringContainsString("if (\$candidateTenantId <= 0 || !StoreMembershipService::requireCurrentMembership((int)\$request->userId, \$candidateTenantId))", $source);
        self::assertStringContainsString("return JsonService::fail('登录超时，请重新登录', [], -1, 0);", $source);
        self::assertStringContainsString("\$isOnboarding = \$mode === 'enforce-onboarding';", $source);
        self::assertStringContainsString('if (!$isOnboarding) {', $source);
        self::assertStringContainsString('} else {', $source);
        self::assertStringContainsString("'tenant_id'   => \$tenantId,", $source);
        self::assertStringContainsString("\$request->jxcFromUserToken = true;", $source);
        self::assertStringNotContainsString("'tenant_id'   => \$userInfo['tenant_id'] ?? 0,", $source);
    }

    public function testJxcControllersDoNotReauthenticateOrRehydrateTenantContext(): void
    {
        $baseController = self::source('app/api/jxc/controller/BaseJxcController.php');
        $authController = self::source('app/api/jxc/controller/AuthController.php');

        self::assertStringNotContainsString("'tenant_id'   => \$this->userInfo['tenant_id'] ?? 0,", $baseController);
        self::assertStringNotContainsString('UserTokenCache', $authController);
        self::assertStringContainsString("return \$this->fail('登录超时，请重新登录', [], -1, 0);", $authController);
    }

    private static function source(string $relativePath): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $relativePath);
        self::assertNotFalse($source);

        return $source;
    }
}
