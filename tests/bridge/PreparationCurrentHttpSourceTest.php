<?php

declare(strict_types=1);

namespace tests\bridge;

use PHPUnit\Framework\TestCase;

/** 经公开 CLI 预检验证来源准入；不加载应用、不连接数据库、不启动 HTTP。 */
final class PreparationCurrentHttpSourceTest extends TestCase
{
    public function test_deferred_accepts_current_sources_without_rewriting_historical_handoff(): void
    {
        $manifest = $this->currentManifest();
        $result = $this->preflight($manifest, 'deferred');
        self::assertSame('preflight', $result['phase']);
        self::assertFalse($result['jointExecuted']);
        self::assertArrayNotHasKey('jointExecutionAttempted', $result);
        self::assertTrue($result['passed']);
        $checks = array_column($result['checks'], 'passed', 'id');
        self::assertTrue($checks['deferred-frozen-sources']);
        self::assertTrue($checks['deferred-component-toolchain-sources']);
        self::assertSame($manifest['targets']['deferred']['coreCheckIds'], $result['expectedCheckIds']);
        self::assertCount(32, $result['expectedCheckIds']);
        self::assertCount(135, $result['frontendSourceWhitelist']);
        self::assertSame('current', $result['sourceAdmission']['mode']);
        self::assertSame(0, $result['sourceAdmission']['requestCount']);
        self::assertCount(6, $result['componentToolchainSources']);
        foreach (['tests/bridge/PreparationCurrentHttpSourceTest.php', 'tests/fixtures/preparation_current_http_manifest.json'] as $path) {
            self::assertSame(hash_file('sha256', dirname(__DIR__, 2) . '/' . $path), $result['sourceFingerprints']['BeiMi-PHP/' . $path]);
        }
        foreach ($result['componentToolchainSources'] as $path => $hash) {
            self::assertArrayNotHasKey($path, $result['frontendSourceWhitelist']);
            self::assertSame($hash, $result['sourceFingerprints']['component-toolchain/' . $path]);
        }
    }

    public function test_expense_payable_accepts_current_sources_without_rewriting_historical_handoff(): void
    {
        $manifest = $this->currentManifest();
        $result = $this->preflight($manifest, 'expense_payable');
        self::assertSame('preflight', $result['phase']);
        self::assertFalse($result['jointExecuted']);
        self::assertArrayNotHasKey('jointExecutionAttempted', $result);
        self::assertTrue($result['passed']);
        self::assertTrue(array_column($result['checks'], 'passed', 'id')['expense-payable-frozen-sources']);
        self::assertSame($manifest['targets']['expense_payable']['coreCheckIds'], $result['expectedCheckIds']);
        self::assertCount(30, $result['expectedCheckIds']);
        self::assertCount(135, $result['frontendSourceWhitelist']);
        self::assertSame('current', $result['sourceAdmission']['mode']);
        self::assertSame(0, $result['sourceAdmission']['requestCount']);
    }

    public function test_reimbursement_accepts_current_sources_without_rewriting_historical_handoff(): void
    {
        $manifest = $this->currentManifest();
        $result = $this->preflight($manifest, 'reimbursement');
        self::assertSame('preflight', $result['phase']);
        self::assertFalse($result['jointExecuted']);
        self::assertArrayNotHasKey('jointExecutionAttempted', $result);
        self::assertTrue($result['passed']);
        self::assertTrue(array_column($result['checks'], 'passed', 'id')['reimbursement-frozen-sources']);
        self::assertSame($manifest['targets']['reimbursement']['coreCheckIds'], $result['expectedCheckIds']);
        self::assertCount(22, $result['expectedCheckIds']);
        self::assertCount(135, $result['frontendSourceWhitelist']);
        self::assertSame('current', $result['sourceAdmission']['mode']);
        self::assertSame(0, $result['sourceAdmission']['requestCount']);
    }

    public function test_salary_accepts_current_sources_without_rewriting_historical_handoff(): void
    {
        $manifest = $this->currentManifest();
        $result = $this->preflight($manifest, 'salary');
        self::assertSame('preflight', $result['phase']);
        self::assertFalse($result['jointExecuted']);
        self::assertArrayNotHasKey('jointExecutionAttempted', $result);
        self::assertTrue($result['passed']);
        self::assertTrue(array_column($result['checks'], 'passed', 'id')['salary-frozen-sources']);
        self::assertSame($manifest['targets']['salary']['coreCheckIds'], $result['expectedCheckIds']);
        self::assertCount(22, $result['expectedCheckIds']);
        self::assertCount(135, $result['frontendSourceWhitelist']);
        self::assertSame('current', $result['sourceAdmission']['mode']);
        self::assertSame(0, $result['sourceAdmission']['requestCount']);
    }

