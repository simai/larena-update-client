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

function g48_replay_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

final class G48ReplayScope implements QueryScopeProvider
{
    public int $calls = 0;
    public function supports(string $resourceType, string $operation): bool { return true; }
    public function scope(array $query, string $actor, string $operation, array $context = []): array { $this->calls++; return $query; }
    public function explain(string $resourceType, string $actor, string $operation, array $context = []): AccessDecision
    { return AccessDecision::allow($operation, $actor, 'update.plan:all', 'fixture_allowed'); }
}

final class G48ReplayOperations implements OperationRuntime
{
    public int $calls = 0;
    public function decide(OperationDescriptor $descriptor, OperationContext $context): OperationDecision { return OperationDecision::allowed(OperationExecutionMode::Sync); }
    public function execute(OperationDescriptor $descriptor, OperationContext $context): OperationResult
    { $this->calls++; return OperationResult::fromDecision(OperationDecision::allowed(OperationExecutionMode::Sync)); }
    public function explain(OperationDescriptor $descriptor, OperationContext $context): array { return []; }
}

final class G48ReplayRecovery implements UpdateRecoveryBoundary
{
    public int $prepareCalls = 0;
    public function prepare(UpdateCycleRequest $request, UpdatePlan $plan): RollbackBoundary
    { $this->prepareCalls++; return new RollbackBoundary('backup.plan.fixture', 'backup.snapshot.fixture', true, true, false); }
    public function rollback(UpdateCycleSnapshot $failed): bool { return true; }
}

$replayScope = new G48ReplayScope();
$replayOperations = new G48ReplayOperations();
$replayRecovery = new G48ReplayRecovery();
$replayAudit = new RecordingUpdateLifecycleAudit();
$replayRuntime = new GovernedUpdateClientRuntime(
    $replayScope,
    new SnapshotLicensingRuntime(),
    new InMemoryFilesystemRuntime(),
    $replayOperations,
    $replayRecovery,
    $replayAudit,
    new InMemoryUpdateExecutionStore(),
);
$replayEntitlement = new EntitlementSnapshot('site.entitlement', 'site-fixture', ['update.install'], true, true, true);
$replayManifest = new UpdateManifest('channel.stable.manifest', 'stable', ['larena/update-client', 'larena/core'], 'signature:manifest', true);
$replayPlanKey = 'update.plan.'.substr(hash('sha256', $replayManifest->manifestKey.'|'.$replayEntitlement->snapshotKey), 0, 16);
$replayArtifact = new ArtifactVerificationResult('artifact.fixture', 'sha256:'.str_repeat('c', 64), 'signature:artifact', $replayManifest->manifestKey, ArtifactVerificationStatus::Verified, true, true);
$replayRequest = new UpdateCycleRequest(
    'user:1',
    'correlation:g48:replay',
    'update:g48:replay:1',
    $replayPlanKey,
    $replayManifest,
    $replayEntitlement,
    new StaticCapability('update.install', 'larena/update-client'),
    $replayArtifact,
    new ArrayLogicalFile('logical:replay', 'replay', new ArrayStorageBlobReference($replayArtifact->checksum, 'fixture', 10, 'application/zip'), FileVisibility::Internal, FileLifecycleClass::Temporary),
    ['apply_fixture'],
);

$replayFirst = $replayRuntime->run($replayRequest);
$replayCalls = [$replayScope->calls, $replayOperations->calls, $replayRecovery->prepareCalls, count($replayAudit->events())];
$replaySecond = $replayRuntime->run($replayRequest);
g48_replay_assert($replayFirst === $replaySecond && $replaySecond->state === UpdateCycleState::Completed, 'Idempotent replay must return the persisted result.');
g48_replay_assert($replayCalls === [$replayScope->calls, $replayOperations->calls, $replayRecovery->prepareCalls, count($replayAudit->events())], 'Idempotent replay must not repeat any side effect.');

$reorderedManifest = new UpdateManifest('channel.stable.manifest', 'stable', ['larena/core', 'larena/update-client'], 'signature:manifest', true);
$replayPlanA = $replayRuntime->computePlan($replayManifest, $replayEntitlement);
$replayPlanB = $replayRuntime->computePlan($reorderedManifest, $replayEntitlement);
g48_replay_assert($replayPlanA->planKey === $replayPlanB->planKey, 'Equivalent manifest ordering must keep plan identity.');
g48_replay_assert($replayPlanA->dependencyOrder === $replayPlanB->dependencyOrder, 'Equivalent manifest ordering must keep deterministic dependency order.');
