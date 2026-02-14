<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\DTOs;

final readonly class EnforcementContext
{
    public function __construct(
        public int $billingAccountId,
        public ResolvedMetricLimit $metricLimit,
        public int $requestedAmount,
        public int $currentCommitted,
        public int $currentReserved,
        public int $effectiveLimit,
        public Period $period,
    ) {}

    public function currentTotal(): int
    {
        return $this->currentCommitted + $this->currentReserved;
    }

    public function projectedTotal(): int
    {
        return $this->currentTotal() + $this->requestedAmount;
    }

    public function remaining(): int
    {
        return max(0, $this->effectiveLimit - $this->currentTotal());
    }

    public function usagePercent(): float
    {
        if ($this->effectiveLimit === 0) {
            return $this->currentTotal() > 0 ? 100.0 : 0.0;
        }

        return ($this->currentTotal() / $this->effectiveLimit) * 100;
    }
}
