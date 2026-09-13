<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 同门店互转本金只在账户和在途间移动；明确核实的手续费单独计费。 */
final class FinanceAccountTransfers
{
    public static function out(FinanceLedger $ledger, array $document, array $data, ?array $originalTransaction = null, int $correctingDocument = 0): array
    {
        FinanceAccess::require('', true); $basis = self::basis($data); $tenant = FinanceAccess::tenant();
        $account = $ledger->account(FinanceValue::id($data['account_id'] ?? null)); $target = $ledger->account(FinanceValue::id($data['target_account_id'] ?? null));
        if ((int)$account['id'] === (int)$target['id']) { throw new \DomainException('互转来源和目标须为本门店不同账户'); }
        $date = FinanceValue::date($data['actual_date'] ?? null); $month = $ledger->postingMonth($date); $amount = FinanceValue::money($data['amount'] ?? null);
        $reference = FinanceValue::text($data['source_reference'] ?? null, 160);
        $existing = Db::name('finance_account_transfer')->where('tenant_id', $tenant)->where('source_account_id', $account['id'])->where('source_reference', $reference)->lock(true)->find();
        if ($existing && !self::correctionChainIncludes((int)$existing['document_id'], $correctingDocument)) { throw new \DomainException('此账户的互转来源已登记，请读取原转出记录'); }
        $fee = self::fee($data, $document['type']); $total = bcadd($amount, $fee['amount'], 2);
        $money = (new FinanceMoney($tenant, $ledger))->record((int)$document['id'], $document['type'], $data, 'out', $date, $total, $month, $originalTransaction);
        $snapshot = ['type' => 'account_transfer_out', 'subject_id' => (int)$account['id'], 'subject_name' => $account['name'],
            'source_account_id' => (int)$account['id'], 'source_account_name' => $account['name'], 'target_account_id' => (int)$target['id'], 'target_account_name' => $target['name'],
            'source_reference' => $reference, 'principal' => $amount, 'actual_date' => $date, 'posting_month' => $month, 'extra_fee' => $fee['amount'], 'fee' => $fee,
            'arrived_amount' => '0.00', 'returned_amount' => '0.00', 'withheld_fee' => '0.00', 'remaining_amount' => $amount, 'internal_principal' => $amount] + $basis;
        $source = $ledger->createSource((int)$document['id'], 'transit', (int)$account['id'], $amount, $date, null, $snapshot);
        $ledger->add((int)$document['id'], 'transit', (int)$account['id'], $amount, $date, $month, 'transfer_out', $source, $date, ['internal_transfer' => true]);
        self::recognizeFee($ledger, $document, $fee, $source, $date, $month);
        if (!$existing) {
            Db::name('finance_account_transfer')->insert(['tenant_id' => $tenant, 'document_id' => $document['id'], 'source_account_id' => $account['id'], 'target_account_id' => $target['id'],
                'source_reference' => $reference, 'source_ref' => $source, 'snapshot' => FinanceValue::json($snapshot), 'create_time' => time()]);
        }
        return $snapshot + ['transfer_source' => $source, 'created_sources' => [$source], 'money' => $money];
    }

    /** 同一真实转出可沿更正链重放；原始登记继续防止无关记录占用来源凭据。 */
    private static function correctionChainIncludes(int $documentId, int $correctingDocument): bool
    {
        $candidate = $correctingDocument; $visited = [];
        while ($candidate > 0 && !isset($visited[$candidate])) {
            if ($candidate === $documentId) { return true; }
            $visited[$candidate] = true;
            $candidate = (int)Db::name('finance_correction')->where('tenant_id', FinanceAccess::tenant())->where('replacement_document_id', $candidate)->value('original_document_id');
        }
        return false;
    }

    private static function basis(array $data): array
    {
        if (($data['transfer_verified'] ?? null) !== 1) { throw new \DomainException('请核实本次互转已经实际发生、两端账户与金额'); }
        return ['reason' => FinanceValue::text($data['reason'] ?? null, 1000), 'transfer_verified' => 1, 'confirmed_by' => FinanceAccess::actor(), 'confirmed_at' => time()];
    }

