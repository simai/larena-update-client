<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Contracts;

use Larena\UpdateClient\Data\UpdateCycleSnapshot;

interface UpdateLifecycleAudit
{
    /**
     * @param array<array-key, mixed> $safeFacts Untrusted facts validated by the sink.
     */
    public function record(string $event, UpdateCycleSnapshot $snapshot, array $safeFacts = []): string;
}
