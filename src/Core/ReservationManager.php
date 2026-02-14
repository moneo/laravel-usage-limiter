<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Core;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Moneo\UsageLimiter\Contracts\UsageRepository;
use Moneo\UsageLimiter\DTOs\CommitResult;
use Moneo\UsageLimiter\DTOs\EnforcementContext;
use Moneo\UsageLimiter\DTOs\EnforcementDecision;
use Moneo\UsageLimiter\DTOs\Period;
use Moneo\UsageLimiter\DTOs\ReleaseResult;
use Moneo\UsageLimiter\DTOs\ReservationResult;
use Moneo\UsageLimiter\DTOs\ResolvedMetricLimit;
use Moneo\UsageLimiter\DTOs\UsageAttempt;
use Moneo\UsageLimiter\Enums\ReservationStatus;
use Moneo\UsageLimiter\Exceptions\ReservationExpiredException;
use Moneo\UsageLimiter\Models\BillingAccount;
use Moneo\UsageLimiter\Models\UsagePeriodAggregate;
use Moneo\UsageLimiter\Models\UsageReservation;

class ReservationManager
{
    private const DEADLOCK_RETRIES = 3;

    public function __construct(
        private readonly UsageRepository $usageRepository,
        private readonly EnforcementEngine $enforcementEngine,
        private readonly PricingEngine $pricingEngine,
    ) {}

    /**
     * Create a reservation (the RESERVE phase).
     *
     * The entire flow is wrapped in a DB transaction so that if any step fails
     * (crash, constraint violation, exception), the aggregate increment is
     * rolled back and no phantom reserved_usage can leak.
     *
     * @return array{result: ReservationResult, aggregate: UsagePeriodAggregate}
     */
    public function reserve(
        UsageAttempt $attempt,
        BillingAccount $account,
        ResolvedMetricLimit $metricLimit,
        Period $period,
    ): array {
        return $this->dbConnection()->transaction(function () use ($attempt, $account, $metricLimit, $period) {
            // 1. Idempotency check (scoped to billing account)
            if ($attempt->idempotencyKey !== null) {
                $existing = $this->usageRepository->findReservationByIdempotencyKey(
                    $attempt->idempotencyKey,
                    $attempt->billingAccountId,
                );
                if ($existing !== null) {
                    return [
                        'result' => new ReservationResult(
                            ulid: $existing->ulid,
                            allowed: $existing->isPending() || $existing->isCommitted(),
                            decision: EnforcementDecision::Allow,
                            warning: 'Idempotent replay: reservation already exists',
                        ),
                        'aggregate' => $this->usageRepository->getOrCreateAggregate(
                            $attempt->billingAccountId,
                            $attempt->metricCode,
                            $period,
                        ),
                    ];
                }
            }

            // 2. Get or create the aggregate row (upsert)
            $aggregate = $this->usageRepository->getOrCreateAggregate(
                $attempt->billingAccountId,
                $attempt->metricCode,
                $period,
            );

            // 3. Build enforcement context
            $context = new EnforcementContext(
                billingAccountId: $attempt->billingAccountId,
                metricLimit: $metricLimit,
                requestedAmount: $attempt->amount,
                currentCommitted: $aggregate->committed_usage,
                currentReserved: $aggregate->reserved_usage,
                effectiveLimit: $metricLimit->effectiveLimit(),
                period: $period,
            );

            // 4. Atomic enforcement check + reserve
            $enforcementResult = $this->enforcementEngine->reserveWithEnforcement(
                $this->usageRepository,
                $context,
                $aggregate->id,
            );

            if (! $enforcementResult['success']) {
                return [
                    'result' => ReservationResult::denied('Usage limit exceeded'),
                    'aggregate' => $aggregate,
                ];
            }

            // 5. Pricing authorization (for prepaid/hybrid — checks wallet)
            $affordability = $this->pricingEngine->authorize(
                $account,
                $metricLimit,
                $attempt->amount,
                $period,
                $this->usageRepository->refreshAggregate($aggregate),
            );

            if (! $affordability->affordable) {
                // Release the reservation we just made (within the same transaction)
                $this->usageRepository->atomicRelease($aggregate->id, $attempt->amount);

                return [
                    'result' => ReservationResult::denied(
                        $affordability->reason ?? 'Cannot afford usage',
                        isInsufficientBalance: $affordability->isInsufficientBalance,
                    ),
                    'aggregate' => $aggregate,
                ];
            }

            // 6. Create reservation record (protected by the transaction — if INSERT
            //    fails due to duplicate idempotency key, the aggregate increment rolls back)
            $ulid = Str::ulid()->toBase32();
            $ttlMinutes = (int) config('usage-limiter.reservation_ttl_minutes', 15);

            $reservation = $this->usageRepository->createReservation([
                'ulid' => $ulid,
                'billing_account_id' => $attempt->billingAccountId,
                'metric_code' => $attempt->metricCode,
                'period_start' => $period->startDate(),
                'amount' => $attempt->amount,
                'idempotency_key' => $attempt->idempotencyKey,
                'status' => ReservationStatus::Pending->value,
                'reserved_at' => now('UTC'),
                'expires_at' => now('UTC')->addMinutes($ttlMinutes),
                'metadata' => $attempt->metadata,
            ]);

            $decision = $enforcementResult['decision'];
            $warning = $decision->hasWarning() ? 'Usage exceeds included limit (soft enforcement)' : null;

            return [
                'result' => new ReservationResult(
                    ulid: $reservation->ulid,
                    allowed: true,
                    decision: $decision,
                    warning: $warning,
                ),
                'aggregate' => $this->usageRepository->refreshAggregate($aggregate),
            ];
        }, self::DEADLOCK_RETRIES);
    }

