<?php

declare(strict_types=1);

namespace tests\performance;

use app\api\jxc\logic\TodoQueryLogic;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

require_once dirname(__DIR__) . '/unit/CustomerReportTestSupport.php';

/**
 * Explicit A18 benchmark. This directory is intentionally outside phpunit.xml's
 * default unit suite so timing evidence is collected only when requested.
 */
final class UnifiedTodoPerformanceTest extends TestCase
{
    use \tests\unit\CustomerReportTestSupport;

    private static bool $financeSchemaReady = false;
    private int $observedQueries = 0;

    protected function setUp(): void
    {
        $this->prepareCustomerReportRequestContext();
        Db::listen(function (): void {
            $this->observedQueries++;
        });
        $this->ensureCustomerReportTables();
        if (!self::$financeSchemaReady) {
            $this->runStatements($this->authoritativeCreateTable(
                (string)file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260521_000002_add_tenant_relation_and_invite_types.sql'),
                'tenant_relation'
            ));
            $files = array_merge(
                glob(dirname(__DIR__, 2) . '/database/migrations/20260907_*.sql') ?: [],
                glob(dirname(__DIR__, 2) . '/database/migrations/20260908_*.sql') ?: [],
                glob(dirname(__DIR__, 2) . '/database/migrations/20260909_*.sql') ?: [],
                glob(dirname(__DIR__, 2) . '/database/migrations/20260913_*.sql') ?: [],
            );
            sort($files);
            foreach ($files as $file) {
                $this->runStatements($this->prepareMigration((string)file_get_contents($file)));
            }
            self::$financeSchemaReady = true;
        }
        $this->cleanFixtures();
    }

    protected function tearDown(): void
    {
        $this->prepareCustomerReportRequestContext();
        $this->cleanFixtures();
    }

    public function test_a18_candidate_scales_report_repeatable_query_evidence(): void
    {
        $profile = [
            'dataset' => 'synthetic_deidentified_fulfillment_tasks',
            'iterations' => 7,
            'php_version' => PHP_VERSION,
            'database_version' => $this->databaseVersion(),
            'scales' => [],
        ];

        foreach ([21, 101, 5001] as $scale) {
            $this->replaceTasks($scale);
            $first = TodoQueryLogic::lists(['branch' => 'task', 'page_size' => 100]);
            self::assertNotFalse($first, TodoQueryLogic::getError());
            self::assertSame($scale, $first['branches']['task']['count']);
            self::assertCount(min(100, $scale), $first['items']);

            $operations = [
                'summary' => static fn(): array|false => TodoQueryLogic::summary(),
                'first_page' => static fn(): array|false => TodoQueryLogic::lists([
                    'branch' => 'task', 'page_size' => 100,
                ]),
            ];
            if ($scale > 100) {
                self::assertNotNull($first['next_cursor']);
                $cursor = $first['next_cursor'];
                $operations['continuation'] = static fn(): array|false => TodoQueryLogic::lists([
                    'branch' => 'task', 'page_size' => 100, 'cursor' => $cursor,
                ]);
            }

            $scaleEvidence = [];
            foreach ($operations as $name => $operation) {
                $warm = $operation();
                self::assertNotFalse($warm, TodoQueryLogic::getError());
                $scaleEvidence[$name] = $this->measure($operation, 7);
            }
            $profile['scales'][(string)$scale] = $scaleEvidence;
        }

        fwrite(STDOUT, '[A18] ' . json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    /** @return array{p50_ms:float,p95_ms:float,max_ms:float,query_count_p50:int,query_count_max:int} */
    private function measure(callable $operation, int $iterations): array
    {
        $elapsed = [];
        $queries = [];
        for ($index = 0; $index < $iterations; $index++) {
            $this->observedQueries = 0;
            $startedAt = hrtime(true);
            $result = $operation();
            $elapsed[] = (hrtime(true) - $startedAt) / 1_000_000;
            $queries[] = $this->observedQueries;
            self::assertNotFalse($result, TodoQueryLogic::getError());
        }
        sort($elapsed, SORT_NUMERIC);
        sort($queries, SORT_NUMERIC);

        return [
            'p50_ms' => round($this->percentile($elapsed, 0.50), 3),
            'p95_ms' => round($this->percentile($elapsed, 0.95), 3),
            'max_ms' => round((float)end($elapsed), 3),
            'query_count_p50' => (int)$this->percentile($queries, 0.50),
            'query_count_max' => (int)end($queries),
        ];
    }

    /** @param array<int,int|float> $values */
    private function percentile(array $values, float $percentile): float
    {
        $position = max(0, (int)ceil(count($values) * $percentile) - 1);
        return (float)$values[$position];
    }

    private function replaceTasks(int $count): void
    {
        Db::name('fulfillment_task')->where('tenant_id', self::TENANT_ID)->delete();
        for ($start = 1; $start <= $count; $start += 500) {
            $rows = [];
            for ($index = $start; $index < min($start + 500, $count + 1); $index++) {
                $rows[] = [
                    'tenant_id' => self::TENANT_ID,
                    'group_id' => 1,
                    'report_id' => 600000 + $index,
                    'source_key' => 'benchmark:a18:' . $index,
                    'status' => 'unassigned',
                    'task_type' => 'exception',
                    'exception_code' => 'a18_benchmark',
                    'goods_name' => '合成性能样本 ' . $index,
                    'create_time' => time(),
                    'update_time' => time(),
                ];
            }
            Db::name('fulfillment_task')->insertAll($rows);
        }
    }

    private function databaseVersion(): string
    {
        $row = Db::query('SELECT VERSION() AS version');
        return (string)($row[0]['version'] ?? 'unknown');
    }

    private function cleanFixtures(): void
    {
        foreach ([
            'finance_entry', 'finance_source', 'finance_document', 'finance_period', 'finance_opening_book',
            'employee_permission', 'employee', 'tenant_member', 'fulfillment_delivery_event',
            'fulfillment_task', 'customer_report_item', 'customer_report',
        ] as $table) {
            Db::name($table)->whereIn('tenant_id', [self::TENANT_ID, self::OTHER_TENANT_ID])->delete();
        }
    }
}
