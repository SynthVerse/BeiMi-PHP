<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;

/**
 * 统一待办查询深模块。
 *
 * 外部只暴露 summary/lists；来源、权限、去重、排序、部分失败和游标均封装在本模块内。
 */
final class TodoQueryLogic extends BaseLogic
{
    private const CURSOR_VERSION = 3;
    private const MAX_IDENTITIES_PER_REQUEST = 200000;
    private const TASK_SOURCES = ['S01', 'S02', 'S03', 'S04'];

    /** @return array<string,mixed>|false */
    public static function summary(): array|false
    {
        return self::execute(['page_size' => 1], false);
    }

    /** @return array<string,mixed>|false */
    public static function lists(array $params): array|false
    {
        return self::execute($params, true);
    }

    /** @return array<string,mixed>|false */
    private static function execute(array $params, bool $withItems): array|false
    {
        self::clearError();
        if (FinanceAccess::tenant() <= 0 || FinanceAccess::operator() <= 0) {
            self::setError('请先登录并选择门店');
            return false;
        }
        $branch = (string)($params['branch'] ?? 'all');
        if (!in_array($branch, ['all', 'task', 'finance'], true)) {
            self::setError('待办分支参数无效');
            return false;
        }
        $pageSize = $withItems ? max(1, min(100, (int)($params['page_size'] ?? 20))) : 1;
        $filters = self::filters($params);
        if ($filters === false) { return false; }
        $filterVersion = hash('sha256', json_encode($filters, JSON_UNESCAPED_SLASHES));
        $scope = TodoPermissionScope::snapshot();
        TodoPermissionScope::activate($scope);
        $scopeVersion = TodoPermissionScope::version($scope);
        $cursor = self::decodeCursor((string)($params['cursor'] ?? ''), $scopeVersion, $branch, $filterVersion);
        if ($cursor === false) { return false; }
        $positions = $cursor['positions'] ?? [];

        $coverage = TodoSourceRegistry::coverage();
        TodoQueryBudget::start();
        $results = []; $unavailable = []; $branchState = [
            'task' => ['identities' => [], 'complete' => true],
            'finance' => ['identities' => [], 'complete' => true],
        ];
        $knownIdentityCount = 0;
        $requestBudgetExhausted = false;
        foreach ($coverage as $source => $definition) {
            if ($source === 'S23') { continue; }
            $sourceBranch = in_array($source, self::TASK_SOURCES, true) ? 'task' : 'finance';
            if ($knownIdentityCount >= self::MAX_IDENTITIES_PER_REQUEST) { $requestBudgetExhausted = true; }
            if ($requestBudgetExhausted) {
                $branchState[$sourceBranch]['complete'] = false;
                $unavailable[] = ['source' => $source, 'title' => $definition['title'], 'reason' => '统一查询预算已用尽'];
                continue;
            }
            try {
                $includedInPage = $branch === 'all' || $branch === $sourceBranch;
                $after = $includedInPage && isset($positions[$source]) && is_array($positions[$source]) ? $positions[$source] : null;
                $excludedIdentities = $source === 'S21'
                    ? $branchState['task']['identities'] + $branchState['finance']['identities'] : [];
                $result = $sourceBranch === 'task'
                    ? TodoTaskSourceProvider::query($source, $includedInPage ? $pageSize + 1 : 1, $after, $filters)
                    : TodoFinanceSourceProvider::query($source, $includedInPage ? $pageSize + 1 : 1, $after, $filters, $excludedIdentities);
                if ($knownIdentityCount + count($result['identities']) > self::MAX_IDENTITIES_PER_REQUEST) {
                    throw new \RuntimeException('统一待办超过单次精确查询预算');
                }
                foreach ($result['identities'] as $identity) {
                    $known = isset($branchState['task']['identities'][$identity])
                        || isset($branchState['finance']['identities'][$identity]);
                    $branchState[$sourceBranch]['identities'][$identity] = true;
                    if (!$known) { $knownIdentityCount++; }
                }
                $result['identities'] = [];
                $results[$source] = $result;
            } catch (\Throwable $exception) {
                $branchState[$sourceBranch]['complete'] = false;
                $unavailable[] = ['source' => $source, 'title' => $definition['title'], 'reason' => '该业务来源暂时不可用'];
                if (str_contains($exception->getMessage(), '查询预算')) { $requestBudgetExhausted = true; }
                error_log('[unified-todo] ' . $source . ': ' . $exception->getMessage());
            }
        }

        $candidates = []; $candidateGroups = [];
        foreach ($results as $source => $result) {
            $sourceBranch = in_array($source, self::TASK_SOURCES, true) ? 'task' : 'finance';
            if ($branch !== 'all' && $branch !== $sourceBranch) { continue; }
            foreach ($result['items'] as $item) {
                if (!isset($candidates[$item['_identity']])) { $candidates[$item['_identity']] = $item; }
                $candidateGroups[$item['_identity']][] = $item;
            }
        }
        $candidates = array_values($candidates);
        usort($candidates, [TodoItemBuffer::class, 'compareItems']);
        $items = array_slice($candidates, 0, $withItems ? $pageSize : 0);
        $nextPositions = $positions; $consumedBySource = [];
        foreach ($items as $item) {
            foreach ($candidateGroups[$item['_identity']] as $equivalent) {
                $nextPositions[$equivalent['source_code']] = $equivalent['_sort'];
                $consumedBySource[$equivalent['source_code']] = ($consumedBySource[$equivalent['source_code']] ?? 0) + 1;
            }
        }

        $hasMore = count($candidates) > count($items);
        foreach ($results as $source => $result) {
            $sourceBranch = in_array($source, self::TASK_SOURCES, true) ? 'task' : 'finance';
            if ($branch !== 'all' && $branch !== $sourceBranch) { continue; }
            if ($result['after_count'] > ($consumedBySource[$source] ?? 0)) { $hasMore = true; }
        }
        $complete = $unavailable === [];
        $knownIdentities = $branchState['task']['identities'] + $branchState['finance']['identities'];
        $knownCount = count($knownIdentities);
        $branches = [];
        foreach (['task', 'finance'] as $name) {
            $state = $branchState[$name];
            $branchKnownCount = count($state['identities']);
            $branches[$name] = ['count' => $state['complete'] ? $branchKnownCount : null,
                'known_count' => $branchKnownCount, 'complete' => $state['complete'],
                'entry_visible' => $name !== 'task' || (bool)$scope['owner'] || in_array('task.view', (array)$scope['permissions'], true)];
        }
        $response = [
            'tenant_id' => $scope['tenant_id'],
            'operator_scope' => ['operator_id' => $scope['operator_id'], 'identity' => $scope['identity'], 'owner' => $scope['owner']],
            'as_of' => date('Y-m-d H:i:s'),
            'scope_version' => $scopeVersion,
            'total_count' => $complete ? $knownCount : null,
            'known_count' => $knownCount,
            'branches' => $branches,
            'complete' => $complete,
            'unavailable_sources' => $unavailable,
        ];
        if ($withItems) {
            foreach ($items as &$item) { unset($item['_sort'], $item['_identity'], $item['dedupe_key'], $item['source_code']); } unset($item);
            $response += ['items' => $items, 'has_more' => $hasMore,
                'next_cursor' => $hasMore ? self::encodeCursor($scopeVersion, $branch, $filterVersion, $nextPositions) : null];
        }
        return $response;
    }