    /**
     * Commit a reservation (the COMMIT phase).
     *
     * The CAS transition and aggregate adjustment are wrapped in a single DB
     * transaction so that a crash between the two cannot leave the system in an
     * inconsistent state. On rollback, the reservation stays pending and the
     * caller can safely retry.
     *
     * The pricing charge (wallet debit / overage record) is executed after the
     * transaction commits — it has its own idempotency protection via the
     * reservation ULID as an idempotency key.
     */
    public function commit(
        UsageReservation $reservation,
        BillingAccount $account,
        ResolvedMetricLimit $metricLimit,
        Period $period,
    ): CommitResult {
        // Check if already committed (idempotent fast-path, no transaction needed)
        if ($reservation->fresh()->isCommitted()) {
            return new CommitResult(
                ulid: $reservation->ulid,
                committed: true,
                charged: false,
                chargedAmountCents: 0,
                overageRecorded: false,
                warning: 'Already committed (idempotent replay)',
            );
        }

        // CAS transition + aggregate adjustment in one transaction.
        // If anything fails, the entire operation rolls back and the
        // reservation stays pending — safe to retry.
        $committedBefore = $this->dbConnection()->transaction(function () use ($reservation, $period) {
            // CAS transition: pending → committed
            $transitioned = $this->usageRepository->transitionReservation(
                $reservation->id,
                ReservationStatus::Pending,
                ReservationStatus::Committed,
            );

            if (! $transitioned) {
                // Already committed (concurrent commit won the race),
                // released, or expired — cannot proceed.
                $fresh = $reservation->fresh();
                if ($fresh && $fresh->isCommitted()) {
                    return null; // Signal idempotent success
                }

                throw new ReservationExpiredException($reservation->ulid);
            }

            // Aggregate adjustment: reserved -= N, committed += N
            $aggregate = $this->usageRepository->getOrCreateAggregate(
                $reservation->billing_account_id,
                $reservation->metric_code,
                $period,
            );

            $before = $aggregate->committed_usage;

            $this->usageRepository->atomicCommit($aggregate->id, $reservation->amount);

            return $before;
        }, self::DEADLOCK_RETRIES);

        // Idempotent commit (CAS lost to a concurrent commit)
        if ($committedBefore === null) {
            return new CommitResult(
                ulid: $reservation->ulid,
                committed: true,
                charged: false,
                chargedAmountCents: 0,
                overageRecorded: false,
                warning: 'Already committed (idempotent replay)',
            );
        }

        // Pricing charge (idempotent via reservationUlid, safe outside the transaction)
        $chargeResult = $this->pricingEngine->charge(
            $account,
            $metricLimit,
            $reservation->amount,
            $period,
            $committedBefore,
            $reservation->ulid,
        );

        return new CommitResult(
            ulid: $reservation->ulid,
            committed: true,
            charged: $chargeResult->charged,
            chargedAmountCents: $chargeResult->amountCents,
            overageRecorded: $chargeResult->overageRecorded,
        );
    }

    /**
     * Release a reservation (the RELEASE phase).
     *
     * The CAS transition and aggregate release are wrapped in a single DB
     * transaction. The pricing refund runs after — it has its own idempotency.
     */
    public function release(
        UsageReservation $reservation,
        BillingAccount $account,
        ResolvedMetricLimit $metricLimit,
        Period $period,
    ): ReleaseResult {
        // Check if already in a terminal state (idempotent fast-path)
        $fresh = $reservation->fresh();
        if ($fresh && ($fresh->isReleased() || $fresh->isExpired())) {
            return new ReleaseResult(
                ulid: $reservation->ulid,
                released: true,
                refunded: false,
                refundedAmountCents: 0,
            );
        }
        if ($fresh && $fresh->isCommitted()) {
            return new ReleaseResult(
                ulid: $reservation->ulid,
                released: false,
                refunded: false,
                refundedAmountCents: 0,
            );
        }

        // CAS transition + aggregate release in one transaction.
        // If anything fails, the reservation stays pending — safe to retry.
        $released = $this->dbConnection()->transaction(function () use ($reservation, $period): bool {
            $transitioned = $this->usageRepository->transitionReservation(
                $reservation->id,
                ReservationStatus::Pending,
                ReservationStatus::Released,
            );

            if (! $transitioned) {
                return false;
            }

            $aggregate = $this->usageRepository->getOrCreateAggregate(
                $reservation->billing_account_id,
                $reservation->metric_code,
                $period,
            );

            $this->usageRepository->atomicRelease($aggregate->id, $reservation->amount);

            return true;
        }, self::DEADLOCK_RETRIES);

        if (! $released) {
            // CAS failed inside the transaction — check current state
            $fresh = $reservation->fresh();
            if ($fresh && ($fresh->isReleased() || $fresh->isExpired())) {
                return new ReleaseResult(
                    ulid: $reservation->ulid,
                    released: true,
                    refunded: false,
                    refundedAmountCents: 0,
                );
            }

            return new ReleaseResult(
                ulid: $reservation->ulid,
                released: false,
                refunded: false,
                refundedAmountCents: 0,
            );
        }

        // Pricing refund (idempotent, safe outside the transaction)
        $refundResult = $this->pricingEngine->refund(
            $account,
            $metricLimit,
            $reservation->amount,
            $period,
            $reservation->ulid,
        );

        return new ReleaseResult(
            ulid: $reservation->ulid,
            released: true,
            refunded: $refundResult->refunded,
            refundedAmountCents: $refundResult->amountCents,
        );
    }

    private function dbConnection(): \Illuminate\Database\Connection
    {
        return DB::connection(config('usage-limiter.database_connection'));
    }
}
