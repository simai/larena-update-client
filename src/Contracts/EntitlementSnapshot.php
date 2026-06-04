<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Contracts;

final readonly class EntitlementSnapshot
{
    /**
     * @param list<string> $capabilities
     */
    public function __construct(
        public string $snapshotKey,
        public string $siteIdentity,
        public array $capabilities,
        public bool $redacted,
        public bool $signed,
        public bool $signatureVerified,
        public bool $directRegistrationContact = false,
    ) {
    }

    public function isTrustedLocally(): bool
    {
        return UpdateManifest::isStableKey($this->snapshotKey)
            && $this->siteIdentity !== ''
            && $this->capabilities !== []
            && $this->redacted
            && $this->signed
            && $this->signatureVerified
            && !$this->directRegistrationContact;
    }
}
