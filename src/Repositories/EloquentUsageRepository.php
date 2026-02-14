<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Moneo\UsageLimiter\Contracts\UsageRepository;
use Moneo\UsageLimiter\DTOs\Period;
use Moneo\UsageLimiter\Enums\ReservationStatus;
use Moneo\UsageLimiter\Models\UsagePeriodAggregate;
use Moneo\UsageLimiter\Models\UsageReservation;

class EloquentUsageRepository implements UsageRepository
{
    public function getOrCreateAggregate(
        int $billingAccountId,
        string $metricCode,
        Period $period,
    ): UsagePeriodAggregate {
        $table = (new UsagePeriodAggregate)->getTable();
        $connection = $this->connection();
        $now = now('UTC')->toDateTimeString();

        // Driver-agnostic upsert: INSERT OR IGNORE for SQLite, INSERT IGNORE for MySQL
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $connection->statement(
                "INSERT OR IGNORE INTO {$table}
                    (billing_account_id, metric_code, period_start, period_end, committed_usage, reserved_usage, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 0, 0, ?, ?)",
                [$billingAccountId, $metricCode, $period->startDate(), $period->endDate(), $now, $now]
            );
        } elseif ($driver === 'pgsql') {
            $connection->statement(
                "INSERT INTO {$table}
                    (billing_account_id, metric_code, period_start, period_end, committed_usage, reserved_usage, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 0, 0, ?, ?)
                 ON CONFLICT (billing_account_id, metric_code, period_start) DO NOTHING",
                [$billingAccountId, $metricCode, $period->startDate(), $period->endDate(), $now, $now]
            );
        } else {
            // MySQL and others
            $connection->statement(
                "INSERT IGNORE INTO {$table}
                    (billing_account_id, metric_code, period_start, period_end, committed_usage, reserved_usage, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 0, 0, ?, ?)",
                [$billingAccountId, $metricCode, $period->startDate(), $period->endDate(), $now, $now]
            );
        }

