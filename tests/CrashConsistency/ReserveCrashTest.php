<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Tests\CrashConsistency;

use Moneo\UsageLimiter\Contracts\UsageRepository;
use Moneo\UsageLimiter\Core\UsageLimiter;
use Moneo\UsageLimiter\DTOs\UsageAttempt;
use Moneo\UsageLimiter\Models\UsagePeriodAggregate;
use Moneo\UsageLimiter\Repositories\EloquentUsageRepository;
use Moneo\UsageLimiter\Tests\Concerns\AssertsInvariants;
use Moneo\UsageLimiter\Tests\Concerns\CreatesTestFixtures;
use Moneo\UsageLimiter\Tests\Concerns\SimulatesFailpoints;
use Moneo\UsageLimiter\Tests\Helpers\FailpointAwareUsageRepository;
use Moneo\UsageLimiter\Tests\TestCase;

class ReserveCrashTest extends TestCase
{
    use AssertsInvariants;
    use CreatesTestFixtures;
    use SimulatesFailpoints;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFailpoints();

        // Bind the failpoint-aware repository decorator
        $this->app->singleton(UsageRepository::class, function (): FailpointAwareUsageRepository {
            return new FailpointAwareUsageRepository(
                new EloquentUsageRepository,
                $this->failpoints,
            );
        });

        // Force re-creation of services that depend on UsageRepository
        $this->app->forgetInstance(UsageLimiter::class);
        $this->app->forgetInstance(\Moneo\UsageLimiter\Core\ReservationManager::class);
    }

    // ---------------------------------------------------------------
    // CR1: Crash after aggregate reserved increment, before reservation insert
    //
    // With the DB transaction wrapping the entire reserve flow, the crash
    // causes a full rollback — no phantom reserved_usage, no inconsistency.
    // ---------------------------------------------------------------

    public function test_crash_after_aggregate_reserve_before_insert(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 1000, enforcementMode: 'hard');
        $account = $this->createAccountWithPlanAssignment($plan);

        $this->armFailpoint('reserve.afterAggregateUpdate');

        $limiter = app(UsageLimiter::class);

        try {
            $limiter->reserve(new UsageAttempt(
                billingAccountId: $account->id,
                metricCode: 'api_calls',
                amount: 100,
            ));
            $this->fail('Expected RuntimeException from failpoint');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Failpoint triggered', $e->getMessage());
        }

        // CONSISTENT STATE: The entire reserve transaction rolled back.
        // No phantom reserved_usage leak, no orphaned reservation row.
        $aggregate = UsagePeriodAggregate::where('billing_account_id', $account->id)->first();
        $this->assertTrue(
            $aggregate === null || $aggregate->reserved_usage === 0,
            'Transaction rollback should prevent phantom reserved_usage',
        );
        $this->assertDatabaseCount('ul_usage_reservations', 0);

        // No reconciliation needed — the system is already consistent.
    }

    // ---------------------------------------------------------------
    // CR2: Crash after reservation insert, before return
    //
    // With the DB transaction, the crash rolls back both the aggregate
    // increment and the reservation insert. No orphaned state.
    // ---------------------------------------------------------------

    public function test_crash_after_reservation_insert_before_return(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 1000, enforcementMode: 'hard');
        $account = $this->createAccountWithPlanAssignment($plan);

        $this->armFailpoint('reserve.afterReservationInsert');

        $limiter = app(UsageLimiter::class);

        try {
            $limiter->reserve(new UsageAttempt(
                billingAccountId: $account->id,
                metricCode: 'api_calls',
                amount: 100,
            ));
            $this->fail('Expected RuntimeException from failpoint');
        } catch (\RuntimeException) {
            // expected
        }

        // CONSISTENT STATE: The transaction rolled back both the aggregate
        // increment and the reservation insert. Nothing leaked.
        $aggregate = UsagePeriodAggregate::where('billing_account_id', $account->id)->first();
        $this->assertTrue(
            $aggregate === null || $aggregate->reserved_usage === 0,
            'Transaction rollback should prevent phantom reserved_usage',
        );
        $this->assertDatabaseCount('ul_usage_reservations', 0);
    }

    // ---------------------------------------------------------------
    // CR3: Verify reserve order: aggregate incremented BEFORE reservation insert
    // ---------------------------------------------------------------

    public function test_reserve_increments_aggregate_before_inserting_reservation(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 1000, enforcementMode: 'hard');
        $account = $this->createAccountWithPlanAssignment($plan);

        // Arm a failpoint after aggregate update to verify the order
        $orderLog = [];

        $this->failpoints->arm('reserve.afterAggregateUpdate', function () use (&$orderLog): void {
            $orderLog[] = 'aggregate_updated';
            // Don't throw -- let it continue to verify insertion order
        });

        $this->failpoints->arm('reserve.afterReservationInsert', function () use (&$orderLog): void {
            $orderLog[] = 'reservation_inserted';
        });

        $limiter = app(UsageLimiter::class);

        $limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 50,
        ));

        // Aggregate update happens before reservation insert
        $this->assertEquals(['aggregate_updated', 'reservation_inserted'], $orderLog);
    }
}
