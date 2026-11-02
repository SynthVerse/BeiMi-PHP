<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use think\facade\Db;

/** 建档操作身份独立于期初账本；成功事实与业务创建在同一事务提交。 */
final class MasterCreationReceipt extends BaseLogic
{
    public static function run(string $kind, array $params, callable $create): array
    {
        $scope = self::scope($kind, $params);
        $payload = $params;
        unset($payload['idempotency_key'], $payload['creation_key'], $payload['expected_tenant_id']);
        $fingerprint = hash('sha256', self::json(self::normalize($payload)));
        return Db::transaction(static function () use ($scope, $fingerprint, $create): array {
            // 唯一键先取得实际行锁，竞争者等待提交；失败时占位与创建一并回滚。
            Db::name('master_creation_receipt')->duplicate(['tenant_id'])->insert($scope + [
                'fingerprint' => $fingerprint, 'result_data' => '', 'create_time' => time(),
            ]);
            $receipt = Db::name('master_creation_receipt')->where($scope)->lock(true)->find();
            if (!$receipt || !hash_equals((string)$receipt['fingerprint'], $fingerprint)) {
                throw new \DomainException('同一建档标识不能用于不同内容');
            }
            if ($receipt['result_data'] !== '') {
                return json_decode($receipt['result_data'], true, 512, JSON_THROW_ON_ERROR);
            }
            $result = $create();
            if (!is_array($result) || (int)($result['id'] ?? 0) <= 0) {
                throw new \DomainException('建档未成功，原操作未提交');
            }
            Db::name('master_creation_receipt')->where('id', (int)$receipt['id'])->update(['result_data' => self::json($result)]);
            return $result;
        });
    }

    public static function lookup(array $params): array|false
    {
        self::clearError();
        try {
            $kind = is_string($params['kind'] ?? null) ? $params['kind'] : '';
            $scope = self::scope($kind, $params);
            $receipt = Db::name('master_creation_receipt')->where($scope)->find();
            $known = $receipt && $receipt['result_data'] !== '';
            return [
                'state' => $known ? 'succeeded' : 'unknown', 'kind' => $kind,
                'idempotency_key' => $scope['idempotency_key'],
                'result' => $known ? json_decode($receipt['result_data'], true, 512, JSON_THROW_ON_ERROR) : null,
            ];
        } catch (\DomainException $error) {
            self::setError($error->getMessage());
            return false;
        }
    }

    /** 员工既有列也使用完整归属，避免旧tenant+creation_key唯一键跨人误碰撞。 */
    public static function storageKey(string $kind, array $params): string
    {
        return hash('sha256', self::json(self::scope($kind, $params)));
    }

    private static function scope(string $kind, array $params): array
    {
        if (!in_array($kind, ['customer', 'supplier', 'employee'], true)) {
            throw new \DomainException('建档对象类型无效');
        }
        $tenant = FinanceAccess::tenant();
        $actor = FinanceAccess::operator();
        if ($tenant <= 0 || $actor <= 0) { throw new \DomainException('请先登录并选择门店'); }
        if (array_key_exists('expected_tenant_id', $params)
            && (filter_var($params['expected_tenant_id'], FILTER_VALIDATE_INT) !== $tenant)) {
            throw new \DomainException('当前门店已变化，请返回后重新进入');
        }
        if ($kind === 'employee' && !FinanceAccess::has('employee.manage')) {
            throw new \DomainException('没有管理员工的电子权限');
        }
        $key = $params['idempotency_key'] ?? ($kind === 'employee' ? ($params['creation_key'] ?? '') : '');
        if (!is_string($key) || !preg_match('/^[A-Za-z0-9_.:-]{1,100}$/D', trim($key))) {
            throw new \DomainException('建档提交标识无效');
        }
        if ($kind === 'employee' && isset($params['idempotency_key'], $params['creation_key'])
            && (!is_string($params['creation_key']) || trim($params['creation_key']) !== trim($key))) {
            throw new \DomainException('员工建档提交标识不一致');
        }
        return ['tenant_id' => $tenant, 'actor_type' => FinanceAccess::userIdentity() ? 'user' : 'tenant_admin',
            'actor_id' => $actor, 'kind' => $kind, 'idempotency_key' => trim($key)];
    }

    private static function normalize(mixed $value, string $field = ''): mixed
    {
        if (is_string($value)) { return trim($value); }
        if (!is_array($value)) { return $value; }
        $result = [];
        foreach ($value as $key => $item) { $result[$key] = self::normalize($item, (string)$key); }
        if (in_array($field, ['permission_keys', 'process_ids'], true)) {
            $result = array_values(array_unique($result, SORT_REGULAR));
            sort($result);
        } elseif (!array_is_list($result)) { ksort($result); }
        return $result;
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
