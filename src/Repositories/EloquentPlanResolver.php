<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Repositories;

use Illuminate\Support\Facades\Cache;
use Moneo\UsageLimiter\Contracts\PlanResolver;
use Moneo\UsageLimiter\DTOs\ResolvedMetricLimit;
use Moneo\UsageLimiter\DTOs\ResolvedPlan;
use Moneo\UsageLimiter\Enums\EnforcementMode;
use Moneo\UsageLimiter\Enums\PricingMode;
use Moneo\UsageLimiter\Models\BillingAccountMetricOverride;
use Moneo\UsageLimiter\Models\BillingAccountPlanAssignment;
use Moneo\UsageLimiter\Models\PlanMetricLimit;

class EloquentPlanResolver implements PlanResolver
{
    public function resolve(int $billingAccountId): ResolvedPlan
    {
        $cacheKey = $this->cacheKey($billingAccountId);
        $ttl = (int) config('usage-limiter.cache.ttl_seconds', 60);
        $store = config('usage-limiter.cache.store');

        return Cache::store($store)->remember($cacheKey, $ttl, function () use ($billingAccountId) {
            return $this->buildResolvedPlan($billingAccountId);
        });
    }

    public function resolveMetric(int $billingAccountId, string $metricCode): ?ResolvedMetricLimit
    {
        $plan = $this->resolve($billingAccountId);

        return $plan->getMetric($metricCode);
    }

    public function invalidateCache(int $billingAccountId): void
    {
        $cacheKey = $this->cacheKey($billingAccountId);
        $store = config('usage-limiter.cache.store');

        Cache::store($store)->forget($cacheKey);
    }

    private function buildResolvedPlan(int $billingAccountId): ResolvedPlan
    {
        // Get current plan assignment
        $assignment = BillingAccountPlanAssignment::where('billing_account_id', $billingAccountId)
            ->where('started_at', '<=', now('UTC'))
            ->whereNull('ended_at')
            ->latest('started_at')
            ->with('plan')
            ->first();

        if ($assignment === null || $assignment->plan === null) {
            return new ResolvedPlan(
                planId: 0,
                planCode: 'none',
                metrics: [],
            );
        }

        $plan = $assignment->plan;

        // Load all metric limits for the plan
        $planLimits = PlanMetricLimit::where('plan_id', $plan->id)->get();

        // Load all active overrides for this account
        $overrides = BillingAccountMetricOverride::where('billing_account_id', $billingAccountId)
            ->where('started_at', '<=', now('UTC'))
            ->whereNull('ended_at')
            ->get()
            ->keyBy('metric_code');

        // Merge plan limits with overrides
        $metrics = [];

        foreach ($planLimits as $limit) {
            $override = $overrides->get($limit->metric_code);
            $metrics[$limit->metric_code] = $this->mergeLimit($limit, $override);
        }

        return new ResolvedPlan(
            planId: $plan->id,
            planCode: $plan->code,
            metrics: $metrics,
        );
    }

    private function mergeLimit(PlanMetricLimit $limit, ?BillingAccountMetricOverride $override): ResolvedMetricLimit
    {
        $defaultEnforcement = EnforcementMode::tryFrom(
            config('usage-limiter.default_enforcement_mode', 'hard')
        ) ?? EnforcementMode::Hard;

        $defaultPricing = PricingMode::tryFrom(
            config('usage-limiter.default_pricing_mode', 'postpaid')
        ) ?? PricingMode::Postpaid;

        if ($override === null) {
            return new ResolvedMetricLimit(
                metricCode: $limit->metric_code,
                includedAmount: (int) $limit->included_amount,
                overageEnabled: (bool) $limit->overage_enabled,
                overageUnitSize: $limit->overage_unit_size ? (int) $limit->overage_unit_size : null,
                overagePriceCents: $limit->overage_price_cents ? (int) $limit->overage_price_cents : null,
                pricingMode: $limit->pricing_mode ?? $defaultPricing,
                enforcementMode: $limit->enforcement_mode ?? $defaultEnforcement,
                maxOverageAmount: $limit->max_overage_amount ? (int) $limit->max_overage_amount : null,
                hybridOverflowMode: $limit->hybrid_overflow_mode,
                metadata: $limit->metadata,
            );
        }

        // Override: only non-null DB fields win.
        // We use hasOverrideFor() which checks the raw DB value to avoid Eloquent
        // cast interference (e.g. integer cast turning null → 0, boolean cast
        // turning null → false). Without this, ANY override row — even one that
        // only overrides enforcement_mode — would silently zero-out included_amount
        // and disable overage.
        return new ResolvedMetricLimit(
            metricCode: $limit->metric_code,
            includedAmount: $override->hasOverrideFor('included_amount')
                ? (int) $override->included_amount
                : (int) $limit->included_amount,
            overageEnabled: $override->hasOverrideFor('overage_enabled')
                ? (bool) $override->overage_enabled
                : (bool) $limit->overage_enabled,
            overageUnitSize: $override->hasOverrideFor('overage_unit_size')
                ? (int) $override->overage_unit_size
                : ($limit->overage_unit_size ? (int) $limit->overage_unit_size : null),
            overagePriceCents: $override->hasOverrideFor('overage_price_cents')
                ? (int) $override->overage_price_cents
                : ($limit->overage_price_cents ? (int) $limit->overage_price_cents : null),
            pricingMode: $override->pricing_mode ?? $limit->pricing_mode ?? $defaultPricing,
            enforcementMode: $override->enforcement_mode ?? $limit->enforcement_mode ?? $defaultEnforcement,
            maxOverageAmount: $override->hasOverrideFor('max_overage_amount')
                ? (int) $override->max_overage_amount
                : ($limit->max_overage_amount ? (int) $limit->max_overage_amount : null),
            hybridOverflowMode: $override->hybrid_overflow_mode ?? $limit->hybrid_overflow_mode,
            metadata: array_merge($limit->metadata ?? [], $override->metadata ?? []),
        );
    }

    private function cacheKey(int $billingAccountId): string
    {
        $prefix = config('usage-limiter.cache.prefix', 'ul_plan:');

        return $prefix.$billingAccountId;
    }
}