    public function test_supplier_refund_accepts_current_sources_without_rewriting_historical_handoff(): void
    {
        $manifest = $this->currentManifest();
        $result = $this->preflight($manifest);
        self::assertSame('preflight', $result['phase']);
        self::assertFalse($result['jointExecuted']);
        self::assertArrayNotHasKey('jointExecutionAttempted', $result);
        self::assertTrue($result['passed']);
        self::assertTrue(array_column($result['checks'], 'passed', 'id')['supplier-refund-frozen-sources']);
        self::assertSame($manifest['targets']['supplier_refund']['coreCheckIds'], $result['expectedCheckIds']);
        self::assertCount(135, $result['frontendSourceWhitelist']);
        self::assertSame('current', $result['sourceAdmission']['mode']);
        self::assertSame(0, $result['sourceAdmission']['requestCount']);
    }

    /** @dataProvider rejectedManifests */
    public function test_authorized_targets_reject_incomplete_or_changed_current_admission(string $mutation, string $target): void
    {
        $manifest = $this->currentManifest(); $sha = null; $omit = [];
        if ($mutation === 'wrong-file-sha') { $sha = str_repeat('0', 64); }
        elseif ($mutation === 'missing-selection-sha') { $omit = ['BEIMI_PREPARATION_HTTP_SOURCE_SHA256']; }
        elseif ($mutation === 'missing-selection-path') { $omit = ['BEIMI_PREPARATION_HTTP_SOURCE_MANIFEST']; }
        elseif ($mutation === 'wrong-generation') { $manifest['generation'] = 'unapproved'; }
        elseif ($mutation === 'missing-source') { array_pop($manifest['sources']); }
        elseif ($mutation === 'changed-source') { $manifest['sources'][0]['sha256'] = str_repeat('0', 64); }
        elseif ($mutation === 'duplicate-source') { $manifest['sources'][1] = $manifest['sources'][0]; }
        elseif ($mutation === 'outside-source') { $manifest['sources'][0]['path'] = '../AGENTS.md'; }
        elseif ($mutation === 'noncanonical-source') { $manifest['sources'][0]['path'] = 'common/../' . $manifest['sources'][0]['path']; }
        elseif ($mutation === 'wrong-target-entry') { $manifest['targets'][$target]['entry'] = $manifest['targets']['advance']['entry']; }
        elseif ($mutation === 'missing-id') { array_pop($manifest['targets'][$target]['coreCheckIds']); }
        elseif ($mutation === 'reordered-ids') {
            [$manifest['targets'][$target]['coreCheckIds'][0], $manifest['targets'][$target]['coreCheckIds'][1]] =
                [$manifest['targets'][$target]['coreCheckIds'][1], $manifest['targets'][$target]['coreCheckIds'][0]];
        }
        elseif ($mutation === 'unrelated-source') {
            foreach ($manifest['sources'] as &$source) {
                if ($source['path'] === 'docs/agents/pitfalls.md') { $source['sha256'] = str_repeat('0', 64); }
            }
            unset($source);
        }
        else { self::fail('未知反例'); }
        $result = $this->preflight($manifest, $target, $sha, $omit);
        self::assertFalse($result['passed']);
        self::assertFalse(array_column($result['checks'], 'passed', 'id')[str_replace('_', '-', $target) . '-frozen-sources']);
        self::assertFalse($result['jointExecuted']);
        self::assertArrayNotHasKey('jointExecutionAttempted', $result);
        self::assertArrayNotHasKey('sourceAdmission', $result);
        self::assertSame([], $result['frontendSourceWhitelist']);
    }

    public static function rejectedManifests(): array
    {
        $cases = [];
        foreach (['supplier_refund', 'salary', 'reimbursement', 'expense_payable', 'deferred'] as $target) {
            foreach ([
            'wrong-file-sha', 'missing-selection-sha', 'missing-selection-path', 'wrong-generation',
            'missing-source', 'changed-source', 'duplicate-source', 'outside-source', 'noncanonical-source',
            'wrong-target-entry', 'missing-id', 'reordered-ids', 'unrelated-source',
            ] as $mutation) { $cases[$target . ':' . $mutation] = [$mutation, $target]; }
        }
        return $cases;
    }

    public function test_absent_current_selection_keeps_supplier_refund_historical_gate(): void
    {
        $result = $this->preflight($this->currentManifest(), 'supplier_refund', null,
            ['BEIMI_PREPARATION_HTTP_SOURCE_MANIFEST', 'BEIMI_PREPARATION_HTTP_SOURCE_SHA256']);
        self::assertFalse(array_column($result['checks'], 'passed', 'id')['supplier-refund-frozen-sources']);
        self::assertArrayNotHasKey('sourceAdmission', $result);
        self::assertCount(80, $result['frontendSourceWhitelist']);
        self::assertSame('2c29c6b2c50b9020ca97455ecb70a1ef60fd3610c0e92da8963c134990dfb640', $result['sourceHandoff']['sha256']);
    }

