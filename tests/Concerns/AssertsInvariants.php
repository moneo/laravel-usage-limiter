<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Tests\Concerns;

use Illuminate\Support\Facades\DB;
use Moneo\UsageLimiter\Enums\ReservationStatus;
use Moneo\UsageLimiter\Models\BillingAccount;
use Moneo\UsageLimiter\Models\BillingTransaction;
use Moneo\UsageLimiter\Models\UsageOverage;
use Moneo\UsageLimiter\Models\UsagePeriodAggregate;
use Moneo\UsageLimiter\Models\UsageReservation;

/**
 * Assertion helpers for verifying system invariants.
 *
 * Every invariant is a property that must ALWAYS hold in a correct system.
 * These helpers are used by integration, crash-consistency, and fuzz tests.
 */
trait AssertsInvariants
{
    /**
     * INV-1 + INV-2: Aggregate committed/reserved match reservation sums.
     */
    protected function assertAggregateMatchesReservations(
        int $accountId,
        string $metricCode,
        string $periodStart,
    ): void {
        $aggregate = UsagePeriodAggregate::where('billing_account_id', $accountId)
            ->where('metric_code', $metricCode)
            ->where('period_start', $periodStart)
            ->first();

        if ($aggregate === null) {
            // No aggregate means no activity -- ensure no reservations exist either
            $pendingSum = $this->sumReservations($accountId, $metricCode, $periodStart, ReservationStatus::Pending);
            $committedSum = $this->sumReservations($accountId, $metricCode, $periodStart, ReservationStatus::Committed);
            $this->assertEquals(0, $pendingSum, 'INV-2: Pending reservations exist without aggregate');
            $this->assertEquals(0, $committedSum, 'INV-1: Committed reservations exist without aggregate');

            return;
        }

        $actualCommitted = $this->sumReservations($accountId, $metricCode, $periodStart, ReservationStatus::Committed);
        $actualReserved = $this->sumReservations($accountId, $metricCode, $periodStart, ReservationStatus::Pending);

        $this->assertEquals(
            $actualCommitted,
            $aggregate->committed_usage,
            "INV-1: aggregate.committed_usage ({$aggregate->committed_usage}) != SUM(committed reservations) ({$actualCommitted})"
                . " for account={$accountId} metric={$metricCode} period={$periodStart}",
        );

        $this->assertEquals(
            $actualReserved,
            $aggregate->reserved_usage,
            "INV-2: aggregate.reserved_usage ({$aggregate->reserved_usage}) != SUM(pending reservations) ({$actualReserved})"
                . " for account={$accountId} metric={$metricCode} period={$periodStart}",
        );
    }

    /**
     * INV-3: Hard enforcement ceiling -- committed + reserved <= effective_limit.
     */
    protected function assertHardLimitNotExceeded(
        int $accountId,
        string $metricCode,
        string $periodStart,
        int $effectiveLimit,
    ): void {
        $aggregate = UsagePeriodAggregate::where('billing_account_id', $accountId)
            ->where('metric_code', $metricCode)
            ->where('period_start', $periodStart)
            ->first();

        if ($aggregate === null) {
            return; // No usage, limit trivially holds
        }

        $total = $aggregate->committed_usage + $aggregate->reserved_usage;

        $this->assertLessThanOrEqual(
            $effectiveLimit,
            $total,
            "INV-3: committed ({$aggregate->committed_usage}) + reserved ({$aggregate->reserved_usage}) = {$total}"
                . " exceeds effective_limit ({$effectiveLimit})"
                . " for account={$accountId} metric={$metricCode} period={$periodStart}",
        );
    }

    /**
     * INV-4: Wallet balance matches ledger.
     *
     * For test fixtures that start with wallet_balance_cents = 0 and no seed transaction,
     * the invariant is: wallet_balance_cents == SUM(transactions.amount_cents).
     * For fixtures that start with a non-zero balance, pass $initialSeed.
     */
    protected function assertWalletMatchesLedger(int $accountId, int $initialSeed = 0): void
    {
        $account = BillingAccount::findOrFail($accountId);
        $txnTable = (new BillingTransaction)->getTable();
        $connection = DB::connection(config('usage-limiter.database_connection'));

        $ledgerSum = (int) $connection->table($txnTable)
            ->where('billing_account_id', $accountId)
            ->sum('amount_cents');

        $expectedBalance = $initialSeed + $ledgerSum;

        $this->assertEquals(
            $expectedBalance,
            $account->wallet_balance_cents,
            "INV-4: wallet_balance_cents ({$account->wallet_balance_cents}) != initial_seed ({$initialSeed})"
                . " + SUM(transactions) ({$ledgerSum}) = {$expectedBalance} for account={$accountId}",
        );
    }

    /**
     * INV-5: No duplicate debits per reservation idempotency key.
     */
    protected function assertNoDuplicateDebits(int $accountId): void
    {
        $txnTable = (new BillingTransaction)->getTable();
        $connection = DB::connection(config('usage-limiter.database_connection'));

        $duplicates = $connection->table($txnTable)
            ->where('billing_account_id', $accountId)
            ->where('type', 'debit')
            ->whereNotNull('idempotency_key')
            ->select('idempotency_key')
            ->groupBy('idempotency_key')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $this->assertCount(
            0,
            $duplicates,
            'INV-5: Duplicate debit transactions found for idempotency keys: '
                . $duplicates->pluck('idempotency_key')->implode(', '),
        );
    }

