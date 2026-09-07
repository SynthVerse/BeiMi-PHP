<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

/** 金额在接口及存储中始终使用十进制字符串，不经过浮点运算。 */
final class FinanceValue
{
    public static function money(mixed $value, bool $allowZero = false, bool $signed = false): string
    {
        if (!is_string($value) || !preg_match('/^' . ($signed ? '-?' : '') . '(0|[1-9][0-9]{0,11})(\.[0-9]{1,2})?$/D', $value)) { throw new \DomainException('金额必须为最多两位小数的有效数字'); }
        $amount = bcadd($value, '0', 2);
        if (!$allowZero && bccomp($amount, '0', 2) === 0) { throw new \DomainException('本次处理金额必须大于零'); }
        return $amount;
    }

    public static function id(mixed $value, bool $allowZero = false): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^(0|[1-9][0-9]{0,9})$/D', (string)$value) || (!$allowZero && (int)$value === 0)) { throw new \DomainException('对象或版本标识无效'); }
        return (int)$value;
    }

    public static function text(mixed $value, int $limit = 1000, bool $required = true): string
    {
        if (!is_string($value) || mb_strlen(trim($value)) > $limit || ($required && trim($value) === '')) { throw new \DomainException('请填写有效内容，且不能超过长度限制'); }
        return trim($value);
    }

    public static function date(mixed $value, bool $optional = false): ?string
    {
        if ($optional && ($value === null || $value === '')) { return null; }
        $text = self::text($value, 10);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        if (!$date || $date->format('Y-m-d') !== $text || $text < '1900-01-01' || $text > '2099-12-31') { throw new \DomainException('日期无效'); }
        return $text;
    }

    public static function json(array $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); }
    public static function decode(string $value): array { return json_decode($value, true, 512, JSON_THROW_ON_ERROR); }
}
