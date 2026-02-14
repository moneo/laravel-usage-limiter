<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\DTOs;

enum EnforcementDecision: string
{
    case Allow = 'allow';
    case Deny = 'deny';
    case AllowWithWarning = 'allow_with_warning';

    public function isAllowed(): bool
    {
        return $this !== self::Deny;
    }

    public function hasWarning(): bool
    {
        return $this === self::AllowWithWarning;
    }
}
