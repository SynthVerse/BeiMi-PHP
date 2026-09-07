<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use app\common\logic\BaseLogic;
use app\common\service\jxc\StoreMembershipService;
use think\facade\Db;

/** 财务准备边界。草稿和账户档案均不产生资金、往来、收入或库存流水。 */
final class FinanceSetupLogic extends BaseLogic
{
    public const ACCOUNT_TYPES = ['cash' => '现金', 'wechat' => '微信', 'alipay' => '支付宝', 'bank' => '银行'];

    public static function workbench(): array|false
    {
        self::clearError();
        if (self::tenantId() <= 0) {
            self::setError('请先选择门店');
            return false;
        }
        $owner = self::isOwner();
        return [
            'tenant_id' => self::tenantId(),
            'operator_id' => self::operatorId(),
            'capabilities' => [
                'manage_accounts' => $owner,
                'confirm_opening' => $owner,
                'prepare_opening' => $owner || (self::isUserIdentity() && WorkforceLogic::hasPermission('finance.opening.prepare')),
                'view_settlement' => $owner || (self::isUserIdentity() && WorkforceLogic::hasPermission('settlement.view')),
            ],
        ];
    }

    public static function preparation(): array|false
    {
        if (!self::authorize(false)) {
            return false;
        }
        // 与保存共用门店草稿锁，避免旧内容搭配另一次保存的操作人信息。
        return Db::transaction(static fn(): array => self::preparationResult(self::preparationRow(true)));
    }

    public static function accounts(): array|false
    {
        if (!self::authorize(true)) {
            return false;
        }
        return [
            'tenant_id' => self::tenantId(),
            'types' => self::ACCOUNT_TYPES,
            'lists' => Db::name('finance_account')->where('tenant_id', self::tenantId())
                ->field('id,name,account_type,is_enabled,version')->order('id')->select()->toArray(),
        ];
    }

    public static function savePreparation(array $params): array|false
    {
        if (!self::authorize(false)) {
            return false;
        }
        try {
            $date = self::text($params['activation_date'] ?? '', 10);
            if ($date !== '') {
                $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                if (!$parsed || $parsed->format('Y-m-d') !== $date || $date < '2000-01-01' || $date > '2099-12-31') {
                    throw new \DomainException('请选择有效的财务启用日期');
                }
            }
            $data = [
                'activation_date' => $date ?: null,
                'inventory_cost_reviewed' => self::flag($params['inventory_cost_reviewed'] ?? 0),
                'legacy_settlement_reviewed' => self::flag($params['legacy_settlement_reviewed'] ?? 0),
                'excluded_business_reviewed' => self::flag($params['excluded_business_reviewed'] ?? 0),
                'notes' => self::text($params['notes'] ?? '', 1000),
            ];
            return self::mutate('preparation.save', $params, $data, static function (int $version) use ($data): array {
                $before = self::preparationRow();
                if ((int)$before['version'] !== $version) {
                    throw new \DomainException('准备资料已更新，请重新加载后再保存');
                }
                $row = array_merge($before, $data, [
                    'version' => $version + 1, 'operator_id' => self::operatorId(), 'update_time' => time(),
                ]);
                Db::name('finance_preparation')->where('tenant_id', self::tenantId())->update($row);
                return [$before, self::preparationResult($row, [
                    'id' => self::operatorId(),
                    'type' => self::isUserIdentity() ? 'user' : 'tenant_admin',
                    'name' => (string)(request()->adminInfo['name'] ?? '') ?: '操作人 #' . self::operatorId(),
                ])];
            });
        } catch (\DomainException $e) {
            self::setError($e->getMessage());
            return false;
        }
    }

