<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 采购阈值与付款规则追加版本；到货差异保存当时规则，不随后设规则重算。 */
final class FinancePurchaseRuleBook
{
    public static function threshold(int $vendor, int $sku, int $category): ?array
    {
        foreach ([['vendor_sku', $vendor, $sku, 0], ['sku', 0, $sku, 0], ['category', 0, 0, $category], ['store', 0, 0, 0]] as [$scope, $v, $s, $c]) {
            if (($scope === 'vendor_sku' && (!$v || !$s)) || ($scope === 'sku' && !$s) || ($scope === 'category' && !$c)) { continue; }
            $rule = self::rule($scope, $v, $s, $c);
            if ($rule) { return $rule; }
        }
        return null;
    }

    public static function terms(int $vendor, string $date): array
    {
        $date = FinanceValue::date($date);
        $row = Db::name('finance_supplier_terms')->where('tenant_id', FinanceAccess::tenant())->where('vendor_id', $vendor)->order('version', 'desc')->lock(true)->find();
        $mode = $row['mode'] ?? 'unagreed'; $days = (int)($row['days'] ?? 0);
        return ['version' => (int)($row['version'] ?? 0), 'mode' => $mode, 'days' => $days,
            'default_due_date' => $mode === 'unagreed' ? null : (new \DateTimeImmutable($date))->modify('+' . $days . ' days')->format('Y-m-d')];
    }

    public static function confirm(array $document, array $data): array
    {
        FinanceAccess::require('', true); $tenant = FinanceAccess::tenant();
        $kind = FinanceValue::text($data['rule_kind'] ?? null, 20);
        $version = FinanceValue::id($data['expected_rule_version'] ?? null, true);
        $reason = FinanceValue::text($data['reason'] ?? null, 1000);
        $base = ['tenant_id' => $tenant, 'document_id' => $document['id'], 'version' => $version + 1,
            'reason' => $reason, 'actor' => FinanceValue::json(FinanceAccess::actor()), 'create_time' => time()];
        if ($kind === 'terms') {
            $vendor = FinanceValue::id($data['subject_id'] ?? null); self::subject('vendor', $vendor);
            $current = self::terms($vendor, date('Y-m-d'));
            if ($current['version'] !== $version) { throw new \DomainException('供应商付款规则已变化，请读取最新版本'); }
            $mode = FinanceValue::text($data['mode'] ?? null, 20);
            if (!in_array($mode, ['unagreed', 'arrival', 'days_after'], true)) { throw new \DomainException('供应商付款规则无效'); }
            $days = $mode === 'days_after' ? FinanceValue::id($data['days'] ?? null, true) : 0;
            if ($days > 365) { throw new \DomainException('到货后付款天数须为0至365天'); }
            Db::name('finance_supplier_terms')->insert($base + ['vendor_id' => $vendor, 'mode' => $mode, 'days' => $days]);
            $result = ['subject_id' => $vendor, 'before' => $current, 'rule' => self::terms($vendor, date('Y-m-d'))];
        } elseif ($kind === 'difference') {
            [$scope, $vendor, $sku, $category] = self::dimensions($data);
            $current = self::rule($scope, $vendor, $sku, $category);
            if ((int)($current['version'] ?? 0) !== $version) { throw new \DomainException('采购复核阈值已变化，请读取最新版本'); }
            Db::name('finance_purchase_difference_rule')->insert($base + ['scope' => $scope, 'vendor_id' => $vendor, 'sku_id' => $sku, 'category_id' => $category,
                'absolute_limit' => FinancePurchaseSettlement::quantity($data['absolute_limit'] ?? null, true),
                'percent_limit' => FinancePurchaseSettlement::quantity($data['percent_limit'] ?? null, true)]);
            $result = ['subject_id' => $vendor, 'before' => $current, 'rule' => self::rule($scope, $vendor, $sku, $category)];
        } else { throw new \DomainException('请选择重量差复核或供应商付款规则'); }
        return $result + ['type' => 'purchase_rules', 'rule_kind' => $kind, 'reason' => $reason, 'created_sources' => []];
    }

    public static function options(array $data): array
    {
        FinanceAccess::require('', true); $kind = $data['rule_kind'] ?? 'difference';
        if (($data['lookup'] ?? '') === 'category') {
            $page = FinanceValue::id($data['page'] ?? 1); $keyword = FinanceValue::text($data['keyword'] ?? '', 60, false);
            $rows = Db::name('tenant_goodscat')->where('tenant_id', FinanceAccess::tenant())->whereNull('delete_time')
                ->whereLike('name', '%' . $keyword . '%')->order('id')->limit(($page - 1) * 20, 21)->field('id,name')->select()->toArray();
            return ['sources' => [], 'has_more' => count($rows) > 20, 'categories' => array_slice($rows, 0, 20)];
        }
        if ($kind === 'terms') {
            $vendor = FinanceValue::id($data['subject_id'] ?? 0, true);
            if ($vendor) { self::subject('vendor', $vendor); }
            $rule = self::terms($vendor, FinanceValue::date($data['actual_date'] ?? date('Y-m-d')));
        } else {
            [$scope, $vendor, $sku, $category] = self::dimensions($data + ['scope' => 'store']);
            $rule = self::rule($scope, $vendor, $sku, $category);
        }
        return ['sources' => [], 'has_more' => false, 'rule_kind' => $kind, 'rule' => $rule];
    }

    private static function dimensions(array $data): array
    {
        $scope = FinanceValue::text($data['scope'] ?? null, 20); $vendor = 0; $sku = 0; $category = 0;
        if (!in_array($scope, ['store', 'category', 'sku', 'vendor_sku'], true)) { throw new \DomainException('采购规则适用范围无效'); }
        if ($scope === 'vendor_sku') { $vendor = FinanceValue::id($data['subject_id'] ?? null); self::subject('vendor', $vendor); }
        if (in_array($scope, ['sku', 'vendor_sku'], true)) { $sku = FinanceValue::id($data['sku_id'] ?? null); self::subject('goods_sku', $sku); }
        if ($scope === 'category') { $category = FinanceValue::id($data['category_id'] ?? null); self::subject('tenant_goodscat', $category); }
        return [$scope, $vendor, $sku, $category];
    }

    private static function subject(string $table, int $id): void
    {
        if (!Db::name($table)->where('tenant_id', FinanceAccess::tenant())->where('id', $id)->find()) { throw new \DomainException('采购规则对象不属于本门店'); }
    }

    private static function rule(string $scope, int $vendor, int $sku, int $category): ?array
    {
        $row = Db::name('finance_purchase_difference_rule')->where('tenant_id', FinanceAccess::tenant())->where('scope', $scope)
            ->where('vendor_id', $vendor)->where('sku_id', $sku)->where('category_id', $category)->order('version', 'desc')->lock(true)->find();
        if (!$row) { return null; }
        return ['id' => (int)$row['id'], 'scope' => $scope, 'vendor_id' => $vendor, 'sku_id' => $sku, 'category_id' => $category, 'version' => (int)$row['version'],
            'absolute_limit' => (string)$row['absolute_limit'], 'percent_limit' => (string)$row['percent_limit'], 'reason' => $row['reason']];
    }
}
