<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Enums;

enum EnforcementMode: string
{
    case Hard = 'hard';
    case Soft = 'soft';
}
