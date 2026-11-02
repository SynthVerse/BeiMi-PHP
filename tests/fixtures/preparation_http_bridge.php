<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '0');
// 无 --execute 时只做静态就绪检查：不加载应用、不连接 DB、不启动 HTTP/客户端。
$projectRoot = dirname(__DIR__, 2);
$nodeBinary = (string)getenv('JXC_PREPARATION_BRIDGE_NODE');
require_once dirname(__DIR__) . '/support/IsolatedDatabaseGuard.php';
require_once dirname(__DIR__) . '/support/PreparationHttpBridgeProcess.php';
require_once dirname(__DIR__) . '/support/PreparationCurrentHttpSource.php';
require_once dirname(__DIR__) . '/support/ExpenseRefundHttpFixture.php';

$arguments = array_slice($argv, 1);
$mode = null; $target = null;
foreach ($arguments as $argument) {
    if (in_array($argument, ['--preflight', '--execute'], true) && $mode === null) {
        $mode = $argument;
    } elseif (preg_match('/^--target=(payable|customer_refund|advance|supplier_refund|salary|reimbursement|expense_payable|deferred|expense_refund)$/D', $argument, $match) === 1 && $target === null) {
        $target = $match[1];
    } else {
        fwrite(STDERR, "bridge_invalid_arguments\n");
        exit(2);
    }
}
$mode ??= '--preflight'; $target ??= 'payable';
// 仅允许这些固定测试客户端；不接受外部路径、URL、身份或任意事项。
$clients = [
    'payable' => '/BeiMi-uniapp/docs/ui-previews/payable-preparation-20261023/implementation/http-bridge-client.mjs',
    'customer_refund' => '/BeiMi-uniapp/docs/ui-previews/customer-refund-preparation-20261023/implementation/http-bridge-client.mjs',
    'advance' => '/BeiMi-uniapp/docs/ui-previews/advance-preparation-20261023/implementation/advance-http-bridge-client.mjs',
    'supplier_refund' => '/BeiMi-uniapp/docs/ui-previews/supplier-refund-preparation-20261023/implementation/supplier-refund-http-bridge-client.mjs',
    'salary' => '/BeiMi-uniapp/docs/ui-previews/salary-preparation-20261023/implementation/salary-http-client-20261025.mjs',
    'reimbursement' => '/BeiMi-uniapp/docs/ui-previews/reimbursement-preparation-20261023/implementation/reimbursement-http-client-20261026.mjs',
    'expense_payable' => '/BeiMi-uniapp/docs/ui-previews/expense-payable-preparation-20261023/implementation/expense-payable-http-client-20261026.mjs',
    'deferred' => '/BeiMi-uniapp/docs/ui-previews/deferred-preparation-20261023/implementation/deferred-http-client-20261026.mjs',
    'expense_refund' => '/BeiMi-uniapp/docs/ui-previews/expense-refund-preparation-20261102/implementation/expense-refund-http-client.mjs',
];
$clientPath = dirname($projectRoot) . $clients[$target];
$frontendRoot = dirname($projectRoot) . '/BeiMi-uniapp';
$fixedTargets = [
    'expense_refund' => [
        'revision' => 'expense-refund-joint-http-20261102-01', 'prefix' => 'expense-refund',
        'checks' => [
            'expense_refund_initial_real_fields', 'supplier_original_receipt', 'master_receipt_actor_isolation',
            'detail_no_due_date_optional_refund', 'unknown_amount_save_review_layers', 'refund_confirmation_native_wire',
            'supplier_transition_resets_confirmation', 'same_supplier_amount_record_identity', 'immutable_saved_source',
            'illegal_values_zero_http', 'definite_rejection_preserves_input', 'financial_unknown_original_command',
            'success_get_only', 'receipt_storage_blocks_exit', 'store_draft_cold_restore', 'conflict_preserves_original_id',
            'deleted_record_not_new', 'mark_later_preserves_saved_input', 'remove_success_get_only', 'complete_expense_refund',
            'independent_evidence_keeps_review', 'supplier_unknown_not_created',
        ],
    ],
    'supplier_refund' => [
        'revision' => 'takeover-20261025-supplier-refund-joint-http-01', 'prefix' => 'supplier-refund', 'sourceCount' => 80,
        'handoff' => [
            'path' => 'docs/ui-previews/supplier-refund-preparation-20261023/implementation/supplier-refund-http-handoff-20261025.json',
            'sha256' => '2c29c6b2c50b9020ca97455ecb70a1ef60fd3610c0e92da8963c134990dfb640',
        ],
        'checks' => [
            'initial_supplier_refund_guide', 'native_supplier_exact_receipt', 'detail_no_due_date_optional_refund',
            'unknown_amount_null_blocks_review', 'supplier_id_confirmation_transition', 'same_supplier_same_amount_record_identity',
            'owner_manual_unlinked_rebind', 'positive_amount_validation', 'financial_unknown_original_command', 'success_get_only',
            'receipt_storage_blocks_exit', 'store_draft_local_retention', 'conflict_explicit_field_rebase', 'conflict_adopt_latest',
            'deleted_explicit_new', 'mark_later_preserves_saved_input', 'complete_supplier_refund', 'evidence_preserves_saved_review',
        ],
    ],
    'salary' => [
        'revision' => 'takeover-20261025-salary-joint-http-02', 'prefix' => 'salary', 'sourceCount' => 81,
        'handoff' => [
            'path' => 'docs/ui-previews/salary-preparation-20261023/implementation/salary-http-handoff-20261025.json',
            'sha256' => 'f8007ed3a1b8c77e5843f5b04ede07ab5dde4a1945dd3fe64acfa14f33b2f818',
        ],
        'checks' => [
            'salary_initial_guide', 'employee_full_form_original_receipt', 'employee_same_name_distinct_ids',
            'unknown_amount_null', 'missing_month_save_blocks_complete', 'cutoff_month_complete',
            'future_month_save_blocks_complete', 'summary_self_edit_and_group', 'empty_month_group',
            'multiple_details_and_distinct_employees', 'second_record_edit_store_restore', 'positive_amount_frontend_guard',
            'code0_keeps_original_input', 'financial_unknown_original_command', 'success_readback_get_only',
            'receipt_storage_blocks_exit', 'local_input_restore', 'salary_view_prepare_permissions',
            'salary_revoked_pending_filters_restore', 'complete_salary', 'independent_evidence_keeps_complete', 'master_unknown_not_created',
        ],
    ],
    'reimbursement' => [
        'revision' => 'takeover-20261026-reimbursement-joint-http-01', 'prefix' => 'reimbursement', 'sourceCount' => 81,
        'handoff' => [
            'path' => 'docs/ui-previews/reimbursement-preparation-20261023/implementation/reimbursement-http-handoff-20261026.json',
            'sha256' => 'c8b091dea0d077764caa48b604559c46c4370bce0780c54c862f80e53cdc9f5d',
        ],
        'checks' => [
            'reimbursement_initial_guide', 'employee_full_form_original_receipt', 'employee_same_name_distinct_ids',
            'unknown_amount_null', 'missing_fee_month_save_blocks_complete', 'cutoff_month_complete',
            'future_month_save_blocks_complete', 'summary_self_edit_fee_not_group_key', 'empty_month_group',
            'same_amount_details_distinct_ids', 'second_record_edit_store_restore', 'positive_amount_frontend_guard',
            'code0_keeps_original_input', 'financial_unknown_original_command', 'remove_rejected_original_identity',
            'remove_success_readback_get_only', 'remove_receipt_failure_blocks_exit', 'local_input_restore',
            'ordinary_financial_employee_access', 'complete_reimbursement', 'independent_evidence_keeps_complete', 'master_unknown_not_created',
        ],
    ],
    'expense_payable' => [
        'revision' => 'takeover-20261026-expense-payable-joint-http-01', 'prefix' => 'expense-payable', 'sourceCount' => 81,
        'handoff' => [
            'path' => 'docs/ui-previews/expense-payable-preparation-20261023/implementation/expense-payable-http-handoff-20261026.json',
            'sha256' => '7f45bb56b3e73fb9a7ce93edd4cdaad901aa13bbee10cdd9f1a4dacbac46811a',
        ],
        'checks' => [
            'expense_initial_guide', 'supplier_full_form_original_receipt', 'master_receipt_actor_isolation',
            'unknown_amount_null', 'positive_amount_frontend_guard', 'missing_description_save_blocks_complete',
            'historical_date_save_review_layers', 'summary_self_edit', 'summary_to_detail_blocked',
            'summary_to_summary_blocked', 'detail_to_summary_blocked', 'detail_records_same_amount_distinct_id',
            'second_record_store_restore', 'financial_unknown_original_command', 'success_readback_get_only',
            'receipt_failure_blocks_exit', 'complete_expense', 'independent_evidence_keeps_complete',
            'source_identity_version_handoff', 'deferred_real_id_reference', 'deferred_cumulative_exact',
            'deferred_edit_excludes_self', 'deferred_over_limit', 'deferred_unknown_source',
            'deferred_changed_subject', 'deferred_lowered_amount', 'deferred_removed_source',
            'deferred_same_name_no_inheritance', 'deferred_reused_reference_not_inherit', 'supplier_unknown_not_created',
        ],
    ],
    'deferred' => [
        'revision' => 'deferred-joint-http-01', 'prefix' => 'deferred', 'sourceCount' => 101,
        'handoff' => [
            'path' => 'docs/ui-previews/deferred-preparation-20261023/implementation/frontend-stage-handoff-20261026.json',
            'sha256' => '17aba336739fcbeeeaea72317948f9d9a6ec0f4374f32b124290ab7df9cf28f3',
        ],
        'contract' => [
            'path' => 'docs/ui-previews/deferred-preparation-20261023/implementation/deferred-http-contract-20261026.json',
            'sha256' => 'd4041867ade2cef517f1c42a356d24827f9c9ae2538f16d74f70ceaf3f85b7bf',
        ],
        'manifest' => [
            'path' => 'docs/ui-previews/deferred-preparation-20261023/implementation/deferred-http-sources-20261026.json',
            'sha256' => '4dacb886bd7d36658170e2f1b922ed8810a11acaaa56693bd2571ca4f010bf13',
        ],
        'clientSha256' => '9d76cd8c19fa1a68060ce39233023d24360491e4c90e5ff9764d5b57a029f0dc',
        'checks' => [
            'deferred_initial_real_fields', 'supplier_original_receipt', 'master_receipt_actor_isolation',
            'upstream_real_source_handoff', 'remaining_only_real_wire', 'full_history_real_wire',
            'illegal_values_zero_http', 'manual_121_zero_http', 'unknown_required_save_review_layers',
            'zero_month_save_review_layers', 'history_equalities_save_review_layers', 'schedule_semantics_save_review_layers',
            'service_before_activation_save_review_layers', 'schedule_native_confirmation', 'real_source_id_reference',
            'source_cumulative_exact', 'source_edit_excludes_self_zero', 'source_over_limit',
            'first_read_vs_server_baseline', 'second_record_cold_restore', 'financial_unknown_original_command',
            'success_get_only', 'receipt_storage_blocks_exit', 'complete_deferred',
            'independent_evidence_keeps_progress', 'source_unknown_amount', 'source_changed_subject',
            'source_lowered_amount', 'source_removed', 'same_name_no_inheritance',
            'reused_reference_no_inheritance', 'supplier_unknown_not_created',
        ],
    ],
];
$fixedTarget = $fixedTargets[$target] ?? null;
$sourceHandoff = $fixedTarget['handoff'] ?? null; $frontendSourceWhitelist = []; $frozenSourcesValid = true;
$sourceContract = $fixedTarget['contract'] ?? null; $sourceManifest = $fixedTarget['manifest'] ?? null;
$componentToolchainSources = []; $componentToolchainValid = false;
$currentSourceAdmission = null;
$useCurrentSources = $target === 'expense_refund' || in_array($target, ['supplier_refund', 'salary', 'reimbursement', 'expense_payable', 'deferred'], true) && \tests\support\PreparationCurrentHttpSource::requested();
if ($useCurrentSources) {
    $frozenSourcesValid = false;
    try {
        $currentSourceAdmission = \tests\support\PreparationCurrentHttpSource::forTarget($target, $frontendRoot, $nodeBinary, $fixedTarget['checks']);
        $frontendSourceWhitelist = $currentSourceAdmission['sources'];
        unset($currentSourceAdmission['sources']);
        $frozenSourcesValid = true;
    } catch (\Throwable $error) { /* 显式 current 准入失败必须关闭，不回退历史 pin。 */ }
} elseif ($fixedTarget) {
    $handoffPath = $frontendRoot . '/' . $sourceHandoff['path'];
    $frozenSourcesValid = false;
    // 先匹配固定交接哈希，再读取其中的固定来源；外部参数不能替换清单或检查编号。
    if (is_file($handoffPath) && hash_equals($sourceHandoff['sha256'], (string)@hash_file('sha256', $handoffPath))) {
        $manifest = json_decode((string)file_get_contents($handoffPath), true);
        if ($target === 'deferred') {
            $handoff = $manifest; $documents = [];
            foreach (['contract' => $sourceContract, 'manifest' => $sourceManifest] as $key => $document) {
                $path = $frontendRoot . '/' . $document['path'];
                if (is_file($path) && hash_equals($document['sha256'], (string)@hash_file('sha256', $path))) {
                    $documents[$key] = json_decode((string)file_get_contents($path), true);
                }
            }
            $contract = $documents['contract'] ?? null; $manifest = $documents['manifest'] ?? null;
            $valid = is_array($handoff) && is_array($contract) && is_array($manifest)
                && ($handoff['runId'] ?? null) === 'preparation-development-20261024-01'
                && ($handoff['dispatchId'] ?? null) === 'takeover-20261026-deferred-frontend-01'
                && ($handoff['sharedFrontendWritesStopped'] ?? false) === true && ($handoff['allTaskWritesStopped'] ?? false) === true
                && ($handoff['http']['sourceFrozen'] ?? false) === true && ($handoff['http']['businessCaseCount'] ?? null) === 32
                && ($handoff['http']['sourceManifest'] ?? null) === $sourceManifest['path']
                && ($handoff['http']['sourceManifestSha256'] ?? null) === $sourceManifest['sha256']
                && ($handoff['http']['projectSources'] ?? null) === 101 && ($handoff['http']['componentToolchainSources'] ?? null) === 6
                && ($contract['runId'] ?? null) === $handoff['runId'] && ($manifest['runId'] ?? null) === $handoff['runId']
                && ($contract['dispatchId'] ?? null) === $handoff['dispatchId'] && ($manifest['dispatchId'] ?? null) === $handoff['dispatchId']
                && ($contract['protocol'] ?? null) === \tests\support\PreparationHttpBridgeProcess::PROTOCOL
                && ($contract['sourceFrozen'] ?? false) === true && ($contract['sourceSha256'] ?? null) === $sourceManifest['sha256']
                && ($contract['sourceManifest'] ?? null) === basename($sourceManifest['path'])
                && is_array($contract['coreChecks'] ?? null) && array_is_list($contract['coreChecks'])
                && array_column($contract['coreChecks'], 'id') === $fixedTarget['checks']
                && hash_equals($fixedTarget['clientSha256'], (string)@hash_file('sha256', $clientPath));
        } else {
            $valid = is_array($manifest) && ($manifest['protocol'] ?? null) === \tests\support\PreparationHttpBridgeProcess::PROTOCOL
                && ($manifest['runId'] ?? null) === 'preparation-development-20261024-01'
                && ($manifest['clientContractFrozen'] ?? false) === true && ($manifest['frontendSourcesDeclaredFrozen'] ?? false) === true
                && ($manifest['expectedCoreCheckCount'] ?? null) === count($fixedTarget['checks']) && ($manifest['coreCheckIds'] ?? null) === $fixedTarget['checks'];
        }
        if ($valid) {
            $rootPrefix = str_replace('\\', '/', (string)realpath($frontendRoot)) . '/';
            foreach ($target === 'deferred' ? ['sources'] : ['clientFiles', 'supportFiles', 'sources'] as $group) {
                if (!isset($manifest[$group]) || !is_array($manifest[$group]) || !array_is_list($manifest[$group])) { $valid = false; break; }
                foreach ($manifest[$group] as $source) {
                    $path = $source['path'] ?? null; $hash = $source['sha256'] ?? null;
                    if (!is_string($path) || preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.\/-]*$/D', $path) !== 1
                        || array_intersect(explode('/', $path), ['.', '..']) || !is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) { $valid = false; break 2; }
                    $resolved = str_replace('\\', '/', (string)realpath($frontendRoot . '/' . $path));
                    if ($resolved === '' || strncasecmp($resolved, $rootPrefix, strlen($rootPrefix)) !== 0
                        || !is_file($resolved) || isset($frontendSourceWhitelist[$path]) && ($target === 'deferred' || $frontendSourceWhitelist[$path] !== $hash)) { $valid = false; break 2; }
                    $frontendSourceWhitelist[$path] = $hash;
                }
            }
        }
        if ($valid && count($frontendSourceWhitelist) === $fixedTarget['sourceCount']) {
            $frozenSourcesValid = true;
            foreach ($frontendSourceWhitelist as $path => $hash) {
                if (!hash_equals($hash, (string)@hash_file('sha256', $frontendRoot . '/' . $path))) { $frozenSourcesValid = false; }
            }
        } else { $frontendSourceWhitelist = []; }
    }
}
if ($target === 'deferred') {
    // 外部工具链与项目 current/历史选择分开核验，仍只信固定历史 manifest 的六条路径和 SHA。
    $toolchainManifestPath = $frontendRoot . '/' . $sourceManifest['path'];
    if (is_file($toolchainManifestPath) && hash_equals($sourceManifest['sha256'], (string)@hash_file('sha256', $toolchainManifestPath))) {
        $toolchainManifest = json_decode((string)file_get_contents($toolchainManifestPath), true);
        $toolchainFiles = [
            'D:/HBuilderX/plugins/uniapp-cli/node_modules/process/browser.js',
            'D:/HBuilderX/plugins/uniapp-cli/node_modules/setimmediate/setImmediate.js',
            'D:/HBuilderX/plugins/uniapp-cli/node_modules/timers-browserify/main.js',
            'D:/HBuilderX/plugins/uniapp-cli/node_modules/vue/dist/vue.common.dev.js',
            'D:/HBuilderX/plugins/uniapp-cli/node_modules/vue/dist/vue.common.js',
            'D:/HBuilderX/plugins/uniapp-cli/node_modules/webpack/buildin/global.js',
        ];
        $toolchain = $toolchainManifest['componentToolchainSources'] ?? null;
        if (is_array($toolchain) && array_is_list($toolchain) && array_column($toolchain, 'file') === $toolchainFiles) {
            $componentToolchainValid = true;
            foreach ($toolchain as $source) {
                $file = $source['file']; $hash = $source['sha256'] ?? null;
                if (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) { $componentToolchainValid = false; break; }
                $componentToolchainSources[$file] = $hash;
                $resolved = str_replace('\\', '/', (string)realpath($file));
                if (strcasecmp($resolved, $file) !== 0 || !is_file($resolved)
                    || !hash_equals($hash, (string)@hash_file('sha256', $resolved))) { $componentToolchainValid = false; }
            }
        }
    }
}
$environment = is_file($projectRoot . '/.env.testing')
    ? (parse_ini_file($projectRoot . '/.env.testing', true, INI_SCANNER_RAW) ?: []) : [];