    private static function fee(array $data, string $type): array
    {
        $amount = FinanceValue::money($data['fee_amount'] ?? '0', true);
        if (bccomp($amount, '0', 2) === 0) { return ['amount' => '0.00']; }
        if (($data['fee_verified'] ?? null) !== 1) { throw new \DomainException('手续费须单独核实真实承担金额，不能用未知差额代替'); }
        $vendor = FinanceValue::id($data['fee_vendor_id'] ?? null); $name = Db::name('vendor')->where('tenant_id', FinanceAccess::tenant())->where('id', $vendor)->value('supplier_name');
        if ($name === null) { throw new \DomainException('请选择本门店手续费收款对象'); }
        $reason = FinanceValue::text($data['fee_basis'] ?? null, 1000);
        $line = FinanceExpenseCategories::lines([['category_id' => $data['fee_category_id'] ?? null, 'expected_category_version' => $data['expected_fee_category_version'] ?? null, 'amount' => $amount, 'reason' => $reason]], $amount)[0];
        if ($line['parent'] === 'personnel') { throw new \DomainException('账户互转手续费不能归入人员工资费用'); }
        $material = FinanceExpenses::material(['material_status' => $data['fee_material_status'] ?? null, 'material_verified' => $data['fee_material_verified'] ?? null,
            'missing_material_reason' => $data['fee_missing_material_reason'] ?? null, 'evidence_ids' => ($data['fee_material_status'] ?? '') === 'provided' ? ($data['evidence_ids'] ?? []) : []], $type);
        return $line + $material + ['vendor_id' => $vendor, 'vendor_name' => $name, 'fee_verified' => 1];
    }

    private static function recognizeFee(FinanceLedger $ledger, array $document, array $fee, string $source, string $date, string $month): void
    {
        if (bccomp($fee['amount'], '0', 2) === 0) { return; }
        $ledger->add((int)$document['id'], 'expense', $fee['vendor_id'], $fee['amount'], $date, $month, 'transfer_fee', '', null,
            $fee + ['transfer_source' => $source, 'settled_by_transfer' => true, 'benefit_month' => substr($date, 0, 7)]);
    }

    /** 原结清事实与关联冲正分别保留日期、入账月，供当前余额和历史月末共同使用。 */
    public static function settlements(string $reference, int $correctingDocument = 0): array
    {
        $tenant = FinanceAccess::tenant();
        $rows = Db::name('finance_transfer_settlement')->where('tenant_id', $tenant)->where('source_ref', $reference)->order('id')->select()->toArray();
        $events = [];
        foreach ($rows as $row) {
            if ((int)$row['document_id'] === $correctingDocument) { continue; }
            $events[] = $row + ['settlement_event_id' => (int)$row['id'] * 10];
            $correction = Db::name('finance_correction')->where('tenant_id', $tenant)->where('original_document_id', $row['document_id'])->find();
            if (!$correction) { continue; }
            $reversal = Db::name('finance_entry')->where('tenant_id', $tenant)->where('document_id', $correction['replacement_document_id'])
                ->where('metric', 'balance')->where('purpose', 'correction_reversal')->where('source_ref', $reference)->find();
            if (!$reversal) { throw new \DomainException('互转更正缺少原在途反向依据'); }
            $snapshot = FinanceValue::decode($row['snapshot']);
            $events[] = array_replace($row, ['document_id' => $correction['replacement_document_id'], 'settlement_event_id' => (int)$row['id'] * 10 + 1, 'amount' => bcsub('0', $row['amount'], 2), 'withheld_fee' => bcsub('0', $row['withheld_fee'], 2),
                'snapshot' => FinanceValue::json(array_replace($snapshot, ['actual_date' => $reversal['business_date'], 'posting_month' => $reversal['posting_month'], 'reverses_document_id' => (int)$row['document_id']]))]);
        }
        if ($correction = self::outCorrection($reference)) { $events[] = $correction; }
        foreach (self::rebaseEvents($reference) as $event) { $events[] = $event; }
        return $events;
    }

