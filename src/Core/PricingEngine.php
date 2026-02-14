<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Core;

use Moneo\UsageLimiter\Contracts\PricingPolicy;
use Moneo\UsageLimiter\DTOs\AffordabilityResult;
use Moneo\UsageLimiter\DTOs\ChargeResult;
use Moneo\UsageLimiter\DTOs\Period;
use Moneo\UsageLimiter\DTOs\RefundResult;
use Moneo\UsageLimiter\DTOs\ResolvedMetricLimit;
use Moneo\UsageLimiter\Enums\PricingMode;
use Moneo\UsageLimiter\Models\BillingAccount;
use Moneo\UsageLimiter\Models\UsagePeriodAggregate;

class PricingEngine
{
    /** @var array<string, PricingPolicy> */
    private array $policies = [];

    /**
     * Register a pricing policy for a mode.
     */
    public function registerPolicy(PricingMode $mode, PricingPolicy $policy): void
    {
        $this->policies[$mode->value] = $policy;
    }

    /**
     * Check whether the account can afford the usage.
     */
    public function authorize(
        BillingAccount $account,
        ResolvedMetricLimit $metricLimit,
        int $amount,
        Period $period,
        UsagePeriodAggregate $aggregate,
    ): AffordabilityResult {
        $policy = $this->resolvePolicy($metricLimit->pricingMode);

        return $policy->authorize($account, $metricLimit, $amount, $period, $aggregate);
    }

    /**
     * Charge the account on commit.
     *
     * @param  int  $committedBefore  The committed_usage value BEFORE this reservation was committed.
     */
    public function charge(
        BillingAccount $account,
        ResolvedMetricLimit $metricLimit,
        int $amount,
        Period $period,
        int $committedBefore,
        string $reservationUlid,
    ): ChargeResult {
        $policy = $this->resolvePolicy($metricLimit->pricingMode);

        return $policy->charge($account, $metricLimit, $amount, $period, $committedBefore, $reservationUlid);
    }

    /**
     * Refund the account on release.
     */
    public function refund(
        BillingAccount $account,
        ResolvedMetricLimit $metricLimit,
        int $amount,
        Period $period,
        string $reservationUlid,
    ): RefundResult {
        $policy = $this->resolvePolicy($metricLimit->pricingMode);

        return $policy->refund($account, $metricLimit, $amount, $period, $reservationUlid);
    }

    private function resolvePolicy(PricingMode $mode): PricingPolicy
    {
        $policy = $this->policies[$mode->value] ?? null;

        if ($policy === null) {
            throw new \RuntimeException("No pricing policy registered for mode: {$mode->value}");
        }

        return $policy;
    }
}
