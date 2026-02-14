<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Core;

use Moneo\UsageLimiter\Contracts\PeriodResolver;
use Moneo\UsageLimiter\Contracts\PlanResolver;
use Moneo\UsageLimiter\Contracts\UsageRepository;
use Moneo\UsageLimiter\DTOs\CommitResult;
use Moneo\UsageLimiter\DTOs\EnforcementContext;
use Moneo\UsageLimiter\DTOs\EnforcementDecision;
use Moneo\UsageLimiter\DTOs\ReleaseResult;
use Moneo\UsageLimiter\DTOs\ReservationResult;
use Moneo\UsageLimiter\DTOs\UsageAttempt;
use Moneo\UsageLimiter\Events\LimitApproaching;
use Moneo\UsageLimiter\Events\LimitExceeded;
use Moneo\UsageLimiter\Events\OverageAccumulated;
use Moneo\UsageLimiter\Events\UsageCommitted;
use Moneo\UsageLimiter\Events\UsageReleased;
use Moneo\UsageLimiter\Events\UsageReserved;
use Moneo\UsageLimiter\Exceptions\InsufficientBalanceException;
use Moneo\UsageLimiter\Exceptions\UsageLimitExceededException;
use Moneo\UsageLimiter\Models\BillingAccount;

class UsageLimiter
{
    public function __construct(
        private readonly ReservationManager $reservationManager,
        private readonly PlanResolver $planResolver,
        private readonly PeriodResolver $periodResolver,
        private readonly UsageRepository $usageRepository,
        private readonly EnforcementEngine $enforcementEngine,
    ) {}

    /**
     * Reserve usage capacity (the RESERVE phase).
     *
     * Throws UsageLimitExceededException if hard enforcement denies.
     * Throws InsufficientBalanceException if prepaid wallet cannot afford.
     */
    public function reserve(UsageAttempt $attempt): ReservationResult
    {
        $account = BillingAccount::findOrFail($attempt->billingAccountId);

        if (! $account->is_active) {
            throw new UsageLimitExceededException(
                metricCode: $attempt->metricCode,
                billingAccountId: $attempt->billingAccountId,
                message: "Billing account {$attempt->billingAccountId} is inactive",
            );
        }

        $metricLimit = $this->planResolver->resolveMetric($attempt->billingAccountId, $attempt->metricCode);

        if ($metricLimit === null) {
            throw new UsageLimitExceededException(
                metricCode: $attempt->metricCode,
                billingAccountId: $attempt->billingAccountId,
                message: "Metric '{$attempt->metricCode}' is not configured for this account's plan",
            );
        }

        $period = $this->periodResolver->current($attempt->billingAccountId);

        $reserveResult = $this->reservationManager->reserve(
            $attempt,
            $account,
            $metricLimit,
            $period,
        );

        $result = $reserveResult['result'];

        if (! $result->allowed) {
            if ($result->isInsufficientBalance) {
                throw new InsufficientBalanceException(
                    billingAccountId: $attempt->billingAccountId,
                );
            }

            throw new UsageLimitExceededException(
                result: $result,
                metricCode: $attempt->metricCode,
                billingAccountId: $attempt->billingAccountId,
            );
        }

        // Fire events
        event(new UsageReserved(
            billingAccountId: $attempt->billingAccountId,
            metricCode: $attempt->metricCode,
            amount: $attempt->amount,
            reservationUlid: $result->ulid,
        ));

        // Check if approaching limit threshold
        $aggregate = $reserveResult['aggregate'];
        $threshold = (int) config('usage-limiter.limit_warning_threshold_percent', 80);
        $effectiveLimit = $metricLimit->effectiveLimit();

        if ($effectiveLimit > 0 && $effectiveLimit < PHP_INT_MAX) {
            $usagePercent = (($aggregate->committed_usage + $aggregate->reserved_usage) / $effectiveLimit) * 100;

            if ($usagePercent >= $threshold) {
                event(new LimitApproaching(
                    billingAccountId: $attempt->billingAccountId,
                    metricCode: $attempt->metricCode,
                    currentUsage: $aggregate->committed_usage + $aggregate->reserved_usage,
                    limit: $effectiveLimit,
                    percent: $usagePercent,
                ));
            }
        }

        return $result;
    }

