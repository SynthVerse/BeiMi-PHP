<?php
declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 说明是签发版本的补充记录，不参与金额确认或入账有效性。 */
final class CustomerReportSalesNotes
{
    private const TYPES = ['revision_reason' => '本次修改原因', 'zero_price_reason' => '零价原因', 'difference_reason' => '计价差异说明'];

    public static function pending(int $reportId): array
    {
        $tenant = FinanceAccess::tenant();
        $document = Db::name('customer_report_sales_document')->where('tenant_id', $tenant)->where('report_id', $reportId)->where('status', 'issued')->find();
        if (!$document || Db::name('customer_report')->where('tenant_id', $tenant)->where('id', $reportId)->value('status') === 'cancelled') { return []; }
        $filled = [];
        foreach (Db::name('customer_report_sales_note')->where('tenant_id', $tenant)->where('report_id', $reportId)->select()->toArray() as $note) {
            $filled[self::identity((int)$note['revision_id'], (int)$note['report_item_id'], $note['type'])] = true;
        }
        $pending = [];
        $revisions = Db::name('customer_report_sales_revision')->where('tenant_id', $tenant)->where('document_id', $document['id'])->order('version')->select()->toArray();
        foreach ($revisions as $revision) {
            $required = [];
            if ((int)$revision['version'] > 1 && trim($revision['reason']) === '') { $required[] = ['type' => 'revision_reason', 'report_item_id' => 0]; }
            $snapshot = FinanceValue::decode($revision['snapshot']);
            foreach ($snapshot['lines'] as $line) {
                if (bccomp($line['price'], '0', 2) === 0 && trim($line['zero_price_reason'] ?? '') === '') {
                    $required[] = ['type' => 'zero_price_reason', 'report_item_id' => (int)$line['report_item_id'], 'goods_name' => $line['goods_name']];
                }
                if (!empty($line['has_quantity_difference']) && trim($line['difference_reason'] ?? '') === '') {
                    $required[] = ['type' => 'difference_reason', 'report_item_id' => (int)$line['report_item_id'], 'goods_name' => $line['goods_name']];
                }
            }
            foreach ($required as $note) {
                if (isset($filled[self::identity((int)$revision['id'], $note['report_item_id'], $note['type'])])) { continue; }
                $pending[] = $note + ['revision_id' => (int)$revision['id'], 'document_version' => (int)$revision['version'],
                    'label' => self::TYPES[$note['type']], 'can_supplement' => FinanceAccess::has('settlement.bill') && ($note['type'] !== 'difference_reason' || FinanceAccess::owner())];
            }
        }
        return $pending;
    }

    public static function forRevision(int $revisionId): array
    {
        return Db::name('customer_report_sales_note')->where('tenant_id', FinanceAccess::tenant())->where('revision_id', $revisionId)
            ->field('id,revision_id,report_item_id,type,content,actor,create_time')->order('id')->select()->toArray();
    }

    public static function supplement(array $params): void
    {
        Db::transaction(static fn() => self::supplementWithinTransaction($params));
    }

    public static function supplementWithinTransaction(array $params): void
    {
        FinanceAccess::require('settlement.bill');
        $tenant = FinanceAccess::tenant(); $reportId = FinanceValue::id($params['id'] ?? 0);
        $key = FinanceValue::text($params['idempotency_key'] ?? '', 96);
        $fingerprint = hash('sha256', FinanceValue::json([$params, FinanceAccess::actor()]));
        FinanceIntegration::lock();
        $report = Db::name('customer_report')->where('tenant_id', $tenant)->where('id', $reportId)->whereNull('delete_time')->lock(true)->find();
        if (!$report) { throw new \DomainException('报货单不存在或不属于当前门店'); }
        $replay = Db::name('customer_report_sales_note')->where('tenant_id', $tenant)->where('operation_key', $key)->find();
        if ($replay) {
            if ((int)$replay['report_id'] !== $reportId || !hash_equals($replay['fingerprint'], $fingerprint)) { throw new \DomainException('同一提交标识不能用于不同说明或操作人'); }
            return;
        }
        $notes = $params['notes'] ?? null;
        if (!is_array($notes) || !array_is_list($notes) || !$notes || count($notes) > 600) { throw new \DomainException('请提交需要补充的说明'); }
        $required = [];
        foreach (self::pending($reportId) as $entry) { $required[self::identity($entry['revision_id'], $entry['report_item_id'], $entry['type'])] = $entry; }
        $document = Db::name('customer_report_sales_document')->where('tenant_id', $tenant)->where('report_id', $reportId)->lock(true)->find();
        $seen = [];
        foreach ($notes as $note) {
            if (!is_array($note)) { throw new \DomainException('说明格式无效'); }
            $revision = FinanceValue::id($note['revision_id'] ?? 0); $item = FinanceValue::id($note['report_item_id'] ?? 0, true);
            $type = FinanceValue::text($note['type'] ?? '', 32);
            if (!isset(self::TYPES[$type])) { throw new \DomainException('不支持的销售说明类型'); }
            $identity = self::identity($revision, $item, $type);
            if (isset($seen[$identity])) { throw new \DomainException('同一说明不能重复提交'); }
            $seen[$identity] = true;
            if (!isset($required[$identity])) { throw new \DomainException('该说明已补充或不属于本单待补事项，请刷新'); }
            if ($type === 'difference_reason') { FinanceAccess::require('', true); }
            $content = FinanceValue::text($note['content'] ?? '', $type === 'zero_price_reason' ? 500 : 1000);
            Db::name('customer_report_sales_note')->insert(['tenant_id' => $tenant, 'report_id' => $reportId, 'document_id' => $document['id'],
                'revision_id' => $revision, 'report_item_id' => $item, 'type' => $type, 'content' => $content,
                'actor' => FinanceValue::json(FinanceAccess::actor()), 'operation_key' => $key, 'fingerprint' => $fingerprint, 'create_time' => time()]);
        }
    }

    private static function identity(int $revision, int $item, string $type): string { return $revision . ':' . $item . ':' . $type; }
}
