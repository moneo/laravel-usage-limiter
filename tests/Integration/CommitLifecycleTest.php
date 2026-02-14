<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Tests\Integration;

use Illuminate\Support\Facades\Event;
use Moneo\UsageLimiter\Core\UsageLimiter;
use Moneo\UsageLimiter\DTOs\UsageAttempt;
use Moneo\UsageLimiter\Events\LimitExceeded;
use Moneo\UsageLimiter\Events\OverageAccumulated;
use Moneo\UsageLimiter\Events\UsageCommitted;
use Moneo\UsageLimiter\Exceptions\ReservationExpiredException;
use Moneo\UsageLimiter\Models\BillingTransaction;
use Moneo\UsageLimiter\Models\UsageOverage;
use Moneo\UsageLimiter\Models\UsagePeriodAggregate;
use Moneo\UsageLimiter\Models\UsageReservation;
use Moneo\UsageLimiter\Tests\Concerns\AssertsInvariants;
use Moneo\UsageLimiter\Tests\Concerns\CreatesTestFixtures;
use Moneo\UsageLimiter\Tests\TestCase;

class CommitLifecycleTest extends TestCase
{
    use AssertsInvariants;
    use CreatesTestFixtures;

    private UsageLimiter $limiter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->limiter = app(UsageLimiter::class);
    }

    // ---------------------------------------------------------------
    // C1: Commit transitions pending -> committed via CAS
    // ---------------------------------------------------------------

    public function test_commit_transitions_status_via_cas(): void
    {
        Event::fake();

        $plan = $this->createPlanWithMetric(includedAmount: 1000, enforcementMode: 'hard');
        $account = $this->createAccountWithPlanAssignment($plan);

        $reservation = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 100,
        ));

        $commitResult = $this->limiter->commit($reservation->ulid);

        $this->assertTrue($commitResult->committed);

        // DB assertions
        $dbReservation = UsageReservation::where('ulid', $reservation->ulid)->first();
        $this->assertEquals('committed', $dbReservation->status->value);
        $this->assertNotNull($dbReservation->committed_at);

        $aggregate = UsagePeriodAggregate::where('billing_account_id', $account->id)->first();
        $this->assertEquals(100, $aggregate->committed_usage);
        $this->assertEquals(0, $aggregate->reserved_usage);

        Event::assertDispatched(UsageCommitted::class);

        $this->assertAggregateMatchesReservations(
            $account->id,
            'api_calls',
            $aggregate->period_start->format('Y-m-d'),
        );
    }

    // ---------------------------------------------------------------
    // C2: Commit idempotency -- no double count
    // ---------------------------------------------------------------

    public function test_commit_idempotency_no_double_count(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 1000, enforcementMode: 'hard');
        $account = $this->createAccountWithPlanAssignment($plan);

        $reservation = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 100,
        ));

        $result1 = $this->limiter->commit($reservation->ulid);
        $result2 = $this->limiter->commit($reservation->ulid);

        $this->assertTrue($result1->committed);
        $this->assertTrue($result2->committed);
        $this->assertNotNull($result2->warning);
        $this->assertStringContainsString('idempotent', strtolower($result2->warning));

        // Usage counted only once
        $aggregate = UsagePeriodAggregate::where('billing_account_id', $account->id)->first();
        $this->assertEquals(100, $aggregate->committed_usage);
        $this->assertEquals(0, $aggregate->reserved_usage);
    }

    // ---------------------------------------------------------------
    // C3: Commit on expired reservation throws
    // ---------------------------------------------------------------

    public function test_commit_on_expired_reservation_throws(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 1000, enforcementMode: 'hard');
        $account = $this->createAccountWithPlanAssignment($plan);

        $reservation = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 100,
        ));

        // Manually expire the reservation via DB
        UsageReservation::where('ulid', $reservation->ulid)
            ->update(['status' => 'expired', 'released_at' => now()]);

        $this->expectException(ReservationExpiredException::class);

        $this->limiter->commit($reservation->ulid);
    }

    // ---------------------------------------------------------------
    // C4: Commit on released reservation throws
    // ---------------------------------------------------------------

    public function test_commit_on_released_reservation_throws(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 1000, enforcementMode: 'hard');
        $account = $this->createAccountWithPlanAssignment($plan);

        $reservation = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 100,
        ));

        $this->limiter->release($reservation->ulid);

        $this->expectException(ReservationExpiredException::class);

        $this->limiter->commit($reservation->ulid);
    }

    // ---------------------------------------------------------------
    // C5: Commit on nonexistent reservation throws
    // ---------------------------------------------------------------

    public function test_commit_on_missing_reservation_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->limiter->commit('01ARZ3NDEKTSV4RRFFQ69G5FAV');
    }

    // ---------------------------------------------------------------
    // C6: Commit triggers prepaid wallet debit
    // ---------------------------------------------------------------

    public function test_commit_prepaid_debits_wallet(): void
    {
        $plan = $this->createPlanWithMetric(
            includedAmount: 50,
            enforcementMode: 'hard',
            pricingMode: 'prepaid',
            overageEnabled: true,
            overageUnitSize: 1,
            overagePriceCents: 10,
            maxOverageAmount: 1000,
        );
        $account = $this->createAccountWithPlanAssignment($plan, walletBalanceCents: 10000);

        $reservation = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 75,
        ));

        $this->limiter->commit($reservation->ulid);

        // committed_before=0, committed_after=75, included=50
        // overage_before=0, overage_after=25 => delta cost = 25 * 10 = 250 cents
        $account->refresh();
        $this->assertEquals(10000 - 250, $account->wallet_balance_cents);

        // Verify billing transaction exists
        $txn = BillingTransaction::where('billing_account_id', $account->id)
            ->where('type', 'debit')
            ->first();
        $this->assertNotNull($txn);
        $this->assertEquals(-250, $txn->amount_cents);

        $this->assertWalletMatchesLedger($account->id, initialSeed: 10000);
    }

    // ---------------------------------------------------------------
    // C7: Commit triggers postpaid overage record
    // ---------------------------------------------------------------

    public function test_commit_postpaid_records_overage(): void
    {
        Event::fake();

        $plan = $this->createPlanWithMetric(
            includedAmount: 50,
            enforcementMode: 'hard',
            pricingMode: 'postpaid',
            overageEnabled: true,
            overageUnitSize: 10,
            overagePriceCents: 100,
            maxOverageAmount: 1000,
        );
        $account = $this->createAccountWithPlanAssignment($plan);

        $reservation = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 75,
        ));

        $this->limiter->commit($reservation->ulid);

        // committed_after=75, included=50 => totalOverage=25
        // ceil(25/10) = 3 units * 100 = 300 cents
        $overage = UsageOverage::where('billing_account_id', $account->id)->first();
        $this->assertNotNull($overage);
        $this->assertEquals(25, $overage->overage_amount);
        $this->assertEquals(300, $overage->total_price_cents);

        Event::assertDispatched(OverageAccumulated::class);

        $aggregate = UsagePeriodAggregate::where('billing_account_id', $account->id)->first();
        $this->assertOverageMathCorrect(
            $account->id,
            'api_calls',
            $aggregate->period_start->format('Y-m-d'),
        );
    }

    // ---------------------------------------------------------------
    // C8: Commit within included amount -- no charge
    // ---------------------------------------------------------------

    public function test_commit_within_included_no_charge(): void
    {
        $plan = $this->createPlanWithMetric(
            includedAmount: 100,
            enforcementMode: 'hard',
            pricingMode: 'prepaid',
            overageEnabled: true,
            overageUnitSize: 1,
            overagePriceCents: 10,
            maxOverageAmount: 1000,
        );
        $account = $this->createAccountWithPlanAssignment($plan, walletBalanceCents: 5000);

        $reservation = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 50,
        ));

        $this->limiter->commit($reservation->ulid);

        $account->refresh();
        $this->assertEquals(5000, $account->wallet_balance_cents);
        $this->assertDatabaseCount('ul_billing_transactions', 0);
    }

    // ---------------------------------------------------------------
    // C9: Commit fires LimitExceeded event when over included
    // ---------------------------------------------------------------

    public function test_commit_fires_limit_exceeded_event(): void
    {
        Event::fake();

        $plan = $this->createPlanWithMetric(
            includedAmount: 50,
            enforcementMode: 'hard',
            pricingMode: 'postpaid',
            overageEnabled: true,
            overageUnitSize: 1,
            overagePriceCents: 10,
            maxOverageAmount: 1000,
        );
        $account = $this->createAccountWithPlanAssignment($plan);

        $reservation = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 75,
        ));

        $this->limiter->commit($reservation->ulid);

        Event::assertDispatched(LimitExceeded::class, function (LimitExceeded $event) use ($account): bool {
            return $event->billingAccountId === $account->id
                && $event->metricCode === 'api_calls'
                && $event->currentUsage === 75
                && $event->limit === 50;
        });
    }

    // ---------------------------------------------------------------
    // C10: Full lifecycle with currentUsage checks
    // ---------------------------------------------------------------

    public function test_usage_stats_correct_through_lifecycle(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 1000, enforcementMode: 'hard');
        $account = $this->createAccountWithPlanAssignment($plan);

        // Before any usage
        $usage = $this->limiter->currentUsage($account->id, 'api_calls');
        $this->assertEquals(0, $usage['committed']);
        $this->assertEquals(0, $usage['reserved']);
        $this->assertEquals(1000, $usage['remaining']);

        // After reserve
        $reservation = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 300,
        ));

        $usage = $this->limiter->currentUsage($account->id, 'api_calls');
        $this->assertEquals(0, $usage['committed']);
        $this->assertEquals(300, $usage['reserved']);
        $this->assertEquals(700, $usage['remaining']);

        // After commit
        $this->limiter->commit($reservation->ulid);

        $usage = $this->limiter->currentUsage($account->id, 'api_calls');
        $this->assertEquals(300, $usage['committed']);
        $this->assertEquals(0, $usage['reserved']);
        $this->assertEquals(700, $usage['remaining']);
    }
}
