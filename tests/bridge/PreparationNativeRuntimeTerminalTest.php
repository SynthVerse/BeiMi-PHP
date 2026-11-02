<?php

declare(strict_types=1);

namespace tests\bridge;

use PHPUnit\Framework\TestCase;
use tests\support\PreparationNativeRuntimeSupervisor;

require_once dirname(__DIR__) . '/support/PreparationNativeRuntimeSupervisor.php';

/** 仅公开终态协议，不启动应用或连接数据库。 */
final class PreparationNativeRuntimeTerminalTest extends TestCase
{
    private function evidence(): array
    {
        $binding = ['runId' => 'preparation-development-20261024-01', 'dispatchId' => 'takeover-20261027-native-lease-03',
            'leaseId' => '33333333-4444-4555-8666-777777777777', 'expiresAt' => '2026-10-26T22:45:00.000Z'];
        $ready = $binding + ['brokerProcessId' => 12345];
        $control = $binding + ['sequence' => 2, 'phase' => 'stop'];
        $terminal = $binding + ['pid' => 12345, 'phase' => 'stop', 'controlSequence' => 2, 'claimed' => true,
            'stopReason' => 'control_stop', 'stopRequestedAt' => '2026-10-26T22:00:00.000Z', 'stoppedAt' => '2026-10-26T22:00:00.000Z',
            'phaseBeforeStop' => 'armed', 'monotonicElapsedMs' => 6000, 'claimExpiresAt' => '2026-10-26T22:02:00.000Z',
            'claimElapsedMs' => 1000, 'statePersisted' => true, 'terminalPersisted' => true,
            'counts' => ['accepted' => 2, 'forwarded' => 1, 'rejected' => 0, 'upstreamFailures' => 0, 'inFlight' => 0,
                'acceptedGET' => 1, 'acceptedPOST' => 1, 'acceptedOther' => 0, 'forwardedGET' => 1, 'forwardedPOST' => 0,
                'rejectedGET' => 0, 'rejectedPOST' => 0, 'rejectedOther' => 0]];
        return [$terminal, $ready, $control];
    }

    public function test_zero_exit_is_insufficient_without_matching_expected_terminal(): void
    {
        [$terminal, $ready, $control] = $this->evidence();
        self::assertTrue(PreparationNativeRuntimeSupervisor::isExpectedTerminalStop($terminal, $ready, $control, false));
        foreach ([[], array_replace($terminal, ['phase' => 'armed']), array_replace($terminal, ['pid' => 999]),
            array_replace($terminal, ['leaseId' => 'old']), array_replace($terminal, ['expiresAt' => '2026-10-26T22:45:00Z']),
            array_replace($terminal, ['statePersisted' => false]), array_replace($terminal, ['terminalPersisted' => false]),
            array_replace($terminal, ['stoppedAt' => '2026-10-26T21:59:59.000Z'])] as $invalid) {
            self::assertFalse(PreparationNativeRuntimeSupervisor::isExpectedTerminalStop($invalid, $ready, $control, false));
        }
        foreach (['state_write_failed', 'control_read_failed', 'control_contract_invalid', 'signal_sigint', 'signal_sigterm',
            'parent_unavailable', 'parent_changed', 'parent_stdin_eof', 'upstream_response_rejected', 'explicit_close'] as $reason) {
            self::assertFalse(PreparationNativeRuntimeSupervisor::isExpectedTerminalStop(array_replace($terminal, ['stopReason' => $reason]), $ready, $control, false));
        }
    }

    public function test_deadlines_and_selfcheck_eof_require_their_actual_conditions(): void
    {
        [$terminal, $ready, $control] = $this->evidence();
        $armed = array_replace($control, ['phase' => 'armed', 'sequence' => 1]);
        $expired = array_replace($terminal, ['stopReason' => 'lease_expired', 'controlSequence' => 1]);
        self::assertFalse(PreparationNativeRuntimeSupervisor::isExpectedTerminalStop($expired, $ready, $armed, false));
        self::assertFalse(PreparationNativeRuntimeSupervisor::isExpectedTerminalStop(array_replace($expired, ['stoppedAt' => $ready['expiresAt']]), $ready, $armed, false));
        self::assertTrue(PreparationNativeRuntimeSupervisor::isExpectedTerminalStop(array_replace($expired, ['stopRequestedAt' => $ready['expiresAt'], 'stoppedAt' => $ready['expiresAt']]), $ready, $armed, false));
        self::assertTrue(PreparationNativeRuntimeSupervisor::isExpectedTerminalStop(array_replace($expired, ['monotonicElapsedMs' => 2700000]), $ready, $armed, false));
        $timeout = array_replace($terminal, ['stopReason' => 'claim_timeout', 'controlSequence' => 1, 'claimed' => false]);
        self::assertFalse(PreparationNativeRuntimeSupervisor::isExpectedTerminalStop($timeout, $ready, $armed, false));
        self::assertFalse(PreparationNativeRuntimeSupervisor::isExpectedTerminalStop(array_replace($timeout, ['stoppedAt' => $timeout['claimExpiresAt']]), $ready, $armed, false));
        self::assertTrue(PreparationNativeRuntimeSupervisor::isExpectedTerminalStop(array_replace($timeout, ['stopRequestedAt' => $timeout['claimExpiresAt'], 'stoppedAt' => $timeout['claimExpiresAt']]), $ready, $armed, false));
        self::assertFalse(PreparationNativeRuntimeSupervisor::isExpectedTerminalStop(array_replace($timeout, ['claimed' => true, 'stoppedAt' => $timeout['claimExpiresAt']]), $ready, $armed, false));
        $prepared = array_replace($control, ['phase' => 'prepared', 'sequence' => 0]);
        $eof = array_replace($terminal, ['stopReason' => 'parent_stdin_eof', 'phaseBeforeStop' => 'prepared', 'claimed' => false, 'controlSequence' => 0]);
        self::assertTrue(PreparationNativeRuntimeSupervisor::isExpectedTerminalStop($eof, $ready, $prepared, true));
        self::assertFalse(PreparationNativeRuntimeSupervisor::isExpectedTerminalStop($eof, $ready, $prepared, false));
    }

