<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

/** 请求级查询预算；容量或时间耗尽时由统一查询转为显式部分结果。 */
final class TodoQueryBudget
{
    private const MAX_SECONDS = 8.0;
    private const MAX_CANDIDATE_ROWS = 200000;

    public static function start(): void
    {
        request()->todoQueryBudget = ['deadline' => microtime(true) + self::MAX_SECONDS, 'rows' => 0];
    }

    public static function checkpoint(int $rows = 1): void
    {
        $budget = (array)(request()->todoQueryBudget ?? []);
        $budget['rows'] = (int)($budget['rows'] ?? 0) + max(0, $rows);
        request()->todoQueryBudget = $budget;
        if (microtime(true) > (float)($budget['deadline'] ?? 0)
            || $budget['rows'] > self::MAX_CANDIDATE_ROWS) {
            throw new \RuntimeException('统一待办超过单次查询预算');
        }
    }

    /** @template T @param iterable<T> $rows @return \Generator<int|string,T> */
    public static function candidates(iterable $rows): \Generator
    {
        foreach ($rows as $key => $row) {
            self::checkpoint();
            yield $key => $row;
        }
    }
}
