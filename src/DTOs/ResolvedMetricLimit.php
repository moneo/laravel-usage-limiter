<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\DTOs;

use Moneo\UsageLimiter\Enums\EnforcementMode;
use Moneo\UsageLimiter\Enums\PricingMode;

final readonly class ResolvedMetricLimit
{
    public function __construct(
        public string $metricCode,
        public int $includedAmount,
        public bool $overageEnabled,
        public ?int $overageUnitSize,
        public ?int $overagePriceCents,
        public PricingMode $pricingMode,
        public EnforcementMode $enforcementMode,
        public ?int $maxOverageAmount,
        public ?string $hybridOverflowMode,
        public ?array $metadata = null,
    ) {}

    /**
     * Compute the effective limit for enforcement decisions.
     *
     * For hard enforcement: this is the maximum usage allowed.
     * - If overage is disabled: effective limit = included amount
     * - If overage is enabled with a cap: effective limit = included + max overage
     * - If overage is enabled without a cap: effective limit = PHP_INT_MAX (unlimited)
     */
    public function effectiveLimit(): int
    {
        if (! $this->overageEnabled) {
            return $this->includedAmount;
        }

        if ($this->maxOverageAmount !== null) {
            return $this->includedAmount + $this->maxOverageAmount;
        }

        return PHP_INT_MAX;
    }

    /**
     * Calculate the cost in cents for a given overage amount.
     */
    public function calculateOverageCost(int $overageUnits): int
    {
        if (! $this->overageEnabled || $overageUnits <= 0) {
            return 0;
        }

        if ($this->overageUnitSize === null || $this->overagePriceCents === null || $this->overageUnitSize === 0) {
            return 0;
        }

        $chunks = (int) ceil($overageUnits / $this->overageUnitSize);

        return $chunks * $this->overagePriceCents;
    }
}
