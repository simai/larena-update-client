<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Contracts;

interface UpdateClientRuntime
{
    public function verifyEntitlement(EntitlementSnapshot $snapshot): bool;

    public function verifyManifest(UpdateManifest $manifest): bool;

    public function computePlan(UpdateManifest $manifest, EntitlementSnapshot $snapshot): UpdatePlan;

    public function verifyArtifact(ArtifactVerificationResult $artifact): bool;

    public function preflight(UpdatePlan $plan): PreflightResult;
}
