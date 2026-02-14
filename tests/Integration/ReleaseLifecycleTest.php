<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Tests\Integration;

use Illuminate\Support\Facades\Event;
use Moneo\UsageLimiter\Core\UsageLimiter;
use Moneo\UsageLimiter\DTOs\UsageAttempt;
use Moneo\UsageLimiter\Events\UsageReleased;
use Moneo\UsageLimiter\Models\UsagePeriodAggregate;
use Moneo\UsageLimiter\Models\UsageReservation;
use Moneo\UsageLimiter\Tests\Concerns\AssertsInvariants;
use Moneo\UsageLimiter\Tests\Concerns\CreatesTestFixtures;
use Moneo\UsageLimiter\Tests\TestCase;

class ReleaseLifecycleTest extends TestCase
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
    // D1: Release returns reserved usage
    // ---------------------------------------------------------------

    public function test_release_decrements_reserved_usage(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 1000, enforcementMode: 'hard');
        $account = $this->createAccountWithPlanAssignment($plan);

        $reservation = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 100,
        ));

        $result = $this->limiter->release($reservation->ulid);

        $this->assertTrue($result->released);

        $dbReservation = UsageReservation::where('ulid', $reservation->ulid)->first();
        $this->assertEquals('released', $dbReservation->status->value);

        $aggregate = UsagePeriodAggregate::where('billing_account_id', $account->id)->first();
        $this->assertEquals(0, $aggregate->reserved_usage);
        $this->assertEquals(0, $aggregate->committed_usage);

        $this->assertAggregateMatchesReservations(
            $account->id,
            'api_calls',
            $aggregate->period_start->format('Y-m-d'),
        );
    }

    // ---------------------------------------------------------------
    // D2: Release idempotency
    // ---------------------------------------------------------------

    public function test_release_idempotency(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 1000, enforcementMode: 'hard');
        $account = $this->createAccountWithPlanAssignment($plan);

        $reservation = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 100,
        ));

        $result1 = $this->limiter->release($reservation->ulid);
        $result2 = $this->limiter->release($reservation->ulid);

        $this->assertTrue($result1->released);
        $this->assertTrue($result2->released); // Idempotent: already released

        // No double decrement
        $aggregate = UsagePeriodAggregate::where('billing_account_id', $account->id)->first();
        $this->assertEquals(0, $aggregate->reserved_usage);
    }

    // ---------------------------------------------------------------
    // D3: Release after commit is a no-op
    // ---------------------------------------------------------------

    public function test_release_after_commit_is_noop(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 1000, enforcementMode: 'hard');
        $account = $this->createAccountWithPlanAssignment($plan);

        $reservation = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 100,
        ));

        $this->limiter->commit($reservation->ulid);

        $result = $this->limiter->release($reservation->ulid);

        $this->assertFalse($result->released);

        // Committed usage unchanged
        $aggregate = UsagePeriodAggregate::where('billing_account_id', $account->id)->first();
        $this->assertEquals(100, $aggregate->committed_usage);
        $this->assertEquals(0, $aggregate->reserved_usage);
    }

    // ---------------------------------------------------------------
    // D4: Release after expiry
    // ---------------------------------------------------------------

    public function test_release_after_expiry_returns_released(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 1000, enforcementMode: 'hard');
        $account = $this->createAccountWithPlanAssignment($plan);

        $reservation = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 100,
        ));

        // Manually expire the reservation
        UsageReservation::where('ulid', $reservation->ulid)
            ->update(['status' => 'expired', 'released_at' => now()]);

        $result = $this->limiter->release($reservation->ulid);

        // Already expired = idempotent released
        $this->assertTrue($result->released);
    }

    // ---------------------------------------------------------------
    // D5: Release nonexistent reservation is a no-op
    // ---------------------------------------------------------------

    public function test_release_nonexistent_reservation_is_noop(): void
    {
        $result = $this->limiter->release('01ARZ3NDEKTSV4RRFFQ69G5FAV');

        $this->assertFalse($result->released);
        $this->assertFalse($result->refunded);
        $this->assertEquals(0, $result->refundedAmountCents);
    }

    // ---------------------------------------------------------------
    // D6: Release of pending reservation -- no refund (nothing charged yet)
    // ---------------------------------------------------------------

    public function test_release_pending_reservation_no_refund(): void
    {
        $plan = $this->createPlanWithMetric(
            includedAmount: 0,
            enforcementMode: 'hard',
            pricingMode: 'prepaid',
            overageEnabled: true,
            overageUnitSize: 1,
            overagePriceCents: 100,
            maxOverageAmount: 1000,
        );
        $account = $this->createAccountWithPlanAssignment($plan, walletBalanceCents: 50000);

        $reservation = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 10,
        ));

        // Release without committing -- charge happens at commit, so nothing to refund
        $result = $this->limiter->release($reservation->ulid);

        $this->assertTrue($result->released);
        $this->assertFalse($result->refunded);
        $this->assertEquals(0, $result->refundedAmountCents);

        // Wallet unchanged
        $account->refresh();
        $this->assertEquals(50000, $account->wallet_balance_cents);
    }

    // ---------------------------------------------------------------
    // D7: Release fires UsageReleased event
    // ---------------------------------------------------------------

    public function test_release_fires_event(): void
    {
        Event::fake();

        $plan = $this->createPlanWithMetric(includedAmount: 1000);
        $account = $this->createAccountWithPlanAssignment($plan);

        $reservation = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 100,
        ));

        $this->limiter->release($reservation->ulid);

        Event::assertDispatched(UsageReleased::class, function (UsageReleased $event) use ($account): bool {
            return $event->billingAccountId === $account->id
                && $event->metricCode === 'api_calls'
                && $event->amount === 100;
        });
    }

    // ---------------------------------------------------------------
    // D8: Invariants hold after release
    // ---------------------------------------------------------------

    public function test_invariants_hold_after_reserve_and_release(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 1000, enforcementMode: 'hard');
        $account = $this->createAccountWithPlanAssignment($plan);

        $res1 = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 100,
        ));

        $res2 = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 200,
        ));

        // Commit first, release second
        $this->limiter->commit($res1->ulid);
        $this->limiter->release($res2->ulid);

        $aggregate = UsagePeriodAggregate::where('billing_account_id', $account->id)->first();

        $this->assertAggregateMatchesReservations(
            $account->id,
            'api_calls',
            $aggregate->period_start->format('Y-m-d'),
        );

        $this->assertHardLimitNotExceeded(
            $account->id,
            'api_calls',
            $aggregate->period_start->format('Y-m-d'),
            1000,
        );
    }
}
