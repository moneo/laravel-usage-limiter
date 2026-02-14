<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Enums;

enum ReservationStatus: string
{
    case Pending = 'pending';
    case Committed = 'committed';
    case Released = 'released';
    case Expired = 'expired';
}