    /** 更正不重记实际到账或返还；只将已有结清事实投影到替代在途。 */
    public static function rebaseSettlements(FinanceLedger $ledger, int $correctionDocument, string $originalSource, string $replacementSource): array
    {
        $tenant = FinanceAccess::tenant(); $replacement = $ledger->source($replacementSource);
        $totals = ['rebased_arrived_amount' => '0.00', 'rebased_returned_amount' => '0.00', 'rebased_withheld_fee' => '0.00'];
        $rebases = [];
        foreach (self::settlements($originalSource, $correctionDocument) as $event) {
            $settled = FinanceValue::decode($event['snapshot']); $kind = $event['kind'] === 'transfer_settlement_rebase' ? ($settled['settlement_kind'] ?? '') : $event['kind'];
            if (!in_array($kind, ['arrival', 'return'], true)) { continue; }
            $amount = $event['amount']; $fee = $event['withheld_fee']; $consumed = bcadd($amount, $fee, 2);
            if (bccomp($consumed, '0', 2) === 0) { continue; }
            $eventId = (int)($event['settlement_event_id'] ?? $event['document_id']); $actualDate = FinanceValue::date($settled['actual_date'] ?? null); $settlementMonth = FinanceValue::text($settled['posting_month'] ?? null, 7);
            $replacementMonth = FinanceValue::text($replacement['snapshot']['posting_month'] ?? null, 7); $postingMonth = $ledger->postingMonth(max($actualDate, $replacementMonth ? $replacementMonth . '-01' : $actualDate));
            $rebases[] = ['event_id' => $eventId, 'document_id' => (int)$event['document_id'], 'kind' => $kind, 'amount' => $amount, 'fee' => $fee, 'consumed' => $consumed,
                'actual_date' => $actualDate, 'settlement_month' => $settlementMonth, 'posting_month' => $postingMonth, 'snapshot' => $settled];
        }
        foreach ($rebases as $rebase) {
            Db::name('finance_transfer_settlement_rebase')->insert(['tenant_id' => $tenant, 'correction_document_id' => $correctionDocument,
                'original_source_ref' => $originalSource, 'replacement_source_ref' => $replacementSource, 'settlement_document_id' => $rebase['event_id'],
                'kind' => $rebase['kind'], 'amount' => $rebase['amount'], 'withheld_fee' => $rebase['fee'], 'snapshot' => FinanceValue::json($rebase['snapshot'] + ['settlement_kind' => $rebase['kind'], 'settlement_event_id' => $rebase['event_id']]), 'create_time' => time()]);
            $key = $rebase['kind'] === 'arrival' ? 'rebased_arrived_amount' : 'rebased_returned_amount';
            $totals[$key] = bcadd($totals[$key], $rebase['amount'], 2); $totals['rebased_withheld_fee'] = bcadd($totals['rebased_withheld_fee'], $rebase['fee'], 2);
        }
        // 先承接反向事实，避免历史峰值在同一有效结清链中错误限制替代本金。
        usort($rebases, static fn(array $left, array $right): int => bccomp($left['consumed'], '0', 2) === bccomp($right['consumed'], '0', 2) ? 0 : (bccomp($left['consumed'], '0', 2) < 0 ? -1 : 1));
        foreach ($rebases as $rebase) {
            $ledger->add($correctionDocument, 'balance', (int)$replacement['subject_id'], bcsub('0', $rebase['consumed'], 2), $rebase['actual_date'], $rebase['posting_month'],
                'transfer_settlement_rebase', $replacementSource, $rebase['actual_date'], ['transfer_settlement_rebase' => true, 'original_source_ref' => $originalSource,
                    'settlement_document_id' => $rebase['document_id'], 'settlement_event_id' => $rebase['event_id'], 'settlement_kind' => $rebase['kind'], 'settlement_posting_month' => $rebase['settlement_month']]);
        }
        return $totals;
    }

