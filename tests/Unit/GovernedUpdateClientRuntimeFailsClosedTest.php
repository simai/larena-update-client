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
use Larena\Filesystem\Contracts\FileDeliveryDecision;
use Larena\Filesystem\Contracts\FileDeliveryRequest;
use Larena\Filesystem\Contracts\FileMetadataProjection;
use Larena\Filesystem\Contracts\FileSearchProjection;
use Larena\Filesystem\Contracts\FilesystemRuntime;
use Larena\Filesystem\Contracts\LogicalFile;
use Larena\Filesystem\Contracts\LogicalFileMutation;
use Larena\Filesystem\Enums\FileDecisionStatus;
use Larena\Filesystem\Enums\FileLifecycleClass;
use Larena\Filesystem\Enums\FileVisibility;
use Larena\Filesystem\Runtime\ArrayLogicalFile;
use Larena\Filesystem\Runtime\ArrayStorageBlobReference;
use Larena\Licensing\Runtime\SnapshotLicensingRuntime;
use Larena\Licensing\Runtime\StaticCapability;
use Larena\UpdateClient\Contracts\ArtifactVerificationResult;
use Larena\UpdateClient\Contracts\EntitlementSnapshot;
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

function g48_negative_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

final class G48NegativeQueryScope implements QueryScopeProvider
{
    public function __construct(private readonly bool $allowed) {}
    public function supports(string $resourceType, string $operation): bool { return true; }
    public function scope(array $query, string $actor, string $operation, array $context = []): array { return $this->allowed ? $query : []; }
    public function explain(string $resourceType, string $actor, string $operation, array $context = []): AccessDecision
    {
        return $this->allowed
            ? AccessDecision::allow($operation, $actor, 'update.plan:all', 'fixture_allowed')
            : AccessDecision::deny($operation, $actor, 'update.plan:all', 'fixture_denied');
    }
}

final class G48NegativeFilesystem implements FilesystemRuntime
{
    public int $mutationCalls = 0;
    public function __construct(private readonly bool $allowed) {}
    public function register(LogicalFile $file): void {}
    public function file(string $logicalFileId): ?LogicalFile { return null; }
    public function decideDelivery(FileDeliveryRequest $request, FileMetadataProjection $metadata): FileDeliveryDecision { throw new RuntimeException('unused'); }
    public function decideMutation(LogicalFileMutation $mutation): FileDecisionStatus
    {
        $this->mutationCalls++;
        return $this->allowed ? FileDecisionStatus::Allowed : FileDecisionStatus::Denied;
    }
    public function applyMutation(LogicalFileMutation $mutation, ?LogicalFile $file = null): FileDecisionStatus
    {
        $this->mutationCalls++;
        return $this->allowed ? FileDecisionStatus::Allowed : FileDecisionStatus::Denied;
    }
    public function metadataProjection(LogicalFile $file, array $metadata = []): FileMetadataProjection { throw new RuntimeException('unused'); }
    public function searchProjection(FileMetadataProjection $metadata): FileSearchProjection { throw new RuntimeException('unused'); }
}

final class G48NegativeOperations implements OperationRuntime
{
    public int $calls = 0;
    public function __construct(private readonly bool $fail) {}
    public function decide(OperationDescriptor $descriptor, OperationContext $context): OperationDecision { return OperationDecision::allowed(OperationExecutionMode::Sync); }
    public function execute(OperationDescriptor $descriptor, OperationContext $context): OperationResult
    {
        $this->calls++;
        return OperationResult::fromDecision($this->fail
            ? OperationDecision::invalid('fixture_step_failed')
            : OperationDecision::allowed(OperationExecutionMode::Sync));
    }
    public function explain(OperationDescriptor $descriptor, OperationContext $context): array { return []; }
}

