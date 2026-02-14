<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Policies\Enforcement;

use Moneo\UsageLimiter\Contracts\EnforcementPolicy;
use Moneo\UsageLimiter\Contracts\UsageRepository;
use Moneo\UsageLimiter\DTOs\EnforcementContext;
use Moneo\UsageLimiter\DTOs\EnforcementDecision;

class SoftEnforcementPolicy implements EnforcementPolicy
{
    public function evaluate(EnforcementContext $context): EnforcementDecision
    {
        if ($context->projectedTotal() > $context->effectiveLimit) {
            return EnforcementDecision::AllowWithWarning;
        }

        return EnforcementDecision::Allow;
    }

    public function reserveAtomic(
        UsageRepository $repository,
        EnforcementContext $context,
        int $aggregateId,
    ): array {
        // Soft enforcement: unconditional increment, then check
        $repository->atomicUnconditionalReserve(
            $aggregateId,
            $context->requestedAmount,
        );

        // After increment, determine if we're over the limit
        $decision = $context->projectedTotal() > $context->effectiveLimit
            ? EnforcementDecision::AllowWithWarning
            : EnforcementDecision::Allow;

        return [
            'success' => true, // Soft enforcement always succeeds
            'decision' => $decision,
        ];
    }
}