    /** 释放原来源已承接的结清余额，再由完整本金替代；日期仍归属各结清事实。 */
    public static function releaseSettlements(FinanceLedger $ledger, int $correctionDocument, string $source, string $reason): void
    {
        $subject = $ledger->source($source)['subject_id'];
        foreach (self::settlements($source, $correctionDocument) as $event) {
            $settled = FinanceValue::decode($event['snapshot']); $kind = $event['kind'] === 'transfer_settlement_rebase' ? ($settled['settlement_kind'] ?? '') : $event['kind'];
            if (!in_array($kind, ['arrival', 'return'], true)) { continue; }
            $amount = bcadd($event['amount'], $event['withheld_fee'], 2);
            if (bccomp($amount, '0', 2) === 0) { continue; }
            $date = FinanceValue::date($settled['actual_date'] ?? null); $ledger->add($correctionDocument, 'balance', $subject, $amount, $date, $ledger->postingMonth($date),
                'transfer_rebase_release', $source, $date, ['original_source_ref' => $source, 'settlement_document_id' => (int)$event['document_id'],
                    'settlement_event_id' => (int)($event['settlement_event_id'] ?? $event['document_id']), 'reason' => $reason]);
        }
    }

    /** 返回某笔实际结清沿互转更正链承接到的来源和每一段承接关系。 */
    public static function settlementRebasePath(int $documentId, string $source): ?array
    {
        $settlement = Db::name('finance_transfer_settlement')->where('tenant_id', FinanceAccess::tenant())->where('source_ref', $source)->where('document_id', $documentId)->find();
        if (!$settlement) { return null; }
        $eventId = (int)$settlement['id'] * 10; $current = $source; $links = [];
        while ($link = Db::name('finance_transfer_settlement_rebase')->where('tenant_id', FinanceAccess::tenant())->where('original_source_ref', $current)
            ->where('settlement_document_id', $eventId)->order('id')->find()) {
            $links[] = $link; $current = $link['replacement_source_ref'];
        }
        return $links ? ['settlement' => $settlement, 'terminal_source' => $current, 'links' => $links, 'event_id' => $eventId] : null;
    }

    /** 实际结清更正写向最新承接来源，同时在每段承接链补入反向事实。 */
    public static function redirectSettlementCorrection(FinanceLedger $ledger, int $correctionDocument, string $originalSource, array $path, string $reason): void
    {
        $settlement = $path['settlement']; $snapshot = FinanceValue::decode($settlement['snapshot']); $amount = bcadd($settlement['amount'], $settlement['withheld_fee'], 2);
        $date = FinanceValue::date($snapshot['actual_date'] ?? null); $month = $ledger->postingMonth($date); $eventId = (int)$path['event_id'];
        $ledger->add($correctionDocument, 'balance', $ledger->source($originalSource)['subject_id'], bcsub('0', $amount, 2), $date, $month,
            'transfer_rebase_redirect', $originalSource, $date, ['settlement_document_id' => (int)$settlement['document_id'], 'settlement_event_id' => $eventId, 'reason' => $reason]);
        $terminal = $path['terminal_source']; $terminalSnapshot = $ledger->source($terminal)['snapshot']; $terminalMonth = FinanceValue::text($terminalSnapshot['posting_month'] ?? null, 7);
        $ledger->add($correctionDocument, 'balance', $ledger->source($terminal)['subject_id'], $amount, $date,
            $ledger->postingMonth(max($date, $terminalMonth ? $terminalMonth . '-01' : $date)), 'transfer_rebase_redirect', $terminal, $date,
            ['settlement_document_id' => (int)$settlement['document_id'], 'settlement_event_id' => $eventId, 'reason' => $reason]);
        foreach ($path['links'] as $link) {
            Db::name('finance_transfer_settlement_rebase')->insert(['tenant_id' => FinanceAccess::tenant(), 'correction_document_id' => $correctionDocument,
                'original_source_ref' => $link['original_source_ref'], 'replacement_source_ref' => $link['replacement_source_ref'], 'settlement_document_id' => $eventId + 1,
                'kind' => $settlement['kind'], 'amount' => bcsub('0', $settlement['amount'], 2), 'withheld_fee' => bcsub('0', $settlement['withheld_fee'], 2),
                'snapshot' => FinanceValue::json($snapshot + ['settlement_kind' => $settlement['kind'], 'settlement_event_id' => $eventId + 1]), 'create_time' => time()]);
        }
    }

