<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Contracts;

use Moneo\UsageLimiter\DTOs\EnforcementContext;
use Moneo\UsageLimiter\DTOs\EnforcementDecision;

interface EnforcementPolicy
{
    /**
     * Evaluate whether the usage should be allowed (read-only, no state change).
     *
     * Used for pre-flight checks (e.g., UI indicators).
     */
    public function evaluate(EnforcementContext $context): EnforcementDecision;

    /**
     * Perform the atomic reserve operation combining check + increment.
     *
     * This couples enforcement decision with the atomic DB operation
     * to prevent TOCTOU between evaluate() and increment.
     *
     * HARD implementation: conditional UPDATE (only if within limit)
     * SOFT implementation: unconditional UPDATE + post-check
     *
     * @return array{success: bool, decision: EnforcementDecision}
     */
    public function reserveAtomic(
        UsageRepository $repository,
        EnforcementContext $context,
        int $aggregateId,
    ): array;
}