    public static function saveAccount(array $params): array|false
    {
        if (!self::authorize(true)) {
            return false;
        }
        try {
            $id = self::integer($params['id'] ?? 0);
            $name = self::text($params['name'] ?? '', 60);
            $type = self::text($params['account_type'] ?? '', 20);
            if ($name === '' || !isset(self::ACCOUNT_TYPES[$type])) {
                throw new \DomainException('请填写账户名称并选择账户类型');
            }
            $data = ['id' => $id, 'name' => $name, 'account_type' => $type, 'is_enabled' => self::flag($params['is_enabled'] ?? 1)];
            return self::mutate('account.save', $params, $data, static function (int $version) use ($data, $id): array {
                $before = $id > 0 ? Db::name('finance_account')->where('tenant_id', self::tenantId())->where('id', $id)->find() : [];
                if ($id > 0 && !$before) {
                    throw new \DomainException('资金账户不存在或不属于当前门店');
                }
                if ((int)($before['version'] ?? 0) !== $version) {
                    throw new \DomainException('账户已更新，请重新加载后再保存');
                }
                if ($before && $before['account_type'] !== $data['account_type']) {
                    throw new \DomainException('已有账户不能更换类型，请另建账户');
                }
                $duplicate = Db::name('finance_account')->where('tenant_id', self::tenantId())->where('name', $data['name'])->where('id', '<>', $id)->find();
                if ($duplicate) {
                    throw new \DomainException('当前门店已有同名账户，请使用不同名称');
                }
                $row = array_merge($data, ['tenant_id' => self::tenantId(), 'version' => $version + 1, 'update_time' => time()]);
                unset($row['id']);
                if ($id > 0) {
                    Db::name('finance_account')->where('tenant_id', self::tenantId())->where('id', $id)->update($row);
                } else {
                    $row['create_time'] = time();
                    $row['id'] = Db::name('finance_account')->insertGetId($row);
                }
                $row['id'] = $row['id'] ?? $id;
                return [$before ?: [], $row];
            });
        } catch (\DomainException $e) {
            self::setError($e->getMessage());
            return false;
        }
    }

    public static function opening(array $params = [], bool $subjects = false): array|false
    {
        if (!self::authorize(false)) { return false; }
        try {
            return Db::transaction(static function () use ($params, $subjects): array {
                Db::name('finance_preparation')->duplicate(['tenant_id'])->insert(['tenant_id' => self::tenantId()]);
                self::preparationRow(true);
                $service = new FinanceOpeningService(self::tenantId(), self::actor());
                return $subjects ? $service->subjects($params) : $service->snapshot();
            });
        } catch (\DomainException $e) { self::setError($e->getMessage()); return false; }
    }

    public static function openingAction(string $action, array $params): array|false
    {
        if (!self::authorize(false)) { return false; }
        if ($action === 'confirm' && !self::isOwner()) {
            self::setError('仅门店最高权限人员可最终确认期初'); return false;
        }
        try {
            $data = $params;
            unset($data['expected_tenant_id'], $data['expected_version'], $data['idempotency_key']);
            return self::mutate('opening.' . $action, $params, $data, static function (int $version) use ($action, $data): array {
                return (new FinanceOpeningService(self::tenantId(), self::actor()))->execute($action, $data, $version);
            });
        } catch (\DomainException $e) { self::setError($e->getMessage()); return false; }
    }

    private static function actor(): array
    {
        return ['id' => self::operatorId(), 'type' => self::isUserIdentity() ? 'user' : 'tenant_admin',
            'name' => (string)(request()->adminInfo['name'] ?? '') ?: '操作人 #' . self::operatorId()];
    }

    /** 同一门店串行修改准备资料；幂等结果与档案修改在同一个事务内提交。 */
    private static function mutate(string $action, array $params, array $data, callable $write): array
    {
        if (self::integer($params['expected_tenant_id'] ?? 0) !== self::tenantId()) {
            throw new \DomainException('当前门店已变化，请返回后重新进入');
        }
        $version = self::integer($params['expected_version'] ?? null);
        $key = self::text($params['idempotency_key'] ?? '', 96);
        if (!preg_match('/^[a-zA-Z0-9_-]{16,96}$/D', $key)) {
            throw new \DomainException('提交标识无效，请重新进入页面');
        }
        $fingerprint = hash('sha256', self::json([$action, $data, $version, self::operatorId(), self::isUserIdentity()]));
        return Db::transaction(static function () use ($action, $version, $key, $fingerprint, $write): array {
            // 唯一主键行同时解决首次创建竞争；不依赖不存在的行上的间隙锁。
            Db::name('finance_preparation')->duplicate(['tenant_id'])->insert(['tenant_id' => self::tenantId()]);
            Db::name('finance_preparation')->where('tenant_id', self::tenantId())->lock(true)->find();
            $existing = Db::name('finance_setup_action')->where('tenant_id', self::tenantId())->where('idempotency_key', $key)->find();
            if ($existing) {
                if (!hash_equals($existing['fingerprint'], $fingerprint)) {
                    throw new \DomainException('同一提交标识不能用于不同内容');
                }
                return json_decode($existing['result_data'], true, 512, JSON_THROW_ON_ERROR);
            }
            if ($action === 'preparation.save' && Db::name('finance_opening_book')->where('tenant_id', self::tenantId())->value('status') === 'active') {
                throw new \DomainException('财务已启用，不能覆盖启用日期和期初准备历史');
            }
            [$before, $result] = $write($version);
            Db::name('finance_setup_action')->insert([
                'tenant_id' => self::tenantId(), 'idempotency_key' => $key, 'fingerprint' => $fingerprint,
                'action_type' => $action, 'operator_id' => self::operatorId(), 'before_data' => self::json($before),
                'result_data' => self::json($result), 'create_time' => time(),
            ]);
            return $result;
        });
    }