    /** 关联重投影在旧来源抵销、在替代来源承接，展示时保留其非资金性质。 */
    private static function rebaseEvents(string $reference): array
    {
        $rows = Db::name('finance_transfer_settlement_rebase')->where('tenant_id', FinanceAccess::tenant())
            ->whereRaw('(original_source_ref = ? OR replacement_source_ref = ?)', [$reference, $reference])->order('id')->select()->toArray();
        $events = [];
        foreach ($rows as $row) {
            $removesFromOriginal = $row['original_source_ref'] === $reference; $snapshot = FinanceValue::decode($row['snapshot']);
            $events[] = ['document_id' => (int)$row['correction_document_id'], 'settlement_event_id' => (int)($snapshot['settlement_event_id'] ?? $row['settlement_document_id']), 'kind' => 'transfer_settlement_rebase',
                'amount' => $removesFromOriginal ? bcsub('0', $row['amount'], 2) : $row['amount'],
                'withheld_fee' => $removesFromOriginal ? bcsub('0', $row['withheld_fee'], 2) : $row['withheld_fee'],
                'snapshot' => FinanceValue::json($snapshot + ['settlement_kind' => $row['kind'], 'original_source_ref' => $row['original_source_ref'],
                    'replacement_source_ref' => $row['replacement_source_ref'], 'rebase_direction' => $removesFromOriginal ? 'remove' : 'apply'])];
        }
        return $events;
    }

    /** 转出更正冲销旧在途，不把替代事实伪装成到账或返还。 */
    private static function outCorrection(string $reference): ?array
    {
        if (!preg_match('/^n:([1-9][0-9]*)$/D', $reference, $matches)) { return null; }
        $tenant = FinanceAccess::tenant(); $original = (int)Db::name('finance_source')->where('tenant_id', $tenant)->where('id', (int)$matches[1])->value('document_id');
        if (!$original) { return null; }
        $correction = Db::name('finance_correction')->where('tenant_id', $tenant)->where('original_document_id', $original)->find();
        if (!$correction) { return null; }
        $replacement = Db::name('finance_document')->where('tenant_id', $tenant)->where('id', $correction['replacement_document_id'])->find();
        if (!$replacement || $replacement['type'] !== 'account_transfer_out') { throw new \DomainException('互转更正缺少替代转出记录'); }
        $result = FinanceValue::decode($replacement['confirmed_result']); $replacementSource = FinanceValue::text($result['transfer_source'] ?? '', 40);
        $reversal = Db::name('finance_entry')->where('tenant_id', $tenant)->where('document_id', $correction['replacement_document_id'])->where('metric', 'transit')
            ->where('purpose', 'correction_reversal')->where('source_ref', $reference)->find();
        $source = Db::name('finance_entry')->where('tenant_id', $tenant)->where('document_id', $correction['replacement_document_id'])->where('metric', 'balance')
            ->where('purpose', 'correction_source')->where('source_ref', $reference)->find();
        if (!$reversal || !$source || bccomp($reversal['amount'], '0', 2) >= 0 || bccomp($source['amount'], '0', 2) > 0) { throw new \DomainException('互转更正缺少旧在途冲销依据'); }
        return ['document_id' => (int)$correction['replacement_document_id'], 'kind' => 'transfer_out_correction', 'amount' => $reversal['amount'], 'withheld_fee' => '0.00',
            'snapshot' => FinanceValue::json(['actual_date' => $reversal['business_date'], 'posting_month' => $reversal['posting_month'], 'reverses_document_id' => $original,
                'replacement_document_id' => (int)$correction['replacement_document_id'], 'replacement_transfer_source' => $replacementSource])];
    }

