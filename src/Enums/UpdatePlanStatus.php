<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Enums;

enum UpdatePlanStatus: string
{
    case Draft = 'draft';
    case ReadyForPreflight = 'ready_for_preflight';
    case Blocked = 'blocked';
}
