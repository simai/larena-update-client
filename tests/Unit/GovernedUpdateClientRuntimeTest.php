<?php

declare(strict_types=1);

use Larena\Access\Contracts\QueryScopeProvider;
use Larena\Access\ValueObjects\AccessDecision;
use Larena\Core\Contracts\OperationContext;
use Larena\Core\Contracts\OperationDecision;
use Larena\Core\Contracts\OperationDescriptor;
use Larena\Core\Contracts\OperationResult;
use Larena\Core\Contracts\OperationRuntime;
use Larena\Core\Enums\OperationExecutionMode;
use Larena\Filesystem\Enums\FileLifecycleClass;
use Larena\Filesystem\Enums\FileVisibility;
use Larena\Filesystem\Runtime\ArrayLogicalFile;
use Larena\Filesystem\Runtime\ArrayStorageBlobReference;
use Larena\Filesystem\Runtime\InMemoryFilesystemRuntime;
use Larena\Licensing\Runtime\SnapshotLicensingRuntime;
use Larena\Licensing\Runtime\StaticCapability;
use Larena\UpdateClient\Contracts\ArtifactVerificationResult;
use Larena\UpdateClient\Contracts\RollbackBoundary;
use Larena\UpdateClient\Contracts\UpdatePlan;
use Larena\UpdateClient\Contracts\UpdateRecoveryBoundary;
use Larena\UpdateClient\Contracts\UpdateManifest;
use Larena\UpdateClient\Data\UpdateCycleRequest;
use Larena\UpdateClient\Data\UpdateCycleSnapshot;
use Larena\UpdateClient\Enums\ArtifactVerificationStatus;
use Larena\UpdateClient\Enums\UpdateCycleState;
use Larena\UpdateClient\Runtime\GovernedUpdateClientRuntime;
use Larena\UpdateClient\Runtime\InMemoryUpdateExecutionStore;
use Larena\UpdateClient\Runtime\RecordingUpdateLifecycleAudit;
use Larena\UpdateClient\Contracts\EntitlementSnapshot;

function g48_positive_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class G48PositiveQueryScope implements QueryScopeProvider
{
    public int $scopeCalls = 0;

    public function supports(string $resourceType, string $operation): bool
    {
        return $resourceType === 'update.plan' && $operation === 'update.plan.list';
    }

    public function scope(array $query, string $actor, string $operation, array $context = []): array
    {
        $this->scopeCalls++;
        return $query;
    }

    public function explain(string $resourceType, string $actor, string $operation, array $context = []): AccessDecision
    {
        return AccessDecision::allow($operation, $actor, 'update.plan:all', 'fixture_scope_allowed');
    }
}

final class G48PositiveOperationRuntime implements OperationRuntime
{
    /** @var list<string> */
    public array $calls = [];

    public function decide(OperationDescriptor $descriptor, OperationContext $context): OperationDecision
    {
        return OperationDecision::allowed(OperationExecutionMode::Sync);
    }

    public function execute(OperationDescriptor $descriptor, OperationContext $context): OperationResult
    {
        $step = (string) ($context->metadata['step'] ?? 'unknown');
        $attempt = (int) ($context->metadata['attempt'] ?? 0);
        $this->calls[] = $step.':'.$attempt;
        if ($step === 'migrate_database' && $attempt === 1) {
            return OperationResult::fromDecision(OperationDecision::invalid('fixture_step_failed'));
        }

        return OperationResult::fromDecision(OperationDecision::allowed(OperationExecutionMode::Sync), ['step' => $step]);
    }

    public function explain(OperationDescriptor $descriptor, OperationContext $context): array
    {
        return ['operation' => $descriptor->name, 'allowed' => true];
    }
}

final class G48PositiveRecovery implements UpdateRecoveryBoundary
{
    public int $prepareCalls = 0;
    public int $rollbackCalls = 0;

    public function prepare(UpdateCycleRequest $request, UpdatePlan $plan): RollbackBoundary
    {
        $this->prepareCalls++;
        return new RollbackBoundary('backup.plan.pre_update', 'backup.snapshot.fixture', true, true, false);
    }

    public function rollback(UpdateCycleSnapshot $failed): bool
    {
        $this->rollbackCalls++;
        return $failed->failedStep === 'migrate_database';
    }
}

$positiveScope = new G48PositiveQueryScope();
$positiveFilesystem = new InMemoryFilesystemRuntime();
$positiveOperations = new G48PositiveOperationRuntime();
$positiveRecovery = new G48PositiveRecovery();
$positiveAudit = new RecordingUpdateLifecycleAudit();
$positiveStore = new InMemoryUpdateExecutionStore();
$positiveRuntime = new GovernedUpdateClientRuntime(
    $positiveScope,
    new SnapshotLicensingRuntime(new DateTimeImmutable('2026-08-05T00:00:00Z')),
    $positiveFilesystem,
    $positiveOperations,
    $positiveRecovery,
    $positiveAudit,
    $positiveStore,
);

