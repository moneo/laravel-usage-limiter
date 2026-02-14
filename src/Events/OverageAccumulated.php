<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Events;

use Illuminate\Foundation\Events\Dispatchable;

class OverageAccumulated
{
    use Dispatchable;

    public function __construct(
        public readonly int $billingAccountId,
        public readonly string $metricCode,
        public readonly string $reservationUlid,
    ) {}
}
