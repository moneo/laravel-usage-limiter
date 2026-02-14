<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Enums;

enum OverageSettlementMode: string
{
    case Pending = 'pending';
    case Invoiced = 'invoiced';
    case Paid = 'paid';
    case Waived = 'waived';
}