    /** @return array<string,mixed>|false */
    private static function decodeCursor(string $cursor, string $scopeVersion, string $branch, string $filterVersion): array|false
    {
        if ($cursor === '') { return ['positions' => []]; }
        $padding = (4 - strlen($cursor) % 4) % 4;
        $decoded = base64_decode(strtr($cursor . str_repeat('=', $padding), '-_', '+/'), true);
        $payload = $decoded === false ? null : json_decode($decoded, true);
        if (!is_array($payload) || ($payload['v'] ?? null) !== self::CURSOR_VERSION
            || !hash_equals($scopeVersion, (string)($payload['scope'] ?? ''))
            || !hash_equals($filterVersion, (string)($payload['filter'] ?? ''))
            || ($payload['branch'] ?? null) !== $branch || !is_array($payload['positions'] ?? null)) {
            self::setError('待办列表上下文已变化，请刷新后重试');
            return false;
        }
        foreach ($payload['positions'] as $source => $tuple) {
            if (!isset(TodoSourceRegistry::coverage()[$source]) || $source === 'S23' || !is_array($tuple)
                || count($tuple) !== 4 || !is_int($tuple[0])
                || !is_string($tuple[1]) || !is_string($tuple[2]) || !is_string($tuple[3])) {
                self::setError('待办列表上下文已变化，请刷新后重试');
                return false;
            }
        }
        return $payload;
    }

    /** @param array<string,array<int,string|int>> $positions */
    private static function encodeCursor(string $scopeVersion, string $branch, string $filterVersion, array $positions): string
    {
        $json = json_encode(['v' => self::CURSOR_VERSION, 'scope' => $scopeVersion,
            'branch' => $branch, 'filter' => $filterVersion, 'positions' => $positions], JSON_UNESCAPED_SLASHES);
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /** @return array{kind:string,date_from:string,date_to:string}|false */
    private static function filters(array $params): array|false
    {
        $filters = ['kind' => trim((string)($params['kind'] ?? '')),
            'date_from' => trim((string)($params['date_from'] ?? '')),
            'date_to' => trim((string)($params['date_to'] ?? ''))];
        if (($filters['kind'] !== '' && !preg_match('/^[a-z][a-z0-9_]{0,63}$/', $filters['kind']))
            || !self::validDate($filters['date_from']) || !self::validDate($filters['date_to'])
            || ($filters['date_from'] !== '' && $filters['date_to'] !== '' && $filters['date_from'] > $filters['date_to'])) {
            self::setError('待办筛选参数无效');
            return false;
        }
        return $filters;
    }

    private static function validDate(string $value): bool
    {
        if ($value === '') { return true; }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
