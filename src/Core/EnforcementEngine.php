<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Core;

use Moneo\UsageLimiter\Contracts\EnforcementPolicy;
use Moneo\UsageLimiter\Contracts\UsageRepository;
use Moneo\UsageLimiter\DTOs\EnforcementContext;
use Moneo\UsageLimiter\DTOs\EnforcementDecision;
use Moneo\UsageLimiter\Enums\EnforcementMode;

class EnforcementEngine
{
    /** @var array<string, EnforcementPolicy> */
    private array $policies = [];

    /**
     * Register an enforcement policy for a mode.
     */
    public function registerPolicy(EnforcementMode $mode, EnforcementPolicy $policy): void
    {
        $this->policies[$mode->value] = $policy;
    }

    /**
     * Evaluate enforcement without state change (read-only pre-flight check).
     */
    public function evaluate(EnforcementContext $context): EnforcementDecision
    {
        $policy = $this->resolvePolicy($context->metricLimit->enforcementMode);

        return $policy->evaluate($context);
    }

    /**
     * Perform the atomic reserve with enforcement check in one operation.
     *
     * @return array{success: bool, decision: EnforcementDecision}
     */
    public function reserveWithEnforcement(
        UsageRepository $repository,
        EnforcementContext $context,
        int $aggregateId,
    ): array {
        $policy = $this->resolvePolicy($context->metricLimit->enforcementMode);

        return $policy->reserveAtomic($repository, $context, $aggregateId);
    }

    private function resolvePolicy(EnforcementMode $mode): EnforcementPolicy
    {
        $policy = $this->policies[$mode->value] ?? null;

        if ($policy === null) {
            throw new \RuntimeException("No enforcement policy registered for mode: {$mode->value}");
        }

        return $policy;
    }
}