    /**
     * Commit a reservation (the COMMIT phase).
     */
    public function commit(string $reservationUlid): CommitResult
    {
        $reservation = $this->usageRepository->findReservationByUlid($reservationUlid);

        if ($reservation === null) {
            throw new \RuntimeException("Reservation not found: {$reservationUlid}");
        }

        $account = BillingAccount::findOrFail($reservation->billing_account_id);
        $metricLimit = $this->planResolver->resolveMetric(
            $reservation->billing_account_id,
            $reservation->metric_code,
        );

        if ($metricLimit === null) {
            // Metric was removed from plan — commit anyway to avoid stuck reservations
            $metricLimit = $this->buildFallbackMetricLimit($reservation->metric_code);
        }

        $period = $this->periodResolver->forDate(
            \Carbon\CarbonImmutable::parse($reservation->period_start),
            $reservation->billing_account_id,
        );

        $result = $this->reservationManager->commit(
            $reservation,
            $account,
            $metricLimit,
            $period,
        );

        // Fire events
        event(new UsageCommitted(
            billingAccountId: $reservation->billing_account_id,
            metricCode: $reservation->metric_code,
            amount: $reservation->amount,
            reservationUlid: $reservation->ulid,
            chargedAmountCents: $result->chargedAmountCents,
        ));

        // Check if limit exceeded after commit
        if ($metricLimit->effectiveLimit() < PHP_INT_MAX) {
            $aggregate = $this->usageRepository->getOrCreateAggregate(
                $reservation->billing_account_id,
                $reservation->metric_code,
                $period,
            );

            if ($aggregate->committed_usage > $metricLimit->includedAmount) {
                event(new LimitExceeded(
                    billingAccountId: $reservation->billing_account_id,
                    metricCode: $reservation->metric_code,
                    currentUsage: $aggregate->committed_usage,
                    limit: $metricLimit->includedAmount,
                ));
            }
        }

        if ($result->overageRecorded) {
            event(new OverageAccumulated(
                billingAccountId: $reservation->billing_account_id,
                metricCode: $reservation->metric_code,
                reservationUlid: $reservation->ulid,
            ));
        }

        return $result;
    }

    /**
     * Release a reservation (the RELEASE phase).
     */
    public function release(string $reservationUlid): ReleaseResult
    {
        $reservation = $this->usageRepository->findReservationByUlid($reservationUlid);

        if ($reservation === null) {
            // Already cleaned up — return a no-op result
            return new ReleaseResult(
                ulid: $reservationUlid,
                released: false,
                refunded: false,
                refundedAmountCents: 0,
            );
        }

        $account = BillingAccount::findOrFail($reservation->billing_account_id);
        $metricLimit = $this->planResolver->resolveMetric(
            $reservation->billing_account_id,
            $reservation->metric_code,
        );

        if ($metricLimit === null) {
            $metricLimit = $this->buildFallbackMetricLimit($reservation->metric_code);
        }

        $period = $this->periodResolver->forDate(
            \Carbon\CarbonImmutable::parse($reservation->period_start),
            $reservation->billing_account_id,
        );

        $result = $this->reservationManager->release(
            $reservation,
            $account,
            $metricLimit,
            $period,
        );

        if ($result->released) {
            event(new UsageReleased(
                billingAccountId: $reservation->billing_account_id,
                metricCode: $reservation->metric_code,
                amount: $reservation->amount,
                reservationUlid: $reservation->ulid,
                refundedAmountCents: $result->refundedAmountCents,
            ));
        }

        return $result;
    }

    /**
     * Read-only check: can this usage be allowed?
     *
     * Does NOT create a reservation. For UI/pre-flight checks only.
     */
    public function check(int $billingAccountId, string $metricCode, int $amount): EnforcementDecision
    {
        $metricLimit = $this->planResolver->resolveMetric($billingAccountId, $metricCode);

        if ($metricLimit === null) {
            return EnforcementDecision::Deny;
        }

        $period = $this->periodResolver->current($billingAccountId);
        $aggregate = $this->usageRepository->getOrCreateAggregate(
            $billingAccountId,
            $metricCode,
            $period,
        );

        $context = new EnforcementContext(
            billingAccountId: $billingAccountId,
            metricLimit: $metricLimit,
            requestedAmount: $amount,
            currentCommitted: $aggregate->committed_usage,
            currentReserved: $aggregate->reserved_usage,
            effectiveLimit: $metricLimit->effectiveLimit(),
            period: $period,
        );

        return $this->enforcementEngine->evaluate($context);
    }

    /**
     * Get current usage stats for a metric.
     *
     * @return array{committed: int, reserved: int, limit: int, remaining: int}
     */
    public function currentUsage(int $billingAccountId, string $metricCode): array
    {
        $metricLimit = $this->planResolver->resolveMetric($billingAccountId, $metricCode);

        $period = $this->periodResolver->current($billingAccountId);
        $aggregate = $this->usageRepository->getOrCreateAggregate(
            $billingAccountId,
            $metricCode,
            $period,
        );

        $limit = $metricLimit?->effectiveLimit() ?? 0;
        $total = $aggregate->committed_usage + $aggregate->reserved_usage;

        return [
            'committed' => $aggregate->committed_usage,
            'reserved' => $aggregate->reserved_usage,
            'limit' => $limit,
            'remaining' => max(0, $limit - $total),
        ];
    }

    /**
     * Invalidate the plan cache for a billing account.
     */
    public function invalidatePlanCache(int $billingAccountId): void
    {
        $this->planResolver->invalidateCache($billingAccountId);
    }

    /**
     * Build a fallback metric limit when the metric has been removed from the plan.
     */
    private function buildFallbackMetricLimit(string $metricCode): \Moneo\UsageLimiter\DTOs\ResolvedMetricLimit
    {
        return new \Moneo\UsageLimiter\DTOs\ResolvedMetricLimit(
            metricCode: $metricCode,
            includedAmount: 0,
            overageEnabled: false,
            overageUnitSize: null,
            overagePriceCents: null,
            pricingMode: \Moneo\UsageLimiter\Enums\PricingMode::Postpaid,
            enforcementMode: \Moneo\UsageLimiter\Enums\EnforcementMode::Hard,
            maxOverageAmount: null,
            hybridOverflowMode: null,
        );
    }
}
