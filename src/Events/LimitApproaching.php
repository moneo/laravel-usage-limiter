<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Events;

use Illuminate\Foundation\Events\Dispatchable;

class LimitApproaching
{
    use Dispatchable;

    public function __construct(
        public readonly int $billingAccountId,
        public readonly string $metricCode,
        public readonly int $currentUsage,
        public readonly int $limit,
        public readonly float $percent,
    ) {}
}
