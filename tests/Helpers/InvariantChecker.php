<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Tests\Helpers;

use Illuminate\Support\Facades\DB;
use Moneo\UsageLimiter\Enums\ReservationStatus;
use Moneo\UsageLimiter\Models\BillingAccount;
use Moneo\UsageLimiter\Models\BillingTransaction;
use Moneo\UsageLimiter\Models\UsageOverage;
use Moneo\UsageLimiter\Models\UsagePeriodAggregate;
use Moneo\UsageLimiter\Models\UsageReservation;

/**
 * Standalone invariant checker for use in fuzz tests and batch verification.
 *
 * Returns a list of violation descriptions (empty = all invariants hold).
 */
final readonly class InvariantChecker
{
    /**
     * Check all invariants for a given account/metric/period.
     *
     * @return list<string> List of violation descriptions (empty = pass)
     */
    public static function check(
        int $accountId,
        string $metricCode,
        string $periodStart,
        int $effectiveLimit,
    ): array {
        $violations = [];

        $aggregate = UsagePeriodAggregate::where('billing_account_id', $accountId)
            ->where('metric_code', $metricCode)
            ->where('period_start', $periodStart)
            ->first();

        $actualCommitted = self::sumReservations($accountId, $metricCode, $periodStart, ReservationStatus::Committed);
        $actualReserved = self::sumReservations($accountId, $metricCode, $periodStart, ReservationStatus::Pending);

        if ($aggregate !== null) {
            // INV-1
            if ($aggregate->committed_usage !== $actualCommitted) {
                $violations[] = "INV-1: committed_usage={$aggregate->committed_usage} != SUM(committed)={$actualCommitted}";
            }

            // INV-2
            if ($aggregate->reserved_usage !== $actualReserved) {
                $violations[] = "INV-2: reserved_usage={$aggregate->reserved_usage} != SUM(pending)={$actualReserved}";
            }

            // INV-3
            $total = $aggregate->committed_usage + $aggregate->reserved_usage;
            if ($total > $effectiveLimit) {
                $violations[] = "INV-3: total={$total} exceeds limit={$effectiveLimit}";
            }

            // INV-14
            if ($aggregate->committed_usage < 0) {
                $violations[] = "INV-14: committed_usage={$aggregate->committed_usage} is negative";
            }
            if ($aggregate->reserved_usage < 0) {
                $violations[] = "INV-14: reserved_usage={$aggregate->reserved_usage} is negative";
            }
        } elseif ($actualCommitted > 0 || $actualReserved > 0) {
            $violations[] = "INV-1/2: Reservations exist without aggregate row";
        }

        // INV-4: Wallet matches ledger
        $account = BillingAccount::find($accountId);
        if ($account !== null) {
            $txnTable = (new BillingTransaction)->getTable();
            $connection = DB::connection(config('usage-limiter.database_connection'));
            $ledgerSum = (int) $connection->table($txnTable)
                ->where('billing_account_id', $accountId)
                ->sum('amount_cents');

            if ($account->wallet_balance_cents !== $ledgerSum) {
                $violations[] = "INV-4: wallet={$account->wallet_balance_cents} != ledger_sum={$ledgerSum}";
            }
        }

        // INV-5: No duplicate debits
        $txnTable = (new BillingTransaction)->getTable();
        $connection = DB::connection(config('usage-limiter.database_connection'));
        $duplicateDebits = $connection->table($txnTable)
            ->where('billing_account_id', $accountId)
            ->where('type', 'debit')
            ->whereNotNull('idempotency_key')
            ->select('idempotency_key')
            ->groupBy('idempotency_key')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        if ($duplicateDebits > 0) {
            $violations[] = "INV-5: {$duplicateDebits} duplicate debit idempotency keys found";
        }

        // INV-7: No released reservations with committed_at
        $releasedCommitted = UsageReservation::where('billing_account_id', $accountId)
            ->where('metric_code', $metricCode)
            ->where('status', ReservationStatus::Released)
            ->whereNotNull('committed_at')
            ->count();

        if ($releasedCommitted > 0) {
            $violations[] = "INV-7: {$releasedCommitted} released reservations with committed_at set";
        }

        // INV-9: Overage math
        $overage = UsageOverage::where('billing_account_id', $accountId)
            ->where('metric_code', $metricCode)
            ->whereDate('period_start', $periodStart)
            ->first();

        if ($overage !== null && $overage->overage_unit_size > 0) {
            $expectedPrice = (int) ceil($overage->overage_amount / $overage->overage_unit_size)
                * $overage->unit_price_cents;
            if ($overage->total_price_cents !== $expectedPrice) {
                $violations[] = "INV-9: overage price={$overage->total_price_cents} != expected={$expectedPrice}";
            }
        }

        return $violations;
    }

    private static function sumReservations(
        int $accountId,
        string $metricCode,
        string $periodStart,
        ReservationStatus $status,
    ): int {
        return (int) UsageReservation::where('billing_account_id', $accountId)
            ->where('metric_code', $metricCode)
            ->whereDate('period_start', $periodStart)
            ->where('status', $status)
            ->sum('amount');
    }
}
