<?php

declare(strict_types=1);

namespace tests\bridge;

use PHPUnit\Framework\TestCase;
use tests\support\PreparationMemoryCache;
use tests\support\PreparationNativeRuntimeGate;

require_once dirname(__DIR__) . '/support/PreparationMemoryCache.php';
require_once dirname(__DIR__) . '/support/PreparationNativeRuntimeGate.php';
require_once dirname(__DIR__) . '/support/PreparationNativeRuntimeSupervisor.php';
if (is_file(dirname(__DIR__) . '/support/PreparationNativeRuntimePrivacy.php')) { require_once dirname(__DIR__) . '/support/PreparationNativeRuntimePrivacy.php'; }

final class PreparationNativeRuntimeSafetyTest extends TestCase
{
    public function test_auth_cache_remains_in_memory_and_supports_expiry_and_tags(): void
    {
        $cache = new PreparationMemoryCache(); $cache->clear();
        $secret = bin2hex(random_bytes(32));
        self::assertTrue($cache->tag('native-session')->set('session', ['token' => $secret], 60));
        self::assertTrue(hash_equals($secret, (string)$cache->get('session')['token']));
        self::assertTrue((new PreparationMemoryCache())->has('session'));
        $cache->tag('native-session')->clear();
        self::assertFalse($cache->has('session'));
        $cache->set('expired', 'value', new \DateTimeImmutable('-1 second'));
        self::assertFalse($cache->has('expired'));
        self::assertSame(1, $cache->inc('counter')); self::assertSame(0, $cache->dec('counter'));
        self::assertTrue($cache->clear());
    }

