<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Tests\Concurrency;

use Moneo\UsageLimiter\Core\UsageLimiter;
use Moneo\UsageLimiter\DTOs\UsageAttempt;
use Moneo\UsageLimiter\Exceptions\ReservationExpiredException;
use Moneo\UsageLimiter\Models\UsagePeriodAggregate;
use Moneo\UsageLimiter\Tests\Concerns\AssertsInvariants;
use Moneo\UsageLimiter\Tests\Concerns\CreatesTestFixtures;
use Moneo\UsageLimiter\Tests\TestCase;

/**
 * Concurrency tests for commit retry behavior.
 *
 * @group concurrency
 */
class CommitRetryTest extends TestCase
{
    use AssertsInvariants;
    use CreatesTestFixtures;

    /**
     * Multiple sequential commit attempts on the same reservation.
     * Only the first CAS should succeed; the rest are idempotent.
     */
    public function test_sequential_commit_same_reservation_idempotent(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 1000, enforcementMode: 'hard');
        $account = $this->createAccountWithPlanAssignment($plan);

        $limiter = app(UsageLimiter::class);

        $reservation = $limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 100,
        ));

        $commitResults = [];
        for ($i = 0; $i < 5; $i++) {
            $commitResults[] = $limiter->commit($reservation->ulid);
        }

        // All return committed=true
        foreach ($commitResults as $result) {
            $this->assertTrue($result->committed);
        }

        // But committed_usage only incremented once
        $aggregate = UsagePeriodAggregate::where('billing_account_id', $account->id)->first();
        $this->assertEquals(100, $aggregate->committed_usage);
        $this->assertEquals(0, $aggregate->reserved_usage);

        $this->assertAggregateMatchesReservations(
            $account->id,
            'api_calls',
            $aggregate->period_start->format('Y-m-d'),
        );
    }

    /**
     * Commit after release should throw, not silently succeed.
     */
    public function test_commit_after_release_throws(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 1000, enforcementMode: 'hard');
        $account = $this->createAccountWithPlanAssignment($plan);

        $limiter = app(UsageLimiter::class);

        $reservation = $limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 100,
        ));

        $limiter->release($reservation->ulid);

        $this->expectException(ReservationExpiredException::class);
        $limiter->commit($reservation->ulid);
    }
}
