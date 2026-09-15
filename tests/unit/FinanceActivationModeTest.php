<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\FinanceOpeningService;
use PHPUnit\Framework\TestCase;
use think\facade\Config;

final class FinanceActivationModeTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::set(['activation_mode' => 'all', 'activation_tenant_ids' => []], 'finance');
    }

    public function test_all_mode_allows_existing_and_future_tenants_without_an_allowlist_entry(): void
    {
        Config::set(['activation_mode' => 'all', 'activation_tenant_ids' => []], 'finance');

        self::assertTrue(FinanceOpeningService::isActivationAvailableForTenant(1));
        self::assertTrue(FinanceOpeningService::isActivationAvailableForTenant(999999));
    }

    public function test_allowlist_mode_remains_available_for_gradual_rollout(): void
    {
        Config::set(['activation_mode' => 'allowlist', 'activation_tenant_ids' => [19]], 'finance');

        self::assertTrue(FinanceOpeningService::isActivationAvailableForTenant(19));
        self::assertFalse(FinanceOpeningService::isActivationAvailableForTenant(20));
    }
}
