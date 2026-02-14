<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Policies\Pricing;

use Moneo\UsageLimiter\Contracts\PricingPolicy;
use Moneo\UsageLimiter\Contracts\WalletRepository;
use Moneo\UsageLimiter\DTOs\AffordabilityResult;
use Moneo\UsageLimiter\DTOs\ChargeResult;
use Moneo\UsageLimiter\DTOs\Period;
use Moneo\UsageLimiter\DTOs\RefundResult;
use Moneo\UsageLimiter\DTOs\ResolvedMetricLimit;
use Moneo\UsageLimiter\Models\BillingAccount;
use Moneo\UsageLimiter\Models\UsagePeriodAggregate;

/**
 * Hybrid pricing: included free allowance + overflow via prepaid or postpaid.
 *
 * The hybrid_overflow_mode on the metric limit determines how the
 * overflow portion (beyond included_amount) is settled.
 */
class HybridPricingPolicy implements PricingPolicy
{
    private readonly PrepaidPricingPolicy $prepaidPolicy;

    private readonly PostpaidPricingPolicy $postpaidPolicy;

    public function __construct(
        WalletRepository $walletRepository,
    ) {
        $this->prepaidPolicy = new PrepaidPricingPolicy($walletRepository);
        $this->postpaidPolicy = new PostpaidPricingPolicy;
    }

    public function authorize(
        BillingAccount $account,
        ResolvedMetricLimit $metricLimit,
        int $amount,
        Period $period,
        UsagePeriodAggregate $aggregate,
    ): AffordabilityResult {
        // authorize() runs after reserve; estimate whether commit would exceed included usage.
        $projectedCommitted = $aggregate->committed_usage + $amount;

        if ($projectedCommitted <= $metricLimit->includedAmount) {
            return AffordabilityResult::free();
        }

        // Overflow exists — delegate to the configured overflow policy
        return $this->overflowPolicy($metricLimit)->authorize(
            $account, $metricLimit, $amount, $period, $aggregate,
        );
    }

    public function charge(
        BillingAccount $account,
        ResolvedMetricLimit $metricLimit,
        int $amount,
        Period $period,
        int $committedBefore,
        string $reservationUlid,
    ): ChargeResult {
        // committedBefore is the actual pre-commit value.
        $totalCommittedAfter = $committedBefore + $amount;

        if ($totalCommittedAfter <= $metricLimit->includedAmount) {
            return ChargeResult::free();
        }

        // Delegate the charge to the overflow policy
        return $this->overflowPolicy($metricLimit)->charge(
            $account, $metricLimit, $amount, $period, $committedBefore, $reservationUlid,
        );
    }

    public function refund(
        BillingAccount $account,
        ResolvedMetricLimit $metricLimit,
        int $amount,
        Period $period,
        string $reservationUlid,
    ): RefundResult {
        return $this->overflowPolicy($metricLimit)->refund(
            $account, $metricLimit, $amount, $period, $reservationUlid,
        );
    }

    private function overflowPolicy(ResolvedMetricLimit $metricLimit): PricingPolicy
    {
        if ($metricLimit->hybridOverflowMode === 'prepaid') {
            return $this->prepaidPolicy;
        }

        // Default to postpaid overflow
        return $this->postpaidPolicy;
    }
}
