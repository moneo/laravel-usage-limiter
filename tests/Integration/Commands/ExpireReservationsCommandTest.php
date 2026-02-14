<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Tests\Integration\Commands;

use Carbon\Carbon;
use Moneo\UsageLimiter\Core\UsageLimiter;
use Moneo\UsageLimiter\DTOs\UsageAttempt;
use Moneo\UsageLimiter\Models\UsagePeriodAggregate;
use Moneo\UsageLimiter\Models\UsageReservation;
use Moneo\UsageLimiter\Tests\Concerns\AssertsInvariants;
use Moneo\UsageLimiter\Tests\Concerns\CreatesTestFixtures;
use Moneo\UsageLimiter\Tests\TestCase;

class ExpireReservationsCommandTest extends TestCase
{
    use AssertsInvariants;
    use CreatesTestFixtures;

    private UsageLimiter $limiter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->limiter = app(UsageLimiter::class);
    }

    public function test_expires_stale_pending_reservations(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

        $plan = $this->createPlanWithMetric(includedAmount: 1000);
        $account = $this->createAccountWithPlanAssignment($plan);

        $res = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 100,
        ));

        // Fast forward past TTL
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:30:00'));

        $this->artisan('usage:expire-reservations')
            ->assertSuccessful();

        $reservation = UsageReservation::where('ulid', $res->ulid)->first();
        $this->assertEquals('expired', $reservation->status->value);

        Carbon::setTestNow();
    }

    public function test_decrements_reserved_usage_on_expire(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

        $plan = $this->createPlanWithMetric(includedAmount: 1000);
        $account = $this->createAccountWithPlanAssignment($plan);

        $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 100,
        ));

        $aggregate = UsagePeriodAggregate::where('billing_account_id', $account->id)->first();
        $this->assertEquals(100, $aggregate->reserved_usage);

        Carbon::setTestNow(Carbon::parse('2026-06-15 12:30:00'));

        $this->artisan('usage:expire-reservations');

        $aggregate->refresh();
        $this->assertEquals(0, $aggregate->reserved_usage);

        $this->assertAggregateMatchesReservations(
            $account->id,
            'api_calls',
            $aggregate->period_start->format('Y-m-d'),
        );

        Carbon::setTestNow();
    }

    public function test_does_not_touch_committed_reservations(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

        $plan = $this->createPlanWithMetric(includedAmount: 1000);
        $account = $this->createAccountWithPlanAssignment($plan);

        $res = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 100,
        ));
        $this->limiter->commit($res->ulid);

        Carbon::setTestNow(Carbon::parse('2026-06-15 12:30:00'));

        $this->artisan('usage:expire-reservations');

        $reservation = UsageReservation::where('ulid', $res->ulid)->first();
        $this->assertEquals('committed', $reservation->status->value);

        Carbon::setTestNow();
    }

    public function test_idempotent_running_twice(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

        $plan = $this->createPlanWithMetric(includedAmount: 1000);
        $account = $this->createAccountWithPlanAssignment($plan);

        $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 100,
        ));

        Carbon::setTestNow(Carbon::parse('2026-06-15 12:30:00'));

        $this->artisan('usage:expire-reservations');
        $this->artisan('usage:expire-reservations');

        $aggregate = UsagePeriodAggregate::where('billing_account_id', $account->id)->first();
        $this->assertEquals(0, $aggregate->reserved_usage);

        Carbon::setTestNow();
    }
}