    private static function current(FinanceLedger $ledger, string $reference, int $correctingDocument = 0): array
    {
        $source = $ledger->source($reference); $snapshot = $source['snapshot']; $opening = str_starts_with($reference, 'o:');
        if ($source['category'] !== 'transit' || (!$opening && ($snapshot['type'] ?? '') !== 'account_transfer_out')) { throw new \DomainException('请选择本门店合法互转在途来源'); }
        if ($opening) {
            $details = $snapshot['details'] ?? [];
            foreach (['target_account_id', 'principal', 'arrived_amount', 'returned_amount', 'withheld_fee'] as $key) { if (!isset($details[$key])) { throw new \DomainException('期初在途组成尚未核实，不能结清'); } }
            $target = $ledger->account(FinanceValue::id($details['target_account_id']), false); $account = $ledger->account($source['subject_id'], false);
            $snapshot = ['type' => 'opening_transit', 'source_account_id' => $source['subject_id'], 'source_account_name' => $account['name'], 'target_account_id' => (int)$target['id'], 'target_account_name' => $target['name'],
                'subject_id' => $source['subject_id'], 'subject_name' => $account['name'], 'source_reference' => $snapshot['source_reference'], 'actual_date' => $source['business_date'],
                'principal' => $details['principal'], 'arrived_amount' => $details['arrived_amount'], 'returned_amount' => $details['returned_amount'], 'withheld_fee' => $details['withheld_fee'],
                'extra_fee' => null, 'opening_basis' => $snapshot['evidence'] ?? '', 'opening_item_id' => (int)($snapshot['id'] ?? 0)];
        }
        $entries = self::settlements($reference, $correctingDocument); $correctionAmount = '0.00'; $replacementSource = null; $replacementDocument = null;
        $rebasedArrived = '0.00'; $rebasedReturned = '0.00'; $rebasedFee = '0.00';
        foreach ($entries as $entry) {
            if ($entry['kind'] === 'transfer_out_correction') {
                $correctionAmount = bcadd($correctionAmount, $entry['amount'], 2); $correction = FinanceValue::decode($entry['snapshot']);
                $replacementSource = $correction['replacement_transfer_source']; $replacementDocument = (int)$correction['replacement_document_id']; continue;
            }
            if ($entry['kind'] === 'transfer_settlement_rebase') {
                $rebased = FinanceValue::decode($entry['snapshot']); $key = ($rebased['settlement_kind'] ?? '') === 'arrival' ? 'arrival' : 'return';
                if ($key === 'arrival') { $rebasedArrived = bcadd($rebasedArrived, $entry['amount'], 2); } else { $rebasedReturned = bcadd($rebasedReturned, $entry['amount'], 2); }
                $rebasedFee = bcadd($rebasedFee, $entry['withheld_fee'], 2); continue;
            }
            $key = $entry['kind'] === 'arrival' ? 'arrived_amount' : 'returned_amount';
            $snapshot[$key] = bcadd($snapshot[$key], $entry['amount'], 2); $snapshot['withheld_fee'] = bcadd($snapshot['withheld_fee'], $entry['withheld_fee'], 2);
        }
        $remaining = bcsub(bcsub(bcadd($snapshot['principal'], $correctionAmount, 2), $snapshot['arrived_amount'], 2), $snapshot['returned_amount'], 2); $remaining = bcsub($remaining, $snapshot['withheld_fee'], 2);
        $remaining = bcsub(bcsub(bcsub($remaining, $rebasedArrived, 2), $rebasedReturned, 2), $rebasedFee, 2);
        if (bccomp($remaining, $source['balance'], 2) !== 0) { throw new \DomainException('互转在途与到账、返还及手续费组成不一致，请核实原来源'); }
        return array_replace($snapshot, ['transfer_source' => $reference, 'remaining_amount' => $remaining, 'original_document_id' => $source['document_id'],
            'transfer_correction_amount' => $correctionAmount, 'rebased_arrived_amount' => $rebasedArrived, 'rebased_returned_amount' => $rebasedReturned,
            'rebased_withheld_fee' => $rebasedFee, 'replacement_transfer_source' => $replacementSource, 'replacement_document_id' => $replacementDocument]);
    }

