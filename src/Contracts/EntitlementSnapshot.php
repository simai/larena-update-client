<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Contracts;

use DateTimeImmutable;
use Larena\Licensing\Contracts\EntitlementSnapshot as LicensingEntitlementSnapshot;
use Larena\Licensing\Enums\EntitlementStatus;

final readonly class EntitlementSnapshot implements LicensingEntitlementSnapshot
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
        public EntitlementStatus $entitlementStatus = EntitlementStatus::Active,
        public string $signingKeyId = 'update.platform',
        public string $signatureValue = 'signature:entitlement',
        public ?DateTimeImmutable $validUntil = null,
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
            && !$this->directRegistrationContact
            && $this->entitlementStatus->canUnlockPaidCapabilities()
            && trim($this->signingKeyId) !== ''
            && trim($this->signatureValue) !== '';
    }

    public function status(): EntitlementStatus
    {
        return $this->entitlementStatus;
    }

    public function keyId(): string
    {
        return $this->signingKeyId;
    }

    public function signature(): string
    {
        return $this->signatureValue;
    }

    public function capabilityKeys(): array
    {
        return $this->capabilities;
    }

    public function expiresAt(): ?DateTimeImmutable
    {
        return $this->validUntil;
    }
}
