<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

/** 财务错误的可操作分类；文案只用于展示，不用于判断权限或字段。 */
final class FinanceBusinessException extends \DomainException
{
    public function __construct(string $message, private readonly array $errorDetails)
    {
        parent::__construct($message);
    }

    public function details(): array { return $this->errorDetails; }

    public static function validation(array $fields): self
    {
        return new self(implode('；', array_column($fields, 'message')), ['error_code' => 'FINANCE_VALIDATION_FAILED', 'field_errors' => array_values($fields)]);
    }

    public static function classified(string $message, string $code, array $fields = []): self
    {
        return new self($message, ['error_code' => $code, 'field_errors' => $fields]);
    }

    public static function capture(\DomainException $error): void
    {
        FinanceBusinessLogic::setError($error->getMessage());
        FinanceBusinessLogic::setReturnData($error instanceof self ? $error->details() : []);
    }

    public static function forRead(\DomainException $error): \DomainException
    {
        return $error instanceof self && ($error->details()['error_code'] ?? '') === 'FINANCE_ACTION_FORBIDDEN'
            ? self::classified($error->getMessage(), 'FINANCE_ACCESS_DENIED') : $error;
    }
}
