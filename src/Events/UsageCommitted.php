<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Events;

use Illuminate\Foundation\Events\Dispatchable;

class UsageCommitted
{
    use Dispatchable;

    public function __construct(
        public readonly int $billingAccountId,
        public readonly string $metricCode,
        public readonly int $amount,
        public readonly string $reservationUlid,
        public readonly int $chargedAmountCents,
    ) {}
}