    public function test_direct_php_ingress_is_read_only_until_current_lease_is_claimed_and_released(): void
    {
        [$state, $control, $now] = $this->lease();
        $get = ['REMOTE_ADDR' => '127.0.0.1', 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/user/store/status'];
        $post = array_replace($get, ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/finance/opening/item/save']);
        self::assertTrue(PreparationNativeRuntimeGate::allows($get, $state, $control, $now));
        self::assertFalse(PreparationNativeRuntimeGate::allows($post, $state, $control, $now));
        $state['phase'] = $control['phase'] = 'released';
        self::assertFalse(PreparationNativeRuntimeGate::allows($post, $state, $control, $now));
        $state['claimed'] = true;
        self::assertTrue(PreparationNativeRuntimeGate::allows($post, $state, $control, $now));
        self::assertFalse(PreparationNativeRuntimeGate::allows(array_replace($post, ['REQUEST_METHOD' => 'DELETE']), $state, $control, $now));
        self::assertFalse(PreparationNativeRuntimeGate::allows(array_replace($post, ['REQUEST_URI' => '/api/tenant/reset']), $state, $control, $now));
        self::assertFalse(PreparationNativeRuntimeGate::allows(array_replace($get, ['REMOTE_ADDR' => '10.0.0.2']), $state, $control, $now));
    }

    public function test_missing_stale_expired_or_mismatched_runtime_control_fails_closed(): void
    {
        [$state, $control, $now] = $this->lease();
        $get = ['REMOTE_ADDR' => '127.0.0.1', 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/finance/opening/guide'];
        foreach ([[], array_replace($state, ['heartbeatAt' => gmdate('c', $now - 11)]),
            array_replace($state, ['expiresAt' => gmdate('c', $now - 1)]),
            array_replace($state, ['phase' => 'stopped']), array_replace($state, ['leaseId' => 'other'])] as $invalid) {
            self::assertFalse(PreparationNativeRuntimeGate::allows($get, $invalid, $control, $now));
        }
        self::assertFalse(PreparationNativeRuntimeGate::allows($get, $state, [], $now));
        self::assertFalse(PreparationNativeRuntimeGate::allows($get, $state, array_replace($control, ['sequence' => 2]), $now));
        $oldDispatch = ['dispatchId' => 'takeover-20261027-native-lease-02'];
        self::assertFalse(PreparationNativeRuntimeGate::allows($get, array_replace($state, $oldDispatch), array_replace($control, $oldDispatch), $now));
    }

    public function test_current_native_master_loading_paths_are_allowed_without_auth_info_echo(): void
    {
        [$state, $control, $now] = $this->lease();
        foreach (['supplier/index', 'supplier/details', 'customer/index', 'customer/detail',
            'jxc/workforce/employees', 'jxc/workforce/employee', 'jxc/workforce/me/permissions', 'jxc/workforce/permissions', 'finance/opening'] as $path) {
            self::assertTrue(PreparationNativeRuntimeGate::allows(['REMOTE_ADDR' => '127.0.0.1', 'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/api/' . $path], $state, $control, $now), '固定原生建档读取路径必须可用');
        }
        self::assertFalse(PreparationNativeRuntimeGate::allows(['REMOTE_ADDR' => '127.0.0.1', 'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/jxc/auth/info'], $state, $control, $now));
    }

    private function lease(): array
    {
        $now = time();
        $shared = ['runId' => 'preparation-development-20261024-01', 'dispatchId' => 'takeover-20261027-native-lease-03',
            'leaseId' => '11111111-2222-4333-8444-555555555555', 'expiresAt' => gmdate('c', $now + 2700), 'phase' => 'prepared'];
        return [$shared + ['heartbeatAt' => gmdate('c', $now), 'controlSequence' => 0, 'claimed' => false], $shared + ['sequence' => 0], $now];
    }

    public function test_child_shutdown_distinguishes_clean_exit_failure_and_forced_stop(): void
    {
        foreach ([0, 7, null] as $exit) {
            $null = fopen(PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w');
            $code = $exit === null ? 'sleep(30);' : 'while (fgets(STDIN) !== false) {} exit(' . $exit . ');';
            $process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => $null, 2 => $null], $pipes);
            self::assertIsResource($process);
            $result = \tests\support\PreparationNativeRuntimeSupervisor::stopBrokerProcess($process, $pipes[0], null, 300);
            fclose($null);
            self::assertSame($exit === 0, $result['cleanExit']);
            self::assertSame($exit === null, $result['forcedStop']);
            self::assertTrue($result['confirmedStopped']);
            if ($exit !== null) { self::assertSame($exit, $result['exitCode']); }
        }
    }

    public function test_runtime_privacy_disables_sql_and_disk_cache_and_detects_an_inert_canary_file(): void
    {
        $existingConnection = \think\facade\Db::name('native_privacy_cache_probe')->getConnection();
        \tests\support\PreparationNativeRuntimePrivacy::install(app());
        self::assertFalse(\think\facade\Db::getConfig('connections.mysql.trigger_sql'));
        foreach (['native-memory', 'file', 'redis'] as $store) {
            self::assertInstanceOf(PreparationMemoryCache::class, app('cache')->store($store));
        }
        self::assertInstanceOf(PreparationMemoryCache::class, \think\facade\Db::name('native_privacy_cache_probe')->getCache());
        self::assertInstanceOf(PreparationMemoryCache::class, $existingConnection->getCache());
        $root = str_replace('\\', '/', dirname(__DIR__, 2)) . '/.local';
        $directory = $root . '/native-privacy-canary-' . bin2hex(random_bytes(6));
        mkdir($directory); $file = $directory . '/inert-canary.log';
        // 本值不参与任何鉴权，只用于文件检测反例；不是会话或capability。
        $canary = 'noncredential-canary-' . bin2hex(random_bytes(12));
        try {
            self::assertSame(0, \tests\support\PreparationNativeRuntimePrivacy::assertNoCredentialFiles([$directory], [$canary]));
            file_put_contents($file, $canary);
            try { \tests\support\PreparationNativeRuntimePrivacy::assertNoCredentialFiles([$directory], [$canary]); self::fail('必须发现惰性标记文件'); }
            catch (\RuntimeException $error) { self::assertSame('native_runtime_credential_file_detected', $error->getMessage()); }
        } finally {
            self::assertTrue(str_starts_with($directory, $root . '/native-privacy-canary-') && !is_link($directory));
            if (is_file($file)) { unlink($file); } rmdir($directory);
        }
    }
}
