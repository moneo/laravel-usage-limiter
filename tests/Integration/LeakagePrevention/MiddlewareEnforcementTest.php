<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Tests\Integration\LeakagePrevention;

use Moneo\UsageLimiter\Contracts\UsageLimiterAware;
use Moneo\UsageLimiter\Exceptions\UsageLimitExceededException;
use Moneo\UsageLimiter\Middleware\EnforceUsageLimitMiddleware;
use Moneo\UsageLimiter\Models\UsagePeriodAggregate;
use Moneo\UsageLimiter\Tests\Concerns\AssertsInvariants;
use Moneo\UsageLimiter\Tests\Concerns\CreatesTestFixtures;
use Moneo\UsageLimiter\Tests\TestCase;

/**
 * Stub job implementing UsageLimiterAware for middleware tests.
 */
class StubMeteredJob implements UsageLimiterAware
{
    public bool $handled = false;

    public function __construct(
        private readonly int $accountId,
        private readonly string $metric = 'api_calls',
        private readonly int $amount = 1,
        private readonly ?string $idempotencyKey = null,
        public ?\Throwable $throwDuring = null,
    ) {}

    public function billingAccountId(): int
    {
        return $this->accountId;
    }

    public function metricCode(): string
    {
        return $this->metric;
    }

    public function usageAmount(): int
    {
        return $this->amount;
    }

    public function usageIdempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    public function handle(): void
    {
        if ($this->throwDuring !== null) {
            throw $this->throwDuring;
        }
        $this->handled = true;
    }
}

class MiddlewareEnforcementTest extends TestCase
{
    use AssertsInvariants;
    use CreatesTestFixtures;

    public function test_middleware_reserves_and_commits_on_success(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 1000);
        $account = $this->createAccountWithPlanAssignment($plan);

        $job = new StubMeteredJob($account->id, amount: 10);
        $middleware = new EnforceUsageLimitMiddleware;

        $middleware->handle($job, function ($job): void {
            $job->handle();
        });

        $this->assertTrue($job->handled);

        $aggregate = UsagePeriodAggregate::where('billing_account_id', $account->id)->first();
        $this->assertEquals(10, $aggregate->committed_usage);
        $this->assertEquals(0, $aggregate->reserved_usage);
    }

    public function test_middleware_releases_on_job_exception(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 1000);
        $account = $this->createAccountWithPlanAssignment($plan);

        $job = new StubMeteredJob(
            $account->id,
            amount: 10,
            throwDuring: new \RuntimeException('Job exploded'),
        );
        $middleware = new EnforceUsageLimitMiddleware;

        try {
            $middleware->handle($job, function ($job): void {
                $job->handle();
            });
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException) {
            // expected
        }

        $aggregate = UsagePeriodAggregate::where('billing_account_id', $account->id)->first();
        $this->assertEquals(0, $aggregate->committed_usage);
        $this->assertEquals(0, $aggregate->reserved_usage);

        $this->assertAggregateMatchesReservations(
            $account->id,
            'api_calls',
            $aggregate->period_start->format('Y-m-d'),
        );
    }

    public function test_middleware_denies_over_limit(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 5);
        $account = $this->createAccountWithPlanAssignment($plan);

        $middleware = new EnforceUsageLimitMiddleware;

        // Use up the limit
        for ($i = 0; $i < 5; $i++) {
            $job = new StubMeteredJob($account->id, amount: 1);
            $middleware->handle($job, function ($job): void {
                $job->handle();
            });
        }

        // Next one should fail
        $this->expectException(UsageLimitExceededException::class);

        $job = new StubMeteredJob($account->id, amount: 1);
        $middleware->handle($job, function ($job): void {
            $job->handle();
        });
    }

    public function test_middleware_passes_through_non_aware_job(): void
    {
        $nonAwareJob = new class
        {
            public bool $handled = false;

            public function handle(): void
            {
                $this->handled = true;
            }
        };

        $middleware = new EnforceUsageLimitMiddleware;

        $middleware->handle($nonAwareJob, function ($job): void {
            $job->handle();
        });

        $this->assertTrue($nonAwareJob->handled);
    }
}
