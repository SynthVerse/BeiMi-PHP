<?php

declare(strict_types=1);

namespace tests\support;

final class PreparationNativeRuntimeSupervisor
{
    private const METHOD_COUNTS = ['accepted', 'forwarded', 'rejected', 'upstreamFailures', 'inFlight',
        'acceptedGET', 'acceptedPOST', 'acceptedOther', 'forwardedGET', 'forwardedPOST', 'rejectedGET', 'rejectedPOST', 'rejectedOther'];
    private const STOP_REASONS = ['explicit_close', 'control_stop', 'lease_expired', 'claim_timeout', 'control_read_failed',
        'control_json_invalid', 'control_contract_invalid', 'control_transition_invalid', 'state_write_failed',
        'parent_stdin_eof', 'parent_stdin_closed', 'parent_stdin_error', 'parent_stdin_extra_input', 'parent_unavailable',
        'parent_changed', 'signal_sigterm', 'signal_sigint', 'upstream_response_rejected', 'initialization_failed'];

    public static function run(array $context, array $checks, array $peerBefore, bool $checkOnly, array $runtimeScope, array $privacyPaths): void
    {
        $root = dirname(__DIR__, 2); $directory = $root . '/.local/final-runtime-backend-20261026/service-attempt-03';
        $node = 'C:/Program Files/nodejs/node.exe';
        $null = fopen(PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w');
        $process = proc_open([$node, $root . '/tests/fixtures/preparation_native_runtime_broker.mjs'],
            [0 => ['pipe', 'r'], 1 => $null, 2 => $null], $pipes, $root);
        if (!is_resource($process)) { fclose($null); throw new \RuntimeException('native_runtime_broker_start_failed'); }
        $published = false; $started = microtime(true); $lastHeartbeat = 0; $exitCode = null; $ready = null;
        try {
            $credentials = array_column($context['actors'], 'token');
            $input = json_encode($context, JSON_THROW_ON_ERROR) . "\n";
            if (fwrite($pipes[0], $input) !== strlen($input) || !fflush($pipes[0])) { throw new \RuntimeException('native_runtime_stdin_failed'); }
            unset($input, $context); // 实际DB token不进入状态文件、命令、环境或输出。
            while (true) {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $exitCode = $status['exitcode'] >= 0 ? (int)$status['exitcode'] : null;
                    if (!$published || ($status['exitcode'] ?? -1) !== 0) { throw new \RuntimeException('native_runtime_broker_exited'); }
                    break;
                }
                $state = json_decode((string)@file_get_contents($directory . '/state.json'), true);
                if (!$published && is_array($state) && ($state['pid'] ?? null) === $status['pid'] && isset($state['baseUrl'])
                    && ($state['phase'] ?? '') === 'prepared' && ($state['claimed'] ?? null) === false) {
                    $base = $state['baseUrl'];
                    if (!is_string($base) || preg_match('~^http://127\.0\.0\.1:([1-9][0-9]{0,4})/api/$~D', $base, $match) !== 1
                        || (int)$match[1] > 65535) { throw new \RuntimeException('native_runtime_broker_address_failed'); }
                    $body = @file_get_contents(substr($base, 0, -4) . '__runtime/health', false,
                        stream_context_create(['http' => ['timeout' => 5, 'follow_location' => 0, 'ignore_errors' => true]]));
                    if ($body === false || !is_array(json_decode($body, true)) || !str_contains($http_response_header[0] ?? '', '200')) {
                        throw new \RuntimeException('native_runtime_health_failed');
                    }
                    $filesChecked = PreparationNativeRuntimePrivacy::assertNoCredentialFiles($privacyPaths, $credentials);
                    unset($credentials);
                    $ready = ['protocol' => 'beimi-preparation-native-runtime/v1', 'runId' => PreparationNativeRuntimeGate::RUN,
                        'dispatchId' => PreparationNativeRuntimeGate::DISPATCH, 'ready' => true, 'phase' => 'prepared',
                        'readyAt' => gmdate('c'), 'baseUrl' => $base, 'leaseId' => $state['leaseId'], 'expiresAt' => $state['expiresAt'],
                        'parentProcessId' => getmypid(), 'brokerProcessId' => $status['pid'], 'scopeLockHeld' => true,
                        'claimEnabled' => false, 'businessWritesReleased' => false, 'checkOnly' => $checkOnly,
                        'readOnlyChecks' => array_merge($checks, [['id' => 'broker-health-no-credentials', 'passed' => true]]),
                        'peerBefore' => $peerBefore, 'runtimeScope' => $runtimeScope, 'tenantIds' => [882641, 882642], 'userIds' => [992641, 992642, 992643],
                        'adminId' => 992641, 'adminPageLoginSupported' => false,
                        'cutoffDate' => '2026-10-16', 'activationDate' => '2026-10-17',
                        'credentialTransport' => 'DB会话仅PHP→Node stdin与后端内存；一次claim返回另行生成的短期代理能力凭据，只准进入前端内存适配器',
                        'nativeBusinessOperationsExecuted' => false, 'privacyCheck' => ['passed' => true, 'runtimeFilesChecked' => $filesChecked,
                            'sqlLoggingDisabled' => true, 'cacheDriver' => 'request-memory-only']];
                    \tests\unit\PreparationHttpWorkflowTest::writeNativeRuntimeJson($directory . ($checkOnly ? '/selfcheck-ready.json' : '/service-ready.json'), $ready);
                    echo json_encode(['kind' => 'ready', 'baseUrl' => $base, 'leaseId' => $ready['leaseId'], 'expiresAt' => $ready['expiresAt'],
                        'claimEnabled' => false, 'businessWritesReleased' => false, 'checkOnly' => $checkOnly], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
                    fflush(STDOUT); $published = true;
                    if ($checkOnly) { break; }
                }
                if (microtime(true) - $started > ($published ? 2730 : 60)) { throw new \RuntimeException('native_runtime_supervisor_timeout'); }
                if (microtime(true) - $lastHeartbeat >= 2) {
                    \tests\unit\PreparationHttpWorkflowTest::writeNativeRuntimeJson($directory . '/fixture-state.json', [
                        'runId' => PreparationNativeRuntimeGate::RUN, 'dispatchId' => PreparationNativeRuntimeGate::DISPATCH,
                        'heartbeatAt' => gmdate('c'), 'parentProcessId' => getmypid(), 'brokerProcessId' => $status['pid'],
                        'ready' => $published, 'scopeLockHeld' => true, 'checkOnly' => $checkOnly]);
                    $lastHeartbeat = microtime(true);
                }
                usleep(100000);
            }
        } finally {
            // EOF要求broker先关入口/在途请求；父fixture随后才停止PHP并清理DB。
            $stop = self::stopBrokerProcess($process, $pipes[0], $exitCode);
            fclose($null);
            $terminalBytes = @file_get_contents($directory . '/terminal.json', false, null, 0, 65537);
            $terminal = is_string($terminalBytes) && strlen($terminalBytes) <= 65536 ? json_decode($terminalBytes, true) : null;
            $control = json_decode((string)@file_get_contents($directory . '/control.json'), true);
            $expected = $published && is_array($ready) && is_array($terminal) && is_array($control)
                && self::isExpectedTerminalStop($terminal, $ready, $control, $checkOnly);
            $reason = is_array($terminal) && in_array($terminal['stopReason'] ?? null, self::STOP_REASONS, true)
                ? $terminal['stopReason'] : 'invalid_or_missing';
            $methodCounts = [];
            foreach (self::METHOD_COUNTS as $key) {
                $value = is_array($terminal) && is_array($terminal['counts'] ?? null) ? ($terminal['counts'][$key] ?? null) : null;
                $methodCounts[$key] = is_int($value) && $value >= 0 ? $value : null;
            }
            \tests\unit\PreparationHttpWorkflowTest::writeNativeRuntimeJson($directory . '/broker-stop.json', $stop + [
                'runId' => PreparationNativeRuntimeGate::RUN, 'dispatchId' => PreparationNativeRuntimeGate::DISPATCH, 'checkOnly' => $checkOnly,
                'terminalRecordReceived' => is_array($terminal), 'terminalExpectedStop' => $expected, 'terminalReason' => $reason,
                'terminalCounts' => $methodCounts, 'terminalRecordTooLarge' => is_string($terminalBytes) && strlen($terminalBytes) > 65536,
                'terminalSha256' => is_string($terminalBytes) && strlen($terminalBytes) <= 65536 ? hash('sha256', $terminalBytes) : null]);
            if (!$stop['cleanExit']) { throw new \RuntimeException('native_runtime_broker_shutdown_failed'); }
            if ($published && !$expected) { throw new \RuntimeException('native_runtime_unexpected_or_unverified_stop'); }
        }
    }

    /** 零退出码只是进程结果；必须有本lease的完整、预期终态，不能回退为旧心跳。 */
    public static function isExpectedTerminalStop(array $terminal, array $ready, array $control, bool $checkOnly): bool
    {
        foreach (['runId', 'dispatchId', 'leaseId', 'expiresAt'] as $key) {
            if (!is_string($ready[$key] ?? null) || ($terminal[$key] ?? null) !== $ready[$key] || ($control[$key] ?? null) !== $ready[$key]) { return false; }
        }
        if (count($control) !== 6 || ($terminal['phase'] ?? null) !== 'stop' || ($terminal['pid'] ?? null) !== ($ready['brokerProcessId'] ?? null)
            || !is_int($terminal['pid'] ?? null) || !is_bool($terminal['claimed'] ?? null)
            || ($terminal['statePersisted'] ?? null) !== true || ($terminal['terminalPersisted'] ?? null) !== true
            || !is_int($terminal['controlSequence'] ?? null) || $terminal['controlSequence'] < 0
            || !is_int($control['sequence'] ?? null) || $control['sequence'] < $terminal['controlSequence']
            || !in_array($control['phase'] ?? null, ['prepared', 'armed', 'released', 'stop'], true)
            || !in_array($terminal['phaseBeforeStop'] ?? null, ['prepared', 'armed', 'released'], true)
            || !is_int($terminal['monotonicElapsedMs'] ?? null) || $terminal['monotonicElapsedMs'] < 0) { return false; }
        $counts = $terminal['counts'] ?? null;
        if (!is_array($counts) || count($counts) !== count(self::METHOD_COUNTS)) { return false; }
        foreach (self::METHOD_COUNTS as $key) { if (!is_int($counts[$key] ?? null) || $counts[$key] < 0) { return false; } }
        if ($counts['inFlight'] !== 0 || $counts['acceptedGET'] + $counts['acceptedPOST'] + $counts['acceptedOther'] !== $counts['accepted']
            || $counts['forwardedGET'] + $counts['forwardedPOST'] !== $counts['forwarded']
            || $counts['rejectedGET'] + $counts['rejectedPOST'] + $counts['rejectedOther'] !== $counts['rejected']
            || $counts['forwarded'] + $counts['rejected'] > $counts['accepted']) { return false; }
        $stoppedAt = self::terminalTime($terminal['stoppedAt'] ?? null);
        $requestedAt = self::terminalTime($terminal['stopRequestedAt'] ?? null);
        $expiresAt = self::terminalTime($ready['expiresAt']);
        if ($stoppedAt === null || $requestedAt === null || $expiresAt === null || $stoppedAt < $requestedAt) { return false; }
        return match ($terminal['stopReason'] ?? null) {
            'control_stop' => $control['phase'] === 'stop' && $control['sequence'] === $terminal['controlSequence'],
            'lease_expired' => $requestedAt >= $expiresAt || $terminal['monotonicElapsedMs'] >= 2700000,
            'claim_timeout' => $terminal['claimed'] === false && $terminal['phaseBeforeStop'] === 'armed'
                && self::terminalTime($terminal['claimExpiresAt'] ?? null) !== null
                && ($requestedAt >= self::terminalTime($terminal['claimExpiresAt'])
                    || (is_int($terminal['claimElapsedMs'] ?? null) && $terminal['claimElapsedMs'] >= 120000)),
            'parent_stdin_eof' => $checkOnly && $terminal['claimed'] === false && $terminal['phaseBeforeStop'] === 'prepared'
                && $control['phase'] === 'prepared' && $control['sequence'] === 0 && $terminal['controlSequence'] === 0,
            default => false,
        };
    }

    private static function terminalTime(mixed $value): ?float
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D', $value) !== 1) { return null; }
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.v\Z', $value, new \DateTimeZone('UTC'));
        return $time !== false && $time->format('Y-m-d\TH:i:s.v\Z') === $value ? (float)$time->format('U.u') : null;
    }