    public static function settle(FinanceLedger $ledger, array $document, array $data, ?array $originalTransaction = null, int $correctingDocument = 0): array
    {
        FinanceAccess::require('', true); $basis = self::basis($data); $source = FinanceValue::text($data['source'] ?? null, 40); $current = self::current($ledger, $source, $correctingDocument);
        $arrival = $document['type'] === 'account_transfer_arrival'; $account = $arrival ? $current['target_account_id'] : $current['source_account_id'];
        if (FinanceValue::id($data['account_id'] ?? null) !== $account) { throw new \DomainException('到账须进入原目标账户，实际返还须进入原来源账户'); }
        $date = FinanceValue::date($data['actual_date'] ?? null); $sourceMonth = isset($current['posting_month']) ? FinanceValue::text($current['posting_month'], 7) : ''; $month = $ledger->postingMonth(max($date, $sourceMonth ? $sourceMonth . '-01' : $date));
        if (!$current['actual_date'] || $date < $current['actual_date']) { throw new \DomainException('到账或返还日期不能早于已核实转出日期'); }
        $amount = FinanceValue::money($data['amount'] ?? null, $arrival); $fee = self::fee($data, $document['type']);
        if (!$arrival && bccomp($fee['amount'], '0', 2) !== 0) { throw new \DomainException('返还本金不能夹带未知手续费，原已付未退手续费继续保留'); }
        $consumed = bcadd($amount, $fee['amount'], 2);
        if (bccomp($consumed, '0', 2) <= 0 || bccomp($consumed, $current['remaining_amount'], 2) > 0) { throw new \DomainException('本次到账、实际返还与代扣手续费合计须大于零且不能超过剩余在途'); }
        $money = (new FinanceMoney(FinanceAccess::tenant(), $ledger))->record((int)$document['id'], $document['type'], $data, 'in', $date, $amount, $month, $originalTransaction);
        $ledger->add((int)$document['id'], 'balance', $current['source_account_id'], '-' . $consumed, $date, $month, 'transfer_settlement', $source, $date);
        $ledger->add((int)$document['id'], 'transit', $current['source_account_id'], '-' . $consumed, $date, $month, 'transfer_settlement', $source, $date, ['internal_transfer' => true]);
        self::recognizeFee($ledger, $document, $fee, $source, $date, $month);
        $result = ['type' => $document['type'], 'subject_id' => $current['source_account_id'], 'subject_name' => $current['source_account_name'], 'transfer' => $current,
            'transfer_source' => $source, 'amount' => $amount, 'withheld_fee' => $fee['amount'], 'fee' => $fee, 'consumed_amount' => $consumed,
            'remaining_amount' => bcsub($current['remaining_amount'], $consumed, 2), 'actual_date' => $date, 'posting_month' => $month,
            'internal_principal' => $amount, 'money' => $money, 'created_sources' => []] + $basis;
        Db::name('finance_transfer_settlement')->insert(['tenant_id' => FinanceAccess::tenant(), 'source_ref' => $source, 'document_id' => $document['id'],
            'kind' => $arrival ? 'arrival' : 'return', 'amount' => $amount, 'withheld_fee' => $fee['amount'], 'snapshot' => FinanceValue::json($result), 'create_time' => time()]);
        return $result;
    }

    public static function options(FinanceLedger $ledger, array $params): array
    {
        $exact = !empty($params['transfer_source']); $page = FinanceValue::id($params['page'] ?? 1); $account = FinanceValue::id($params['subject_id'] ?? 0, true);
        $sourcePage = $exact ? ['sources' => [], 'has_more' => false] : $ledger->sourcePage(['transit'], $account, $page);
        $refs = $exact ? [FinanceValue::text($params['transfer_source'], 40)] : array_column($sourcePage['sources'], 'reference');
        $rows = array_map(static fn(string $ref): array => self::current($ledger, $ref), $refs);
        $categories = FinanceExpenseCategories::options();
        return ['sources' => [], 'has_more' => false, 'transfers' => $rows, 'transfer_has_more' => $sourcePage['has_more'],
            'selected_transfer' => $exact ? $rows[0] : null] + ['expense_categories' => array_values(array_filter($categories['expense_categories'], static fn(array $row): bool => $row['parent'] !== 'personnel'))];
    }
}
