<?php

declare(strict_types=1);

namespace tests\support;

use app\ExceptionHandle;
use think\App;
use think\Response;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use Throwable;

final class GoodsHttpTraceExceptionHandler extends ExceptionHandle
{
    public function __construct(App $app, private GoodsHttpTrace $trace)
    {
        parent::__construct($app);
    }

    public function render($request, Throwable $e): Response
    {
        $status = match (true) {
            $e instanceof HttpResponseException => $e->getResponse()->getCode(),
            $e instanceof HttpException => $e->getStatusCode(),
            default => 500,
        };
        if ($status < 500) {
            return parent::render($request, $e);
        }

        $this->trace->capture($e);
        return self::failureResponse($status);
    }

    public function prepareResponse(Response $response): Response
    {
        $this->trace->sendingResponse();
        if ($response->getCode() >= 500) {
            return self::failureResponse($response->getCode());
        }
        try {
            // send() 内部会捕获内容生成异常。先生成并缓存，才能记录这一阶段的错误。
            $response->getContent();
            return $response;
        } catch (Throwable $error) {
            $this->trace->capture($error);
            return self::failureResponse(500);
        }
    }

    private static function failureResponse(int $status): Response
    {
        return Response::create(['code' => 0, 'msg' => '商品读取失败'], 'json', $status);
    }
}
