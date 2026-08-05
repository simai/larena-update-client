<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Runtime;

use Larena\UpdateClient\Contracts\UpdateExecutionStore;
use Larena\UpdateClient\Data\UpdateCycleSnapshot;
use RuntimeException;

final class InMemoryUpdateExecutionStore implements UpdateExecutionStore
{
    /** @var array<string, UpdateCycleSnapshot> */
    private array $cycles = [];

    /** @var array<string, string> */
    private array $idempotency = [];

    public function findByIdempotencyKey(string $idempotencyKey): ?UpdateCycleSnapshot
    {
        $cycleId = $this->idempotency[$idempotencyKey] ?? null;

        return $cycleId === null ? null : ($this->cycles[$cycleId] ?? null);
    }

    public function require(string $cycleId): UpdateCycleSnapshot
    {
        return $this->cycles[$cycleId] ?? throw new RuntimeException('update_cycle_not_found');
    }

    public function create(UpdateCycleSnapshot $snapshot): UpdateCycleSnapshot
    {
        if ($snapshot->revision !== 1
            || isset($this->cycles[$snapshot->cycleId])
            || isset($this->idempotency[$snapshot->request->idempotencyKey])) {
            throw new RuntimeException('update_cycle_duplicate');
        }
        $this->cycles[$snapshot->cycleId] = $snapshot;
        $this->idempotency[$snapshot->request->idempotencyKey] = $snapshot->cycleId;

        return $snapshot;
    }

    public function save(UpdateCycleSnapshot $snapshot, int $expectedRevision): UpdateCycleSnapshot
    {
        $current = $this->require($snapshot->cycleId);
        if ($current->revision !== $expectedRevision
            || $snapshot->revision !== $expectedRevision + 1
            || $snapshot->request->idempotencyKey !== $current->request->idempotencyKey) {
            throw new RuntimeException('update_cycle_stale_state');
        }
        $this->cycles[$snapshot->cycleId] = $snapshot;

        return $snapshot;
    }
}
