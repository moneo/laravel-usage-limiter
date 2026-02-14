<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Contracts;

use Moneo\UsageLimiter\DTOs\ResolvedMetricLimit;
use Moneo\UsageLimiter\DTOs\ResolvedPlan;

interface PlanResolver
{
    /**
     * Resolve the full plan with all metric limits for a billing account.
     *
     * Loads the account's current plan assignment, all plan_metric_limits,
     * and all active billing_account_metric_overrides. Merges overrides
     * on a per-field basis (override wins when non-null).
     *
     * Should be cached.
     */
    public function resolve(int $billingAccountId): ResolvedPlan;

    /**
     * Convenience: resolve just one metric for a billing account.
     *
     * Returns null if metric not configured for this account's plan.
     */
    public function resolveMetric(int $billingAccountId, string $metricCode): ?ResolvedMetricLimit;

    /**
     * Invalidate the cached plan for a billing account.
     *
     * Should be called when the account's plan assignment or overrides change.
     */
    public function invalidateCache(int $billingAccountId): void;
}
