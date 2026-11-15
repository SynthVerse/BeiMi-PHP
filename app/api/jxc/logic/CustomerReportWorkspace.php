<?php
declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;

/** 一次保存的数量事实、金额决定、未完成输入和重试结果使用同一个事务。 */
final class CustomerReportWorkspace
{
    public static function blank(mixed $value): bool { return $value === null || (is_string($value) && trim($value) === ''); }

    public static function field(string $path, callable $validate): mixed
    {
        try { return $validate(); }
        catch (\DomainException $error) { throw FinanceBusinessException::validation([['field' => $path, 'code' => 'invalid', 'message' => $error->getMessage()]]); }
    }

    public static function latest(int $reportId, bool $lock = false): ?array
    {
        return Db::name('customer_report_workspace_save')->where('tenant_id', FinanceAccess::tenant())->where('report_id', $reportId)->order('version desc')->lock($lock)->find();
    }

    public static function hasIncomplete(int $reportId, bool $lock = false): bool { return (self::latest($reportId, $lock)['state'] ?? '') === 'incomplete'; }

    public static function present(int $reportId, array $report): array
    {
        $latest = self::latest($reportId); $state = $latest['state'] ?? 'empty';
        $canQuantities = WorkforceLogic::hasPermission('settlement.weight'); $canSales = FinanceAccess::has('settlement.bill');
        $input = $state === 'incomplete' ? FinanceValue::decode($latest['input']) : null;
        if ($input !== null) {
            if (!$canSales) { unset($input['sales']); }
            if (!$canQuantities) { unset($input['quantities']); }
        }
        $document = $report['sales_document'] ?? null;
        return ['version' => (int)($latest['version'] ?? 0), 'state' => $state, 'input' => $input,
            'missing_fields' => $state === 'incomplete' ? array_values(array_filter(FinanceValue::decode($latest['missing_fields']), static fn($field) => str_starts_with($field['path'], 'sales.') ? $canSales : $canQuantities)) : [],
            'pending_notes' => ($canSales || FinanceAccess::has('settlement.view')) ? CustomerReportSalesNotes::pending($reportId) : [],
            'can_save_quantities' => $canQuantities && in_array($report['status'], ['submitted_ready','submitted_shortage','submitted_pending_measurement','partially_delivered_pending'], true),
            'can_save_sales' => $canSales && $report['status'] !== 'cancelled' && (!$document || !empty($document['can_revise']))];
    }

