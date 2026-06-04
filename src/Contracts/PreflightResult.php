<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Contracts;

use Larena\UpdateClient\Enums\PreflightStatus;

final readonly class PreflightResult
{
    /**
     * @param list<string> $checks
     * @param list<string> $warnings
     */
    public function __construct(
        public PreflightStatus $status,
        public RollbackBoundary $rollbackBoundary,
        public array $checks,
        public array $warnings = [],
    ) {
    }

    public function allowsMutation(): bool
    {
        return $this->status === PreflightStatus::Passed
            && $this->checks !== []
            && $this->rollbackBoundary->isReady();
    }
}
