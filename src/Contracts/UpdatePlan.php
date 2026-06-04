<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Contracts;

use Larena\UpdateClient\Enums\UpdatePlanStatus;

final readonly class UpdatePlan
{
    /**
     * @param list<string> $dependencyOrder
     * @param list<string> $diagnostics
     */
    public function __construct(
        public string $planKey,
        public UpdateManifest $manifest,
        public EntitlementSnapshot $entitlement,
        public UpdatePlanStatus $status,
        public array $dependencyOrder,
        public array $diagnostics,
        public bool $mutatesInstalledSite = false,
    ) {
    }

    public function isValid(): bool
    {
        return UpdateManifest::isStableKey($this->planKey)
            && $this->manifest->isVerified()
            && $this->entitlement->isTrustedLocally()
            && $this->dependencyOrder !== []
            && $this->diagnostics !== []
            && !$this->mutatesInstalledSite
            && $this->status !== UpdatePlanStatus::Blocked;
    }
}