    public static function save(array $params): array
    {
        $reportId = FinanceValue::id($params['id'] ?? 0); $version = FinanceValue::id($params['version'] ?? 0);
        $workspaceVersion = FinanceValue::id($params['workspace_version'] ?? null, true);
        $key = FinanceValue::text($params['idempotency_key'] ?? '', 96); $tenant = FinanceAccess::tenant();
        $fingerprint = hash('sha256', FinanceValue::json([$params, FinanceAccess::actor()]));
        $hasQuantities = array_key_exists('quantities', $params); $hasSales = array_key_exists('sales', $params);
        if (!$hasQuantities && !$hasSales) { throw new \DomainException('请提交本次需要保存的数量或计价'); }
        if ($hasQuantities && !WorkforceLogic::hasPermission('settlement.weight')) { throw new \DomainException('没有保存数量权限'); }
        if ($hasSales) { FinanceAccess::require('settlement.bill'); }
        foreach (['quantities', 'sales'] as $section) {
            if (array_key_exists($section, $params) && !is_array($params[$section])) { throw new \DomainException('保存内容格式无效'); }
        }
        if (strlen(FinanceValue::json($params)) > 1048576) { throw new \DomainException('本次保存内容超过容量限制'); }
        $result = Db::transaction(static function () use ($params, $reportId, $version, $workspaceVersion, $key, $tenant, $fingerprint, $hasQuantities, $hasSales): array {
            FinanceIntegration::lock();
            $report = Db::name('customer_report')->where('tenant_id', $tenant)->where('id', $reportId)->whereNull('delete_time')->lock(true)->find();
            if (!$report) { throw new \DomainException('报货单不存在或不属于当前门店'); }
            $replay = Db::name('customer_report_workspace_save')->where('tenant_id', $tenant)->where('operation_key', $key)->find();
            if ($replay) {
                if ((int)$replay['report_id'] !== $reportId || !hash_equals($replay['fingerprint'], $fingerprint)) { throw new \DomainException('同一提交标识不能用于不同保存内容或操作人'); }
                return FinanceValue::decode($replay['result']) + ['replayed' => true];
            }
            if ($report['status'] === 'cancelled') { throw new \DomainException('已取消报货不能继续保存'); }
            if ($hasQuantities && !in_array($report['status'], ['submitted_ready','submitted_shortage','submitted_pending_measurement','partially_delivered_pending'], true)) { throw new \DomainException('当前报货已交付，不能修改数量事实'); }
            $latest = self::latest($reportId, true);
            if ((int)$report['version'] !== $version || (int)($latest['version'] ?? 0) !== $workspaceVersion) { throw new \DomainException('报货或已保存输入已变化，请保留输入并核对最新版本'); }
            $document = Db::name('customer_report_sales_document')->where('tenant_id', $tenant)->where('report_id', $reportId)->lock(true)->find();
            if ($hasSales) {
                $documentVersion = FinanceValue::id($params['sales']['document_version'] ?? 0, true);
                if ((int)($document['version'] ?? 0) !== $documentVersion) { throw new \DomainException('销售单版本已变化，请保留输入并核对最新版本'); }
                if ($document && ($document['accounting_status'] === 'posted' || $document['status'] === 'voided')) { throw new \DomainException('已入账或作废的销售单请使用正式更正流程'); }
            }
            $input = ($latest['state'] ?? '') === 'incomplete' ? FinanceValue::decode($latest['input']) : [];
            $missing = ($latest['state'] ?? '') === 'incomplete' ? FinanceValue::decode($latest['missing_fields']) : [];
            if ($hasQuantities) {
                $input['quantities'] = $params['quantities'];
                $missing = array_values(array_filter($missing, static fn($field) => !str_starts_with($field['path'], 'quantities.')));
                [$completeGroups, $quantityMissing, $groupPositions] = self::quantities($report, $params['quantities']);
                $missing = array_merge($missing, $quantityMissing);
                if ($completeGroups) {
                    $savedIds = CustomerReportQuantityService::saveWithinTransaction(['id' => $reportId, 'version' => $version,
                        'idempotency_key' => 'workspace-quantity:' . hash('sha256', $key), 'groups' => $completeGroups,
                        'specification_verifications' => $params['quantities']['specification_verifications'] ?? []]);
                    foreach ($savedIds as $position => $savedId) {
                        $originalPosition = $groupPositions[$position];
                        $input['quantities']['groups'][$originalPosition]['id'] = $savedId;
                        unset($input['quantities']['groups'][$originalPosition]['append_to_group_id']);
                    }
                    $report = Db::name('customer_report')->where('tenant_id', $tenant)->where('id', $reportId)->find();
                }
            }
            $notes = $params['notes'] ?? [];
            if (!is_array($notes) || !array_is_list($notes)) { throw new \DomainException('说明格式无效'); }
            // 尚未补写的可选说明继续留待办，不能反过来阻止本次数字保存。
            $notes = array_values(array_filter($notes, static fn($note) => !is_array($note) || !self::blank($note['content'] ?? null)));
            if ($hasSales) {
                $input['sales'] = $params['sales'];
                $missing = array_values(array_filter($missing, static fn($field) => !str_starts_with($field['path'], 'sales.')));
                FinanceValue::text($params['sales']['revision_reason'] ?? '', 1000, false);
                $snapshot = CustomerReportSalesDocument::inspectWorkspace($report, $params['sales']);
                $missing = array_merge($missing, $snapshot['missing_fields'] ?? []);
                if (empty($snapshot['missing_fields'])) {
                    // 再次保存完整计价即核对页面上已锁定的交付事实；只解除这些分组的旧输入缺项。
                    // 不重新保存数量，也不替未交付分组补造称量。
                    [$input, $missing] = self::useDeliveredFacts($reportId, $input, $missing);
                }
                if (!$missing) {
                    if (!$document || $document['accounting_status'] === 'review_required' || !self::sameApproval(FinanceValue::decode($document['snapshot']), $snapshot)) {
                        CustomerReportSalesDocument::issueWithinTransaction(array_merge($params['sales'], ['id' => $reportId, 'version' => (int)$report['version'], 'idempotency_key' => 'workspace-sale:' . hash('sha256', $key)]));
                        $document = Db::name('customer_report_sales_document')->where('tenant_id', $tenant)->where('report_id', $reportId)->find();
                    } else {
                        $notes = self::mergeNotes($notes, self::inlineNotes($reportId, $document, $params['sales']));
                    }
                }
            }
            if ($notes) {
                CustomerReportSalesNotes::supplementWithinTransaction(['id' => $reportId, 'idempotency_key' => 'workspace-note:' . hash('sha256', $key), 'notes' => $notes]);
            }
            $state = $missing ? 'incomplete' : 'complete';
            $outcome = ['state' => $missing ? 'incomplete' : ($hasSales ? 'complete' : 'quantities_saved')];
            Db::name('customer_report_workspace_save')->insert(['tenant_id' => $tenant, 'report_id' => $reportId, 'version' => $workspaceVersion + 1,
                'report_version' => (int)$report['version'], 'document_version' => (int)($document['version'] ?? 0), 'state' => $state,
                'input' => FinanceValue::json($input), 'missing_fields' => FinanceValue::json($missing), 'actor' => FinanceValue::json(FinanceAccess::actor()),
                'operation_key' => $key, 'fingerprint' => $fingerprint, 'result' => FinanceValue::json($outcome), 'create_time' => time()]);
            return $outcome + ['replayed' => false];
        });
        // 自动入账失败由原状态与待办承接，不撤销已成功的保存，也不制造第二笔金额。
        try { CustomerReportSalesDocument::afterReportChange($reportId); }
        catch (\Throwable $error) { throw new \RuntimeException('保存已提交，后续状态尚未读取完成', 0, $error); }
        return $result;
    }