$checks = [
    ['id' => 'isolated-environment-contract', 'passed' => \tests\support\IsolatedDatabaseGuard::acceptsEnvironment($environment)],
    ['id' => 'frontend-client-present', 'passed' => is_file($clientPath)],
    ['id' => 'node-binary-present', 'passed' => $nodeBinary !== '' && is_file($nodeBinary) && realpath($nodeBinary) !== false],
    ['id' => 'phpunit-autoload-present', 'passed' => is_file($projectRoot . '/vendor/autoload.php')],
    ['id' => 'scope-lock-directory-present', 'passed' => is_dir($projectRoot . '/.local')],
];
if ($fixedTarget) { $checks[] = ['id' => $fixedTarget['prefix'] . '-frozen-sources', 'passed' => $frozenSourcesValid]; }
if ($target === 'expense_refund') {
    $contractPath = $frontendRoot . '/docs/ui-previews/expense-refund-preparation-20261102/implementation/expense-refund-http-contract.json';
    $contract = is_file($contractPath) ? json_decode((string)file_get_contents($contractPath), true) : null;
    $checks[] = ['id' => 'expense-refund-fixture-contract', 'passed' => is_array($contract)
        && \tests\support\ExpenseRefundHttpFixture::acceptsContract($contract, $fixedTarget['checks'])];
}
if ($target === 'deferred') { $checks[] = ['id' => 'deferred-component-toolchain-sources', 'passed' => $componentToolchainValid]; }
$sources = ['tests/fixtures/preparation_http_bridge.php', 'tests/fixtures/preparation_http_router.php',
    'tests/fixtures/preparation_test_bootstrap.php', 'tests/support/PreparationHttpBridgeProcess.php',
    'tests/support/PreparationCurrentHttpSource.php',
    'tests/support/ExpenseRefundHttpFixture.php', 'tests/support/PreparationIsolatedHttpFixture.php', 'tests/bridge/ExpenseRefundHttpBridgeAdmissionTest.php',
    'tests/support/IsolatedDatabaseGuard.php', 'tests/unit/PreparationHttpWorkflowTest.php', 'tests/bootstrap.php',
    'tests/bridge/PreparationHttpBridgeProcessTest.php',
    'app/api/jxc/logic/FinanceOpeningService.php', 'app/api/jxc/logic/FinanceOpeningAssets.php',
    'app/api/jxc/logic/FinanceSetupLogic.php', 'app/api/jxc/logic/MasterCreationReceipt.php', 'app/api/route/jxc.php'];
