<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Enums;

enum PreflightStatus: string
{
    case Pending = 'pending';
    case Passed = 'passed';
    case Failed = 'failed';
    case Blocked = 'blocked';
}
