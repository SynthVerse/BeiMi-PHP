<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

/** 单一待办来源的有界收集器：完整计数，但内存中只保留当前游标后的最前一小段。 */
final class TodoItemBuffer
{
    private const MAX_IDENTITIES = 100000;
    private int $count = 0;
    private int $afterCount = 0;
    /** @var array<string,true> */
    private array $identities = [];
    /** @var array<int,array<string,mixed>> */
    private array $items = [];

    /** @param array<int,string|int>|null $after */
    public function __construct(
        private readonly string $sourceCode,
        private readonly string $branch,
        private readonly int $limit,
        private readonly ?array $after = null,
        private readonly array $filters = [],
        private readonly array $excludedIdentities = [],
    ) {}

    /** @param array<string,mixed> $item */
    public function add(array $item): void
    {
        TodoQueryBudget::checkpoint();
        if (!$this->matchesFilters($item)) { return; }
        $identity = (string)($item['dedupe_key'] ?? $item['key']);
        if (isset($this->excludedIdentities[$identity])) { return; }
        if (isset($this->identities[$identity])) { return; }
        if (count($this->identities) >= self::MAX_IDENTITIES) {
            throw new \RuntimeException('待办来源超过单次精确查询预算');
        }
        $this->identities[$identity] = true;
        $this->count++;
        $item['_identity'] = $identity;
        $item['source_code'] = $this->sourceCode;
        $item['source'] = array_replace((array)($item['source'] ?? []), ['code' => $this->sourceCode]);
        $item['branch'] = $this->branch;
        $item['priority'] = self::priority((string)($item['due_date'] ?? ''));
        $item['_sort'] = self::sortTuple($item);
        if ($this->after !== null && self::compareTuple($item['_sort'], $this->after) <= 0) {
            return;
        }
        $this->afterCount++;
        $this->items[] = $item;
        if (count($this->items) > $this->limit * 2) {
            usort($this->items, [self::class, 'compareItems']);
            $this->items = array_slice($this->items, 0, $this->limit);
        }
    }

    /** @return array{count:int,after_count:int,identities:array<int,string>,items:array<int,array<string,mixed>>} */
    public function finish(): array
    {
        usort($this->items, [self::class, 'compareItems']);
        return ['count' => $this->count, 'after_count' => $this->afterCount, 'identities' => array_keys($this->identities),
            'items' => array_slice($this->items, 0, $this->limit)];
    }

    /** @param array<string,mixed> $item @return array<int,string|int> */
    public static function sortTuple(array $item): array
    {
        $date = (string)($item['due_date'] ?? '');
        if ($date === '') { $date = (string)($item['business_date'] ?? ''); }
        if ($date === '') { $date = '9999-12-31'; }
        return [self::priorityRank((string)($item['priority'] ?? 'normal')), $date,
            (string)($item['business_date'] ?? '9999-12-31'), (string)$item['key']];
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    public static function compareItems(array $left, array $right): int
    {
        return self::compareTuple($left['_sort'], $right['_sort']);
    }

    /** @param array<int,string|int> $left @param array<int,string|int> $right */
    public static function compareTuple(array $left, array $right): int
    {
        for ($index = 0; $index < 4; $index++) {
            $comparison = $left[$index] <=> $right[$index];
            if ($comparison !== 0) { return $comparison; }
        }
        return 0;
    }

    private static function priority(string $dueDate): string
    {
        if ($dueDate === '') { return 'normal'; }
        $today = date('Y-m-d');
        return $dueDate < $today ? 'overdue' : ($dueDate === $today ? 'due_today' : 'normal');
    }

    private static function priorityRank(string $priority): int
    {
        return match ($priority) { 'overdue' => 0, 'due_today' => 1, 'urgent' => 2, default => 3 };
    }

    /** @param array<string,mixed> $item */
    private function matchesFilters(array $item): bool
    {
        $kind = (string)($this->filters['kind'] ?? '');
        if ($kind !== '' && (string)($item['kind'] ?? '') !== $kind) { return false; }
        $from = (string)($this->filters['date_from'] ?? '');
        $to = (string)($this->filters['date_to'] ?? '');
        if ($from === '' && $to === '') { return true; }
        $date = (string)($item['business_date'] ?? '');
        return $date !== '' && ($from === '' || $date >= $from) && ($to === '' || $date <= $to);
    }
}
