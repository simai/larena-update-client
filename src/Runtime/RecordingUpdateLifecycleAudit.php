<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Runtime;

use InvalidArgumentException;
use Larena\UpdateClient\Contracts\UpdateLifecycleAudit;
use Larena\UpdateClient\Data\UpdateCycleSnapshot;

final class RecordingUpdateLifecycleAudit implements UpdateLifecycleAudit
{
    /** @var list<array<string, mixed>> */
    private array $events = [];

    public function record(string $event, UpdateCycleSnapshot $snapshot, array $safeFacts = []): string
    {
        if (preg_match('/\Aupdate\.[a-z][a-z0-9_.-]{1,98}\z/D', $event) !== 1) {
            throw new InvalidArgumentException('update_audit_event_invalid');
        }
        foreach ($safeFacts as $key => $value) {
            if (!is_string($key)
                || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $key) !== 1
                || preg_match('/secret|token|password|credential|path|exception|payload|customer/i', $key) === 1
                || (!is_scalar($value) && $value !== null)
                || (is_string($value) && (strlen($value) > 160 || str_contains($value, "\n")))) {
                throw new InvalidArgumentException('update_audit_facts_unsafe');
            }
        }
        ksort($safeFacts);
        $record = [
            'event' => $event,
            'cycle_id' => $snapshot->cycleId,
            'actor_ref' => $snapshot->request->actorRef,
            'correlation_ref' => $snapshot->request->correlationRef,
            'state' => $snapshot->state->value,
            'attempt' => $snapshot->attempt,
            'revision' => $snapshot->revision,
            'facts' => $safeFacts,
        ];
        $reference = 'audit:'.substr(hash('sha256', json_encode($record, JSON_THROW_ON_ERROR)), 0, 24);
        $this->events[] = ['ref' => $reference, ...$record];

        return $reference;
    }

    /** @return list<array<string, mixed>> */
    public function events(): array
    {
        return $this->events;
    }
}