if (in_array($target, ['supplier_refund', 'salary', 'deferred'], true)) {
    array_push($sources, 'tests/bridge/PreparationCurrentHttpSourceTest.php', 'tests/fixtures/preparation_current_http_manifest.json');
}
$fingerprints = static function () use ($sources, $projectRoot, $clientPath, $frontendRoot, $sourceHandoff, $frontendSourceWhitelist, $sourceContract, $sourceManifest, $componentToolchainSources, $currentSourceAdmission): array {
    $hashes = [];
    foreach ($sources as $source) { $hashes['BeiMi-PHP/' . $source] = hash_file('sha256', $projectRoot . '/' . $source); }
    $hashes['frontend-client'] = is_file($clientPath) ? hash_file('sha256', $clientPath) : null;
    if ($currentSourceAdmission) {
        $hashes['current-source-manifest'] = hash_file('sha256', $currentSourceAdmission['manifest']['path']);
    }
    $fixedSources = $frontendSourceWhitelist;
    if ($sourceHandoff) { $fixedSources[$sourceHandoff['path']] = $sourceHandoff['sha256']; }
    foreach ([$sourceContract, $sourceManifest] as $document) {
        if ($document) { $fixedSources[$document['path']] = $document['sha256']; }
    }
    $rootPrefix = str_replace('\\', '/', (string)realpath($frontendRoot)) . '/';
    foreach ($fixedSources as $path => $expected) {
        $resolved = str_replace('\\', '/', (string)realpath($frontendRoot . '/' . $path));
        $hashes['BeiMi-uniapp/' . $path] = $resolved !== '' && strncasecmp($resolved, $rootPrefix, strlen($rootPrefix)) === 0
            && is_file($resolved) ? hash_file('sha256', $resolved) : null;
    }
    foreach ($componentToolchainSources as $file => $expected) {
        $resolved = str_replace('\\', '/', (string)realpath($file));
        $hashes['component-toolchain/' . $file] = strcasecmp($resolved, $file) === 0 && is_file($resolved) ? hash_file('sha256', $resolved) : null;
    }
    return $hashes;
};
$result = ['revision' => 'hb-20261025T034124-backend-decoded-token-guard',
    'protocol' => \tests\support\PreparationHttpBridgeProcess::PROTOCOL,
    'runId' => 'preparation-development-20261024-01', 'phase' => 'preflight', 'jointExecuted' => false,
    'passed' => !in_array(false, array_column($checks, 'passed'), true), 'checks' => $checks,
    'sourceFingerprints' => $fingerprints()];