    public function test_absent_current_selection_keeps_salary_historical_gate(): void
    {
        $result = $this->preflight($this->currentManifest(), 'salary', null,
            ['BEIMI_PREPARATION_HTTP_SOURCE_MANIFEST', 'BEIMI_PREPARATION_HTTP_SOURCE_SHA256']);
        self::assertFalse(array_column($result['checks'], 'passed', 'id')['salary-frozen-sources']);
        self::assertArrayNotHasKey('sourceAdmission', $result);
        self::assertCount(81, $result['frontendSourceWhitelist']);
        self::assertSame('f8007ed3a1b8c77e5843f5b04ede07ab5dde4a1945dd3fe64acfa14f33b2f818', $result['sourceHandoff']['sha256']);
    }

    public function test_absent_current_selection_keeps_reimbursement_historical_gate(): void
    {
        $result = $this->preflight($this->currentManifest(), 'reimbursement', null,
            ['BEIMI_PREPARATION_HTTP_SOURCE_MANIFEST', 'BEIMI_PREPARATION_HTTP_SOURCE_SHA256']);
        self::assertFalse(array_column($result['checks'], 'passed', 'id')['reimbursement-frozen-sources']);
        self::assertArrayNotHasKey('sourceAdmission', $result);
        self::assertCount(81, $result['frontendSourceWhitelist']);
        self::assertSame('c8b091dea0d077764caa48b604559c46c4370bce0780c54c862f80e53cdc9f5d', $result['sourceHandoff']['sha256']);
    }

    public function test_absent_current_selection_keeps_expense_payable_historical_gate(): void
    {
        $result = $this->preflight($this->currentManifest(), 'expense_payable', null,
            ['BEIMI_PREPARATION_HTTP_SOURCE_MANIFEST', 'BEIMI_PREPARATION_HTTP_SOURCE_SHA256']);
        self::assertFalse(array_column($result['checks'], 'passed', 'id')['expense-payable-frozen-sources']);
        self::assertArrayNotHasKey('sourceAdmission', $result);
        self::assertCount(81, $result['frontendSourceWhitelist']);
        self::assertSame('7f45bb56b3e73fb9a7ce93edd4cdaad901aa13bbee10cdd9f1a4dacbac46811a', $result['sourceHandoff']['sha256']);
    }

    public function test_absent_current_selection_keeps_deferred_historical_gate(): void
    {
        $result = $this->preflight($this->currentManifest(), 'deferred', null,
            ['BEIMI_PREPARATION_HTTP_SOURCE_MANIFEST', 'BEIMI_PREPARATION_HTTP_SOURCE_SHA256']);
        $checks = array_column($result['checks'], 'passed', 'id');
        self::assertFalse($result['passed']);
        self::assertFalse($checks['deferred-frozen-sources']);
        self::assertTrue($checks['deferred-component-toolchain-sources']);
        self::assertArrayNotHasKey('sourceAdmission', $result);
        self::assertSame([], $result['frontendSourceWhitelist']);
        self::assertCount(32, $result['expectedCheckIds']);
        self::assertCount(6, $result['componentToolchainSources']);
        self::assertSame('17aba336739fcbeeeaea72317948f9d9a6ec0f4374f32b124290ab7df9cf28f3', $result['sourceHandoff']['sha256']);
    }

    private function currentManifest(): array
    {
        $family = dirname(__DIR__, 3);
        // 仓库内夹具只冻结协议结构/目标/路径，零 SHA 占位不是验收证据。
        $manifest = json_decode((string)file_get_contents(dirname(__DIR__) . '/fixtures/preparation_current_http_manifest.json'), true, 64, JSON_THROW_ON_ERROR);
        foreach ($manifest['sources'] as &$source) {
            $source['sha256'] = hash_file('sha256', $family . '/BeiMi-uniapp/' . $source['path']);
        }
        unset($source);
        return $manifest;
    }

    private function preflight(array $manifest, string $target = 'supplier_refund', ?string $sha = null, array $omit = []): array
    {
        $root = dirname(__DIR__, 2);
        $node = (string)getenv('JXC_PREPARATION_BRIDGE_NODE');
        self::assertFileExists($node, '请通过 JXC_PREPARATION_BRIDGE_NODE 指定已有 Node');
        $file = tempnam(sys_get_temp_dir(), 'beimi-current-source-');
        self::assertNotFalse($file);
        try {
            file_put_contents($file, json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $environment = array_merge(getenv(), ['BEIMI_PREPARATION_HTTP_SOURCE_MANIFEST' => $file,
                'BEIMI_PREPARATION_HTTP_SOURCE_SHA256' => $sha ?? hash_file('sha256', $file)]);
            foreach ($omit as $name) { unset($environment[$name]); }
            $process = proc_open([PHP_BINARY, $root . '/tests/fixtures/preparation_http_bridge.php', '--preflight', '--target=' . $target],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $environment, ['bypass_shell' => true]);
            self::assertIsResource($process);
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]); $diagnostic = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            self::assertContains(proc_close($process), [0, 1]);
            self::assertSame('', $diagnostic);
            return json_decode($output, true, 64, JSON_THROW_ON_ERROR);
        } finally { unlink($file); }
    }
}
