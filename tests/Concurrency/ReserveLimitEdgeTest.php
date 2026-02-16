<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Tests\Concurrency;

use Moneo\UsageLimiter\Core\UsageLimiter;
use Moneo\UsageLimiter\DTOs\UsageAttempt;
use Moneo\UsageLimiter\Exceptions\UsageLimitExceededException;
use Moneo\UsageLimiter\Models\UsagePeriodAggregate;
use Moneo\UsageLimiter\Tests\Concerns\AssertsInvariants;
use Moneo\UsageLimiter\Tests\Concerns\CreatesTestFixtures;
use Moneo\UsageLimiter\Tests\Helpers\ConcurrencyRunner;
use Moneo\UsageLimiter\Tests\TestCase;

/**
 * Concurrency tests for reserve operations at the limit edge.
 *
 * These tests require a real MySQL or PostgreSQL database (not SQLite)
 * because SQLite serializes writes and cannot demonstrate true concurrency.
 *
 * Run with: vendor/bin/phpunit --group=concurrency
 *
 * @group concurrency
 */
class ReserveLimitEdgeTest extends TestCase
{
    use AssertsInvariants;
    use CreatesTestFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $driver = $this->app['db']->connection(
            config('usage-limiter.database_connection')
        )->getDriverName();

        if ($driver === 'sqlite') {
            $this->markTestSkipped('Concurrency tests require MySQL or PostgreSQL (not SQLite).');
        }
    }

    /**
     * 10 workers each try to reserve(1) with limit=5.
     * Exactly 5 should succeed, 5 should fail. No overshoot.
     */
    public function test_10_workers_reserve_at_limit_edge_no_overshoot(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 5, enforcementMode: 'hard');
        $account = $this->createAccountWithPlanAssignment($plan);

        // Pre-seed the aggregate so workers don't race on upsert
        $this->limiter()->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 0,
        ));
        // Actually let's just ensure the aggregate exists by triggering currentUsage
        $this->limiter()->currentUsage($account->id, 'api_calls');

        $results = ConcurrencyRunner::run(
            scriptPath: __DIR__.'/scripts/reserve_worker.php',
            workerCount: 10,
            env: $this->workerEnv($account->id),
        );

        $successes = ConcurrencyRunner::countSuccesses($results);
        $failures = ConcurrencyRunner::countFailures($results);

        $this->assertEquals(5, $successes, "Expected exactly 5 successes, got {$successes}");
        $this->assertEquals(5, $failures, "Expected exactly 5 failures, got {$failures}");

        $aggregate = UsagePeriodAggregate::where('billing_account_id', $account->id)
            ->where('metric_code', 'api_calls')
            ->first();

        $this->assertEquals(5, $aggregate->reserved_usage);

        $this->assertHardLimitNotExceeded(
            $account->id,
            'api_calls',
            $aggregate->period_start->format('Y-m-d'),
            5,
        );
    }

    /**
     * With partial committed usage, only remaining capacity is available.
     */
    public function test_concurrent_reserve_with_partial_committed(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 10, enforcementMode: 'hard');
        $account = $this->createAccountWithPlanAssignment($plan);

        // Commit 8 units
        for ($i = 0; $i < 8; $i++) {
            $res = $this->limiter()->reserve(new UsageAttempt(
                billingAccountId: $account->id,
                metricCode: 'api_calls',
                amount: 1,
            ));
            $this->limiter()->commit($res->ulid);
        }

        // 5 workers each try to reserve(1), only 2 should succeed
        $results = ConcurrencyRunner::run(
            scriptPath: __DIR__.'/scripts/reserve_worker.php',
            workerCount: 5,
            env: $this->workerEnv($account->id),
        );

        $successes = ConcurrencyRunner::countSuccesses($results);

        $this->assertEquals(2, $successes, "Expected exactly 2 successes, got {$successes}");

        $aggregate = UsagePeriodAggregate::where('billing_account_id', $account->id)
            ->where('metric_code', 'api_calls')
            ->first();

        $total = $aggregate->committed_usage + $aggregate->reserved_usage;
        $this->assertLessThanOrEqual(10, $total);
    }

    /**
     * In-process concurrency simulation (sequential but validates the atomic SQL).
     * This is a deterministic fallback for environments without external DB.
     */
    public function test_sequential_reserves_enforce_hard_limit(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 5, enforcementMode: 'hard');
        $account = $this->createAccountWithPlanAssignment($plan);

        $limiter = $this->limiter();
        $successes = 0;
        $failures = 0;

        for ($i = 0; $i < 10; $i++) {
            try {
                $limiter->reserve(new UsageAttempt(
                    billingAccountId: $account->id,
                    metricCode: 'api_calls',
                    amount: 1,
                ));
                $successes++;
            } catch (UsageLimitExceededException) {
                $failures++;
            }
        }

        $this->assertEquals(5, $successes);
        $this->assertEquals(5, $failures);

        $aggregate = UsagePeriodAggregate::where('billing_account_id', $account->id)->first();
        $this->assertEquals(5, $aggregate->reserved_usage);

        $this->assertAggregateMatchesReservations(
            $account->id,
            'api_calls',
            $aggregate->period_start->format('Y-m-d'),
        );
    }

    private function limiter(): UsageLimiter
    {
        return app(UsageLimiter::class);
    }

    /**
     * @return array<string, string>
     */
    private function workerEnv(int $accountId): array
    {
        $connection = config('usage-limiter.database_connection') ?? config('database.default');
        $dbConfig = config("database.connections.{$connection}");

        return [
            'ACCOUNT_ID' => (string) $accountId,
            'METRIC_CODE' => 'api_calls',
            'AMOUNT' => '1',
            'DB_CONNECTION' => $dbConfig['driver'] ?? 'mysql',
            'DB_HOST' => $dbConfig['host'] ?? '127.0.0.1',
            'DB_PORT' => (string) ($dbConfig['port'] ?? 3306),
            'DB_DATABASE' => $dbConfig['database'] ?? 'testing',
            'DB_USERNAME' => $dbConfig['username'] ?? 'root',
            'DB_PASSWORD' => $dbConfig['password'] ?? 'password',
        ];
    }
}
