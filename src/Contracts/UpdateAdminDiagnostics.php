<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Contracts;

final readonly class UpdateAdminDiagnostics
{
    /**
     * @param list<string> $messages
     */
    public function __construct(
        public UpdatePlan $plan,
        public PreflightResult $preflight,
        public array $messages,
        public bool $containsSecret = false,
        public bool $authorizesInstall = false,
    ) {
    }

    public function isSafeForDisplay(): bool
    {
        return $this->plan->isValid()
            && $this->messages !== []
            && !$this->containsSecret
            && !$this->authorizesInstall;
    }
}
