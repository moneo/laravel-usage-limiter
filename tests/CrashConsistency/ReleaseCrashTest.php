<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Tests\CrashConsistency;

use Moneo\UsageLimiter\Contracts\UsageRepository;
use Moneo\UsageLimiter\Core\UsageLimiter;
use Moneo\UsageLimiter\DTOs\UsageAttempt;
use Moneo\UsageLimiter\Models\UsagePeriodAggregate;
use Moneo\UsageLimiter\Models\UsageReservation;
use Moneo\UsageLimiter\Repositories\EloquentUsageRepository;
use Moneo\UsageLimiter\Tests\Concerns\AssertsInvariants;
use Moneo\UsageLimiter\Tests\Concerns\CreatesTestFixtures;
use Moneo\UsageLimiter\Tests\Concerns\SimulatesFailpoints;
use Moneo\UsageLimiter\Tests\Helpers\FailpointAwareUsageRepository;
use Moneo\UsageLimiter\Tests\TestCase;

class ReleaseCrashTest extends TestCase
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
    // CR6: Crash after release CAS but before aggregate decrement
    //
    // With the CAS now inside the same DB transaction as the aggregate
    // release, a crash rolls back BOTH. The reservation stays pending
    // and the system is fully consistent — safe to retry.
    // ---------------------------------------------------------------

    public function test_crash_during_release_after_cas_before_aggregate(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 1000, enforcementMode: 'hard');
        $account = $this->createAccountWithPlanAssignment($plan);

        $limiter = app(UsageLimiter::class);

        $reservation = $limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 100,
        ));

        $this->armFailpoint('release.afterStatusTransition');

        try {
            $limiter->release($reservation->ulid);
            $this->fail('Expected RuntimeException from failpoint');
        } catch (\RuntimeException) {
            // expected
        }

        // CONSISTENT STATE: The transaction rolled back both the CAS and aggregate.
        // Reservation stays pending. No inconsistency.
        $dbReservation = UsageReservation::where('ulid', $reservation->ulid)->first();
        $this->assertEquals('pending', $dbReservation->status->value);

        $aggregate = UsagePeriodAggregate::where('billing_account_id', $account->id)->first();
        $this->assertEquals(100, $aggregate->reserved_usage); // Unchanged (not orphaned)

        // Disarm the failpoint so the retry succeeds
        $this->failpoints->disarm('release.afterStatusTransition');

        // RETRY: The release can be retried successfully
        $result = $limiter->release($reservation->ulid);
        $this->assertTrue($result->released);

        $aggregate->refresh();
        $this->assertEquals(0, $aggregate->reserved_usage);
    }
}
