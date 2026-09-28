<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\TodoQueryLogic;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

require_once __DIR__ . '/CustomerReportTestSupport.php';

final class UnifiedTodoQueryTest extends TestCase
{
    use CustomerReportTestSupport;
    private static bool $financeSchemaReady = false;

    protected function setUp(): void
    {
        $this->prepareCustomerReportRequestContext();
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
            foreach ($files as $file) { $this->runStatements($this->prepareMigration((string)file_get_contents($file))); }
            self::$financeSchemaReady = true;
        }
        $this->cleanTodoFixtures([self::TENANT_ID, self::OTHER_TENANT_ID]);
    }

    protected function tearDown(): void
    {
        $this->prepareCustomerReportRequestContext();
        $this->cleanTodoFixtures([self::TENANT_ID, self::OTHER_TENANT_ID]);
    }

    public function test_cross_day_items_draft_and_future_balance_boundaries_and_large_cursor_pagination(): void
    {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        Db::name('finance_opening_book')->insert(['tenant_id' => self::TENANT_ID, 'status' => 'active', 'version' => 1,
            'reviews' => '{}', 'submitted_hash' => '', 'confirmed_snapshot' => '{}', 'created_by' => '{}',
            'last_modified_by' => '{}', 'create_time' => time(), 'update_time' => time(), 'confirmed_at' => time()]);
        Db::name('customer_report')->insert(['tenant_id' => self::TENANT_ID, 'sn' => 'TODO-OLD', 'main_customer_id' => 1,
            'main_customer_name' => '跨日客户', 'status' => 'submitted_ready', 'delivery_date' => $yesterday,
            'submitted_time' => strtotime('-2 days'), 'idempotency_key' => 'todo-old', 'request_fingerprint' => hash('sha256', 'todo-old')]);
        $tasks = [];
        for ($index = 1; $index <= 105; $index++) {
            $tasks[] = ['tenant_id' => self::TENANT_ID, 'group_id' => 1, 'report_id' => 900000 + $index,
                'source_key' => 'todo:test:' . $index, 'status' => 'unassigned', 'task_type' => 'exception',
                'exception_code' => 'todo_test', 'goods_name' => '测试任务 ' . $index,
                'create_time' => time(), 'update_time' => time()];
        }
        Db::name('fulfillment_task')->insertAll($tasks);

        Db::name('finance_document')->insert(['tenant_id' => self::TENANT_ID, 'type' => 'expense', 'status' => 'draft',
            'payload' => '{}', 'confirmed_result' => '{}', 'created_by' => '{}', 'last_modified_by' => '{}',
            'confirmed_by' => '{}', 'create_time' => time(), 'update_time' => time()]);
        $pendingDocument = (int)Db::name('finance_document')->insertGetId(['tenant_id' => self::TENANT_ID, 'type' => 'expense', 'status' => 'pending',
            'payload' => json_encode(['subject_name' => '待确认费用', 'actual_date' => $yesterday]), 'confirmed_result' => '{}',
            'created_by' => '{}', 'last_modified_by' => '{}', 'confirmed_by' => '{}', 'create_time' => time(), 'update_time' => time()]);
        Db::name('finance_source')->insert(['tenant_id' => self::TENANT_ID, 'document_id' => $pendingDocument, 'category' => 'receivable',
            'subject_id' => 1, 'amount' => '20.00', 'business_date' => $yesterday, 'due_date' => $yesterday,
            'snapshot' => json_encode(['subject_name' => '到期客户']), 'create_time' => time()]);
        Db::name('finance_source')->insert(['tenant_id' => self::TENANT_ID, 'document_id' => $pendingDocument, 'category' => 'receivable',
            'subject_id' => 2, 'amount' => '30.00', 'business_date' => date('Y-m-d'), 'due_date' => date('Y-m-d', strtotime('+1 day')),
            'snapshot' => json_encode(['subject_name' => '未来客户']), 'create_time' => time()]);

        $first = TodoQueryLogic::lists(['branch' => 'task', 'page_size' => 100]);
        self::assertNotFalse($first, TodoQueryLogic::getError());
        self::assertSame(108, $first['total_count']);
        self::assertSame(106, $first['branches']['task']['count']);
        self::assertSame(2, $first['branches']['finance']['count']);
        self::assertCount(100, $first['items']);
        self::assertTrue($first['has_more']);
        self::assertSame('report:' . Db::name('customer_report')->where('sn', 'TODO-OLD')->value('id'), $first['items'][0]['key']);

        $second = TodoQueryLogic::lists(['branch' => 'task', 'page_size' => 100, 'cursor' => $first['next_cursor']]);
        self::assertNotFalse($second, TodoQueryLogic::getError());
        self::assertCount(6, $second['items']);
        self::assertFalse($second['has_more']);
        self::assertCount(106, array_unique(array_merge(array_column($first['items'], 'key'), array_column($second['items'], 'key'))));

        $finance = TodoQueryLogic::lists(['branch' => 'finance', 'page_size' => 100]);
        self::assertNotFalse($finance, TodoQueryLogic::getError());
        self::assertContains('finance-document:' . $pendingDocument, array_column($finance['items'], 'key'));
        self::assertSame(1, count(array_filter($finance['items'], static fn(array $item): bool => str_starts_with($item['key'], 'receivable-due:'))));
        self::assertNotContains('finance-document:' . ($pendingDocument - 1), array_column($finance['items'], 'key'));
    }

    public function test_cursor_is_rejected_after_tenant_switch(): void
    {
        for ($index = 1; $index <= 2; $index++) {
            Db::name('fulfillment_task')->insert(['tenant_id' => self::TENANT_ID, 'group_id' => 1, 'report_id' => 800000 + $index,
                'source_key' => 'cursor:test:' . $index, 'status' => 'unassigned', 'task_type' => 'exception',
                'exception_code' => 'cursor_test', 'create_time' => time(), 'update_time' => time()]);
        }
        $first = TodoQueryLogic::lists(['branch' => 'task', 'page_size' => 1]);
        self::assertNotFalse($first, TodoQueryLogic::getError());
        self::assertNotNull($first['next_cursor']);

        $this->prepareCustomerReportRequestContext(self::OTHER_TENANT_ID);
        self::assertFalse(TodoQueryLogic::lists(['branch' => 'task', 'page_size' => 1, 'cursor' => $first['next_cursor']]));
        self::assertSame('待办列表上下文已变化，请刷新后重试', TodoQueryLogic::getError());
    }

    public function test_malformed_source_position_in_cursor_is_rejected(): void
    {
        Db::name('fulfillment_task')->insert([
            'tenant_id' => self::TENANT_ID, 'group_id' => 1, 'report_id' => 810001,
            'source_key' => 'cursor:malformed', 'status' => 'unassigned', 'task_type' => 'exception',
            'exception_code' => 'cursor_test', 'create_time' => time(), 'update_time' => time(),
        ]);
        Db::name('fulfillment_task')->insert([
            'tenant_id' => self::TENANT_ID, 'group_id' => 1, 'report_id' => 810002,
            'source_key' => 'cursor:malformed:2', 'status' => 'unassigned', 'task_type' => 'exception',
            'exception_code' => 'cursor_test', 'create_time' => time(), 'update_time' => time(),
        ]);
        $first = TodoQueryLogic::lists(['branch' => 'task', 'page_size' => 1]);
        self::assertNotFalse($first, TodoQueryLogic::getError());
        $cursor = (string)$first['next_cursor'];
        $decoded = base64_decode(strtr($cursor . str_repeat('=', (4 - strlen($cursor) % 4) % 4), '-_', '+/'), true);
        $payload = json_decode((string)$decoded, true);
        $payload['positions'] = ['S03' => [0]];
        $malformed = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');

        self::assertFalse(TodoQueryLogic::lists(['branch' => 'task', 'page_size' => 1, 'cursor' => $malformed]));
        self::assertSame('待办列表上下文已变化，请刷新后重试', TodoQueryLogic::getError());
    }

    public function test_kind_and_business_date_filters_are_exact_and_bound_to_cursor(): void
    {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        Db::name('customer_report')->insert([
            'tenant_id' => self::TENANT_ID, 'sn' => 'TODO-FILTER', 'main_customer_id' => 1,
            'main_customer_name' => '筛选客户', 'status' => 'submitted_ready', 'delivery_date' => $yesterday,
            'submitted_time' => strtotime('-1 day'), 'idempotency_key' => 'todo-filter',
            'request_fingerprint' => hash('sha256', 'todo-filter'),
        ]);
        for ($index = 1; $index <= 2; $index++) {
            Db::name('fulfillment_task')->insert([
                'tenant_id' => self::TENANT_ID, 'group_id' => 1, 'report_id' => 820000 + $index,
                'source_key' => 'filter:test:' . $index, 'status' => 'unassigned', 'task_type' => 'exception',
                'exception_code' => 'filter_test', 'create_time' => time(), 'update_time' => time(),
            ]);
        }

        $reports = TodoQueryLogic::lists(['branch' => 'task', 'kind' => 'customer_report',
            'date_from' => $yesterday, 'date_to' => $yesterday, 'page_size' => 10]);
        self::assertNotFalse($reports, TodoQueryLogic::getError());
        self::assertSame(1, $reports['total_count']);
        self::assertSame(['customer_report'], array_values(array_unique(array_column($reports['items'], 'kind'))));

        $tasks = TodoQueryLogic::lists(['branch' => 'task', 'kind' => 'fulfillment_task', 'page_size' => 1]);
        self::assertNotFalse($tasks, TodoQueryLogic::getError());
        self::assertNotNull($tasks['next_cursor']);
        self::assertFalse(TodoQueryLogic::lists(['branch' => 'task', 'kind' => 'fulfillment_task',
            'date_from' => date('Y-m-d'), 'page_size' => 1, 'cursor' => $tasks['next_cursor']]));
        self::assertSame('待办列表上下文已变化，请刷新后重试', TodoQueryLogic::getError());

        self::assertFalse(TodoQueryLogic::lists(['date_from' => '2026-02-30']));
        self::assertSame('待办筛选参数无效', TodoQueryLogic::getError());
    }

    public function test_source_failure_is_reported_as_partial_instead_of_zero(): void
    {
        Db::execute('RENAME TABLE `la_finance_sales_print` TO `la_finance_sales_print_todo_test_missing`');
        try {
            $summary = TodoQueryLogic::summary();
            self::assertNotFalse($summary, TodoQueryLogic::getError());
            self::assertFalse($summary['complete']);
            self::assertNull($summary['total_count']);
            self::assertFalse($summary['branches']['finance']['complete']);
            self::assertContains('S22', array_column($summary['unavailable_sources'], 'source'));
        } finally {
            Db::execute('RENAME TABLE `la_finance_sales_print_todo_test_missing` TO `la_finance_sales_print`');
        }
    }

    public function test_pending_finance_document_requires_the_actual_confirm_permission_and_changes_scope(): void
    {
        $userId = 993301;
        $employeeId = (int)Db::name('employee')->insertGetId([
            'tenant_id' => self::TENANT_ID, 'name' => '权限测试员工', 'mobile' => '13999330101',
            'bind_user_id' => $userId, 'is_enabled' => 1, 'create_time' => time(), 'update_time' => time(),
        ]);
        Db::name('employee_permission')->insert([
            'tenant_id' => self::TENANT_ID, 'employee_id' => $employeeId,
            'permission_key' => 'finance.expense.prepare', 'create_time' => time(),
        ]);
        $documentId = (int)Db::name('finance_document')->insertGetId([
            'tenant_id' => self::TENANT_ID, 'type' => 'expense', 'status' => 'pending',
            'payload' => json_encode(['subject_name' => '权限边界费用', 'actual_date' => date('Y-m-d')]),
            'confirmed_result' => '{}', 'created_by' => '{}', 'last_modified_by' => '{}', 'confirmed_by' => '{}',
            'create_time' => time(), 'update_time' => time(),
        ]);
        request()->tenantId = self::TENANT_ID;
        request()->adminId = $userId;
        request()->userId = $userId;
        request()->jxcFromUserToken = true;
        request()->adminInfo = ['user_id' => $userId, 'tenant_id' => self::TENANT_ID, 'root' => 0];

        $withoutConfirm = TodoQueryLogic::lists(['branch' => 'finance', 'page_size' => 100]);
        self::assertNotFalse($withoutConfirm, TodoQueryLogic::getError());
        self::assertNotContains('finance-document:' . $documentId, array_column($withoutConfirm['items'], 'key'));

        Db::name('employee_permission')->insert([
            'tenant_id' => self::TENANT_ID, 'employee_id' => $employeeId,
            'permission_key' => 'finance.expense.confirm', 'create_time' => time(),
        ]);
        $withConfirm = TodoQueryLogic::lists(['branch' => 'finance', 'page_size' => 100]);
        self::assertNotFalse($withConfirm, TodoQueryLogic::getError());
        self::assertContains('finance-document:' . $documentId, array_column($withConfirm['items'], 'key'));
        self::assertNotSame($withoutConfirm['scope_version'], $withConfirm['scope_version']);
    }

    public function test_task_exceptions_require_their_actual_action_permissions_and_skip_waiting_tasks(): void
    {
        $userId = 993302;
        $employeeId = (int)Db::name('employee')->insertGetId([
            'tenant_id' => self::TENANT_ID, 'name' => '任务权限员工', 'mobile' => '13999330201',
            'bind_user_id' => $userId, 'is_enabled' => 1, 'create_time' => time(), 'update_time' => time(),
        ]);
        Db::name('employee_permission')->insert([
            'tenant_id' => self::TENANT_ID, 'employee_id' => $employeeId,
            'permission_key' => 'task.control', 'create_time' => time(),
        ]);
        foreach ([
            ['generic_exception', 'exception'],
            ['missing_inventory_shortage_process', 'exception'],
            ['missing_remark', 'exception'],
            ['unrecognized_remark', 'exception'],
            ['generic_blocked', 'blocked'],
        ] as $index => [$code, $status]) {
            Db::name('fulfillment_task')->insert([
                'tenant_id' => self::TENANT_ID, 'group_id' => 1, 'report_id' => 830001 + $index,
                'source_key' => 'permission:test:' . $index, 'status' => $status, 'task_type' => 'exception',
                'exception_code' => $code, 'create_time' => time(), 'update_time' => time(),
            ]);
        }
        request()->tenantId = self::TENANT_ID; request()->adminId = $userId; request()->userId = $userId;
        request()->jxcFromUserToken = true;
        request()->adminInfo = ['user_id' => $userId, 'tenant_id' => self::TENANT_ID, 'root' => 0];

        $limited = TodoQueryLogic::lists(['branch' => 'task', 'page_size' => 100]);
        self::assertNotFalse($limited, TodoQueryLogic::getError());
        self::assertCount(1, $limited['items']);
        self::assertStringContainsString('generic_exception', $limited['items'][0]['reason']);

        Db::name('employee_permission')->insert(['tenant_id' => self::TENANT_ID, 'employee_id' => $employeeId,
            'permission_key' => 'report.remark', 'create_time' => time()]);
        $remarkOnly = TodoQueryLogic::lists(['branch' => 'task', 'page_size' => 100]);
        self::assertNotFalse($remarkOnly, TodoQueryLogic::getError());
        self::assertCount(3, $remarkOnly['items']);
        self::assertStringContainsString('missing_remark', implode('|', array_column($remarkOnly['items'], 'reason')));
        self::assertStringContainsString('unrecognized_remark', implode('|', array_column($remarkOnly['items'], 'reason')));
        self::assertStringNotContainsString('missing_inventory_shortage_process', implode('|', array_column($remarkOnly['items'], 'reason')));

        Db::name('employee_permission')->insert(['tenant_id' => self::TENANT_ID, 'employee_id' => $employeeId,
            'permission_key' => 'process.manage', 'create_time' => time()]);
        $permitted = TodoQueryLogic::lists(['branch' => 'task', 'page_size' => 100]);
        self::assertNotFalse($permitted, TodoQueryLogic::getError());
        self::assertCount(4, $permitted['items']);
        self::assertStringNotContainsString('generic_blocked', implode('|', array_column($permitted['items'], 'reason')));
    }

    public function test_delivery_todo_requires_final_weight_and_disappears_after_completed_event(): void
    {
        $reportId = (int)Db::name('customer_report')->insertGetId([
            'tenant_id' => self::TENANT_ID, 'sn' => 'TODO-DELIVERY', 'main_customer_id' => 1,
            'main_customer_name' => '配送客户', 'status' => 'submitted_ready', 'delivery_date' => date('Y-m-d'),
            'submitted_time' => time(), 'idempotency_key' => 'todo-delivery',
            'request_fingerprint' => hash('sha256', 'todo-delivery'),
        ]);
        $itemId = (int)Db::name('customer_report_item')->insertGetId([
            'tenant_id' => self::TENANT_ID, 'report_id' => $reportId, 'goods_name' => '配送商品',
            'fulfillment_status' => 'pending', 'create_time' => time(), 'update_time' => time(),
        ]);
        $taskId = (int)Db::name('fulfillment_task')->insertGetId([
            'tenant_id' => self::TENANT_ID, 'group_id' => 1, 'report_id' => $reportId,
            'source_key' => 'todo:delivery', 'status' => 'unassigned', 'task_type' => 'delivery',
            'create_time' => time(), 'update_time' => time(),
        ]);

        $notReady = TodoQueryLogic::lists(['branch' => 'task', 'page_size' => 100]);
        self::assertNotFalse($notReady, TodoQueryLogic::getError());
        self::assertNotContains('delivery-task:' . $taskId, array_column($notReady['items'], 'key'));

        Db::name('customer_report_item')->where('id', $itemId)->update([
            'fulfillment_status' => 'final_weight_recorded', 'final_actual_weight' => '10.0000',
            'final_weight_task_id' => $taskId, 'fulfilled_base_qty' => '0.0000',
            'delivery_loss_total_qty' => '0.0000', 'undelivered_total_qty' => '0.0000',
        ]);
        $ready = TodoQueryLogic::lists(['branch' => 'task', 'page_size' => 100]);
        self::assertNotFalse($ready, TodoQueryLogic::getError());
        self::assertContains('delivery-task:' . $taskId, array_column($ready['items'], 'key'));

        Db::name('fulfillment_delivery_event')->insert([
            'tenant_id' => self::TENANT_ID, 'report_id' => $reportId, 'task_id' => $taskId,
            'status' => 'completed', 'idempotency_key' => 'todo-delivery-event',
            'request_fingerprint' => hash('sha256', 'todo-delivery-event'), 'create_time' => time(), 'update_time' => time(),
        ]);
        Db::name('customer_report_item')->where('id', $itemId)->update([
            'fulfillment_status' => 'partially_delivered_pending', 'fulfilled_base_qty' => '4.0000',
        ]);
        $partial = TodoQueryLogic::lists(['branch' => 'task', 'page_size' => 100]);
        self::assertNotFalse($partial, TodoQueryLogic::getError());
        self::assertContains('delivery-task:' . $taskId, array_column($partial['items'], 'key'));

        Db::name('customer_report_item')->where('id', $itemId)->update([
            'fulfillment_status' => 'delivered', 'fulfilled_base_qty' => '10.0000',
        ]);
        $completed = TodoQueryLogic::lists(['branch' => 'task', 'page_size' => 100]);
        self::assertNotFalse($completed, TodoQueryLogic::getError());
        self::assertNotContains('delivery-task:' . $taskId, array_column($completed['items'], 'key'));
    }

    public function test_closed_period_keeps_unresolved_historical_followups_without_an_id_column(): void
    {
        $month = date('Y-m', strtotime('first day of last month'));
        Db::name('finance_opening_book')->insert([
            'tenant_id' => self::TENANT_ID, 'status' => 'active', 'version' => 1,
            'reviews' => '{}', 'submitted_hash' => '', 'confirmed_snapshot' => '{}', 'created_by' => '{}',
            'last_modified_by' => '{}', 'create_time' => time(), 'update_time' => time(), 'confirmed_at' => time(),
        ]);
        Db::name('finance_period')->insert([
            'tenant_id' => self::TENANT_ID, 'month' => $month, 'status' => 'closed_estimated',
            'snapshot' => json_encode(['unresolved' => [[
                'id' => 'pending-document:998877', 'category' => 'pending_document',
                'reference' => 'n:998877', 'title' => '历史待确认单据',
                'details' => ['document_id' => 998877],
            ]]]),
            'closed_by' => '{}', 'closed_at' => time(),
        ]);

        $result = TodoQueryLogic::lists(['branch' => 'finance', 'page_size' => 100]);
        self::assertNotFalse($result, TodoQueryLogic::getError());
        self::assertContains('period-followup:' . $month . ':pending-document:998877', array_column($result['items'], 'key'));
        self::assertNotContains('finance-period:' . $month, array_column($result['items'], 'key'));
    }

    public function test_live_item_and_period_snapshot_of_the_same_action_are_counted_once(): void
    {
        $month = date('Y-m', strtotime('first day of last month'));
        Db::name('finance_opening_book')->insert([
            'tenant_id' => self::TENANT_ID, 'status' => 'active', 'version' => 1,
            'reviews' => '{}', 'submitted_hash' => '', 'confirmed_snapshot' => '{}', 'created_by' => '{}',
            'last_modified_by' => '{}', 'create_time' => time(), 'update_time' => time(), 'confirmed_at' => time(),
        ]);
        $documentId = (int)Db::name('finance_document')->insertGetId([
            'tenant_id' => self::TENANT_ID, 'type' => 'expense', 'status' => 'pending',
            'payload' => json_encode(['subject_name' => '月结重复动作', 'actual_date' => $month . '-10']),
            'confirmed_result' => '{}', 'created_by' => '{}', 'last_modified_by' => '{}', 'confirmed_by' => '{}',
            'create_time' => time(), 'update_time' => time(),
        ]);
        foreach (['01', '02'] as $day) {
            Db::name('finance_document')->insert([
                'tenant_id' => self::TENANT_ID, 'type' => 'expense', 'status' => 'pending',
                'payload' => json_encode(['subject_name' => '分页占位单据', 'actual_date' => $month . '-' . $day]),
                'confirmed_result' => '{}', 'created_by' => '{}', 'last_modified_by' => '{}', 'confirmed_by' => '{}',
                'create_time' => time(), 'update_time' => time(),
            ]);
        }
        Db::name('finance_period')->insert([
            'tenant_id' => self::TENANT_ID, 'month' => $month, 'status' => 'closed_estimated',
            'snapshot' => json_encode(['unresolved' => [[
                'id' => 'pending_document:' . $documentId, 'category' => 'pending_document',
                'reference' => (string)$documentId, 'title' => '历史待确认单据',
                'details' => ['document_id' => $documentId],
            ]]]),
            'closed_by' => '{}', 'closed_at' => time(),
        ]);

        $keys = []; $cursor = null; $pages = 0;
        do {
            self::assertLessThan(10, ++$pages, '游标分页未收敛');
            $params = ['branch' => 'finance', 'page_size' => 1];
            if ($cursor) { $params['cursor'] = $cursor; }
            $result = TodoQueryLogic::lists($params);
            self::assertNotFalse($result, TodoQueryLogic::getError());
            self::assertSame(3, $result['total_count']);
            $keys = array_merge($keys, array_column($result['items'], 'key'));
            $cursor = $result['next_cursor'];
        } while ($result['has_more']);
        self::assertCount(3, $keys);
        self::assertSame(1, count(array_filter($keys, static fn(string $key): bool => $key === 'finance-document:' . $documentId)));
    }

    public function test_more_than_five_thousand_items_are_counted_without_workbench_cap(): void
    {
        for ($start = 1; $start <= 5001; $start += 500) {
            $rows = [];
            for ($index = $start; $index < min($start + 500, 5002); $index++) {
                $rows[] = ['tenant_id' => self::TENANT_ID, 'group_id' => 1, 'report_id' => 700000 + $index,
                    'source_key' => 'large:test:' . $index, 'status' => 'unassigned', 'task_type' => 'exception',
                    'exception_code' => 'large_test', 'goods_name' => '大列表任务 ' . $index,
                    'create_time' => time(), 'update_time' => time()];
            }
            Db::name('fulfillment_task')->insertAll($rows);
        }
        $result = TodoQueryLogic::lists(['branch' => 'task', 'page_size' => 100]);
        self::assertNotFalse($result, TodoQueryLogic::getError());
        self::assertSame(5001, $result['branches']['task']['count']);
        self::assertCount(100, $result['items']);
        self::assertTrue($result['has_more']);
    }

    /** @param array<int,int> $tenants */
    private function cleanTodoFixtures(array $tenants): void
    {
        foreach (['finance_entry', 'finance_source', 'finance_document', 'finance_period', 'finance_opening_book',
            'employee_permission', 'employee', 'tenant_member', 'fulfillment_delivery_event',
            'fulfillment_task', 'customer_report_item', 'customer_report'] as $table) {
            Db::name($table)->whereIn('tenant_id', $tenants)->delete();
        }
    }
}
