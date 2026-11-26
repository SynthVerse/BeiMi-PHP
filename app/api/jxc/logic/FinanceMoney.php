<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 实际资金事实与账务确认分别保存，交易号只在所属渠道范围内判重。 */
final class FinanceMoney
{
    public function __construct(private readonly int $tenantId, private readonly FinanceLedger $ledger) {}

    public function record(int $documentId, string $type, array $payload, string $direction, string $date, string $amount, string $month, ?array $originalTransaction = null, array $resolvedSourceCategories = []): array
    {
        try { $account = $this->ledger->account(FinanceValue::id($payload['account_id'] ?? 0)); }
        catch (\DomainException $error) { throw FinanceBusinessException::validation([['field' => 'account_id', 'code' => 'unavailable', 'message' => '资金账户无效、已停用或不属于本门店，请重新选择账户']]); }
        $requiresScreenshot = $account['account_type'] !== 'cash' && FinanceBusinessValidation::requiresElectronicScreenshot($type, $resolvedSourceCategories);
        $evidence = $this->evidence($type, $payload, $requiresScreenshot);
        $number = FinanceBusinessValidation::text('transaction_no', $payload['transaction_no'] ?? '', 120, false, '交易号');
        $scope = FinanceBusinessValidation::text('transaction_scope', $payload['transaction_scope'] ?? '', 160, false, '交易所属渠道范围');
        if ($number !== '' && $scope === '') { throw FinanceBusinessException::validation([['field' => 'transaction_scope', 'code' => 'required', 'message' => '填写交易号时须明确银行账户或收款商户范围']]); }
        $externalKey = $number === '' ? null : hash('sha256', FinanceValue::json([$account['account_type'], $scope, $number]));
        if ($originalTransaction) {
            $identity = FinanceValue::decode($originalTransaction['evidence']);
            if (($identity['transaction_no'] ?? '') !== $number || ($identity['transaction_scope'] ?? '') !== $scope) {
                throw new \DomainException('关联更正必须保留原真实交易身份，不能改成另一笔交易');
            }
        } elseif ($externalKey && Db::name('finance_transaction_identity')->where('external_key', $externalKey)->count()) { throw new \DomainException('该渠道交易已登记，录入错误应关联原记录更正'); }
        $similar = Db::name('finance_money_transaction')->where('tenant_id', $this->tenantId)->where('account_id', $account['id'])
            ->where('direction', $direction)->where('amount', $amount)
            ->whereBetween('actual_date', [(new \DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d'), (new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d')])->count();
        $riskReason = FinanceBusinessValidation::text('duplicate_risk_reason', $payload['duplicate_risk_reason'] ?? '', 1000, false, '相似交易继续登记原因');
        if (!$originalTransaction && $number === '' && $similar && $riskReason === '') { throw FinanceBusinessException::validation([['field' => 'duplicate_risk_reason', 'code' => 'required', 'message' => '相近时间有同账户、同方向、同金额记录；核实为不同交易后填写继续登记原因']]); }
        $evidence += ['transaction_no' => $number, 'transaction_scope' => $scope, 'duplicate_risk_reason' => $riskReason];
        $transactionId = $originalTransaction ? (int)$originalTransaction['id'] : (int)Db::name('finance_money_transaction')->insertGetId(['tenant_id' => $this->tenantId, 'document_id' => $documentId,
            'account_id' => $account['id'], 'direction' => $direction, 'amount' => $amount, 'actual_date' => $date,
            'external_key' => null, 'evidence' => FinanceValue::json($evidence), 'create_time' => time()]);
        if ($externalKey) {
            // 旧别名永久保留；唯一键同时串行不同门店对同一外部交易的登记。
            Db::name('finance_transaction_identity')->duplicate(['transaction_id' => Db::raw('transaction_id')])->insert([
                'external_key' => $externalKey, 'tenant_id' => $this->tenantId, 'transaction_id' => $transactionId, 'create_time' => time()]);
            $identity = Db::name('finance_transaction_identity')->where('external_key', $externalKey)->lock(true)->find();
            if ((int)$identity['transaction_id'] !== $transactionId || (int)$identity['tenant_id'] !== $this->tenantId) {
                throw new \DomainException('该渠道交易已登记，录入错误应关联原记录更正');
            }
        }
        $this->ledger->add($documentId, 'cash', (int)$account['id'], ($direction === 'out' ? '-' : '') . $amount, $date, $month, $originalTransaction ? 'correction_replacement' : 'actual_money', '', null,
            ['transaction_id' => $transactionId, 'direction' => $direction, 'type' => $type]);
        return ['transaction_id' => $transactionId, 'account' => $account['name'], 'amount' => $amount, 'actual_date' => $date,
            'posting_month' => $month, 'evidence' => $evidence];
    }

    private function evidence(string $type, array $payload, bool $requiresScreenshot): array
    {
        $ids = $payload['evidence_ids'] ?? [];
        $errors = FinanceBusinessValidation::evidenceErrors($payload, $requiresScreenshot, FinanceAccess::owner());
        if ($errors) { throw FinanceBusinessException::validation($errors); }
        $screenshots = [];
        foreach ($ids as $id) {
            try { $id = FinanceValue::id($id); }
            catch (\DomainException $error) { throw FinanceBusinessException::validation([['field' => 'evidence_ids', 'code' => 'invalid', 'message' => '收付款截图标识无效，请移除后重新上传']]); }
            $row = Db::name('finance_evidence')->where('tenant_id', $this->tenantId)->where('document_type', $type)->where('id', $id)->find();
            if (!$row) { throw FinanceBusinessException::validation([['field' => 'evidence_ids', 'code' => 'unavailable', 'message' => '截图不存在、不属于本门店或不属于当前业务类型，请移除后重新上传']]); }
            $screenshots[] = FinanceEvidence::metadata($row);
        }
        return ['screenshots' => $screenshots, 'missing_screenshot' => $requiresScreenshot && !$screenshots,
            'exception_reason' => trim($payload['missing_evidence_reason'] ?? ''), 'alternative_evidence' => trim($payload['alternative_evidence'] ?? '')];
    }
}
