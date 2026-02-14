<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Policies\Enforcement;

use Moneo\UsageLimiter\Contracts\EnforcementPolicy;
use Moneo\UsageLimiter\Contracts\UsageRepository;
use Moneo\UsageLimiter\DTOs\EnforcementContext;
use Moneo\UsageLimiter\DTOs\EnforcementDecision;

class HardEnforcementPolicy implements EnforcementPolicy
{
    public function evaluate(EnforcementContext $context): EnforcementDecision
    {
        if ($context->projectedTotal() > $context->effectiveLimit) {
            return EnforcementDecision::Deny;
        }

        return EnforcementDecision::Allow;
    }

    public function reserveAtomic(
        UsageRepository $repository,
        EnforcementContext $context,
        int $aggregateId,
    ): array {
        // The atomic conditional UPDATE: check + reserve in one statement
        $success = $repository->atomicConditionalReserve(
            $aggregateId,
            $context->requestedAmount,
            $context->effectiveLimit,
        );

        return [
            'success' => $success,
            'decision' => $success ? EnforcementDecision::Allow : EnforcementDecision::Deny,
        ];
    }
}
