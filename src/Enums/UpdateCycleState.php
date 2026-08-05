<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Enums;

enum UpdateCycleState: string
{
    case Planned = 'planned';
    case ExecutionReady = 'execution_ready';
    case Running = 'running';
    case Blocked = 'blocked';
    case RecoverableFailed = 'recoverable_failed';
    case ManualRecoveryRequired = 'manual_recovery_required';
    case Completed = 'completed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Blocked, self::ManualRecoveryRequired, self::Completed], true);
    }

    public function isRetryable(): bool
    {
        return $this === self::RecoverableFailed;
    }
}
