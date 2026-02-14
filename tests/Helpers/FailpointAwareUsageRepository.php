<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Tests\Helpers;

use Carbon\CarbonImmutable;
use Moneo\UsageLimiter\Contracts\UsageRepository;
use Moneo\UsageLimiter\DTOs\Period;
use Moneo\UsageLimiter\Enums\ReservationStatus;
use Moneo\UsageLimiter\Models\UsagePeriodAggregate;
use Moneo\UsageLimiter\Models\UsageReservation;

/**
 * Decorator for UsageRepository that injects failpoint checks at critical points.
 *
 * Used ONLY in crash-consistency tests. Wraps the real Eloquent repository and
 * fires FailpointManager::check() at named steps to simulate crashes.
 */
final class FailpointAwareUsageRepository implements UsageRepository
{
    public function __construct(
        private readonly UsageRepository $inner,
        private readonly FailpointManager $failpoints,
    ) {}

    public function getOrCreateAggregate(int $billingAccountId, string $metricCode, Period $period): UsagePeriodAggregate
    {
        return $this->inner->getOrCreateAggregate($billingAccountId, $metricCode, $period);
    }

    public function atomicConditionalReserve(int $aggregateId, int $amount, int $effectiveLimit): bool
    {
        $result = $this->inner->atomicConditionalReserve($aggregateId, $amount, $effectiveLimit);

        if ($result) {
            $this->failpoints->check('reserve.afterAggregateUpdate');
        }

        return $result;
    }

    public function atomicUnconditionalReserve(int $aggregateId, int $amount): void
    {
        $this->inner->atomicUnconditionalReserve($aggregateId, $amount);
        $this->failpoints->check('reserve.afterAggregateUpdate');
    }

    public function atomicCommit(int $aggregateId, int $amount): void
    {
        $this->inner->atomicCommit($aggregateId, $amount);
        $this->failpoints->check('commit.afterAggregateUpdate');
    }

    public function atomicRelease(int $aggregateId, int $amount): void
    {
        $this->inner->atomicRelease($aggregateId, $amount);
    }

    public function createReservation(array $data): UsageReservation
    {
        $reservation = $this->inner->createReservation($data);
        $this->failpoints->check('reserve.afterReservationInsert');

        return $reservation;
    }

    public function transitionReservation(int $reservationId, ReservationStatus $fromStatus, ReservationStatus $toStatus, ?array $extraFields = null): bool
    {
        $result = $this->inner->transitionReservation($reservationId, $fromStatus, $toStatus, $extraFields);

        if ($result && $toStatus === ReservationStatus::Committed) {
            $this->failpoints->check('commit.afterStatusTransition');
        }

        if ($result && $toStatus === ReservationStatus::Released) {
            $this->failpoints->check('release.afterStatusTransition');
        }

        return $result;
    }

    public function findReservationByUlid(string $ulid): ?UsageReservation
    {
        return $this->inner->findReservationByUlid($ulid);
    }

    public function findReservationByIdempotencyKey(string $key, int $billingAccountId): ?UsageReservation
    {
        return $this->inner->findReservationByIdempotencyKey($key, $billingAccountId);
    }

    public function expireStalePendingReservations(CarbonImmutable $cutoff): int
    {
        return $this->inner->expireStalePendingReservations($cutoff);
    }

    public function sumReservationsByStatus(int $billingAccountId, string $metricCode, string $periodStart, ReservationStatus $status): int
    {
        return $this->inner->sumReservationsByStatus($billingAccountId, $metricCode, $periodStart, $status);
    }

    public function refreshAggregate(UsagePeriodAggregate $aggregate): UsagePeriodAggregate
    {
        return $this->inner->refreshAggregate($aggregate);
    }
}
