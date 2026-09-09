<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 退货与既有销售贷项、真实退款的追溯；关联本身不产生库存或账务。 */
final class FinanceCustomerReturnRefunds
{
    public static function input(string $type, array $input): array
    {
        if ($type === 'customer_refund') { unset($input['return_context']); }
        return $input;
    }

    public static function context(int $document, int $customer): array
    {
        $row = Db::name('finance_customer_return')->alias('r')->join('finance_document d', 'd.tenant_id=r.tenant_id AND d.id=r.document_id')
            ->where('r.tenant_id', FinanceAccess::tenant())->where('r.document_id', $document)->where('r.customer_id', $customer)
            ->where('d.type', 'customer_return_actual')->where('d.status', 'confirmed')->field('r.*')->find();
        if (!$row) { throw new \DomainException('请选择本店本客户已确认的实物退货验收'); }
        $fact = FinanceValue::decode($row['snapshot']);
        return ['document_id' => $document, 'subject_id' => $customer, 'subject_name' => $fact['subject_name'], 'original_sales_order_id' => (int)$row['sales_order_id'],
            'order_sn' => $fact['order_sn'], 'sku_id' => (int)$row['sku_id'], 'goods_name' => $fact['goods_name'], 'sku_name' => $fact['sku_name'],
            'actual_date' => $row['business_date'], 'quantity' => $row['quantity'], 'base_unit_name' => $fact['base_unit_name']];
    }

    public static function assertCredit(array $credit, array $return): void
    {
        $fact = $credit['snapshot'];
        if ($credit['category'] !== 'customer_refund' || $credit['subject_id'] !== $return['subject_id'] || !$credit['document_id']) { throw new \DomainException('请选择本客户原销售对应的已确认贷项'); }
        $type = Db::name('finance_document')->where('tenant_id', FinanceAccess::tenant())->where('id', $credit['document_id'])->where('status', 'confirmed')->value('type');
        $matches = in_array($type, ['sales_confirmation', 'sales_batch'], true)
            && (int)($fact['sales_order_id'] ?? $fact['order_id'] ?? 0) === $return['original_sales_order_id']
            && (!isset($fact['sku_id']) || (int)$fact['sku_id'] === $return['sku_id']);
        if ($type === 'sales_batch') {
            $matches = $matches && Db::name('order_goods')->where('tenant_id', FinanceAccess::tenant())->where('id', (int)($fact['line_id'] ?? 0))
                ->where('order_type', 'sales')->where('order_id', $return['original_sales_order_id'])->where('sku_id', $return['sku_id'])->count() > 0;
        }
        if (!$matches) { throw new \DomainException('本次关联退款只能使用该客户原销售对应的已确认贷项'); }
    }

    public static function options(int $document, int $customer, array $params = []): array
    {
        $return = self::context($document, $customer); $ledger = new FinanceLedger(FinanceAccess::tenant());
        $page = FinanceValue::id($params['credit_page'] ?? $params['page'] ?? 1); $refundPage = FinanceValue::id($params['refund_page'] ?? 1);
        $matchingLines = Db::name('order_goods')->where('tenant_id', FinanceAccess::tenant())->where('order_type', 'sales')->where('order_id', $return['original_sales_order_id'])->where('sku_id', $return['sku_id'])->field('id')->buildSql();
        $rows = Db::name('finance_source')->alias('s')->join('finance_document d', 'd.tenant_id=s.tenant_id AND d.id=s.document_id')
            ->where('s.tenant_id', FinanceAccess::tenant())->where('s.subject_id', $customer)->where('s.category', 'customer_refund')
            ->where('d.status', 'confirmed')->whereIn('d.type', ['sales_confirmation', 'sales_batch'])
            ->whereRaw("CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(s.snapshot,'$.sales_order_id')),JSON_UNQUOTE(JSON_EXTRACT(s.snapshot,'$.order_id'))) AS UNSIGNED)=?", [$return['original_sales_order_id']])
            ->whereRaw("(JSON_EXTRACT(s.snapshot,'$.sku_id') IS NULL OR CAST(JSON_UNQUOTE(JSON_EXTRACT(s.snapshot,'$.sku_id')) AS UNSIGNED)=?)", [$return['sku_id']])
            ->whereRaw("(d.type<>'sales_batch' OR CAST(JSON_UNQUOTE(JSON_EXTRACT(s.snapshot,'$.line_id')) AS UNSIGNED) IN " . $matchingLines . ')')
            ->field('s.id,d.type AS document_type')->order('s.id desc')->limit(($page - 1) * 20, 21)->select()->toArray();
        $credits = [];
        foreach (array_slice($rows, 0, 20) as $row) { $credits[] = $ledger->source('n:' . $row['id']) + ['document_type' => $row['document_type']]; }
        $refunds = Db::name('finance_document')->where('tenant_id', FinanceAccess::tenant())->where('type', 'customer_refund')->where('status', 'confirmed')
            ->whereRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(confirmed_result,'$.return_context.document_id')) AS UNSIGNED)=?", [$document])
            ->order('id desc')->limit(($refundPage - 1) * 20, 21)->select()->toArray();
        $history = [];
        foreach (array_slice($refunds, 0, 20) as $refund) {
            $result = FinanceValue::decode($refund['confirmed_result']);
            $history[] = ['document_id' => (int)$refund['id'], 'actual_date' => $result['money']['actual_date'], 'amount' => $result['money']['amount'],
                'replacement_document_id' => (int)Db::name('finance_correction')->where('tenant_id', FinanceAccess::tenant())->where('original_document_id', $refund['id'])->value('replacement_document_id')];
        }
        return ['return_context' => $return, 'return_credits' => $credits, 'return_credit_has_more' => count($rows) > 20, 'return_refunds' => $history, 'return_refund_has_more' => count($refunds) > 20];
    }

    public static function validate(FinanceLedger $ledger, array $data): array
    {
        if (empty($data['return_document_id'])) { return []; }
        $return = self::context(FinanceValue::id($data['return_document_id']), FinanceValue::id($data['subject_id'] ?? null));
        if (($data['return_link_verified'] ?? null) !== 1) { throw new \DomainException('请人工核实本次退款的销售贷项确实对应所选实物退货'); }
        $references = [];
        foreach ($data['allocations'] ?? [] as $line) {
            if (!is_array($line)) { throw new \DomainException('退款贷项组成无效'); }
            $credit = $ledger->source(FinanceValue::text($line['source'] ?? null, 40));
            self::assertCredit($credit, $return);
            $references[] = $credit['reference'];
        }
        return $return + ['credit_references' => $references, 'link_verified' => true, 'verified_by' => FinanceAccess::actor()];
    }

    public static function present(array $document): array
    {
        if ($document['type'] === 'customer_refund' && $document['status'] !== 'confirmed') {
            $input = $document['payload']; unset($document['payload']['return_context']);
            if (!empty($input['return_document_id'])) { $document['payload']['return_context'] = self::context(FinanceValue::id($input['return_document_id']), FinanceValue::id($input['subject_id'] ?? null)); }
        }
        return $document;
    }
}
