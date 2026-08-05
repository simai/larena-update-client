<?php

declare(strict_types=1);

$requiredFiles = [
    '.gitignore',
    '.env.example',
    '.github/workflows/larena-package-ci.yml',
    '.githooks/pre-commit',
    '.githooks/pre-push',
    'composer.json',
    'module.yaml',
    'phpstan.neon.dist',
    '.larena/spec-ref.json',
    '.larena/launch-context.json',
    'tools/larena-scope-check.php',
];
$errors = [];
foreach ($requiredFiles as $file) {
    if (!is_file($file)) {
        $errors[] = "Missing required enforcement file: {$file}";
    }
}
$specRef = is_file('.larena/spec-ref.json')
    ? json_decode((string) file_get_contents('.larena/spec-ref.json'), true, 512, JSON_THROW_ON_ERROR)
    : [];
$launchContext = is_file('.larena/launch-context.json')
    ? json_decode((string) file_get_contents('.larena/launch-context.json'), true, 512, JSON_THROW_ON_ERROR)
    : [];
if (($specRef['canonical_update_allowed'] ?? null) !== false) {
    $errors[] = '.larena/spec-ref.json must keep canonical_update_allowed=false';
}
if (($launchContext['package'] ?? null) !== 'larena/update-client') {
    $errors[] = '.larena/launch-context.json package must be larena/update-client';
}
if (!str_starts_with((string) ($launchContext['evidence_path'] ?? ''), 'docs/project-management/evidence/')) {
    $errors[] = 'launch-context evidence_path must start with docs/project-management/evidence/';
}
if (!str_starts_with((string) ($launchContext['graph_sync_proposal_path'] ?? ''), (string) ($launchContext['evidence_path'] ?? '__missing__'))) {
    $errors[] = 'graph_sync_proposal_path must be inside evidence_path';
}
$allowedStatuses = [
    'repository_prepared_pending_review',
    'coding_started',
    'contract_skeleton_review_passed',
    'authorized_for_source_fixture_implementation',
];
if (!in_array((string) ($launchContext['status'] ?? ''), $allowedStatuses, true)) {
    $errors[] = 'launch-context status is not allowed for this package stage.';
}
$codingStarted = ($launchContext['coding_started'] ?? null) === true;
if (!$codingStarted) {
    foreach (['src', 'config', 'database', 'routes', 'resources', 'tests', 'lang'] as $runtimePath) {
        if (is_dir($runtimePath)) {
            $errors[] = "{$runtimePath}/ is not allowed before a coding launch record.";
        }
    }
}
if ($codingStarted) {
    $contractSkeletonLaunch = 'specs/implementation-planning/launch-records/update-client-batch-1-contract-skeletons-current.json';
    $ownershipReconciliationLaunch = 'specs/project-management/larena-g4-8-update-ownership-reconciliation-launch.json';
    $launchRecord = (string) ($launchContext['launch_record_ref'] ?? '');
    $requiredContractFiles = [
        'src/Contracts/ArtifactVerificationResult.php',
        'src/Contracts/EntitlementSnapshot.php',
        'src/Contracts/PreflightResult.php',
        'src/Contracts/RollbackBoundary.php',
        'src/Contracts/UpdateAdminDiagnostics.php',
        'src/Contracts/UpdateClientRuntime.php',
        'src/Contracts/UpdateManifest.php',
        'src/Contracts/UpdatePlan.php',
        'src/Enums/ArtifactVerificationStatus.php',
        'src/Enums/PreflightStatus.php',
        'src/Enums/UpdatePlanStatus.php',
        'tests/Unit/UpdateClientContractTest.php',
        'tests/Unit/UpdateClientFailsClosedTest.php',
    ];
    if ($launchRecord === $ownershipReconciliationLaunch) {
        if (($launchContext['status'] ?? null) !== 'authorized_for_source_fixture_implementation') {
            $errors[] = 'G4.8 ownership reconciliation requires its authorized source-fixture status.';
        }
        if (($launchContext['base_commit'] ?? null) !== '9a3a75b8c88ed8490f9e58bac2916a75f4c1f854') {
            $errors[] = 'G4.8 ownership reconciliation base commit differs from the sealed launch.';
        }
        $expectedFeatures = [
            'update.admin_diagnostics',
            'update.artifact_verification',
            'update.entitlement_proxy',
            'update.installer_orchestration',
            'update.manifest_plan',
            'update.preflight_rollback',
        ];
        $selectedFeatures = $launchContext['selected_features'] ?? [];
        sort($expectedFeatures);
        if (!is_array($selectedFeatures)) {
            $errors[] = 'G4.8 selected feature closure must be an array.';
        } else {
            sort($selectedFeatures);
        }
        if (!is_array($selectedFeatures) || $selectedFeatures !== $expectedFeatures) {
            $errors[] = 'G4.8 selected feature closure differs from the sealed launch.';
        }
        $requiredContractFiles = array_merge($requiredContractFiles, [
            'src/Contracts/UpdateExecutionStore.php',
            'src/Contracts/UpdateLifecycleAudit.php',
            'src/Contracts/UpdateRecoveryBoundary.php',
            'src/Data/UpdateCycleRequest.php',
            'src/Data/UpdateCycleSnapshot.php',
            'src/Enums/UpdateCycleState.php',
            'src/Runtime/GovernedUpdateClientRuntime.php',
            'src/Runtime/InMemoryUpdateExecutionStore.php',
            'src/Runtime/RecordingUpdateLifecycleAudit.php',
            'tests/Unit/GovernedUpdateClientRuntimeTest.php',
            'tests/Unit/GovernedUpdateClientRuntimeFailsClosedTest.php',
            'tests/Unit/GovernedUpdateClientRuntimeReplayTest.php',
        ]);
    } elseif ($launchRecord !== $contractSkeletonLaunch) {
        $errors[] = 'coding_started requires a recognized sealed update-client launch record.';
    }
    foreach ($requiredContractFiles as $file) {
        if (!is_file($file)) {
            $errors[] = "Missing required update-client contract skeleton file: {$file}";
        }
    }
}
if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, $error . PHP_EOL);
    }
    exit(1);
}
echo $codingStarted
    ? "Larena Update Client sealed launch context is valid.\n"
    : "Larena Update Client clean pre-codegen baseline is valid.\n";
