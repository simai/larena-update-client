<?php

declare(strict_types=1);

use Larena\UpdateClient\Contracts\ArtifactVerificationResult;
use Larena\UpdateClient\Contracts\EntitlementSnapshot;
use Larena\UpdateClient\Contracts\PreflightResult;
use Larena\UpdateClient\Contracts\RollbackBoundary;
use Larena\UpdateClient\Contracts\UpdateAdminDiagnostics;
use Larena\UpdateClient\Contracts\UpdateManifest;
use Larena\UpdateClient\Contracts\UpdatePlan;
use Larena\UpdateClient\Enums\ArtifactVerificationStatus;
use Larena\UpdateClient\Enums\PreflightStatus;
use Larena\UpdateClient\Enums\UpdatePlanStatus;

function update_client_assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$snapshot = new EntitlementSnapshot('site.entitlement', 'site-1', ['larena.core'], true, true, true);
update_client_assert_true($snapshot->isTrustedLocally(), 'Signed redacted entitlement snapshot must be trusted locally.');

$manifest = new UpdateManifest('channel.stable.manifest', 'stable', ['larena.core'], 'signature:manifest', true);
update_client_assert_true($manifest->isVerified(), 'Signed manifest must be verified.');

$plan = new UpdatePlan('site.plan', $manifest, $snapshot, UpdatePlanStatus::ReadyForPreflight, ['larena.core'], ['plan:ok']);
update_client_assert_true($plan->isValid(), 'Non-mutating signed update plan must be valid for preflight.');

$artifact = new ArtifactVerificationResult('artifact.core', 'sha256:example', 'signature:artifact', 'channel.stable.manifest', ArtifactVerificationStatus::Verified, true, true);
update_client_assert_true($artifact->isVerifiedForStaging(), 'Verified artifact must be eligible for staging consideration.');

$rollback = new RollbackBoundary('backup.plan', 'backup.snapshot', true, true, false);
update_client_assert_true($rollback->isReady(), 'Rollback boundary must be ready after dry-run and snapshot verification.');

$preflight = new PreflightResult(PreflightStatus::Passed, $rollback, ['permissions', 'versions']);
update_client_assert_true($preflight->allowsMutation(), 'Passed preflight with rollback boundary must allow future mutation.');

$diagnostics = new UpdateAdminDiagnostics($plan, $preflight, ['Update plan is ready for preflight.']);
update_client_assert_true($diagnostics->isSafeForDisplay(), 'Diagnostics must be safe for display when no secrets or install authorization exist.');