    private static function useDeliveredFacts(int $reportId, array $input, array $missing): array
    {
        $tenant = FinanceAccess::tenant();
        foreach ($input['quantities']['groups'] ?? [] as $position => $entry) {
            $prefix = 'quantities.groups.' . $position . '.';
            if (!array_filter($missing, static fn($field) => str_starts_with($field['path'], $prefix))) { continue; }
            $groupId = (int)($entry['id'] ?? 0);
            if ($groupId <= 0) { continue; }
            $group = Db::name('customer_report_processing_group')->where('tenant_id', $tenant)->where('report_id', $reportId)->where('id', $groupId)->lock(true)->find();
            if (!$group) { continue; }
            $delivered = Db::name('fulfillment_delivery_item')->where('tenant_id', $tenant)->where('report_id', $reportId)
                ->where('report_item_id', $group['report_item_id'])->where('delivery_group_key', 'report:' . $reportId . ':group:' . $groupId)->lock(true)->find();
            if (!$delivered || (int)$delivered['fact_version'] !== 2) { continue; }
            $facts = FinanceValue::decode($delivered['quantity_facts_snapshot']);
            $restored = ['id' => $groupId, 'quantity_facts' => FinanceValue::decode($delivered['quantity_facts'])];
            if (isset($facts['packaging'])) {
                $restored['packaging'] = array_intersect_key($facts['packaging'], array_flip(['source_id','unit_id','quantity','intact']));
            } elseif (isset($facts['net_weight_input']['gross'], $facts['net_weight_input']['tare'])) {
                $restored['gross_weight_input'] = $facts['net_weight_input']['gross'];
                $restored['tare_weight_input'] = $facts['net_weight_input']['tare'];
            } elseif (isset($facts['net_weight_input']['value'])) {
                $restored['net_weight_input'] = $facts['net_weight_input'];
            } elseif ($delivered['actual_delivery_weight'] !== null) {
                $restored['net_weight_input'] = ['value' => $delivered['actual_delivery_weight'], 'unit' => '斤'];
            }
            $input['quantities']['groups'][$position] = $restored;
            $missing = array_values(array_filter($missing, static fn($field) => !str_starts_with($field['path'], $prefix)));
        }
        return [$input, $missing];
    }

    private static function sameApproval(array $before, array $after): bool
    {
        foreach (['issued_at', 'approval_permissions', 'approval_owner_required'] as $key) { unset($before[$key], $after[$key]); }
        foreach (['before', 'after'] as $name) {
            unset($$name['precision']['actor'], $$name['precision']['confirmed_at']);
            foreach ($$name['lines'] as &$line) { unset($line['zero_price_reason'], $line['difference_reason']); }
            unset($line);
        }
        return $before == $after;
    }

    private static function inlineNotes(int $reportId, array $document, array $sales): array
    {
        $notes = []; $lines = array_column($sales['lines'], null, 'report_item_id');
        foreach (CustomerReportSalesNotes::pending($reportId) as $pending) {
            if ($pending['document_version'] !== (int)$document['version']) { continue; }
            $value = $pending['type'] === 'revision_reason' ? ($sales['revision_reason'] ?? '') : ($lines[$pending['report_item_id']][$pending['type']] ?? '');
            if (self::blank($value)) { continue; }
            $notes[] = array_intersect_key($pending, array_flip(['revision_id','report_item_id','type'])) + ['content' => $value];
        }
        return $notes;
    }

