<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Policies\Pricing;

use Moneo\UsageLimiter\Contracts\PricingPolicy;
use Moneo\UsageLimiter\DTOs\AffordabilityResult;
use Moneo\UsageLimiter\DTOs\ChargeResult;
use Moneo\UsageLimiter\DTOs\Period;
use Moneo\UsageLimiter\DTOs\RefundResult;
use Moneo\UsageLimiter\DTOs\ResolvedMetricLimit;
use Moneo\UsageLimiter\Models\BillingAccount;
use Moneo\UsageLimiter\Models\UsageOverage;
use Moneo\UsageLimiter\Models\UsagePeriodAggregate;

class PostpaidPricingPolicy implements PricingPolicy
{
    public function authorize(
        BillingAccount $account,
        ResolvedMetricLimit $metricLimit,
        int $amount,
        Period $period,
        UsagePeriodAggregate $aggregate,
    ): AffordabilityResult {
        // Postpaid always passes authorization (billing happens later).
        // max_overage_amount is enforced by the EnforcementPolicy, not here.
        return AffordabilityResult::canAfford(estimatedCostCents: 0);
    }

    public function charge(
        BillingAccount $account,
        ResolvedMetricLimit $metricLimit,
        int $amount,
        Period $period,
        int $committedBefore,
        string $reservationUlid,
    ): ChargeResult {
        // committedBefore is the actual pre-commit value. Compute committed after.
        $totalCommittedAfter = $committedBefore + $amount;
        $totalOverage = max(0, $totalCommittedAfter - $metricLimit->includedAmount);

        if ($totalOverage <= 0 || ! $metricLimit->overageEnabled) {
            return ChargeResult::noCharge();
        }

        // Use the canonical formula for consistency with PrepaidPricingPolicy
        $totalPrice = $metricLimit->calculateOverageCost($totalOverage);
        $unitSize = max($metricLimit->overageUnitSize ?? 1, 1);
        $unitPrice = $metricLimit->overagePriceCents ?? 0;

        // If the canonical formula returns 0 (misconfigured unitSize/price), skip the record
        if ($totalPrice === 0) {
            return ChargeResult::noCharge();
        }

        // Upsert the overage record — one per (account, metric, period)
        // total_price_cents is always recomputed from cumulative overage_amount
        UsageOverage::updateOrCreate(
            [
                'billing_account_id' => $account->id,
                'metric_code' => $metricLimit->metricCode,
                'period_start' => $period->startDate(),
            ],
            [
                'overage_amount' => $totalOverage,
                'overage_unit_size' => $unitSize,
                'unit_price_cents' => $unitPrice,
                'total_price_cents' => $totalPrice,
                'settlement_status' => 'pending',
            ]
        );

        return new ChargeResult(
            charged: false, // Postpaid = no immediate charge
            amountCents: $totalPrice,
            overageRecorded: true,
        );
    }

    public function refund(
        BillingAccount $account,
        ResolvedMetricLimit $metricLimit,
        int $amount,
        Period $period,
        string $reservationUlid,
    ): RefundResult {
        // Postpaid: nothing charged, nothing to refund.
        // Overage records will be recalculated by reconciliation if needed.
        return RefundResult::nothingToRefund();
    }
}
