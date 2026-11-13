<?php

declare(strict_types=1);

use app\api\jxc\logic\FinanceBusinessException;
use PHPUnit\Framework\TestCase;

/**
 * 真实路由控制器、预览与错误封装；仅在持久化边界替换 action 和事务。
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class FinanceBusinessErrorResponseTest extends TestCase
{
    /** @dataProvider classifiedFailures */
    public function test_action_and_preview_keep_the_same_structured_error(string $endpoint, string $code): void
    {
        $fields = [['field' => 'reason', 'code' => 'required', 'message' => '请填写用途与核对说明']];
        $details = ['error_code' => $code, 'field_errors' => $fields];
        [$response, $transactions] = $this->respond($endpoint, new FinanceBusinessException('请填写用途与核对说明', $details));
        self::assertSame(200, $response->getCode());
        self::assertSame(0, $response->getData()['code']);
        self::assertSame('请填写用途与核对说明', $response->getData()['msg']);
        self::assertSame($details, $response->getData()['data']);
        self::assertSame($endpoint === 'preview' ? 1 : 0, $transactions->starts);
        self::assertSame($transactions->starts, $transactions->rollbacks);
    }

    public static function classifiedFailures(): array
    {
        $cases = [];
        foreach (['action', 'preview'] as $endpoint) {
            foreach (['FINANCE_VALIDATION_FAILED', 'FINANCE_VERSION_CONFLICT', 'FINANCE_SOURCE_CONFLICT', 'FINANCE_ACTION_FORBIDDEN', 'FINANCE_ACCESS_DENIED', 'FINANCE_CONTEXT_CHANGED'] as $code) {
                $cases[$endpoint . '/' . $code] = [$endpoint, $code];
            }
        }
        return $cases;
    }

    public function test_unknown_domain_error_is_not_classified_from_permission_words(): void
    {
        [$response, $transactions] = $this->respond('preview', new DomainException('包含权限说明的业务错误'));
        self::assertSame('包含权限说明的业务错误', $response->getData()['msg']);
        self::assertSame([], $response->getData()['data']);
        self::assertSame(1, $transactions->rollbacks);
    }

    private function respond(string $endpoint, DomainException $failure): array
    {
        class_alias(FinanceBusinessErrorDispatch::class, \app\api\jxc\logic\FinanceBusinessLogic::class);
        FinanceBusinessErrorDispatch::$failure = $failure;
        $request = (new \app\Request())->withPost(['action' => 'record', 'type' => 'customer_refund']);
        $app = new \think\App(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
        $app->instance('request', $request);
        $transactions = new FinanceBusinessPreviewTransactions();
        \think\Container::getInstance()->instance('think\DbManager', $transactions);
        $controller = new \app\api\controller\jxc\FinanceBusinessController($app);
        return [$controller->$endpoint(), $transactions];
    }
}

final class FinanceBusinessErrorDispatch extends \app\common\logic\BaseLogic
{
    public static DomainException $failure;
    public static function action(string $action, array $params): false
    {
        self::clearError();
        FinanceBusinessException::capture(self::$failure);
        return false;
    }
}

final class FinanceBusinessPreviewTransactions
{
    public int $starts = 0;
    public int $rollbacks = 0;
    public function startTrans(): void { $this->starts++; }
    public function rollback(): void { $this->rollbacks++; }
}
