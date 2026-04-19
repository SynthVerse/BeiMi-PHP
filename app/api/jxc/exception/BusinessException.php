<?php
declare(strict_types=1);

namespace app\api\jxc\exception;

use RuntimeException;

/**
 * JXC 业务异常
 * 此异常的 message 会直接返回给前端用户
 * 用于业务逻辑校验失败等场景
 */
class BusinessException extends RuntimeException
{
    public function __construct(string $message, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