if ($target !== 'payable') { $result['target'] = $target; }
if ($target === 'advance') {
    $result['expectedCheckIds'] = [
        'initial_advance_guide', 'native_customer_exact_receipt', 'main_customer_subject_eligibility',
        'advance_detail_no_due_date', 'unknown_amount_null_blocks_review', 'owner_manual_unlinked_rebind',
        'positive_amount_validation', 'financial_unknown_original_command', 'success_get_only',
        'receipt_storage_blocks_exit', 'store_draft_local_retention', 'conflict_explicit_field_rebase',
        'deleted_explicit_new', 'complete_advance', 'evidence_preserves_saved_review',
    ];
}
if ($fixedTarget) {
    $result['revision'] = $fixedTarget['revision'];
    $result['expectedCheckIds'] = $fixedTarget['checks'];
    $result['sourceHandoff'] = $sourceHandoff;
    $result['frontendSourceWhitelist'] = $frontendSourceWhitelist;
}
if ($currentSourceAdmission) { $result['sourceAdmission'] = $currentSourceAdmission; }
if ($target === 'deferred') {
    $result['sourceContract'] = $sourceContract; $result['sourceManifest'] = $sourceManifest;
    $result['componentToolchainSources'] = $componentToolchainSources;
}

if ($mode === '--execute' && $result['passed']) {
    $result['phase'] = 'execution';
    // 原 bootstrap 仍完整验证专用 .env.testing；不加载根 .env，不更改 guard。
    $_SERVER['JXC_PHPUNIT_ENV'] = 'testing';
    $fixtureEvidence = [];
    ob_start();
    try {
        require __DIR__ . '/preparation_test_bootstrap.php';
        \think\facade\Log::close(array_keys(\think\facade\Config::get('log.channels', [])));
        $result['jointExecutionAttempted'] = true;
        if ($target === 'expense_refund') {
            require_once dirname(__DIR__) . '/support/ExpenseRefundHttpFixture.php';
            $result['clientResult'] = \tests\support\ExpenseRefundHttpFixture::run($clientPath, $nodeBinary, $fixtureEvidence);
        } else {
            require dirname(__DIR__) . '/unit/PreparationHttpWorkflowTest.php';
            $result['clientResult'] = \tests\unit\PreparationHttpWorkflowTest::runFrontendHttpBridge($clientPath, $nodeBinary, $target, $fixtureEvidence);
        }
        $result['jointExecuted'] = true;
        $expectedChecksMatch = true;
        if ($target === 'advance' || $fixedTarget) {
            $expectedChecksMatch = \tests\support\PreparationHttpBridgeProcess::hasExactCheckIds(
                $result['clientResult']['checks'], $result['expectedCheckIds']);
            if (in_array($target, ['reimbursement', 'expense_payable', 'deferred', 'expense_refund'], true)) {
                $expectedChecksMatch = $expectedChecksMatch
                    && array_column($result['clientResult']['checks'], 'id') === $result['expectedCheckIds'];
            }
            $result['checks'][] = ['id' => $target === 'advance' ? 'advance-expected-check-set' : $fixedTarget['prefix'] . '-expected-check-set', 'passed' => $expectedChecksMatch];
        }
        $unchanged = $result['sourceFingerprints'] === $fingerprints();
        $result['checks'][] = ['id' => 'sources-unchanged-during-execution', 'passed' => $unchanged];
        $result['passed'] = $result['clientResult']['passed'] && $unchanged && $expectedChecksMatch;
    } catch (\Throwable $error) {
        $result['passed'] = false;
        // 仅允许本驱动固定错误编号。数据库/框架异常及原体绝不透传。
        $message = $error->getMessage();
        $result['error'] = preg_match('/^bridge_[a-z_]+$/D', $message) === 1 ? $message : 'bridge_execution_failed';
        fwrite(STDERR, $result['error'] . "\n");
    } finally {
        if (in_array($target, ['salary', 'reimbursement', 'expense_payable', 'deferred', 'expense_refund'], true) && ($result['jointExecutionAttempted'] ?? false)) {
            $evidenceKey = $target === 'expense_payable' ? 'expensePayableFixtureEvidence' : $target . 'FixtureEvidence';
            $fixtureCheck = $target === 'expense_payable' ? 'expense-payable-fixture-restored' : $target . '-fixture-restored';
            $result[$evidenceKey] = $fixtureEvidence;
            $fixtureRestored = ($fixtureEvidence['verified'] ?? false) === true;
            $result['checks'][] = ['id' => $fixtureCheck, 'passed' => $fixtureRestored];
            $result['passed'] = $result['passed'] && $fixtureRestored;
        }
        ob_end_clean();
    }
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
exit($result['passed'] ? 0 : 1);
