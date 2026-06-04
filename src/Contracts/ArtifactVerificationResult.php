<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Contracts;

use Larena\UpdateClient\Enums\ArtifactVerificationStatus;

final readonly class ArtifactVerificationResult
{
    public function __construct(
        public string $artifactKey,
        public string $checksum,
        public string $signatureRef,
        public string $manifestBinding,
        public ArtifactVerificationStatus $status,
        public bool $channelMatched,
        public bool $compatible,
    ) {
    }

    public function isVerifiedForStaging(): bool
    {
        return UpdateManifest::isStableKey($this->artifactKey)
            && $this->checksum !== ''
            && $this->signatureRef !== ''
            && UpdateManifest::isStableKey($this->manifestBinding)
            && $this->status === ArtifactVerificationStatus::Verified
            && $this->channelMatched
            && $this->compatible;
    }
}
