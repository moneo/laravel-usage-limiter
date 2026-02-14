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
use Moneo\UsageLimiter\Events\WalletTopupRequested;
use Moneo\UsageLimiter\Models\BillingAccount;
use Moneo\UsageLimiter\Models\UsagePeriodAggregate;

class PrepaidPricingPolicy implements PricingPolicy
{
    public function __construct(
        private readonly WalletRepository $walletRepository,
    ) {}

    public function authorize(
        BillingAccount $account,
        ResolvedMetricLimit $metricLimit,
        int $amount,
        Period $period,
        UsagePeriodAggregate $aggregate,
    ): AffordabilityResult {
        // authorize() is called after reserve, so reserved_usage already includes this amount.
        // Estimate incremental commit charge from committed_before -> committed_after.
        $committedBefore = $aggregate->committed_usage;
        $committedAfter = $committedBefore + $amount;
        $overageBefore = max(0, $committedBefore - $metricLimit->includedAmount);
        $overageAfter = max(0, $committedAfter - $metricLimit->includedAmount);

        if ($overageAfter <= $overageBefore) {
            return AffordabilityResult::free();
        }

        $cost = $metricLimit->calculateOverageCost($overageAfter)
            - $metricLimit->calculateOverageCost($overageBefore);

        if ($cost === 0) {
            return AffordabilityResult::free();
        }

        $balance = $this->walletRepository->getBalance($account->id);

        // Check if auto-topup should be triggered
        if ($account->auto_topup_enabled
            && $account->auto_topup_threshold_cents !== null
            && $balance - $cost < $account->auto_topup_threshold_cents) {
            event(new WalletTopupRequested(
                billingAccountId: $account->id,
                requestedAmountCents: $account->auto_topup_amount_cents ?? $cost,
                currentBalanceCents: $balance,
            ));
        }

        // Check affordability with current balance
        if ($balance < $cost) {
            return AffordabilityResult::cannotAfford(
                reason: "Insufficient wallet balance (available: {$balance}, required: {$cost})",
                estimatedCostCents: $cost,
                isInsufficientBalance: true,
            );
        }

        return AffordabilityResult::canAfford($cost);
    }

    public function charge(
        BillingAccount $account,
        ResolvedMetricLimit $metricLimit,
        int $amount,
        Period $period,
        int $committedBefore,
        string $reservationUlid,
    ): ChargeResult {
        // committedBefore is the actual pre-commit value, captured inside the commit
        // transaction. This prevents concurrency-induced pricing errors where
        // reconstructing committedBefore from the refreshed aggregate could include
        // other concurrent commits.
        $committedAfter = $committedBefore + $amount;
        $overageBefore = max(0, $committedBefore - $metricLimit->includedAmount);
        $overageAfter = max(0, $committedAfter - $metricLimit->includedAmount);
        $incrementalOverage = max(0, $overageAfter - $overageBefore);

        if ($incrementalOverage <= 0) {
            return ChargeResult::free();
        }

        $cost = $metricLimit->calculateOverageCost($overageAfter)
            - $metricLimit->calculateOverageCost($overageBefore);

        if ($cost === 0) {
            return ChargeResult::free();
        }

        $idempotencyKey = $reservationUlid;

        $debited = $this->walletRepository->atomicDebit(
            billingAccountId: $account->id,
            amountCents: $cost,
            idempotencyKey: $idempotencyKey,
            referenceType: 'usage_reservation',
            referenceId: null,
            description: "Usage charge for {$metricLimit->metricCode}: {$incrementalOverage} overage units",
        );

        return new ChargeResult(
            charged: $debited,
            amountCents: $debited ? $cost : 0,
            overageRecorded: false, // Prepaid = wallet debit, not overage record
            transactionIdempotencyKey: $idempotencyKey,
        );
    }

    public function refund(
        BillingAccount $account,
        ResolvedMetricLimit $metricLimit,
        int $amount,
        Period $period,
        string $reservationUlid,
    ): RefundResult {
        $idempotencyKey = "refund:{$reservationUlid}";

        // Check if there's a debit transaction to refund
        $debitTxn = $this->walletRepository->getTransactionByIdempotencyKey($reservationUlid);

        if ($debitTxn === null) {
            return RefundResult::nothingToRefund();
        }

        $refundAmount = abs($debitTxn->amount_cents);

        $credited = $this->walletRepository->atomicCredit(
            billingAccountId: $account->id,
            amountCents: $refundAmount,
            idempotencyKey: $idempotencyKey,
            referenceType: 'usage_reservation',
            referenceId: null,
            description: "Refund for released reservation {$reservationUlid}",
        );

        return new RefundResult(
            refunded: $credited,
            amountCents: $credited ? $refundAmount : 0,
        );
    }
}
