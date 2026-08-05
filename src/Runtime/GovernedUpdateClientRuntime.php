<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Runtime;

use Larena\Access\Contracts\QueryScopeProvider;
use Larena\Core\Contracts\OperationContext;
use Larena\Core\Contracts\OperationDescriptor;
use Larena\Core\Contracts\OperationRuntime;
use Larena\Core\Enums\OperationExecutionMode;
use Larena\Filesystem\Contracts\FilesystemRuntime;
use Larena\Filesystem\Enums\FileDecisionStatus;
use Larena\Filesystem\Runtime\ArrayLogicalFileMutation;
use Larena\Licensing\Contracts\LicensingRuntime;
use Larena\UpdateClient\Contracts\ArtifactVerificationResult;
use Larena\UpdateClient\Contracts\EntitlementSnapshot;
use Larena\UpdateClient\Contracts\PreflightResult;
use Larena\UpdateClient\Contracts\RollbackBoundary;
use Larena\UpdateClient\Contracts\UpdateClientRuntime;
use Larena\UpdateClient\Contracts\UpdateExecutionStore;
use Larena\UpdateClient\Contracts\UpdateLifecycleAudit;
use Larena\UpdateClient\Contracts\UpdateManifest;
use Larena\UpdateClient\Contracts\UpdatePlan;
use Larena\UpdateClient\Contracts\UpdateRecoveryBoundary;
use Larena\UpdateClient\Data\UpdateCycleRequest;
use Larena\UpdateClient\Data\UpdateCycleSnapshot;
use Larena\UpdateClient\Enums\PreflightStatus;
use Larena\UpdateClient\Enums\UpdateCycleState;
use Larena\UpdateClient\Enums\UpdatePlanStatus;
use Throwable;

