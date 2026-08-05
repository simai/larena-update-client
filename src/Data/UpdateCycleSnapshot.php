<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Data;

use InvalidArgumentException;
use Larena\UpdateClient\Contracts\RollbackBoundary;
use Larena\UpdateClient\Enums\UpdateCycleState;

final readonly class UpdateCycleSnapshot
{
    /**
     * @param list<string> $completedSteps
     */
    public function __construct(
        public string $cycleId,
        public UpdateCycleRequest $request,
        public UpdateCycleState $state,
        public int $attempt,
        public int $revision,
        public int $nextStepIndex,
        public array $completedSteps = [],
        public ?string $failedStep = null,
        public ?string $reasonCode = null,
        public ?RollbackBoundary $rollbackBoundary = null,
    ) {
        if (preg_match('/\A[a-f0-9]{32}\z/D', $this->cycleId) !== 1 || $this->cycleId !== $this->request->cycleId()) {
            throw new InvalidArgumentException('update_cycle_id_invalid');
        }
        if ($this->attempt < 1 || $this->revision < 1 || $this->nextStepIndex < 0 || $this->nextStepIndex > count($this->request->steps)) {
            throw new InvalidArgumentException('update_cycle_state_invalid');
        }
        if ($this->completedSteps !== array_slice($this->request->steps, 0, $this->nextStepIndex)) {
            throw new InvalidArgumentException('update_cycle_completed_steps_invalid');
        }
        if ($this->state === UpdateCycleState::Completed && $this->nextStepIndex !== count($this->request->steps)) {
            throw new InvalidArgumentException('update_cycle_completion_invalid');
        }
        if ($this->state === UpdateCycleState::RecoverableFailed) {
            if ($this->failedStep !== ($this->request->steps[$this->nextStepIndex] ?? null) || !$this->rollbackBoundary?->isReady()) {
                throw new InvalidArgumentException('update_cycle_recovery_state_invalid');
            }
        }
        if (in_array($this->state, [UpdateCycleState::Blocked, UpdateCycleState::RecoverableFailed, UpdateCycleState::ManualRecoveryRequired], true)
            && ($this->reasonCode === null || preg_match('/\A[a-z][a-z0-9_.-]{0,99}\z/D', $this->reasonCode) !== 1)) {
            throw new InvalidArgumentException('update_cycle_reason_invalid');
        }
    }

    /**
     * @param list<string> $completedSteps
     */
    public function transition(
        UpdateCycleState $state,
        int $nextStepIndex,
        array $completedSteps,
        ?string $failedStep = null,
        ?string $reasonCode = null,
        ?RollbackBoundary $rollbackBoundary = null,
        ?int $attempt = null,
    ): self {
        return new self(
            $this->cycleId,
            $this->request,
            $state,
            $attempt ?? $this->attempt,
            $this->revision + 1,
            $nextStepIndex,
            $completedSteps,
            $failedStep,
            $reasonCode,
            $rollbackBoundary,
        );
    }
}