final class G48NegativeRecovery implements UpdateRecoveryBoundary
{
    public int $prepareCalls = 0;
    public int $rollbackCalls = 0;
    public function __construct(private readonly bool $ready, private readonly bool $rollback) {}
    public function prepare(UpdateCycleRequest $request, UpdatePlan $plan): RollbackBoundary
    {
        $this->prepareCalls++;
        return $this->ready
            ? new RollbackBoundary('backup.plan.fixture', 'backup.snapshot.fixture', true, true, false)
            : new RollbackBoundary('', '', false, false, true);
    }
    public function rollback(UpdateCycleSnapshot $failed): bool { $this->rollbackCalls++; return $this->rollback; }
}

/** @return array{GovernedUpdateClientRuntime,UpdateCycleRequest,G48NegativeFilesystem,G48NegativeOperations,G48NegativeRecovery,InMemoryUpdateExecutionStore} */
function g48_negative_fixture(
    string $id,
    bool $access = true,
    bool $capability = true,
    bool $manifest = true,
    bool $artifact = true,
    bool $filesystem = true,
    bool $recovery = true,
    bool $operationFails = false,
    bool $rollback = true,
): array {
    $entitlement = new EntitlementSnapshot(
        'site.entitlement',
        'site-fixture',
        $capability ? ['update.install'] : ['content.free'],
        true,
        true,
        true,
    );
    $manifestValue = new UpdateManifest('channel.stable.manifest', 'stable', ['larena/core'], 'signature:manifest', $manifest);
    $planKey = 'update.plan.p'.substr(hash('sha256', $manifestValue->manifestKey.'|'.$entitlement->snapshotKey), 0, 16);
    $artifactValue = new ArtifactVerificationResult(
        'artifact.fixture',
        'sha256:'.str_repeat('b', 64),
        'signature:artifact',
        $manifestValue->manifestKey,
        $artifact ? ArtifactVerificationStatus::Verified : ArtifactVerificationStatus::Rejected,
        true,
        true,
    );
    $file = new ArrayLogicalFile(
        'logical:negative-'.$id,
        'negative-'.$id,
        new ArrayStorageBlobReference($artifactValue->checksum, 'fixture', 10, 'application/zip'),
        FileVisibility::Internal,
        FileLifecycleClass::Temporary,
    );
    $request = new UpdateCycleRequest(
        'user:1',
        'correlation:g48:'.$id,
        'update:g48:negative:'.$id,
        $planKey,
        $manifestValue,
        $entitlement,
        new StaticCapability('update.install', 'larena/update-client'),
        $artifactValue,
        $file,
        ['apply_fixture'],
    );
    $filesystemRuntime = new G48NegativeFilesystem($filesystem);
    $operationRuntime = new G48NegativeOperations($operationFails);
    $recoveryRuntime = new G48NegativeRecovery($recovery, $rollback);
    $store = new InMemoryUpdateExecutionStore();
    $runtime = new GovernedUpdateClientRuntime(
        new G48NegativeQueryScope($access),
        new SnapshotLicensingRuntime(),
        $filesystemRuntime,
        $operationRuntime,
        $recoveryRuntime,
        new RecordingUpdateLifecycleAudit(),
        $store,
    );
    return [$runtime, $request, $filesystemRuntime, $operationRuntime, $recoveryRuntime, $store];
}

[$negativeAccessRuntime, $negativeAccessRequest, $negativeAccessFs, $negativeAccessOps, $negativeAccessRecovery] = g48_negative_fixture('access', access: false);
$negativeAccess = $negativeAccessRuntime->run($negativeAccessRequest);
g48_negative_assert($negativeAccess->state === UpdateCycleState::Blocked && $negativeAccess->reasonCode === 'update_access_denied', 'Access denial must fail before plan.');
g48_negative_assert($negativeAccessFs->mutationCalls === 0 && $negativeAccessOps->calls === 0 && $negativeAccessRecovery->prepareCalls === 0, 'Access denial must have no downstream side effect.');

