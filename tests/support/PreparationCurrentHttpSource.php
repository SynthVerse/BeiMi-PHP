<?php

declare(strict_types=1);

namespace tests\support;

/** 仅已授权的固定准备页测试驱动使用；复用前端来源门，不发送 HTTP 或加载数据库。 */
final class PreparationCurrentHttpSource
{
    public static function requested(): bool
    {
        return getenv('BEIMI_PREPARATION_HTTP_SOURCE_MANIFEST') !== false
            || getenv('BEIMI_PREPARATION_HTTP_SOURCE_SHA256') !== false;
    }

    public static function forTarget(string $target, string $frontendRoot, string $nodeBinary, array $expectedIds): array
    {
        $deny = static function (): never { throw new \RuntimeException('bridge_current_source_invalid'); };
        $remaining = in_array($target, ['equipment', 'equipment_refund', 'transit', 'unclaimed', 'recovery', 'excluded', 'capital', 'loan', 'supplier_advance'], true);
        if (!$remaining && !in_array($target, ['supplier_refund', 'salary', 'reimbursement', 'expense_payable', 'deferred', 'expense_refund'], true)) { $deny(); }
        $manifestPath = (string)getenv('BEIMI_PREPARATION_HTTP_SOURCE_MANIFEST');
        $manifestSha = (string)getenv('BEIMI_PREPARATION_HTTP_SOURCE_SHA256');
        if (!is_file($nodeBinary) || !is_file($manifestPath) || preg_match('/^[a-f0-9]{64}$/D', $manifestSha) !== 1) { $deny(); }
        $bytes = file_get_contents($manifestPath);
        if ($bytes === false || !hash_equals($manifestSha, hash('sha256', $bytes))) { $deny(); }
        $expenseRefund = $target === 'expense_refund';
        $validator = $frontendRoot . ($remaining
            ? '/docs/ui-previews/remaining-preparation-20261102/implementation/remaining-http-source.mjs'
            : ($expenseRefund
            ? '/docs/ui-previews/expense-refund-preparation-20261102/implementation/expense-refund-http-source.mjs'
            : '/docs/ui-previews/payable-preparation-20261023/implementation/current-http-freeze.mjs'));
        // 固定模块执行完整 schema、目标固定项目顺序、规范路径和全部 135 条来源 SHA 校验。
        // 不装载 Page/API，不传入认证；错误原文、文件正文与堆栈不输出。
        $script = <<<'JS'
const timer = setTimeout(() => process.exit(1), 15000);
try {
  const { pathToFileURL } = require('node:url');
  const validator = await import(pathToFileURL(process.argv[1]).href);
  const remaining = ['equipment', 'equipment_refund', 'transit', 'unclaimed', 'recovery', 'excluded', 'capital', 'loan', 'supplier_advance'].includes(process.argv[3]);
  const admission = remaining
    ? await validator.assertRemainingHttpSource(process.argv[3], JSON.parse(process.argv[2]))
    : process.argv[3] === 'expense_refund'
    ? await validator.assertExpenseRefundHttpSource(JSON.parse(process.argv[2]))
    : await validator.assertCurrentHttpFreeze(process.argv[3], JSON.parse(process.argv[2]));
  process.stdout.write(JSON.stringify(admission));
} catch { process.exitCode = 1; }
finally { clearTimeout(timer); }
JS;
        // -e 默认 CommonJS；异步边界由固定包装器提供，不能执行清单里的代码。
        $script = '(async()=>{' . $script . '})()';
        $process = proc_open([$nodeBinary, '-e', $script, $validator, json_encode($expectedIds, JSON_THROW_ON_ERROR), $target],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
            dirname(__DIR__, 2), null, ['bypass_shell' => true]);
        if (!is_resource($process)) { $deny(); }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]); $diagnostic = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process);
        $admission = json_decode($output, true);
        $manifest = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        $sourceCount = $expenseRefund || $remaining ? count($manifest['sources'] ?? []) : 135;
        if ($sourceCount < 1) { $deny(); }
        if ($exit !== 0 || $diagnostic !== '' || !is_array($admission)
            || $admission !== ['generation' => $remaining ? 'remaining-http-current-20261102-01' : ($expenseRefund ? 'expense-refund-http-current-20261102-01' : 'preparation-http-current-20261029-04'),
                'target' => $target, 'sourceCount' => $sourceCount, 'requestCount' => 0]
            || !hash_equals($manifestSha, (string)hash_file('sha256', $manifestPath))) { $deny(); }
        $manifest = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        return ['mode' => 'current', 'manifest' => ['path' => $manifestPath, 'sha256' => $manifestSha],
            'generation' => $admission['generation'], 'requestCount' => 0,
            'sources' => array_column($manifest['sources'], 'sha256', 'path')];
    }
}
