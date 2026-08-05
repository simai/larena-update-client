<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Data;

use InvalidArgumentException;
use Larena\Filesystem\Contracts\LogicalFile;
use Larena\Licensing\Contracts\Capability;
use Larena\UpdateClient\Contracts\ArtifactVerificationResult;
use Larena\UpdateClient\Contracts\EntitlementSnapshot;
use Larena\UpdateClient\Contracts\UpdateManifest;

final readonly class UpdateCycleRequest
{
    /** @var list<string> */
    public array $steps;

    /**
     * @param array<array-key, mixed> $steps
     */
    public function __construct(
        public string $actorRef,
        public string $correlationRef,
        public string $idempotencyKey,
        public string $planKey,
        public UpdateManifest $manifest,
        public EntitlementSnapshot $entitlement,
        public Capability $capability,
        public ArtifactVerificationResult $artifact,
        public LogicalFile $artifactFile,
        array $steps,
    ) {
        if (preg_match('/\A[a-z][a-z0-9_-]*:[A-Za-z0-9][A-Za-z0-9:._-]{0,191}\z/D', $this->actorRef) !== 1) {
            throw new InvalidArgumentException('update_actor_ref_invalid');
        }
        foreach (['correlationRef' => $this->correlationRef, 'idempotencyKey' => $this->idempotencyKey] as $field => $value) {
            if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9:._-]{2,127}\z/D', $value) !== 1) {
                throw new InvalidArgumentException('update_'.self::snake($field).'_invalid');
            }
        }
        if (!UpdateManifest::isStableKey($this->planKey) || $steps === [] || !array_is_list($steps)) {
            throw new InvalidArgumentException('update_cycle_plan_invalid');
        }
        $validatedSteps = [];
        foreach ($steps as $step) {
            if (!is_string($step) || !UpdateManifest::isStableKey($step)) {
                throw new InvalidArgumentException('update_cycle_step_invalid');
            }
            $validatedSteps[] = $step;
        }
        if (count(array_unique($validatedSteps)) !== count($validatedSteps)) {
            throw new InvalidArgumentException('update_cycle_step_duplicate');
        }
        $this->steps = $validatedSteps;
    }

    public function cycleId(): string
    {
        return substr(hash('sha256', $this->idempotencyKey), 0, 32);
    }

    private static function snake(string $value): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $value));
    }
}
