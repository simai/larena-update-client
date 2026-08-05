<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Contracts;

use Larena\UpdateClient\Data\UpdateCycleRequest;
use Larena\UpdateClient\Data\UpdateCycleSnapshot;

interface UpdateClientRuntime
{
    public function verifyEntitlement(EntitlementSnapshot $snapshot): bool;

    public function verifyManifest(UpdateManifest $manifest): bool;

    public function computePlan(UpdateManifest $manifest, EntitlementSnapshot $snapshot): UpdatePlan;

    public function verifyArtifact(ArtifactVerificationResult $artifact): bool;

    public function preflight(UpdatePlan $plan): PreflightResult;

    public function run(UpdateCycleRequest $request): UpdateCycleSnapshot;

    public function retry(string $cycleId, int $expectedRevision): UpdateCycleSnapshot;
}
