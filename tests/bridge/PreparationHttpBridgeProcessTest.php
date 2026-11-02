<?php

declare(strict_types=1);

namespace tests\bridge;

use PHPUnit\Framework\TestCase;
use tests\support\PreparationHttpBridgeProcess;

require_once dirname(__DIR__) . '/support/PreparationHttpBridgeProcess.php';

/** 仅验证子进程协议；不启动 HTTP、不加载数据库 bootstrap、不调用前端。 */
final class PreparationHttpBridgeProcessTest extends TestCase
{
    /** @dataProvider invalidDriverTargets */
    public function test_driver_rejects_non_allowlisted_or_repeated_targets_before_bootstrap(array $arguments): void
    {
        $driver = dirname(__DIR__) . '/fixtures/preparation_http_bridge.php';
        $process = proc_open(array_merge([PHP_BINARY, $driver], $arguments),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
            dirname(__DIR__, 2), null, ['bypass_shell' => true]);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]); $diagnostic = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(2, proc_close($process));
        self::assertSame('', $output);
        self::assertSame("bridge_invalid_arguments\n", $diagnostic);
    }

    public static function invalidDriverTargets(): array
    {
        return [
            'unknown' => [['--target=receivable', '--execute']],
            'path' => [['--target=../payable', '--execute']],
            'absolute-path' => [['--target=E:/arbitrary.mjs', '--execute']],
            'duplicate' => [['--target=customer_refund', '--target=customer_refund', '--execute']],
            'different-targets' => [['--target=payable', '--target=customer_refund', '--execute']],
            'duplicate-advance' => [['--target=advance', '--target=advance', '--execute']],
            'advance-customer-targets' => [['--target=advance', '--target=customer_refund', '--execute']],
            'payable-advance-targets' => [['--target=payable', '--target=advance', '--execute']],
            'duplicate-supplier-refund' => [['--target=supplier_refund', '--target=supplier_refund', '--execute']],
            'supplier-refund-advance-targets' => [['--target=supplier_refund', '--target=advance', '--execute']],
            'supplier-refund-external-path' => [['--target=supplier_refund/../../arbitrary.mjs', '--execute']],
            'supplier-refund-custom-checks' => [['--target=supplier_refund', '--check-ids=arbitrary', '--preflight']],
            'duplicate-salary' => [['--target=salary', '--target=salary', '--execute']],
            'salary-supplier-refund-targets' => [['--target=salary', '--target=supplier_refund', '--execute']],
            'salary-external-path' => [['--target=salary/../../arbitrary.mjs', '--execute']],
            'salary-custom-manifest' => [['--target=salary', '--manifest=arbitrary.json', '--preflight']],
            'duplicate-reimbursement' => [['--target=reimbursement', '--target=reimbursement', '--execute']],
            'reimbursement-salary-targets' => [['--target=reimbursement', '--target=salary', '--execute']],
            'reimbursement-external-path' => [['--target=reimbursement/../../arbitrary.mjs', '--execute']],
            'reimbursement-custom-checks' => [['--target=reimbursement', '--check-ids=arbitrary', '--preflight']],
            'duplicate-expense-payable' => [['--target=expense_payable', '--target=expense_payable', '--execute']],
            'expense-payable-reimbursement-targets' => [['--target=expense_payable', '--target=reimbursement', '--execute']],
            'expense-payable-external-path' => [['--target=expense_payable/../../arbitrary.mjs', '--execute']],
            'expense-payable-custom-manifest' => [['--target=expense_payable', '--manifest=arbitrary.json', '--preflight']],
            'duplicate-deferred' => [['--target=deferred', '--target=deferred', '--execute']],
            'deferred-expense-targets' => [['--target=deferred', '--target=expense_payable', '--execute']],
            'deferred-external-path' => [['--target=deferred/../../arbitrary.mjs', '--execute']],
            'deferred-custom-manifest' => [['--target=deferred', '--manifest=arbitrary.json', '--preflight']],
            'deferred-custom-toolchain' => [['--target=deferred', '--toolchain-path=D:/arbitrary.js', '--preflight']],
            'duplicate-mode' => [['--execute', '--execute', '--target=customer_refund']],
            'conflicting-mode' => [['--preflight', '--execute', '--target=customer_refund']],
            'split-target' => [['--target', 'customer_refund', '--execute']],
        ];
    }

    /** @dataProvider preflightDriverTargets */
    public function test_preflight_resolves_only_the_fixed_client_without_executing_it(array $arguments, string $target): void
    {
        $root = dirname(__DIR__, 2);
        $driver = $root . '/tests/fixtures/preparation_http_bridge.php';
        $process = proc_open(array_merge([PHP_BINARY, $driver], $arguments),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
            $root, null, ['bypass_shell' => true]);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]); $diagnostic = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
        self::assertContains($exit, [0, 1]); self::assertSame('', $diagnostic);
        $result = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('preflight', $result['phase']); self::assertFalse($result['jointExecuted']);
        self::assertArrayNotHasKey('jointExecutionAttempted', $result);
        $clients = ['payable' => 'payable-preparation-20261023/implementation/http-bridge-client.mjs',
            'customer_refund' => 'customer-refund-preparation-20261023/implementation/http-bridge-client.mjs',
            'advance' => 'advance-preparation-20261023/implementation/advance-http-bridge-client.mjs',
            'supplier_refund' => 'supplier-refund-preparation-20261023/implementation/supplier-refund-http-bridge-client.mjs',
            'salary' => 'salary-preparation-20261023/implementation/salary-http-client-20261025.mjs',
            'reimbursement' => 'reimbursement-preparation-20261023/implementation/reimbursement-http-client-20261026.mjs',
            'expense_payable' => 'expense-payable-preparation-20261023/implementation/expense-payable-http-client-20261026.mjs',
            'deferred' => 'deferred-preparation-20261023/implementation/deferred-http-client-20261026.mjs'];
        $client = dirname($root) . '/BeiMi-uniapp/docs/ui-previews/' . $clients[$target];
        self::assertSame(is_file($client) ? hash_file('sha256', $client) : null, $result['sourceFingerprints']['frontend-client']);
        self::assertSame($target, $result['target'] ?? 'payable');
        if ($target === 'advance') {
            self::assertArrayHasKey('expectedCheckIds', $result);
            self::assertCount(15, $result['expectedCheckIds']);
            self::assertCount(15, array_unique($result['expectedCheckIds']));
            self::assertSame(1, preg_match('/export const BRIDGE_CHECK_IDS = Object\\.freeze\\(\\[(.*?)\\]\\);/s', (string)file_get_contents($client), $declaration));
            preg_match_all("/'([^']+)'/", $declaration[1], $declaredIds);
            self::assertSame($declaredIds[1], $result['expectedCheckIds']);
        } elseif ($target === 'deferred') {
            $base = 'docs/ui-previews/deferred-preparation-20261023/implementation/';
            $documents = [
                'sourceHandoff' => ['path' => $base . 'frontend-stage-handoff-20261026.json', 'sha256' => '17aba336739fcbeeeaea72317948f9d9a6ec0f4374f32b124290ab7df9cf28f3'],
                'sourceContract' => ['path' => $base . 'deferred-http-contract-20261026.json', 'sha256' => 'd4041867ade2cef517f1c42a356d24827f9c9ae2538f16d74f70ceaf3f85b7bf'],
                'sourceManifest' => ['path' => $base . 'deferred-http-sources-20261026.json', 'sha256' => '4dacb886bd7d36658170e2f1b922ed8810a11acaaa56693bd2571ca4f010bf13'],
            ];
            $decoded = [];
            foreach ($documents as $key => $document) {
                $path = dirname($root) . '/BeiMi-uniapp/' . $document['path'];
                self::assertSame($document['sha256'], hash_file('sha256', $path));
                self::assertSame($document, $result[$key]);
                self::assertSame($document['sha256'], $result['sourceFingerprints']['BeiMi-uniapp/' . $document['path']]);
                $decoded[$key] = json_decode((string)file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
            }
            $historicalClientSha = '9d76cd8c19fa1a68060ce39233023d24360491e4c90e5ff9764d5b57a029f0dc';
            self::assertNotSame($historicalClientSha, $result['sourceFingerprints']['frontend-client'], '当前客户端已变更，历史 pin 必须阻止执行');
            self::assertCount(32, $result['expectedCheckIds']);
            self::assertCount(32, array_unique($result['expectedCheckIds']));
            self::assertSame(array_column($decoded['sourceContract']['coreChecks'], 'id'), $result['expectedCheckIds']);
            $expectedSources = array_column($decoded['sourceManifest']['sources'], 'sha256', 'path');
            self::assertCount(101, $expectedSources);
            self::assertSame($historicalClientSha, $expectedSources[$base . 'deferred-http-client-20261026.mjs']);
            self::assertSame([], $result['frontendSourceWhitelist'], '历史客户端 pin 不符时不得产生可执行来源白名单');
            self::assertArrayNotHasKey('sourceAdmission', $result);
            $toolchain = array_column($decoded['sourceManifest']['componentToolchainSources'], 'sha256', 'file');
            self::assertSame([
                'D:/HBuilderX/plugins/uniapp-cli/node_modules/process/browser.js',
                'D:/HBuilderX/plugins/uniapp-cli/node_modules/setimmediate/setImmediate.js',
                'D:/HBuilderX/plugins/uniapp-cli/node_modules/timers-browserify/main.js',
                'D:/HBuilderX/plugins/uniapp-cli/node_modules/vue/dist/vue.common.dev.js',
                'D:/HBuilderX/plugins/uniapp-cli/node_modules/vue/dist/vue.common.js',
                'D:/HBuilderX/plugins/uniapp-cli/node_modules/webpack/buildin/global.js',
            ], array_keys($toolchain));
            self::assertSame($toolchain, $result['componentToolchainSources']);
            foreach ($toolchain as $path => $hash) {
                self::assertArrayNotHasKey($path, $result['frontendSourceWhitelist']);
                self::assertSame($hash, hash_file('sha256', $path));
                self::assertSame($hash, $result['sourceFingerprints']['component-toolchain/' . $path]);
            }
            $checks = array_column($result['checks'], 'passed', 'id');
            self::assertFalse($checks['deferred-frozen-sources']);
            self::assertFalse($result['passed']);
            self::assertTrue($checks['deferred-component-toolchain-sources']);
            self::assertArrayNotHasKey('deferredFixtureEvidence', $result);
            self::assertArrayNotHasKey('deferred-fixture-restored', $checks);
        } elseif (in_array($target, ['supplier_refund', 'salary', 'reimbursement', 'expense_payable'], true)) {
            $configuration = [
                'supplier_refund' => ['handoff' => 'supplier-refund-preparation-20261023/implementation/supplier-refund-http-handoff-20261025.json',
                    'sha256' => '2c29c6b2c50b9020ca97455ecb70a1ef60fd3610c0e92da8963c134990dfb640', 'checks' => 18, 'sources' => 80, 'prefix' => 'supplier-refund'],
                'salary' => ['handoff' => 'salary-preparation-20261023/implementation/salary-http-handoff-20261025.json',
                    'sha256' => 'f8007ed3a1b8c77e5843f5b04ede07ab5dde4a1945dd3fe64acfa14f33b2f818', 'checks' => 22, 'sources' => 81, 'prefix' => 'salary'],
                'reimbursement' => ['handoff' => 'reimbursement-preparation-20261023/implementation/reimbursement-http-handoff-20261026.json',
                    'sha256' => 'c8b091dea0d077764caa48b604559c46c4370bce0780c54c862f80e53cdc9f5d', 'checks' => 22, 'sources' => 81, 'prefix' => 'reimbursement'],
                'expense_payable' => ['handoff' => 'expense-payable-preparation-20261023/implementation/expense-payable-http-handoff-20261026.json',
                    'sha256' => '7f45bb56b3e73fb9a7ce93edd4cdaad901aa13bbee10cdd9f1a4dacbac46811a', 'checks' => 30, 'sources' => 81, 'prefix' => 'expense-payable'],
            ][$target];
            $handoff = 'docs/ui-previews/' . $configuration['handoff'];
            $handoffPath = dirname($root) . '/BeiMi-uniapp/' . $handoff;
            $handoffHash = $configuration['sha256'];
            self::assertSame($handoffHash, hash_file('sha256', $handoffPath));
            $manifest = json_decode((string)file_get_contents($handoffPath), true, 64, JSON_THROW_ON_ERROR);
            self::assertCount($configuration['checks'], $result['expectedCheckIds']);
            self::assertCount($configuration['checks'], array_unique($result['expectedCheckIds']));
            self::assertSame($manifest['coreCheckIds'], $result['expectedCheckIds']);
            self::assertSame(['path' => $handoff, 'sha256' => $handoffHash], $result['sourceHandoff']);
            $expectedSources = [];
            foreach (['clientFiles', 'supportFiles', 'sources'] as $group) {
                foreach ($manifest[$group] as $source) { $expectedSources[$source['path']] = $source['sha256']; }
            }
            self::assertCount($configuration['sources'], $expectedSources);
            self::assertSame($expectedSources, $result['frontendSourceWhitelist']);
            self::assertSame($handoffHash, $result['sourceFingerprints']['BeiMi-uniapp/' . $handoff]);
            $allFrozen = true;
            foreach ($expectedSources as $path => $hash) {
                $actual = hash_file('sha256', dirname($root) . '/BeiMi-uniapp/' . $path);
                self::assertSame($actual, $result['sourceFingerprints']['BeiMi-uniapp/' . $path]);
                $allFrozen = $allFrozen && $actual === $hash;
            }
            self::assertSame($allFrozen, array_column($result['checks'], 'passed', 'id')[$configuration['prefix'] . '-frozen-sources']);
            if ($target === 'reimbursement') {
                self::assertFalse($allFrozen, '历史员工垫付款冻结来源已漂移，旧pin必须阻止执行');
                self::assertFalse($result['passed']);
                self::assertSame('efd3138862be7cbd00d048eac75f4c4305c3d94ea96d21ffbbf3b566e549cfa2', $manifest['sourceDigest']);
            }
            if ($target === 'expense_payable') {
                self::assertFalse($allFrozen, '历史费用待付款冻结来源已漂移，旧pin必须阻止执行');
                self::assertFalse($result['passed']);
                self::assertSame('38dce78dd689392e78eba114ea05d6b855855850e63b7388eb67d4314943306a', $manifest['sourceDigest']);
                self::assertArrayNotHasKey('expensePayableFixtureEvidence', $result);
                self::assertArrayNotHasKey('expense-payable-fixture-restored', array_column($result['checks'], 'passed', 'id'));
            }
            if (in_array($target, ['salary', 'reimbursement'], true)) {
                self::assertArrayNotHasKey($target . 'FixtureEvidence', $result);
                self::assertArrayNotHasKey($target . '-fixture-restored', array_column($result['checks'], 'passed', 'id'));
            }
            if (!$allFrozen) { self::assertFalse($result['passed']); }
        } else {
            self::assertArrayNotHasKey('expectedCheckIds', $result);
            self::assertArrayNotHasKey('frontendSourceWhitelist', $result);
        }
    }

    public static function preflightDriverTargets(): array
    {
        return ['default-payable' => [[], 'payable'],
            'explicit-payable' => [['--preflight', '--target=payable'], 'payable'],
            'customer-refund' => [['--target=customer_refund', '--preflight'], 'customer_refund'],
            'advance-target-first' => [['--target=advance', '--preflight'], 'advance'],
            'advance-mode-first' => [['--preflight', '--target=advance'], 'advance'],
            'supplier-refund-target-first' => [['--target=supplier_refund', '--preflight'], 'supplier_refund'],
            'supplier-refund-mode-first' => [['--preflight', '--target=supplier_refund'], 'supplier_refund'],
            'salary-target-first' => [['--target=salary', '--preflight'], 'salary'],
            'salary-mode-first' => [['--preflight', '--target=salary'], 'salary'],
            'reimbursement-target-first' => [['--target=reimbursement', '--preflight'], 'reimbursement'],
            'reimbursement-mode-first' => [['--preflight', '--target=reimbursement'], 'reimbursement'],
            'expense-payable-target-first' => [['--target=expense_payable', '--preflight'], 'expense_payable'],
            'expense-payable-mode-first' => [['--preflight', '--target=expense_payable'], 'expense_payable'],
            'deferred-target-first' => [['--target=deferred', '--preflight'], 'deferred'],
            'deferred-mode-first' => [['--preflight', '--target=deferred'], 'deferred']];
    }

    /** @dataProvider expectedCheckSets */
    public function test_expected_check_set_requires_every_unique_id_and_no_other_id(array $checks, bool $expected): void
    {
        self::assertSame($expected, PreparationHttpBridgeProcess::hasExactCheckIds($checks, ['first', 'second']));
    }

    public static function expectedCheckSets(): array
    {
        $first = ['id' => 'first', 'passed' => true]; $second = ['id' => 'second', 'passed' => true];
        return ['missing' => [[$first], false],
            'extra' => [[$first, $second, ['id' => 'third', 'passed' => true]], false],
            'duplicate' => [[$first, $first], false],
            'complete' => [[$first, $second], true],
            'complete-reordered' => [[$second, $first], true]];
    }

    public function test_context_goes_only_through_stdin_and_returns_allowlisted_result(): void
    {
        $result = PreparationHttpBridgeProcess::run($this->child('valid'), $this->context());
        self::assertSame(['protocol' => 'beimi-preparation-http-bridge/v1', 'runId' => 'self-check',
            'passed' => true, 'checks' => [['id' => 'stdin-context', 'passed' => true]], 'requestCount' => 1], $result);
    }

    /** @dataProvider rejectedReplies */
    public function test_untrusted_child_output_never_becomes_evidence(string $mode, string $reason): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($reason);
        PreparationHttpBridgeProcess::run($this->child($mode), $this->context());
    }

    public static function rejectedReplies(): array
    {
        return ['extra-field' => ['extra', 'bridge_client_invalid_result'],
            'wrong-run' => ['wrong-run', 'bridge_client_invalid_result'],
            'contradictory-check' => ['contradictory', 'bridge_client_invalid_result'],
            'unsafe-check-id' => ['unsafe-id', 'bridge_client_invalid_result'],
            'duplicate-check' => ['duplicate', 'bridge_client_invalid_result'],
            'extra-json' => ['extra-json', 'bridge_client_invalid_result'],
            'token-in-output' => ['leak', 'bridge_client_sensitive_output'],
            'token-in-diagnostics' => ['stderr-leak', 'bridge_client_sensitive_output'],
            'too-much-output' => ['overflow', 'bridge_client_output_limit'],
            'exit-disagrees' => ['wrong-exit', 'bridge_client_exit_mismatch']];
    }

    public function test_failed_checks_require_nonzero_exit_and_remain_sanitized(): void
    {
        $result = PreparationHttpBridgeProcess::run($this->child('failed'), $this->context());
        self::assertFalse($result['passed']);
        self::assertSame([['id' => 'stdin-context', 'passed' => false]], $result['checks']);
    }

    /** @dataProvider encodedSensitiveReplies */
    public function test_unicode_escaped_token_cannot_be_returned_in_a_check_id(string $mode): void
    {
        try {
            PreparationHttpBridgeProcess::run($this->child($mode), $this->context());
        } catch (\RuntimeException $error) {
            self::assertTrue($error->getMessage() === 'bridge_client_sensitive_output', '敏感输出必须只返回固定错误编号');
            return;
        }
        self::fail('未拒绝 JSON 解码后的敏感输出');
    }

    public static function encodedSensitiveReplies(): array
    {
        return ['whole-unicode-token' => ['unicode-whole'], 'partial-unicode-token' => ['unicode-partial'],
            'token-embedded-in-legal-id' => ['unicode-embedded'], 'peer-unicode-token' => ['unicode-peer']];
    }

    public function test_equivalent_unicode_escaping_preserves_a_non_sensitive_check_id(): void
    {
        $result = PreparationHttpBridgeProcess::run($this->child('unicode-safe'), $this->context());
        self::assertSame([['id' => 'stdin-context', 'passed' => true]], $result['checks']);
        self::assertTrue($result['passed']);
    }

    public function test_timeout_stops_only_the_owned_child(): void
    {
        $start = microtime(true);
        try {
            PreparationHttpBridgeProcess::run($this->child('timeout'), $this->context(), 100);
            self::fail('未终止超时子进程');
        } catch (\RuntimeException $error) {
            self::assertSame('bridge_client_timeout', $error->getMessage());
        }
        self::assertLessThan(3, microtime(true) - $start);
    }

    private function context(): array
    {
        return ['protocol' => 'beimi-preparation-http-bridge/v1', 'runId' => 'self-check',
            'baseUrl' => 'http://127.0.0.1:1/api/', 'cutoffDate' => '2026-10-16',
            'actors' => ['owner' => ['actorType' => 'user', 'actorId' => 1, 'tenantId' => 2,
                'token' => str_repeat('a1', 16)],
                'peer' => ['actorType' => 'user', 'actorId' => 3, 'tenantId' => 2, 'token' => str_repeat('b2', 16)]]];
    }

    private function child(string $mode): array
    {
        // PHP 子进程只是协议探针，不模拟 HTTP 或任何业务判断。
        $script = <<<'PHP'
$input = json_decode(stream_get_contents(STDIN), true);
$mode = $argv[1];
if ($mode === 'timeout') { usleep(5000000); exit(0); }
$valid = $input['actors']['owner']['token'] === str_repeat('a1', 16) && count($argv) === 2;
$result = ['protocol' => $input['protocol'], 'runId' => $input['runId'], 'passed' => $valid,
    'checks' => [['id' => 'stdin-context', 'passed' => $valid]], 'requestCount' => 1];
if ($mode === 'extra') { $result['rawBody'] = 'never-accepted'; }
if ($mode === 'wrong-run') { $result['runId'] = 'another-run'; }
if ($mode === 'contradictory') { $result['checks'][0]['passed'] = false; }
if ($mode === 'unsafe-id') { $result['checks'][0]['id'] = 'private body text'; }
if ($mode === 'duplicate') { $result['checks'][] = $result['checks'][0]; }
if ($mode === 'leak') { $result['checks'][0]['id'] = $input['actors']['owner']['token']; }
if (in_array($mode, ['unicode-whole', 'unicode-partial', 'unicode-embedded', 'unicode-peer'], true)) {
    $token = $input['actors'][$mode === 'unicode-peer' ? 'peer' : 'owner']['token'];
    $result['checks'][0]['id'] = $mode === 'unicode-embedded' ? 'check-' . $token . '-result' : $token;
}
if ($mode === 'stderr-leak') { fwrite(STDERR, $input['actors']['owner']['token']); }
if ($mode === 'overflow') { echo str_repeat('x', 70000); exit(0); }
if ($mode === 'failed') { $result['passed'] = false; $result['checks'][0]['passed'] = false; }
$json = json_encode($result);
if (isset($token)) {
    $escaped = in_array($mode, ['unicode-whole', 'unicode-peer'], true)
        ? implode('', array_map(static fn($char) => sprintf('\\u%04x', ord($char)), str_split($token)))
        : sprintf('\\u%04x', ord($token[0])) . substr($token, 1);
    $json = str_replace($token, $escaped, $json);
}
if ($mode === 'unicode-safe') { $json = str_replace('stdin-context', 'stdin-\\u0063ontext', $json); }
echo $json;
if ($mode === 'extra-json') { echo '{}'; }
exit(in_array($mode, ['failed', 'wrong-exit'], true) ? 1 : 0);
PHP;
        return [PHP_BINARY, '-r', $script, $mode];
    }
}
