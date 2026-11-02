<?php

declare(strict_types=1);

namespace tests\support;

require_once __DIR__ . '/PreparationIsolatedHttpFixture.php';

/** P10—P18 固定目标适配；不接受外部客户端、检查集、日期或数据范围。 */
final class PreparationRemainingHttpFixture extends PreparationIsolatedHttpFixture
{
    public const TARGETS = ['equipment', 'equipment_refund', 'transit', 'unclaimed', 'recovery', 'excluded', 'capital', 'loan', 'supplier_advance'];
    public const CLIENT = 'docs/ui-previews/remaining-preparation-20261102/implementation/remaining-http-client.mjs';
    public const CONTRACT = 'docs/ui-previews/remaining-preparation-20261102/implementation/remaining-http-contract.json';
    public const SOURCE = 'docs/ui-previews/remaining-preparation-20261102/implementation/remaining-http-source.mjs';

    public static function expectedIds(string $target): array
    {
        if (!in_array($target, self::TARGETS, true)) { throw new \RuntimeException('bridge_unapproved_target'); }
        if ($target === 'excluded') {
            return ['excluded_initial_explanation_only', 'excluded_save_explanation_keeps_conclusion',
                'excluded_none_cancel_zero_write', 'excluded_none_confirm_readback',
                'excluded_later_readback_blocks_completion', 'excluded_later_preserves_explanation',
                'excluded_declared_unresolved_cannot_none', 'excluded_readonly_blocks_writes'];
        }
        $suffixes = ['initial_real_fields', 'master_or_person_identity', 'unknown_save_not_reviewed',
            'readback_update_identity', 'distinct_source_records', 'immutable_saved_source', 'illegal_values_zero_http',
            'required_review_layers', 'domain_difference', 'draft_restore', 'mark_later_preserves_records',
            'none_cancel_zero_write', 'remove_readback', 'complete_readback_progress', 'independent_evidence',
            'cross_store_object_rejected'];
        $ids = array_map(static fn(string $suffix): string => $target . '_' . $suffix, $suffixes);
        if ($target === 'equipment') {
            array_push($ids, 'equipment_shared_unknown_original_command', 'equipment_shared_success_get_only',
                'equipment_shared_receipt_blocks_exit', 'equipment_shared_conflict_retains_original',
                'equipment_shared_deleted_not_new', 'equipment_shared_actor_store_isolation');
        }
        return $ids;
    }

    public static function acceptsContract(array $contract, string $target): bool
    {
        return in_array($target, self::TARGETS, true)
            && ($contract['protocol'] ?? null) === PreparationHttpBridgeProcess::PROTOCOL
            && ($contract['runId'] ?? null) === self::RUN_ID
            && ($contract['cutoffDate'] ?? null) === self::CUTOFF_DATE
            && ($contract['client'] ?? null) === self::CLIENT
            && ($contract['targets'][$target]['coreCheckIds'] ?? null) === self::expectedIds($target)
            && self::expectedIds($target) !== [];
    }

    public static function run(string $target, string $clientPath, string $nodeBinary, array &$evidence): array
    {
        if (!in_array($target, self::TARGETS, true)) { throw new \RuntimeException('bridge_unapproved_target'); }
        return parent::runIsolated($target, $clientPath, $nodeBinary, $evidence);
    }
}