$positiveEntitlement = new EntitlementSnapshot(
    'site.entitlement',
    'site-fixture',
    ['update.install'],
    true,
    true,
    true,
);
$positiveManifest = new UpdateManifest(
    'channel.stable.manifest',
    'stable',
    ['larena/update-client', 'larena/core'],
    'signature:manifest',
    true,
);
$positivePlanKey = 'update.plan.'.substr(hash('sha256', $positiveManifest->manifestKey.'|'.$positiveEntitlement->snapshotKey), 0, 16);
$positiveArtifact = new ArtifactVerificationResult(
    'artifact.update_client',
    'sha256:'.str_repeat('a', 64),
    'signature:artifact',
    $positiveManifest->manifestKey,
    ArtifactVerificationStatus::Verified,
    true,
    true,
);
$positiveFile = new ArrayLogicalFile(
    'logical:update-artifact-fixture',
    'update-artifact-fixture',
    new ArrayStorageBlobReference($positiveArtifact->checksum, 'fixture', 1024, 'application/zip'),
    FileVisibility::Internal,
    FileLifecycleClass::Temporary,
);
$positiveRequest = new UpdateCycleRequest(
    'user:1',
    'correlation:update:g48',
    'update:g48:positive:1',
    $positivePlanKey,
    $positiveManifest,
    $positiveEntitlement,
    new StaticCapability('update.install', 'larena/update-client'),
    $positiveArtifact,
    $positiveFile,
    ['stage_files', 'migrate_database', 'finalize'],
);

$positiveFailed = $positiveRuntime->run($positiveRequest);
g48_positive_assert($positiveFailed->state === UpdateCycleState::RecoverableFailed, 'First execution must stop in recoverable failure.');
g48_positive_assert($positiveFailed->completedSteps === ['stage_files'], 'Completed prefix must be persisted before failure.');
g48_positive_assert($positiveFailed->nextStepIndex === 1 && $positiveFailed->failedStep === 'migrate_database', 'Failed step must remain the retry cursor.');
g48_positive_assert($positiveRecovery->prepareCalls === 1 && $positiveRecovery->rollbackCalls === 1, 'Recovery boundary must prepare and confirm rollback exactly once.');
g48_positive_assert($positiveFilesystem->file($positiveFile->id()) === $positiveFile, 'Artifact must be staged through FilesystemRuntime.');

$positiveCompleted = $positiveRuntime->retry($positiveFailed->cycleId, $positiveFailed->revision);
g48_positive_assert($positiveCompleted->state === UpdateCycleState::Completed, 'Retry must complete from the known failed step.');
g48_positive_assert($positiveCompleted->attempt === 2, 'Retry must increment attempt.');
g48_positive_assert($positiveCompleted->completedSteps === $positiveRequest->steps, 'Completion must persist the full ordered step list.');
g48_positive_assert($positiveOperations->calls === [
    'stage_files:1',
    'migrate_database:1',
    'migrate_database:2',
    'finalize:2',
], 'Retry must not repeat already completed steps.');
g48_positive_assert($positiveScope->scopeCalls === 1, 'Protected plan query must be scoped exactly once.');
g48_positive_assert($positiveStore->require($positiveCompleted->cycleId) === $positiveCompleted, 'Completed state must be persisted.');
g48_positive_assert(count($positiveAudit->events()) >= 7, 'Correlated lifecycle evidence must be recorded.');
foreach ($positiveAudit->events() as $positiveEvent) {
    g48_positive_assert(($positiveEvent['correlation_ref'] ?? null) === 'correlation:update:g48', 'Every audit fact must preserve correlation.');
    g48_positive_assert(!str_contains(strtolower(json_encode($positiveEvent, JSON_THROW_ON_ERROR)), 'password'), 'Audit evidence must not expose secret fields.');
}

$unsafeAuditKeyRejected = false;
try {
    $positiveAudit->record('update.fixture.unsafe', $positiveCompleted, ['password' => 'secret-value']);
} catch (InvalidArgumentException $exception) {
    $unsafeAuditKeyRejected = $exception->getMessage() === 'update_audit_facts_unsafe';
}
g48_positive_assert($unsafeAuditKeyRejected, 'Audit sink must reject secret-like fact keys.');

$unsafeAuditValueRejected = false;
try {
    $positiveAudit->record('update.fixture.unsafe', $positiveCompleted, ['safe_fact' => ['nested']]);
} catch (InvalidArgumentException $exception) {
    $unsafeAuditValueRejected = $exception->getMessage() === 'update_audit_facts_unsafe';
}
g48_positive_assert($unsafeAuditValueRejected, 'Audit sink must reject non-scalar fact values.');