    public function test_method_counts_are_strict_integers_and_closed_inflight_is_zero(): void
    {
        [$terminal, $ready, $control] = $this->evidence();
        foreach (['inFlight' => 1, 'forwardedPOST' => 0.0, 'acceptedGET' => true, 'accepted' => -1, 'forwarded' => 99] as $key => $value) {
            $invalid = $terminal; $invalid['counts'][$key] = $value;
            self::assertFalse(PreparationNativeRuntimeSupervisor::isExpectedTerminalStop($invalid, $ready, $control, false));
        }
        $invalid = $terminal; unset($invalid['counts']['forwardedPOST']);
        self::assertFalse(PreparationNativeRuntimeSupervisor::isExpectedTerminalStop($invalid, $ready, $control, false));
    }

    public function test_real_php_node_pipe_remains_live_then_records_parent_eof_without_database(): void
    {
        $root = dirname(__DIR__, 2); $local = realpath($root . '/.local');
        self::assertIsString($local);
        $directory = $local . '/native-runtime-lifecycle-probe-' . bin2hex(random_bytes(8));
        mkdir($directory); $null = fopen(PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w');
        $process = null; $pipes = [];
        try {
            $process = proc_open(['C:/Program Files/nodejs/node.exe', $root . '/tests/fixtures/preparation_native_lifecycle_probe.mjs', $directory],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => $null], $pipes, $root);
            self::assertIsResource($process);
            $actors = [];
            foreach (['owner' => ['user', 992641, 882641], 'peer' => ['user', 992642, 882641],
                'otherStore' => ['user', 992643, 882642], 'admin' => ['tenant_admin', 992641, 882641]] as $name => $identity) {
                $actors[$name] = ['actorType' => $identity[0], 'actorId' => $identity[1], 'tenantId' => $identity[2],
                    'token' => 'inert-lifecycle-no-auth-' . $name];
            }
            $input = json_encode(['protocol' => 'beimi-preparation-native-runtime/v1', 'runId' => 'preparation-development-20261024-01',
                'dispatchId' => 'takeover-20261027-native-lease-03', 'upstreamBaseUrl' => 'http://127.0.0.1:32109/api/', 'actors' => $actors], JSON_THROW_ON_ERROR) . "\n";
            self::assertSame(strlen($input), fwrite($pipes[0], $input)); self::assertTrue(fflush($pipes[0])); unset($input);
            stream_set_timeout($pipes[1], 5);
            $line = fgets($pipes[1]); self::assertIsString($line);
            $ready = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            usleep(100000); self::assertTrue(proc_get_status($process)['running']);
            $stopped = PreparationNativeRuntimeSupervisor::stopBrokerProcess($process, $pipes[0], null, 2000); $process = null;
            self::assertTrue($stopped['cleanExit']); self::assertFalse($stopped['childAlreadyStoppedBeforeStdinClose']);
            self::assertIsString($stopped['stdinClosedByParentAt']);
            $terminal = json_decode((string)file_get_contents($directory . '/terminal.json'), true, 512, JSON_THROW_ON_ERROR);
            $control = json_decode((string)file_get_contents($directory . '/control.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('parent_stdin_eof', $terminal['stopReason']);
            self::assertTrue(PreparationNativeRuntimeSupervisor::isExpectedTerminalStop($terminal, $ready, $control, true));
            self::assertFalse(PreparationNativeRuntimeSupervisor::isExpectedTerminalStop($terminal, $ready, $control, false));
            self::assertSame(0, $terminal['counts']['forwardedPOST']);
        } finally {
            if (is_resource($process)) { PreparationNativeRuntimeSupervisor::stopBrokerProcess($process, $pipes[0] ?? null); }
            foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            fclose($null);
            $absolute = realpath($directory);
            self::assertSame($local, dirname($absolute)); self::assertFalse(is_link($absolute));
            foreach (scandir($absolute) as $name) {
                if ($name === '.' || $name === '..') { continue; }
                $file = $absolute . '/' . $name;
                self::assertTrue(is_file($file) && !is_link($file)); unlink($file);
            }
            rmdir($absolute);
        }
    }
}