        // Fetch the row (guaranteed to exist now)
        return UsagePeriodAggregate::on($connection->getName())
            ->where('billing_account_id', $billingAccountId)
            ->where('metric_code', $metricCode)
            ->where('period_start', $period->startDate())
            ->firstOrFail();
    }

    public function atomicConditionalReserve(int $aggregateId, int $amount, int $effectiveLimit): bool
    {
        $table = (new UsagePeriodAggregate)->getTable();
        $now = now('UTC')->toDateTimeString();

        $affected = $this->connection()->update(
            "UPDATE {$table}
             SET reserved_usage = reserved_usage + ?,
                 updated_at = ?
             WHERE id = ?
               AND (committed_usage + reserved_usage + ?) <= ?",
            [$amount, $now, $aggregateId, $amount, $effectiveLimit]
        );

        return $affected === 1;
    }

    public function atomicUnconditionalReserve(int $aggregateId, int $amount): void
    {
        $table = (new UsagePeriodAggregate)->getTable();
        $now = now('UTC')->toDateTimeString();

        $this->connection()->update(
            "UPDATE {$table}
             SET reserved_usage = reserved_usage + ?,
                 updated_at = ?
             WHERE id = ?",
            [$amount, $now, $aggregateId]
        );
    }

    public function atomicCommit(int $aggregateId, int $amount): void
    {
        $table = (new UsagePeriodAggregate)->getTable();
        $now = now('UTC')->toDateTimeString();

        $this->connection()->update(
            "UPDATE {$table}
             SET reserved_usage = CASE
                    WHEN reserved_usage >= ? THEN reserved_usage - ?
                    ELSE 0
                 END,
                 committed_usage = committed_usage + ?,
                 updated_at = ?
             WHERE id = ?",
            [$amount, $amount, $amount, $now, $aggregateId]
        );
    }

    public function atomicRelease(int $aggregateId, int $amount): void
    {
        $table = (new UsagePeriodAggregate)->getTable();
        $now = now('UTC')->toDateTimeString();

        $this->connection()->update(
            "UPDATE {$table}
             SET reserved_usage = CASE
                    WHEN reserved_usage >= ? THEN reserved_usage - ?
                    ELSE 0
                 END,
                 updated_at = ?
             WHERE id = ?",
            [$amount, $amount, $now, $aggregateId]
        );
    }

    public function createReservation(array $data): UsageReservation
    {
        return UsageReservation::create($data);
    }

    private const ALLOWED_TRANSITIONS = [
        'pending' => ['committed', 'released', 'expired'],
    ];

    public function transitionReservation(
        int $reservationId,
        ReservationStatus $fromStatus,
        ReservationStatus $toStatus,
        ?array $extraFields = null,
    ): bool {
        $allowed = self::ALLOWED_TRANSITIONS[$fromStatus->value] ?? [];
        if (! in_array($toStatus->value, $allowed, true)) {
            return false;
        }

        $table = (new UsageReservation)->getTable();
        $now = now('UTC')->toDateTimeString();

        $setClause = 'status = ?, updated_at = ?';
        $params = [$toStatus->value, $now];

        if ($toStatus === ReservationStatus::Committed) {
            $setClause .= ', committed_at = ?';
            $params[] = $now;
        } elseif (in_array($toStatus, [ReservationStatus::Released, ReservationStatus::Expired], true)) {
            $setClause .= ', released_at = ?';
            $params[] = $now;
        }

        $params[] = $reservationId;
        $params[] = $fromStatus->value;

        $affected = $this->connection()->update(
            "UPDATE {$table} SET {$setClause} WHERE id = ? AND status = ?",
            $params
        );

        return $affected === 1;
    }

    public function findReservationByUlid(string $ulid): ?UsageReservation
    {
        return UsageReservation::where('ulid', $ulid)->first();
    }

    public function findReservationByIdempotencyKey(string $key, int $billingAccountId): ?UsageReservation
    {
        return UsageReservation::where('idempotency_key', $key)
            ->where('billing_account_id', $billingAccountId)
            ->first();
    }

    public function expireStalePendingReservations(CarbonImmutable $cutoff): int
    {
        $count = 0;

        // Process in batches to avoid locking too many rows
        while (true) {
            $reservations = UsageReservation::where('status', ReservationStatus::Pending)
                ->where('expires_at', '<', $cutoff)
                ->limit(100)
                ->get();

            if ($reservations->isEmpty()) {
                break;
            }

            foreach ($reservations as $reservation) {
                $this->connection()->transaction(function () use ($reservation, &$count) {
                    $transitioned = $this->transitionReservation(
                        $reservation->id,
                        ReservationStatus::Pending,
                        ReservationStatus::Expired,
                    );

                    if ($transitioned) {
                        $aggregateId = $this->findAggregateId(
                            $reservation->billing_account_id,
                            $reservation->metric_code,
                            $reservation->period_start->format('Y-m-d'),
                        );

                        if ($aggregateId > 0) {
                            $this->atomicRelease($aggregateId, $reservation->amount);
                        }
                        $count++;
                    }
                }, 3);
            }
        }

        return $count;
    }

    public function sumReservationsByStatus(
        int $billingAccountId,
        string $metricCode,
        string $periodStart,
        ReservationStatus $status,
    ): int {
        return (int) UsageReservation::where('billing_account_id', $billingAccountId)
            ->where('metric_code', $metricCode)
            ->where('period_start', $periodStart)
            ->where('status', $status)
            ->sum('amount');
    }

    public function refreshAggregate(UsagePeriodAggregate $aggregate): UsagePeriodAggregate
    {
        return $aggregate->fresh();
    }

    /**
     * Find the aggregate ID for a given account/metric/period.
     */
    private function findAggregateId(int $billingAccountId, string $metricCode, string $periodStart): int
    {
        $aggregate = UsagePeriodAggregate::where('billing_account_id', $billingAccountId)
            ->where('metric_code', $metricCode)
            ->where('period_start', $periodStart)
            ->first();

        return $aggregate ? $aggregate->id : 0;
    }

    private function connection(): \Illuminate\Database\Connection
    {
        $connectionName = config('usage-limiter.database_connection');

        return DB::connection($connectionName);
    }
}
