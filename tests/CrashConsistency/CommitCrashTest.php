<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Tests\CrashConsistency;

use Moneo\UsageLimiter\Contracts\UsageRepository;
use Moneo\UsageLimiter\Core\UsageLimiter;
use Moneo\UsageLimiter\DTOs\UsageAttempt;
use Moneo\UsageLimiter\Models\BillingTransaction;
use Moneo\UsageLimiter\Models\UsagePeriodAggregate;
use Moneo\UsageLimiter\Models\UsageReservation;
use Moneo\UsageLimiter\Repositories\EloquentUsageRepository;
use Moneo\UsageLimiter\Tests\Concerns\AssertsInvariants;
use Moneo\UsageLimiter\Tests\Concerns\CreatesTestFixtures;
use Moneo\UsageLimiter\Tests\Concerns\SimulatesFailpoints;
use Moneo\UsageLimiter\Tests\Helpers\FailpointAwareUsageRepository;
use Moneo\UsageLimiter\Tests\TestCase;

class CommitCrashTest extends TestCase
{
    use AssertsInvariants;
    use CreatesTestFixtures;
    use SimulatesFailpoints;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFailpoints();

        $this->app->singleton(UsageRepository::class, function (): FailpointAwareUsageRepository {
            return new FailpointAwareUsageRepository(
                new EloquentUsageRepository,
                $this->failpoints,
            );
        });

        $this->app->forgetInstance(UsageLimiter::class);
        $this->app->forgetInstance(\Moneo\UsageLimiter\Core\ReservationManager::class);
    }

    // ---------------------------------------------------------------
    // CR3: Crash during commit after CAS but before aggregate adjustment
    //
    // With the CAS now inside the same DB transaction as the aggregate
    // adjustment, a crash rolls back BOTH. The reservation stays pending
    // and the system is fully consistent — safe to retry.
    // ---------------------------------------------------------------

    public function test_crash_during_commit_after_cas_before_aggregate(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 1000, enforcementMode: 'hard');
        $account = $this->createAccountWithPlanAssignment($plan);

        $limiter = app(UsageLimiter::class);

        // Reserve first (no failpoint yet)
        $reservation = $limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 100,
        ));

        // Arm failpoint after CAS transition (inside the commit transaction)
        $this->armFailpoint('commit.afterStatusTransition');

        try {
            $limiter->commit($reservation->ulid);
            $this->fail('Expected RuntimeException from failpoint');
        } catch (\RuntimeException) {
            // expected
        }

        // CONSISTENT STATE: The transaction rolled back both the CAS and aggregate.
        // Reservation stays pending, aggregate unchanged.
        $dbReservation = UsageReservation::where('ulid', $reservation->ulid)->first();
        $this->assertEquals('pending', $dbReservation->status->value);

        $aggregate = UsagePeriodAggregate::where('billing_account_id', $account->id)->first();
        $this->assertEquals(100, $aggregate->reserved_usage);
        $this->assertEquals(0, $aggregate->committed_usage);

        // Disarm the failpoint so the retry succeeds
        $this->failpoints->disarm('commit.afterStatusTransition');

        // RETRY: The commit can be retried successfully
        $result = $limiter->commit($reservation->ulid);
        $this->assertTrue($result->committed);

        $aggregate->refresh();
        $this->assertEquals(0, $aggregate->reserved_usage);
        $this->assertEquals(100, $aggregate->committed_usage);
    }

    // ---------------------------------------------------------------
    // CR4: Crash during commit after aggregate adjustment but before charge
    //
    // The aggregate adjustment is inside a transaction. The failpoint
    // fires after atomicCommit but inside the transaction, so the
    // transaction rolls back. CAS and aggregate are both reverted.
    // The reservation stays pending and can be retried.
    // ---------------------------------------------------------------

    public function test_crash_during_commit_after_aggregate_before_charge(): void
    {
        $plan = $this->createPlanWithMetric(
            includedAmount: 0,
            pricingMode: 'prepaid',
            enforcementMode: 'hard',
            overageEnabled: true,
            overageUnitSize: 1,
            overagePriceCents: 100,
            maxOverageAmount: 1000,
        );
        $account = $this->createAccountWithPlanAssignment($plan, walletBalanceCents: 50000);

        $limiter = app(UsageLimiter::class);

        $reservation = $limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 10,
        ));

        // Arm failpoint after aggregate commit (inside the commit transaction)
        $this->armFailpoint('commit.afterAggregateUpdate');

        try {
            $limiter->commit($reservation->ulid);
            $this->fail('Expected RuntimeException from failpoint');
        } catch (\RuntimeException) {
            // expected
        }

        // CONSISTENT STATE: Transaction rolled back. Reservation stays pending.
        $dbReservation = UsageReservation::where('ulid', $reservation->ulid)->first();
        $this->assertEquals('pending', $dbReservation->status->value);

        $aggregate = UsagePeriodAggregate::where('billing_account_id', $account->id)->first();
        $this->assertEquals(10, $aggregate->reserved_usage);
        $this->assertEquals(0, $aggregate->committed_usage);

        // Wallet NOT debited (charge never ran)
        $account->refresh();
        $this->assertEquals(50000, $account->wallet_balance_cents);
        $this->assertDatabaseCount('ul_billing_transactions', 0);

        // Disarm the failpoint so the retry succeeds
        $this->failpoints->disarm('commit.afterAggregateUpdate');

        // RETRY: The commit can be retried and succeeds
        $result = $limiter->commit($reservation->ulid);
        $this->assertTrue($result->committed);

        $account->refresh();
        $this->assertEquals(50000 - 1000, $account->wallet_balance_cents);
        $this->assertDatabaseCount('ul_billing_transactions', 1);
    }

    // ---------------------------------------------------------------
    // CR5: atomicDebit is transactional (wallet debit + transaction insert are atomic)
    // ---------------------------------------------------------------

    public function test_atomic_debit_is_transactional(): void
    {
        $plan = $this->createPlanWithMetric(
            includedAmount: 0,
            pricingMode: 'prepaid',
            enforcementMode: 'hard',
            overageEnabled: true,
            overageUnitSize: 1,
            overagePriceCents: 100,
            maxOverageAmount: 1000,
        );
        $account = $this->createAccountWithPlanAssignment($plan, walletBalanceCents: 50000);

        $limiter = app(UsageLimiter::class);

        // Full successful cycle
        $reservation = $limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 10,
        ));
        $limiter->commit($reservation->ulid);

        // Both wallet decrement and transaction insert happened
        $account->refresh();
        $this->assertEquals(50000 - 1000, $account->wallet_balance_cents);
        $this->assertDatabaseCount('ul_billing_transactions', 1);

        $txn = BillingTransaction::where('billing_account_id', $account->id)->first();
        $this->assertEquals(-1000, $txn->amount_cents);
        $this->assertEquals(50000 - 1000, $txn->balance_after_cents);
    }
}