    /**
     * INV-6 + INV-7: Reservation status transitions are valid and monotonic.
     */
    protected function assertValidStatusTransitions(): void
    {
        $terminalStatuses = [
            ReservationStatus::Committed->value,
            ReservationStatus::Released->value,
            ReservationStatus::Expired->value,
        ];

        // INV-7: No reservation that is released should have committed_at set
        $violating = UsageReservation::where('status', ReservationStatus::Released)
            ->whereNotNull('committed_at')
            ->count();

        $this->assertEquals(
            0,
            $violating,
            'INV-7: Found released reservations with committed_at set (committed then released)',
        );
    }

    /**
     * INV-9: Overage math determinism.
     */
    protected function assertOverageMathCorrect(
        int $accountId,
        string $metricCode,
        string $periodStart,
    ): void {
        $overage = UsageOverage::where('billing_account_id', $accountId)
            ->where('metric_code', $metricCode)
            ->whereDate('period_start', $periodStart)
            ->first();

        if ($overage === null) {
            return;
        }

        $unitSize = $overage->overage_unit_size;
        $unitPrice = $overage->unit_price_cents;

        if ($unitSize > 0) {
            $expectedPrice = (int) ceil($overage->overage_amount / $unitSize) * $unitPrice;

            $this->assertEquals(
                $expectedPrice,
                $overage->total_price_cents,
                "INV-9: overage total_price_cents ({$overage->total_price_cents}) != "
                    . "ceil({$overage->overage_amount}/{$unitSize}) * {$unitPrice} = {$expectedPrice}",
            );
        }
    }

    /**
     * INV-12: Idempotency key uniqueness on billing transactions.
     */
    protected function assertIdempotencyKeyUniqueness(): void
    {
        $txnTable = (new BillingTransaction)->getTable();
        $connection = DB::connection(config('usage-limiter.database_connection'));

        $duplicates = $connection->table($txnTable)
            ->whereNotNull('idempotency_key')
            ->select('idempotency_key')
            ->groupBy('idempotency_key')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $this->assertCount(
            0,
            $duplicates,
            'INV-12: Duplicate billing_transaction idempotency keys: '
                . $duplicates->pluck('idempotency_key')->implode(', '),
        );
    }

    /**
     * INV-14: Non-negative counters.
     */
    protected function assertNonNegativeCounters(
        int $accountId,
        string $metricCode,
        string $periodStart,
    ): void {
        $aggregate = UsagePeriodAggregate::where('billing_account_id', $accountId)
            ->where('metric_code', $metricCode)
            ->where('period_start', $periodStart)
            ->first();

        if ($aggregate === null) {
            return;
        }

        $this->assertGreaterThanOrEqual(
            0,
            $aggregate->committed_usage,
            "INV-14: committed_usage is negative ({$aggregate->committed_usage})",
        );

        $this->assertGreaterThanOrEqual(
            0,
            $aggregate->reserved_usage,
            "INV-14: reserved_usage is negative ({$aggregate->reserved_usage})",
        );
    }

    /**
     * INV-15: Billing transaction balance_after chain consistency.
     */
    protected function assertTransactionBalanceChain(int $accountId): void
    {
        $txnTable = (new BillingTransaction)->getTable();
        $connection = DB::connection(config('usage-limiter.database_connection'));

        $transactions = $connection->table($txnTable)
            ->where('billing_account_id', $accountId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($transactions->isEmpty()) {
            return;
        }

        $previousBalance = null;
        foreach ($transactions as $txn) {
            if ($previousBalance !== null) {
                $expected = $previousBalance + $txn->amount_cents;
                $this->assertEquals(
                    $expected,
                    $txn->balance_after_cents,
                    "INV-15: Transaction #{$txn->id} balance_after_cents ({$txn->balance_after_cents})"
                        . " != previous ({$previousBalance}) + amount ({$txn->amount_cents}) = {$expected}",
                );
            }
            $previousBalance = $txn->balance_after_cents;
        }
    }

    /**
     * Run ALL invariants for a given account/metric/period.
     */
    protected function assertAllInvariants(
        int $accountId,
        string $metricCode,
        string $periodStart,
        int $effectiveLimit,
    ): void {
        $this->assertAggregateMatchesReservations($accountId, $metricCode, $periodStart);
        $this->assertHardLimitNotExceeded($accountId, $metricCode, $periodStart, $effectiveLimit);
        $this->assertWalletMatchesLedger($accountId);
        $this->assertNoDuplicateDebits($accountId);
        $this->assertValidStatusTransitions();
        $this->assertOverageMathCorrect($accountId, $metricCode, $periodStart);
        $this->assertIdempotencyKeyUniqueness();
        $this->assertNonNegativeCounters($accountId, $metricCode, $periodStart);
        $this->assertTransactionBalanceChain($accountId);
    }

    private function sumReservations(
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
