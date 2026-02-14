<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Tests\Integration;

use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Moneo\UsageLimiter\Core\UsageLimiter;
use Moneo\UsageLimiter\DTOs\UsageAttempt;
use Moneo\UsageLimiter\Events\LimitApproaching;
use Moneo\UsageLimiter\Events\UsageReserved;
use Moneo\UsageLimiter\Exceptions\InsufficientBalanceException;
use Moneo\UsageLimiter\Exceptions\UsageLimitExceededException;
use Moneo\UsageLimiter\Models\UsagePeriodAggregate;
use Moneo\UsageLimiter\Models\UsageReservation;
use Moneo\UsageLimiter\Tests\Concerns\AssertsInvariants;
use Moneo\UsageLimiter\Tests\Concerns\CreatesTestFixtures;
use Moneo\UsageLimiter\Tests\TestCase;

class ReserveLifecycleTest extends TestCase
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
    // B1: Basic reserve increments reserved_usage atomically
    // ---------------------------------------------------------------

    public function test_reserve_increments_reserved_usage(): void
    {
        Event::fake();

        $plan = $this->createPlanWithMetric(includedAmount: 1000, enforcementMode: 'hard');
        $account = $this->createAccountWithPlanAssignment($plan);

        $result = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 100,
        ));

        $this->assertTrue($result->allowed);
        $this->assertNotEmpty($result->ulid);

        // DB assertions
        $aggregate = UsagePeriodAggregate::where('billing_account_id', $account->id)->first();
        $this->assertNotNull($aggregate);
        $this->assertEquals(100, $aggregate->reserved_usage);
        $this->assertEquals(0, $aggregate->committed_usage);

        $reservation = UsageReservation::where('ulid', $result->ulid)->first();
        $this->assertNotNull($reservation);
        $this->assertEquals('pending', $reservation->status->value);
        $this->assertEquals(100, $reservation->amount);

        Event::assertDispatched(UsageReserved::class);

        $this->assertAggregateMatchesReservations(
            $account->id,
            'api_calls',
            $aggregate->period_start->format('Y-m-d'),
        );
    }

    // ---------------------------------------------------------------
    // B2: Reserve denied on hard limit (committed fills limit)
    // ---------------------------------------------------------------

    public function test_reserve_denied_when_at_hard_limit(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 100, enforcementMode: 'hard');
        $account = $this->createAccountWithPlanAssignment($plan);

        // Use up the limit
        $res = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 100,
        ));
        $this->limiter->commit($res->ulid);

        // Try to reserve 1 more
        $this->expectException(UsageLimitExceededException::class);

        $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 1,
        ));
    }

    // ---------------------------------------------------------------
    // B3: Reserve denied when reserved fills limit
    // ---------------------------------------------------------------

    public function test_reserve_denied_when_reserved_fills_limit(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 100, enforcementMode: 'hard');
        $account = $this->createAccountWithPlanAssignment($plan);

        // Reserve the full limit (but don't commit)
        $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 100,
        ));

        $this->expectException(UsageLimitExceededException::class);

        $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 1,
        ));
    }

    // ---------------------------------------------------------------
    // B4: Reserve exactly at limit boundary succeeds
    // ---------------------------------------------------------------

    public function test_reserve_exactly_at_limit_succeeds(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 100, enforcementMode: 'hard');
        $account = $this->createAccountWithPlanAssignment($plan);

        $result = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 100,
        ));

        $this->assertTrue($result->allowed);

        $aggregate = UsagePeriodAggregate::where('billing_account_id', $account->id)->first();
        $this->assertEquals(100, $aggregate->reserved_usage);
    }

    // ---------------------------------------------------------------
    // B5: Reserve idempotency
    // ---------------------------------------------------------------

    public function test_reserve_idempotency_same_key_returns_same_reservation(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 1000, enforcementMode: 'hard');
        $account = $this->createAccountWithPlanAssignment($plan);

        $attempt = new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 50,
            idempotencyKey: 'idem-test-123',
        );

        $res1 = $this->limiter->reserve($attempt);
        $res2 = $this->limiter->reserve($attempt);

        $this->assertEquals($res1->ulid, $res2->ulid);
        $this->assertTrue($res2->allowed);

        // Only one reservation row
        $this->assertDatabaseCount('ul_usage_reservations', 1);

        // Reserved usage is 50, not 100
        $aggregate = UsagePeriodAggregate::where('billing_account_id', $account->id)->first();
        $this->assertEquals(50, $aggregate->reserved_usage);

        $this->assertAggregateMatchesReservations(
            $account->id,
            'api_calls',
            $aggregate->period_start->format('Y-m-d'),
        );
    }

    // ---------------------------------------------------------------
    // B6: Reserve creates aggregate on first use (upsert path)
    // ---------------------------------------------------------------

    public function test_reserve_creates_aggregate_on_first_use(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 1000, enforcementMode: 'hard');
        $account = $this->createAccountWithPlanAssignment($plan);

        $this->assertDatabaseCount('ul_usage_period_aggregates', 0);

        $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 10,
        ));

        $this->assertDatabaseCount('ul_usage_period_aggregates', 1);

        $aggregate = UsagePeriodAggregate::where('billing_account_id', $account->id)->first();
        $this->assertEquals(10, $aggregate->reserved_usage);
        $this->assertEquals(0, $aggregate->committed_usage);
    }

    // ---------------------------------------------------------------
    // B7: Reserve for unconfigured metric throws
    // ---------------------------------------------------------------

    public function test_reserve_for_unconfigured_metric_throws(): void
    {
        $plan = $this->createPlanWithMetric(metricCode: 'api_calls', includedAmount: 1000);
        $account = $this->createAccountWithPlanAssignment($plan);

        $this->expectException(UsageLimitExceededException::class);

        $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'nonexistent_metric',
            amount: 1,
        ));
    }

    // ---------------------------------------------------------------
    // B8: LimitApproaching event fires at threshold
    // ---------------------------------------------------------------

    public function test_limit_approaching_event_at_threshold(): void
    {
        Event::fake();

        $plan = $this->createPlanWithMetric(includedAmount: 100, enforcementMode: 'hard');
        $account = $this->createAccountWithPlanAssignment($plan);

        $this->app['config']->set('usage-limiter.limit_warning_threshold_percent', 80);

        $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 81,
        ));

        Event::assertDispatched(LimitApproaching::class, function (LimitApproaching $event) use ($account): bool {
            return $event->billingAccountId === $account->id
                && $event->metricCode === 'api_calls'
                && $event->percent >= 80;
        });
    }

    // ---------------------------------------------------------------
    // B9: Multiple reserves accumulate correctly
    // ---------------------------------------------------------------

    public function test_multiple_reserves_accumulate(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 1000, enforcementMode: 'hard');
        $account = $this->createAccountWithPlanAssignment($plan);

        $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 50,
        ));
        $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 30,
        ));
        $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 20,
        ));

        $aggregate = UsagePeriodAggregate::where('billing_account_id', $account->id)->first();
        $this->assertEquals(100, $aggregate->reserved_usage);

        $this->assertDatabaseCount('ul_usage_reservations', 3);

        $this->assertAggregateMatchesReservations(
            $account->id,
            'api_calls',
            $aggregate->period_start->format('Y-m-d'),
        );
    }

    // ---------------------------------------------------------------
    // B10: Reserve on account without plan throws
    // ---------------------------------------------------------------

    public function test_reserve_on_account_without_plan_throws(): void
    {
        $account = $this->createAccount();

        $this->expectException(UsageLimitExceededException::class);

        $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 1,
        ));
    }

    // ---------------------------------------------------------------
    // B11: Reservation TTL is set correctly
    // ---------------------------------------------------------------

    public function test_reservation_expires_at_set_correctly(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

        $this->app['config']->set('usage-limiter.reservation_ttl_minutes', 15);

        $plan = $this->createPlanWithMetric(includedAmount: 1000);
        $account = $this->createAccountWithPlanAssignment($plan);

        $result = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 10,
        ));

        $reservation = UsageReservation::where('ulid', $result->ulid)->first();
        $this->assertEquals(
            '2026-06-15 12:15:00',
            $reservation->expires_at->format('Y-m-d H:i:s'),
        );

        Carbon::setTestNow();
    }

    // ---------------------------------------------------------------
    // B12: Reserved usage counts toward limit
    // ---------------------------------------------------------------

    public function test_reserved_usage_counts_toward_limit(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 100, enforcementMode: 'hard');
        $account = $this->createAccountWithPlanAssignment($plan);

        // Reserve 80
        $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 80,
        ));

        // Try to reserve 30 more (80 + 30 = 110 > 100)
        $this->expectException(UsageLimitExceededException::class);

        $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 30,
        ));
    }
}
