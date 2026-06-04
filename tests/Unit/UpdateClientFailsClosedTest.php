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

function update_client_assert_false(bool $condition, string $message): void
{
    if ($condition) {
        throw new RuntimeException($message);
    }
}

$directRegistration = new EntitlementSnapshot('site.entitlement', 'site-1', ['larena.core'], true, true, true, true);
update_client_assert_false($directRegistration->isTrustedLocally(), 'Direct registration contact must fail closed.');

$unredacted = new EntitlementSnapshot('site.entitlement', 'site-1', ['larena.core'], false, true, true);
update_client_assert_false($unredacted->isTrustedLocally(), 'Unredacted entitlement snapshot must fail closed.');

$unsignedManifest = new UpdateManifest('channel.stable.manifest', 'stable', ['larena.core'], '', false);
update_client_assert_false($unsignedManifest->isVerified(), 'Unsigned manifest must fail closed.');

$snapshot = new EntitlementSnapshot('site.entitlement', 'site-1', ['larena.core'], true, true, true);
$manifest = new UpdateManifest('channel.stable.manifest', 'stable', ['larena.core'], 'signature:manifest', true);

$mutatingPlan = new UpdatePlan('site.plan', $manifest, $snapshot, UpdatePlanStatus::ReadyForPreflight, ['larena.core'], ['plan:ok'], true);
update_client_assert_false($mutatingPlan->isValid(), 'Plan that mutates installed site must not be valid in contract skeleton batch.');

$blockedPlan = new UpdatePlan('site.plan', $manifest, $snapshot, UpdatePlanStatus::Blocked, ['larena.core'], ['entitlement:denied']);
update_client_assert_false($blockedPlan->isValid(), 'Blocked plan must fail closed.');

$badArtifact = new ArtifactVerificationResult('artifact.core', 'sha256:example', 'signature:artifact', 'channel.stable.manifest', ArtifactVerificationStatus::Rejected, true, true);
update_client_assert_false($badArtifact->isVerifiedForStaging(), 'Rejected artifact must fail closed.');

$missingRollback = new RollbackBoundary('', '', false, false, true);
update_client_assert_false($missingRollback->isReady(), 'Missing rollback boundary must fail closed.');

$failedPreflight = new PreflightResult(PreflightStatus::Failed, $missingRollback, ['permissions']);
update_client_assert_false($failedPreflight->allowsMutation(), 'Failed preflight must not allow mutation.');

$validPlan = new UpdatePlan('site.plan', $manifest, $snapshot, UpdatePlanStatus::ReadyForPreflight, ['larena.core'], ['plan:ok']);
$unsafeDiagnostics = new UpdateAdminDiagnostics($validPlan, $failedPreflight, ['token=secret'], true, true);
update_client_assert_false($unsafeDiagnostics->isSafeForDisplay(), 'Diagnostics with secret or install authorization must fail closed.');