    private static function mergeNotes(array $explicit, array $inline): array
    {
        $result = [];
        foreach (array_merge($explicit, $inline) as $note) {
            if (!is_array($note)) { throw new \DomainException('说明格式无效'); }
            $identity = ($note['revision_id'] ?? 0) . ':' . ($note['report_item_id'] ?? 0) . ':' . ($note['type'] ?? '');
            if (isset($result[$identity]) && trim((string)$result[$identity]['content']) !== trim((string)($note['content'] ?? ''))) { throw new \DomainException('同一说明不能提交不同内容'); }
            $result[$identity] = $note;
        }
        return array_values($result);
    }

    /** 未完整分组保留输入；已完整分组仍复用原数量事实服务的全部业务校验。 */
    private static function quantities(array $report, array $input): array
    {
        $groups = $input['groups'] ?? null;
        if (!is_array($groups) || !array_is_list($groups) || count($groups) > 600) { throw new \DomainException('数量分组格式无效'); }
        $tenant = FinanceAccess::tenant();
        $items = Db::name('customer_report_item')->where('tenant_id', $tenant)->where('report_id', $report['id'])->whereNull('delete_time')->select()->toArray();
        $items = array_column($items, null, 'id');
        $known = array_column(Db::name('customer_report_processing_group')->where('tenant_id', $tenant)->where('report_id', $report['id'])->select()->toArray(), null, 'id');
        $complete = []; $positions = []; $missing = []; $seen = [];
        foreach ($groups as $position => $entry) {
            if (!is_array($entry)) { throw new \DomainException('数量分组格式无效'); }
            if (isset($entry['processing'])) { throw new \DomainException('本页只记录交付数量，加工损耗请使用独立库存处理'); }
            $id = FinanceValue::id($entry['id'] ?? 0, true); $sourceId = $id ?: FinanceValue::id($entry['append_to_group_id'] ?? 0);
            if (!isset($known[$sourceId], $items[$known[$sourceId]['report_item_id']]) || ($id && isset($seen[$id]))) { throw new \DomainException('数量分组不属于本单或重复'); }
            if ($id) { $seen[$id] = true; }
            $item = $items[$known[$sourceId]['report_item_id']]; $units = TradeQuantityService::boundUnits($item);
            $path = 'quantities.groups.' . $position;
            $fields = self::quantityMissing($entry, $item, $units, $path);
            if ($id && $fields) {
                $existing = TradeQuantityService::snapshot($known[$id]);
                if ($existing && Db::name('fulfillment_delivery_item')->where('tenant_id', $tenant)->where('delivery_group_key', $existing['delivery_group_key'])->count()) { throw new \DomainException('已交付分组不能覆盖，请新增剩余补交分组'); }
            }
            if ($fields) { $missing = array_merge($missing, $fields); continue; }
            if ($id && !isset($entry['packaging']) && empty($input['specification_verifications'][$item['id']])) {
                $normalized = TradeQuantityFacts::normalize($entry, $item, $units); $existing = TradeQuantityService::snapshot($known[$id]);
                $keys = array_flip(['actual_delivery_weight','inventory_base_quantity','quantity_facts','processing']);
                if ($existing && array_intersect_key($existing, $keys) == array_intersect_key($normalized, $keys)) { continue; }
            }
            $complete[] = $entry;
            $positions[] = $position;
        }
        return [$complete, $missing, $positions];
    }

