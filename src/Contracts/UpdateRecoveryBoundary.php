<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Contracts;

use Larena\UpdateClient\Data\UpdateCycleRequest;
use Larena\UpdateClient\Data\UpdateCycleSnapshot;

interface UpdateRecoveryBoundary
{
    public function prepare(UpdateCycleRequest $request, UpdatePlan $plan): RollbackBoundary;

    public function rollback(UpdateCycleSnapshot $failed): bool;
}
