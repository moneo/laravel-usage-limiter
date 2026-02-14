<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Contracts;

use Carbon\CarbonImmutable;
use Moneo\UsageLimiter\DTOs\Period;
use Moneo\UsageLimiter\Enums\ReservationStatus;
use Moneo\UsageLimiter\Models\UsagePeriodAggregate;
use Moneo\UsageLimiter\Models\UsageReservation;

interface UsageRepository
{
    /**
     * Get or create the aggregate row for this account/metric/period.
     *
     * Uses INSERT IGNORE + SELECT pattern for concurrency safety.
     */
    public function getOrCreateAggregate(
        int $billingAccountId,
        string $metricCode,
        Period $period,
    ): UsagePeriodAggregate;

    /**
     * Atomic conditional reserve: increment reserved_usage only if within limit.
     *
     * The critical atomic SQL for hard enforcement.
     *
     * @return bool True if rows_affected = 1 (success), false if limit exceeded
     */
    public function atomicConditionalReserve(int $aggregateId, int $amount, int $effectiveLimit): bool;

    /**
     * Atomic unconditional reserve: always increments reserved_usage.
     *
     * Used for soft enforcement.
     */
    public function atomicUnconditionalReserve(int $aggregateId, int $amount): void;

    /**
     * Atomic commit: move from reserved to committed.
     *
     * reserved_usage -= amount, committed_usage += amount
     */
    public function atomicCommit(int $aggregateId, int $amount): void;

    /**
     * Atomic release: decrement reserved_usage with underflow protection.
     *
     * reserved_usage = GREATEST(reserved_usage - amount, 0)
     */
    public function atomicRelease(int $aggregateId, int $amount): void;

    /**
     * Create a new reservation record.
     *
     * @param  array<string, mixed>  $data
     */
    public function createReservation(array $data): UsageReservation;

    /**
     * CAS state transition on a reservation.
     *
     * @return bool True if transition succeeded, false if already transitioned
     */
    public function transitionReservation(
        int $reservationId,
        ReservationStatus $fromStatus,
        ReservationStatus $toStatus,
        ?array $extraFields = null,
    ): bool;

    /**
     * Find a reservation by its ULID.
     */
    public function findReservationByUlid(string $ulid): ?UsageReservation;

    /**
     * Find a reservation by its idempotency key, scoped to a billing account.
     *
     * The scope prevents cross-account idempotency key collisions from
     * returning another account's reservation.
     */
    public function findReservationByIdempotencyKey(string $key, int $billingAccountId): ?UsageReservation;

    /**
     * Expire stale pending reservations and release their holds.
     *
     * @return int Number of expired reservations
     */
    public function expireStalePendingReservations(CarbonImmutable $cutoff): int;

    /**
     * Sum reservation amounts by status for reconciliation.
     */
    public function sumReservationsByStatus(
        int $billingAccountId,
        string $metricCode,
        string $periodStart,
        ReservationStatus $status,
    ): int;

    /**
     * Refresh an aggregate model from the database.
     */
    public function refreshAggregate(UsagePeriodAggregate $aggregate): UsagePeriodAggregate;
}