    private static function quantityMissing(array $entry, array $item, array $units, string $path): array
    {
        $missing = []; $counts = []; $weightAvailable = false;
        $rawCounts = $entry['quantity_facts'] ?? [];
        if (!is_array($rawCounts) || !array_is_list($rawCounts)) { throw new \DomainException('计数事实格式无效'); }
        foreach ($rawCounts as $i => $count) {
            if (!is_array($count)) { throw new \DomainException('计数事实格式无效'); }
            $unitId = FinanceValue::id($count['unit_id'] ?? 0);
            if (!isset($units[$unitId]) || $units[$unitId]['measure_type'] !== 'count' || array_key_exists($unitId, $counts) || ($count['source'] ?? 'counted') !== 'counted') { throw new \DomainException('计数事实单位未绑定、重复或未经实际清点'); }
            $counts[$unitId] = !self::blank($count['quantity'] ?? null);
            if (!$counts[$unitId]) { $missing[] = ['path' => $path . '.quantity_facts.' . $i . '.quantity', 'message' => '请补充实际清点数量']; }
            else self::field($path . '.quantity_facts.' . $i . '.quantity', static fn() => TradeQuantityFacts::decimal($count['quantity'], (int)$units[$unitId]['quantity_precision']));
        }
        if (isset($entry['packaging'])) {
            if (!is_array($entry['packaging']) || isset($entry['net_weight_input']) || isset($entry['gross_weight_input']) || !self::blank($entry['final_actual_weight'] ?? null)) { throw new \DomainException('完整包装与实际称量只能采用一种来源'); }
            FinanceValue::id($entry['packaging']['source_id'] ?? 0); FinanceValue::id($entry['packaging']['unit_id'] ?? 0);
            if (($entry['packaging']['intact'] ?? false) !== true) { throw new \DomainException('包装完整状态尚未核实，请使用实际称量'); }
            // 包装来源由已有服务核实，不把未经验证的包装推断成真实重量。
            if (self::blank($entry['packaging']['quantity'] ?? null)) { $missing[] = ['path' => $path . '.packaging.quantity', 'message' => '请补充完整包装数量']; }
            else { GoodsPackagingSourceService::verify($entry['packaging'], $item); $weightAvailable = true; }
        }
        if (isset($entry['net_weight_input']) && isset($entry['gross_weight_input'])) { throw new \DomainException('净重和毛皮重只能采用一份称量来源'); }
        foreach (['net_weight_input', 'gross_weight_input', 'tare_weight_input'] as $field) {
            if (!isset($entry[$field])) { continue; }
            if (!is_array($entry[$field]) || !in_array(mb_strtolower(trim((string)($entry[$field]['unit'] ?? ''))), ['斤','jin','kg','公斤','千克','g','克','两'], true)) { throw new \DomainException('秤单位须为克、公斤或斤'); }
            if (self::blank($entry[$field]['value'] ?? null)) { $missing[] = ['path' => $path . '.' . $field . '.value', 'message' => '请补充本次称量数值']; continue; }
            $value = self::field($path . '.' . $field . '.value', static fn() => TradeQuantityFacts::decimal($entry[$field]['value'], 4, $field === 'tare_weight_input'));
            if (bccomp($value, '0', 4) > 0) TradeQuantityFacts::weightToJin($entry[$field]['value'], $entry[$field]['unit']);
            if ($field !== 'tare_weight_input') $weightAvailable = true;
        }
        if (isset($entry['gross_weight_input']) && !isset($entry['tare_weight_input'])) { $missing[] = ['path' => $path . '.tare_weight_input.value', 'message' => '请补充皮重']; }
        if (isset($entry['gross_weight_input'], $entry['tare_weight_input']) && !self::blank($entry['gross_weight_input']['value'] ?? null) && !self::blank($entry['tare_weight_input']['value'] ?? null)) {
            $gross = TradeQuantityFacts::weightToJin($entry['gross_weight_input']['value'], $entry['gross_weight_input']['unit']);
            $tare = bccomp($entry['tare_weight_input']['value'], '0', 4) === 0 ? '0' : TradeQuantityFacts::weightToJin($entry['tare_weight_input']['value'], $entry['tare_weight_input']['unit']);
            if (bccomp($gross, $tare, 4) <= 0) { throw FinanceBusinessException::validation([['field' => $path . '.tare_weight_input.value', 'code' => 'invalid', 'message' => '皮重不能大于或等于毛重']]); }
        }
        if (!self::blank($entry['final_actual_weight'] ?? null)) { TradeQuantityFacts::decimal($entry['final_actual_weight']); $weightAvailable = true; }
        foreach (array_unique([(int)$item['base_unit_id'], (int)$item['unit_id']]) as $unitId) {
            $unit = $units[$unitId] ?? null;
            if (!$unit) { throw new \DomainException('报货或库存单位配置缺失'); }
            if ($unit['measure_type'] === 'count' && empty($counts[$unitId]) && !isset($entry['packaging'])) { $missing[] = ['path' => $path . '.quantity_facts', 'message' => '请补充' . $unit['unit_name'] . '的实际清点数量']; }
        }
        if ($units[(int)$item['base_unit_id']]['measure_type'] === 'weight' && !$weightAvailable) { $missing[] = ['path' => $path . '.net_weight_input.value', 'message' => '请补充交付净重']; }
        // 空白是未完成；已填的附加数字仍须合法，不能借其他字段空白绕过校验。
        foreach (['inventory_base_quantity', 'processing_loss_weight'] as $field) {
            if (array_key_exists($field, $entry) && !self::blank($entry[$field])) TradeQuantityFacts::decimal($entry[$field], 4, $field === 'processing_loss_weight');
        }
        return $missing;
    }
}
