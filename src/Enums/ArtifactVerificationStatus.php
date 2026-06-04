<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Enums;

enum ArtifactVerificationStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
}
