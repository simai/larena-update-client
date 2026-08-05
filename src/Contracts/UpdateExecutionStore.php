<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Contracts;

use Larena\UpdateClient\Data\UpdateCycleSnapshot;

interface UpdateExecutionStore
{
    public function findByIdempotencyKey(string $idempotencyKey): ?UpdateCycleSnapshot;

    public function require(string $cycleId): UpdateCycleSnapshot;

    public function create(UpdateCycleSnapshot $snapshot): UpdateCycleSnapshot;

    public function save(UpdateCycleSnapshot $snapshot, int $expectedRevision): UpdateCycleSnapshot;
}
