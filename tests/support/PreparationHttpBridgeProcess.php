<?php

declare(strict_types=1);

namespace tests\support;

/** 只负责子进程协议；不理解准备事项，也不生成 HTTP 请求。 */
final class PreparationHttpBridgeProcess
{
    public const PROTOCOL = 'beimi-preparation-http-bridge/v1';

    /** 只核对检查编号集合；业务通过状态仍由原协议解析及客户端结果决定。 */
    public static function hasExactCheckIds(array $checks, array $expectedIds): bool
    {
        if (!array_is_list($checks) || !array_is_list($expectedIds) || !$expectedIds) { return false; }
        $expected = [];
        foreach ($expectedIds as $id) {
            if (!is_string($id) || $id === '' || isset($expected[$id])) { return false; }
            $expected[$id] = true;
        }
        if (count($checks) !== count($expected)) { return false; }
        $seen = [];
        foreach ($checks as $check) {
            $id = is_array($check) ? ($check['id'] ?? null) : null;
            if (!is_string($id) || !isset($expected[$id]) || isset($seen[$id])) { return false; }
            $seen[$id] = true;
        }
        return true;
    }

    public static function run(array $command, array $context, int $timeoutMs = 120000, string $nodeBinary = 'node'): array
    {
        if ($timeoutMs < 1 || $timeoutMs > 180000) { throw new \InvalidArgumentException('bridge_client_timeout_range'); }
        $input = json_encode($context, JSON_THROW_ON_ERROR);
        if (strlen($input) > 16384) { throw new \RuntimeException('bridge_client_context_limit'); }
        // Windows PHP 匿名管道读取会等待数据，不能用其轮询实现超时。
        // Node 只监督传输和自身直接子进程，不含任何前端或业务状态机。
        $supervisor = <<<'JS'
const { spawn } = require('node:child_process');
const command = JSON.parse(process.argv[1]);
const timeout = Number(process.argv[2]);
let input = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', data => { input += data; });
process.stdin.on('end', () => {
  let output = '', diagnostics = '', size = 0, reason = '', sent = false;
  const child = spawn(command[0], command.slice(1), { shell: false, windowsHide: true, stdio: ['pipe', 'pipe', 'pipe'] });
  const timer = setTimeout(() => { reason = 'bridge_client_timeout'; child.kill(); }, timeout);
  function receive(channel, chunk) {
    size += chunk.length;
    if (size > 65536) { reason = 'bridge_client_output_limit'; child.kill(); return; }
    if (channel === 'out') output += chunk.toString('utf8');
    else diagnostics += chunk.toString('utf8');
  }
  function finish(code) {
    if (sent) return;
    sent = true; clearTimeout(timer);
    process.stdout.write(JSON.stringify({ output, diagnostics, exitCode: code, error: reason }));
  }
  child.stdout.on('data', chunk => receive('out', chunk));
  child.stderr.on('data', chunk => receive('err', chunk));
  child.stdin.on('error', () => { reason = 'bridge_client_stdin_failed'; child.kill(); });
  child.on('error', () => { reason = 'bridge_client_unavailable'; finish(-1); });
  child.on('close', code => finish(code ?? -1));
  child.stdin.end(input);
});
JS;
        $process = proc_open([$nodeBinary, '-e', $supervisor, json_encode($command, JSON_THROW_ON_ERROR), (string)$timeoutMs],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
            dirname(__DIR__, 2), null, ['bypass_shell' => true]);
        if (!is_resource($process)) { throw new \RuntimeException('bridge_client_unavailable'); }
        $written = 0;
        try {
            while ($written < strlen($input)) {
                $count = @fwrite($pipes[0], substr($input, $written));
                if ($count === false || $count === 0) { throw new \RuntimeException('bridge_client_stdin_failed'); }
                $written += $count;
            }
            fclose($pipes[0]);
            $envelope = json_decode(stream_get_contents($pipes[1]), true);
            if (!is_array($envelope) || !isset($envelope['output'], $envelope['diagnostics'], $envelope['exitCode'], $envelope['error'])) {
                throw new \RuntimeException('bridge_supervisor_failed');
            }
            if ($envelope['error'] !== '') { throw new \RuntimeException($envelope['error']); }
            $output = $envelope['output']; $diagnostics = $envelope['diagnostics'];
            foreach ($context['actors'] ?? [] as $actor) {
                $token = (string)($actor['token'] ?? '');
                if ($token !== '' && (str_contains($output, $token) || str_contains($diagnostics, $token))) {
                    throw new \RuntimeException('bridge_client_sensitive_output');
                }
            }
            // 原始 stdout/stderr 和输入从不写文件、异常消息或测试断言。
            return self::result($output, $context, $envelope['exitCode']);
        } finally {
            foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            $status = proc_get_status($process);
            if ($status['running']) { proc_terminate($process); }
            proc_close($process);
        }
    }

    private static function result(string $output, array $context, int $exitCode): array
    {
        try { $result = json_decode($output, true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { throw new \RuntimeException('bridge_client_invalid_result'); }
        if (is_array($result)) {
            // JSON 转义还原后再检查所有字符串，不能只信任原始文本匹配。
            array_walk_recursive($result, static function ($value) use ($context): void {
                if (!is_string($value)) { return; }
                foreach ($context['actors'] ?? [] as $actor) {
                    $token = (string)($actor['token'] ?? '');
                    if ($token !== '' && str_contains($value, $token)) {
                        throw new \RuntimeException('bridge_client_sensitive_output');
                    }
                }
            });
        }
        $keys = is_array($result) ? array_keys($result) : [];
        sort($keys);
        if ($keys !== ['checks', 'passed', 'protocol', 'requestCount', 'runId']
            || $result['protocol'] !== self::PROTOCOL || $result['runId'] !== ($context['runId'] ?? null)
            || !is_bool($result['passed']) || !is_int($result['requestCount']) || $result['requestCount'] < 1
            || !is_array($result['checks']) || !array_is_list($result['checks'])
            || count($result['checks']) < 1 || count($result['checks']) > 256) {
            throw new \RuntimeException('bridge_client_invalid_result');
        }
        $ids = []; $allPassed = true;
        foreach ($result['checks'] as $check) {
            $checkKeys = is_array($check) ? array_keys($check) : [];
            sort($checkKeys);
            if ($checkKeys !== ['id', 'passed'] || !is_string($check['id'])
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,95}$/D', $check['id']) !== 1
                || isset($ids[$check['id']]) || !is_bool($check['passed'])) {
                throw new \RuntimeException('bridge_client_invalid_result');
            }
            $ids[$check['id']] = true;
            $allPassed = $allPassed && $check['passed'];
        }
        if ($result['passed'] !== $allPassed) { throw new \RuntimeException('bridge_client_invalid_result'); }
        if ($exitCode < 0 || $result['passed'] !== ($exitCode === 0)) { throw new \RuntimeException('bridge_client_exit_mismatch'); }
        return $result;
    }
}
