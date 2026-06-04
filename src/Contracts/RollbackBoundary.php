<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Contracts;

final readonly class RollbackBoundary
{
    public function __construct(
        public string $backupPlanRef,
        public string $snapshotRef,
        public bool $dryRunCompleted,
        public bool $irreversibleStepsDeclared,
        public bool $approvalRequired,
    ) {
    }

    public function isReady(): bool
    {
        return UpdateManifest::isStableKey($this->backupPlanRef)
            && UpdateManifest::isStableKey($this->snapshotRef)
            && $this->dryRunCompleted
            && $this->irreversibleStepsDeclared
            && !$this->approvalRequired;
    }
}
