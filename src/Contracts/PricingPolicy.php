<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Contracts;

use Moneo\UsageLimiter\DTOs\AffordabilityResult;
use Moneo\UsageLimiter\DTOs\ChargeResult;
use Moneo\UsageLimiter\DTOs\Period;
use Moneo\UsageLimiter\DTOs\RefundResult;
use Moneo\UsageLimiter\DTOs\ResolvedMetricLimit;
use Moneo\UsageLimiter\Models\BillingAccount;
use Moneo\UsageLimiter\Models\UsagePeriodAggregate;

interface PricingPolicy
{
    /**
     * Check whether the account can pay for potential overage at reservation time.
     *
     * Called during RESERVE, after enforcement passes.
     * Does NOT charge. Read-only check (except possible hold for prepaid).
     */
    public function authorize(
        BillingAccount $account,
        ResolvedMetricLimit $metricLimit,
        int $amount,
        Period $period,
        UsagePeriodAggregate $aggregate,
    ): AffordabilityResult;

    /**
     * Actually charge on commit.
     *
     * For prepaid: debit wallet, insert billing_transaction.
     * For postpaid: compute overage, upsert usage_overages.
     * For hybrid: free if within included, else charge overflow.
     *
     * Must be idempotent (keyed by reservationUlid).
     *
     * @param  int  $committedBefore  The committed_usage value BEFORE this reservation was committed.
     *                                Captured inside the commit transaction to prevent concurrency errors.
     */
    public function charge(
        BillingAccount $account,
        ResolvedMetricLimit $metricLimit,
        int $amount,
        Period $period,
        int $committedBefore,
        string $reservationUlid,
    ): ChargeResult;

    /**
     * Reverse a charge on release/refund.
     *
     * Must be idempotent.
     */
    public function refund(
        BillingAccount $account,
        ResolvedMetricLimit $metricLimit,
        int $amount,
        Period $period,
        string $reservationUlid,
    ): RefundResult;
}