final readonly class GovernedUpdateClientRuntime implements UpdateClientRuntime
{
    public function __construct(
        private QueryScopeProvider $queryScope,
        private LicensingRuntime $licensing,
        private FilesystemRuntime $filesystem,
        private OperationRuntime $operations,
        private UpdateRecoveryBoundary $recovery,
        private UpdateLifecycleAudit $audit,
        private UpdateExecutionStore $store,
    ) {
    }

    public function verifyEntitlement(EntitlementSnapshot $snapshot): bool
    {
        return $snapshot->isTrustedLocally();
    }

    public function verifyManifest(UpdateManifest $manifest): bool
    {
        return $manifest->isVerified();
    }

    public function computePlan(UpdateManifest $manifest, EntitlementSnapshot $snapshot): UpdatePlan
    {
        $valid = $this->verifyManifest($manifest) && $this->verifyEntitlement($snapshot);
        $order = $manifest->packageRefs;
        sort($order, SORT_STRING);

        return new UpdatePlan(
            planKey: 'update.plan.'.substr(hash('sha256', $manifest->manifestKey.'|'.$snapshot->snapshotKey), 0, 16),
            manifest: $manifest,
            entitlement: $snapshot,
            status: $valid ? UpdatePlanStatus::ReadyForPreflight : UpdatePlanStatus::Blocked,
            dependencyOrder: $valid ? $order : [],
            diagnostics: [$valid ? 'plan:verified' : 'plan:blocked'],
        );
    }

    public function verifyArtifact(ArtifactVerificationResult $artifact): bool
    {
        return $artifact->isVerifiedForStaging();
    }

    public function preflight(UpdatePlan $plan): PreflightResult
    {
        return new PreflightResult(
            PreflightStatus::Blocked,
            new RollbackBoundary('', '', false, false, true),
            [$plan->isValid() ? 'rollback_boundary_required' : 'plan_invalid'],
        );
    }

    public function run(UpdateCycleRequest $request): UpdateCycleSnapshot
    {
        $existing = $this->store->findByIdempotencyKey($request->idempotencyKey);
        if ($existing instanceof UpdateCycleSnapshot) {
            return $existing;
        }

        $snapshot = $this->store->create(new UpdateCycleSnapshot(
            cycleId: $request->cycleId(),
            request: $request,
            state: UpdateCycleState::Planned,
            attempt: 1,
            revision: 1,
            nextStepIndex: 0,
        ));
        if (!$this->record('update.cycle.requested', $snapshot, ['plan_key' => $request->planKey])) {
            return $this->block($snapshot, 'update_audit_unavailable', false);
        }
        if (!$this->verifyEntitlement($request->entitlement)) {
            return $this->block($snapshot, 'update_entitlement_untrusted');
        }
        try {
            if (!$this->queryScope->supports('update.plan', 'update.plan.list')) {
                return $this->block($snapshot, 'update_access_scope_missing');
            }
            $access = $this->queryScope->explain(
                'update.plan',
                $request->actorRef,
                'update.plan.list',
                ['resource_type' => 'update.plan'],
            );
            if (!$access->isAllowed()) {
                return $this->block($snapshot, 'update_access_denied');
            }
            $scoped = $this->queryScope->scope(
                ['plan_key' => $request->planKey],
                $request->actorRef,
                'update.plan.list',
                ['resource_type' => 'update.plan'],
            );
            if (($scoped['plan_key'] ?? null) !== $request->planKey) {
                return $this->block($snapshot, 'update_access_scope_invalid');
            }
        } catch (Throwable) {
            return $this->block($snapshot, 'update_access_denied');
        }

        $license = $this->licensing->decide($request->capability, $request->entitlement);
        if (!$license->isAllowed()) {
            return $this->block($snapshot, 'update_capability_denied');
        }
        if (!$this->verifyManifest($request->manifest)) {
            return $this->block($snapshot, 'update_manifest_invalid');
        }
        $plan = $this->computePlan($request->manifest, $request->entitlement);
        if (!$plan->isValid() || $plan->planKey !== $request->planKey) {
            return $this->block($snapshot, 'update_plan_invalid');
        }
        if (!$this->verifyArtifact($request->artifact)
            || !hash_equals($request->manifest->manifestKey, $request->artifact->manifestBinding)) {
            return $this->block($snapshot, 'update_artifact_invalid');
        }

        $mutation = new ArrayLogicalFileMutation(
            null,
            'update.artifact.stage',
            'create',
            [
                'artifact_key' => $request->artifact->artifactKey,
                'checksum' => $request->artifact->checksum,
            ],
        );
        try {
            if ($this->filesystem->decideMutation($mutation) !== FileDecisionStatus::Allowed
                || $this->filesystem->applyMutation($mutation, $request->artifactFile) !== FileDecisionStatus::Allowed) {
                return $this->block($snapshot, 'update_artifact_stage_denied');
            }
        } catch (Throwable) {
            return $this->block($snapshot, 'update_artifact_stage_failed');
        }

        try {
            $rollback = $this->recovery->prepare($request, $plan);
        } catch (Throwable) {
            return $this->block($snapshot, 'update_recovery_boundary_unavailable');
        }
        if (!$rollback->isReady()) {
            return $this->block($snapshot, 'update_recovery_boundary_invalid');
        }

        $ready = $snapshot->transition(
            UpdateCycleState::ExecutionReady,
            0,
            [],
            rollbackBoundary: $rollback,
        );
        $ready = $this->store->save($ready, $snapshot->revision);
        if (!$this->record('update.preflight.passed', $ready, ['snapshot_ref' => $rollback->snapshotRef])) {
            return $this->block($ready, 'update_audit_unavailable', false);
        }

        return $this->executeFrom($ready);
    }

    public function retry(string $cycleId, int $expectedRevision): UpdateCycleSnapshot
    {
        $current = $this->store->require($cycleId);
        if ($current->revision !== $expectedRevision) {
            throw new \RuntimeException('update_cycle_stale_state');
        }
        if (!$current->state->isRetryable() || !$current->rollbackBoundary?->isReady()) {
            throw new \RuntimeException('update_cycle_not_retryable');
        }
        $retry = $current->transition(
            UpdateCycleState::ExecutionReady,
            $current->nextStepIndex,
            $current->completedSteps,
            rollbackBoundary: $current->rollbackBoundary,
            attempt: $current->attempt + 1,
        );
        $retry = $this->store->save($retry, $current->revision);
        if (!$this->record('update.cycle.retry_requested', $retry, ['resume_step' => $retry->request->steps[$retry->nextStepIndex]])) {
            return $this->block($retry, 'update_audit_unavailable', false);
        }

        return $this->executeFrom($retry);
    }

    private function executeFrom(UpdateCycleSnapshot $ready): UpdateCycleSnapshot
    {
        $running = $ready->transition(
            UpdateCycleState::Running,
            $ready->nextStepIndex,
            $ready->completedSteps,
            rollbackBoundary: $ready->rollbackBoundary,
        );
        $running = $this->store->save($running, $ready->revision);

        for ($index = $running->nextStepIndex; $index < count($running->request->steps); $index++) {
            $step = $running->request->steps[$index];
            $descriptor = new OperationDescriptor(
                name: 'update.install.'.$step,
                executionMode: OperationExecutionMode::Sync,
                accessScope: 'update.install',
                requiredCapability: $running->request->capability->key(),
                auditEvent: 'update.install.step',
                idempotencyKey: $running->cycleId.':'.$running->attempt.':'.$step,
                transactional: true,
            );
            $context = new OperationContext(
                actorId: $running->request->actorRef,
                correlationId: $running->request->correlationRef,
                metadata: [
                    'cycle_id' => $running->cycleId,
                    'step' => $step,
                    'attempt' => $running->attempt,
                ],
            );
            try {
                $result = $this->operations->execute($descriptor, $context);
            } catch (Throwable) {
                $result = null;
            }
            if ($result === null || !$result->successful()) {
                $reason = $result === null ? 'update_operation_failed' : $this->safeReason($result->decision->reasonCode);
                $failed = $running->transition(
                    UpdateCycleState::ManualRecoveryRequired,
                    $index,
                    $running->completedSteps,
                    $step,
                    $reason,
                    $running->rollbackBoundary,
                );
                try {
                    $rolledBack = $this->recovery->rollback($failed);
                } catch (Throwable) {
                    $rolledBack = false;
                }
                if ($rolledBack) {
                    $failed = $running->transition(
                        UpdateCycleState::RecoverableFailed,
                        $index,
                        $running->completedSteps,
                        $step,
                        $reason,
                        $running->rollbackBoundary,
                    );
                }
                $failed = $this->store->save($failed, $running->revision);
                $this->record(
                    $rolledBack ? 'update.cycle.rollback_confirmed' : 'update.cycle.manual_recovery_required',
                    $failed,
                    ['failed_step' => $step, 'reason_code' => $reason],
                );

                return $failed;
            }

            $completed = [...$running->completedSteps, $step];
            $running = $running->transition(
                UpdateCycleState::Running,
                $index + 1,
                $completed,
                rollbackBoundary: $running->rollbackBoundary,
            );
            $running = $this->store->save($running, $running->revision - 1);
            $this->record('update.cycle.step_completed', $running, ['step' => $step]);
        }

        $completed = $running->transition(
            UpdateCycleState::Completed,
            count($running->request->steps),
            $running->request->steps,
            rollbackBoundary: $running->rollbackBoundary,
        );
        $completed = $this->store->save($completed, $running->revision);
        $this->record('update.cycle.completed', $completed, ['step_count' => count($completed->completedSteps)]);

        return $completed;
    }

    private function block(UpdateCycleSnapshot $snapshot, string $reason, bool $audit = true): UpdateCycleSnapshot
    {
        $blocked = $snapshot->transition(
            UpdateCycleState::Blocked,
            $snapshot->nextStepIndex,
            $snapshot->completedSteps,
            reasonCode: $reason,
            rollbackBoundary: $snapshot->rollbackBoundary,
        );
        $blocked = $this->store->save($blocked, $snapshot->revision);
        if ($audit) $this->record('update.cycle.blocked', $blocked, ['reason_code' => $reason]);

        return $blocked;
    }

    /** @param array<string, scalar|null> $facts */
    private function record(string $event, UpdateCycleSnapshot $snapshot, array $facts = []): bool
    {
        try {
            $this->audit->record($event, $snapshot, $facts);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function safeReason(string $reason): string
    {
        return preg_match('/\A[a-z][a-z0-9_.-]{0,99}\z/D', $reason) === 1
            ? $reason
            : 'update_operation_failed';
    }
}
