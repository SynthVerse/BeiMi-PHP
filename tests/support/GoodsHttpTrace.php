<?php

declare(strict_types=1);

namespace tests\support;

use Throwable;

/** 商品接口隔离联验的脱敏记录。 */
final class GoodsHttpTrace
{
    private const ROUTES = ['/api/goods/index', '/api/goods/detail'];
    private const ENTRIES = ['purchase-arrival', 'customer-report', 'direct-probe'];
    private const QUERY_FIELDS = [
        'id', 'sku_id', 'warehouse_id', 'main_customer_id', 'supplier_id', 'category_id', 'unit_id',
        'page_type', 'page_no', 'page_size', 'pagesize', 'pageSize',
    ];

    private int $started;
    private array $exception = [];
    private string $stage = 'bootstrap';

    private function __construct(private string $path, private array $record)
    {
        $this->started = hrtime(true);
    }

    public static function start(string $route, string $method, string $entry, array $query, string $path): ?self
    {
        if ($path === '' || $method !== 'GET' || !in_array($route, self::ROUTES, true)) {
            return null;
        }

        $safeQuery = [];
        foreach (self::QUERY_FIELDS as $field) {
            if (array_key_exists($field, $query)) {
                $value = $query[$field];
                $safeQuery[$field] = is_string($value) && preg_match('/^\d{1,16}$/D', $value) === 1 ? $value : 'invalid';
            }
        }

        return new self($path, [
            'request_id' => bin2hex(random_bytes(12)),
            'time_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'route' => $route,
            'method' => $method,
            'entry' => in_array($entry, self::ENTRIES, true) ? $entry : 'unspecified',
            'query' => $safeQuery,
        ]);
    }

    public function id(): string
    {
        return $this->record['request_id'];
    }

    public function enterApplication(): void
    {
        $this->stage = 'application';
    }

    public function sendingResponse(): void
    {
        if ($this->exception === []) {
            $this->stage = 'response';
        }
    }

    public function capture(Throwable $error): void
    {
        if ($this->exception !== []) {
            return;
        }

        $type = get_class($error);
        $this->exception = [
            'type' => str_contains($type, '@anonymous') ? get_parent_class($error) : $type,
            'file' => basename($error->getFile()),
            'line' => $error->getLine(),
        ];

        if ($this->stage !== 'application') {
            return;
        }

        foreach ($error->getTrace() as $frame) {
            $class = $frame['class'] ?? null;
            $method = $frame['function'] ?? null;
            if ($class === \app\api\jxc\logic\GoodsLogic::class && $method === 'attachSupplierSummary') {
                $this->stage = 'catalog-summary';
                return;
            }
            if ($class === \app\api\jxc\lists\GoodsLists::class) {
                $this->stage = 'catalog-query';
                return;
            }
            if ($class === \app\api\jxc\logic\GoodsLogic::class && $method === 'detail') {
                $this->stage = 'goods-detail';
                return;
            }
        }
    }

    public function captureFatal(?array $error): bool
    {
        if ($error === null || !in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true)) {
            return false;
        }

        if ($this->exception === []) {
            $this->exception = [
                'type' => 'PHP fatal error',
                'file' => basename($error['file']),
                'line' => $error['line'],
            ];
        }
        return true;
    }

    public function finish(int $status, ?int $code = null): void
    {
        $record = $this->record + [
            'status' => $status,
            'code' => $code,
            'elapsed_ms' => round((hrtime(true) - $this->started) / 1_000_000, 3),
            'stage' => $this->stage,
        ];
        if ($status >= 500 && $this->exception !== []) {
            $record['exception'] = $this->exception;
        }
        // 调试文件写入失败时保留接口已经发出的响应。
        @file_put_contents($this->path, json_encode($record, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
    }
}