    public static function stopBrokerProcess($process, $stdin, ?int $knownExitCode = null, int $waitMs = 3000): array
    {
        $beforeClose = proc_get_status($process);
        if (!$beforeClose['running'] && $beforeClose['exitcode'] >= 0) { $knownExitCode = (int)$beforeClose['exitcode']; }
        $stdinClosedByParentAt = gmdate('c');
        if (is_resource($stdin)) { fclose($stdin); }
        $deadline = microtime(true) + $waitMs / 1000; $running = true;
        do {
            $status = proc_get_status($process); $running = (bool)$status['running'];
            if (!$running) { if ($status['exitcode'] >= 0) { $knownExitCode = (int)$status['exitcode']; } break; }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $forced = $running;
        if ($forced) {
            proc_terminate($process);
            for ($i = 0; $i < 100; $i++) {
                $status = proc_get_status($process); $running = (bool)$status['running'];
                if (!$running) { if ($status['exitcode'] >= 0) { $knownExitCode = (int)$status['exitcode']; } break; }
                usleep(10000);
            }
        }
        $closed = proc_close($process);
        if ($knownExitCode === null && $closed >= 0) { $knownExitCode = $closed; }
        return ['exitCode' => $knownExitCode, 'forcedStop' => $forced, 'confirmedStopped' => !$running,
            'stdinClosedByParentAt' => $stdinClosedByParentAt, 'childAlreadyStoppedBeforeStdinClose' => !$beforeClose['running'],
            'cleanExit' => !$forced && !$running && $knownExitCode === 0];
    }
}