    private static function preparationRow(bool $lock = false): array
    {
        return Db::name('finance_preparation')->where('tenant_id', self::tenantId())->lock($lock)->find() ?: [
            'tenant_id' => self::tenantId(), 'activation_date' => null, 'version' => 0,
            'inventory_cost_reviewed' => 0, 'legacy_settlement_reviewed' => 0, 'excluded_business_reviewed' => 0,
            'notes' => '', 'operator_id' => 0, 'update_time' => 0,
        ];
    }

    private static function preparationResult(array $row, ?array $actor = null): array
    {
        $actions = Db::name('finance_setup_action')->where('tenant_id', self::tenantId())->where('action_type', 'preparation.save');
        $first = (int)$row['version'] > 0 ? (clone $actions)->order('id')->find() : null;
        $last = (int)$row['version'] > 0 ? (clone $actions)->order('id', 'desc')->find() : null;
        $firstResult = $first ? json_decode($first['result_data'], true, 512, JSON_THROW_ON_ERROR) : [];
        $lastResult = $last ? json_decode($last['result_data'], true, 512, JSON_THROW_ON_ERROR) : [];
        $row['created_by'] = $firstResult['created_by'] ?? ($first ? ['id' => (int)$first['operator_id'], 'name' => '操作人 #' . $first['operator_id']] : $actor);
        $row['created_time'] = (int)($first['create_time'] ?? ($actor ? $row['update_time'] : 0));
        $row['last_modified_by'] = $actor ?? ($lastResult['last_modified_by'] ?? ($last ? ['id' => (int)$last['operator_id'], 'name' => '操作人 #' . $last['operator_id']] : null));
        $row['status'] = Db::name('finance_opening_book')->where('tenant_id', self::tenantId())->value('status') ?: 'draft';
        $row['cutoff_date'] = $row['activation_date']
            ? (new \DateTimeImmutable($row['activation_date']))->modify('-1 day')->format('Y-m-d') : null;
        $row['notice'] = $row['status'] === 'active' ? '财务已启用，期初历史只读。' : '准备声明不能替代逐项期初凭据与迁移验收，请进入期初明细完成核对。';
        return $row;
    }

    private static function authorize(bool $ownerOnly): bool
    {
        self::clearError();
        if (self::tenantId() <= 0 || self::operatorId() <= 0) {
            self::setError('请先登录并选择门店');
            return false;
        }
        if (self::isOwner() || (!$ownerOnly && self::isUserIdentity() && WorkforceLogic::hasPermission('finance.opening.prepare'))) {
            return true;
        }
        self::setError($ownerOnly ? '仅门店最高权限人员可管理资金账户' : '没有财务期初准备权限');
        return false;
    }

    private static function isOwner(): bool
    {
        if (self::isUserIdentity()) {
            return StoreMembershipService::isTenantAdmin(self::operatorId(), self::tenantId());
        }
        $info = (array)(request()->adminInfo ?? []);
        return (int)($info['root'] ?? 0) === 1 && (int)($info['tenant_id'] ?? 0) === self::tenantId();
    }

    private static function integer(mixed $value): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^(0|[1-9][0-9]{0,9})$/D', (string)$value)) {
            throw new \DomainException('版本或标识参数不正确，请重新加载');
        }
        return (int)$value;
    }

    private static function flag(mixed $value): int
    {
        if (!in_array($value, [0, 1, '0', '1', false, true], true)) {
            throw new \DomainException('勾选状态无效');
        }
        return (int)$value;
    }

    private static function text(mixed $value, int $max): string
    {
        if (!is_string($value) || mb_strlen(trim($value)) > $max) {
            throw new \DomainException('填写内容格式错误或超过长度限制');
        }
        return trim($value);
    }

    private static function tenantId(): int { return (int)(request()->tenantId ?? 0); }
    private static function isUserIdentity(): bool { return (bool)(request()->jxcFromUserToken ?? false); }
    private static function operatorId(): int { return (int)(request()->userId ?? request()->adminId ?? 0); }
    private static function json(array $data): string { return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); }
}