[$negativeCapabilityRuntime, $negativeCapabilityRequest, $negativeCapabilityFs] = g48_negative_fixture('capability', capability: false);
$negativeCapability = $negativeCapabilityRuntime->run($negativeCapabilityRequest);
g48_negative_assert($negativeCapability->reasonCode === 'update_capability_denied' && $negativeCapabilityFs->mutationCalls === 0, 'Capability denial must precede staging.');

[$negativeManifestRuntime, $negativeManifestRequest, $negativeManifestFs] = g48_negative_fixture('manifest', manifest: false);
$negativeManifest = $negativeManifestRuntime->run($negativeManifestRequest);
g48_negative_assert($negativeManifest->reasonCode === 'update_manifest_invalid' && $negativeManifestFs->mutationCalls === 0, 'Invalid manifest must precede staging.');

[$negativeArtifactRuntime, $negativeArtifactRequest, $negativeArtifactFs] = g48_negative_fixture('artifact', artifact: false);
$negativeArtifact = $negativeArtifactRuntime->run($negativeArtifactRequest);
g48_negative_assert($negativeArtifact->reasonCode === 'update_artifact_invalid' && $negativeArtifactFs->mutationCalls === 0, 'Invalid artifact must precede staging.');

[$negativeFilesystemRuntime, $negativeFilesystemRequest, $negativeFilesystemFs, $negativeFilesystemOps, $negativeFilesystemRecovery] = g48_negative_fixture('filesystem', filesystem: false);
$negativeFilesystem = $negativeFilesystemRuntime->run($negativeFilesystemRequest);
g48_negative_assert($negativeFilesystem->reasonCode === 'update_artifact_stage_denied', 'Filesystem denial must be explicit.');
g48_negative_assert($negativeFilesystemOps->calls === 0 && $negativeFilesystemRecovery->prepareCalls === 0, 'Filesystem denial must precede recovery and execution.');

[$negativeRecoveryRuntime, $negativeRecoveryRequest, , $negativeRecoveryOps, $negativeRecoveryBoundary] = g48_negative_fixture('recovery', recovery: false);
$negativeRecovery = $negativeRecoveryRuntime->run($negativeRecoveryRequest);
g48_negative_assert($negativeRecovery->reasonCode === 'update_recovery_boundary_invalid', 'Missing recovery boundary must block execution.');
g48_negative_assert($negativeRecoveryOps->calls === 0 && $negativeRecoveryBoundary->prepareCalls === 1, 'Invalid recovery boundary must stop before operation runtime.');

[$negativeRollbackRuntime, $negativeRollbackRequest, , $negativeRollbackOps, $negativeRollbackBoundary] = g48_negative_fixture('rollback', operationFails: true, rollback: false);
$negativeRollback = $negativeRollbackRuntime->run($negativeRollbackRequest);
g48_negative_assert($negativeRollback->state === UpdateCycleState::ManualRecoveryRequired, 'Unconfirmed rollback must require manual recovery.');
g48_negative_assert($negativeRollbackOps->calls === 1 && $negativeRollbackBoundary->rollbackCalls === 1, 'Failed step must stop immediately and attempt one rollback.');
$negativeRetryDenied = false;
try {
    $negativeRollbackRuntime->retry($negativeRollback->cycleId, $negativeRollback->revision);
} catch (RuntimeException $exception) {
    $negativeRetryDenied = $exception->getMessage() === 'update_cycle_not_retryable';
}
g48_negative_assert($negativeRetryDenied, 'Manual recovery state must reject retry.');

[$negativeUnknownRuntime] = g48_negative_fixture('unknown');
$negativeUnknownDenied = false;
try {
    $negativeUnknownRuntime->retry(str_repeat('f', 32), 1);
} catch (RuntimeException $exception) {
    $negativeUnknownDenied = $exception->getMessage() === 'update_cycle_not_found';
}
g48_negative_assert($negativeUnknownDenied, 'Unknown cycle retry must fail closed.');
