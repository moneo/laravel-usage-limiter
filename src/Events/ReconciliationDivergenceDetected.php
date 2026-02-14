<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Events;

use Illuminate\Foundation\Events\Dispatchable;

class ReconciliationDivergenceDetected
{
    use Dispatchable;

    public function __construct(
        public readonly int $billingAccountId,
        public readonly string $metricCode,
        public readonly string $periodStart,
        public readonly string $type,
        public readonly int $expected,
        public readonly int $actual,
        public readonly bool $corrected,
    ) {}
}
