<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Tests\Fuzz;

use Moneo\UsageLimiter\Contracts\PeriodResolver;
use Moneo\UsageLimiter\Contracts\UsageRepository;
use Moneo\UsageLimiter\Core\UsageLimiter;
use Moneo\UsageLimiter\DTOs\UsageAttempt;
use Moneo\UsageLimiter\Exceptions\InsufficientBalanceException;
use Moneo\UsageLimiter\Exceptions\ReservationExpiredException;
use Moneo\UsageLimiter\Exceptions\UsageLimitExceededException;
use Moneo\UsageLimiter\Repositories\EloquentUsageRepository;
use Moneo\UsageLimiter\Tests\Concerns\CreatesTestFixtures;
use Moneo\UsageLimiter\Tests\Concerns\SimulatesFailpoints;
use Moneo\UsageLimiter\Tests\Helpers\FailpointAwareUsageRepository;
use Moneo\UsageLimiter\Tests\Helpers\FailpointManager;
use Moneo\UsageLimiter\Tests\Helpers\InvariantChecker;
use Moneo\UsageLimiter\Tests\TestCase;

/**
 * Fuzz test with random failure injection.
 *
 * Randomly arms failpoints before operations, catches exceptions,
 * then runs reconciliation to prove repair restores correctness.
 *
 * @group fuzz
 * @group nightly
 */
class RandomFailureInjectionTest extends TestCase
{
    use CreatesTestFixtures;
    use SimulatesFailpoints;

    private const FAILPOINT_NAMES = [
        'reserve.afterAggregateUpdate',
        'reserve.afterReservationInsert',
        'commit.afterStatusTransition',
        'commit.afterAggregateUpdate',
        'release.afterStatusTransition',
    ];

    /** @var list<string> */
    private array $pendingUlids = [];

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

    public function test_random_operations_with_failures_then_reconcile_restores_invariants(): void
    {
        $driver = $this->app['db']->connection(config('usage-limiter.database_connection'))->getDriverName();

        if ($driver === 'sqlite') {
            $this->markTestSkipped(
                'Fuzz with failure injection + reconcile requires MySQL/PostgreSQL '
                .'(reconcile uses sumReservationsByStatus which has date format mismatch on SQLite).'
            );
        }
        $numBatches = 10;
        $opsPerBatch = 10;
        $limit = 500;

        $plan = $this->createPlanWithMetric(
            metricCode: 'api_calls',
            includedAmount: $limit,
            enforcementMode: 'hard',
        );
        $account = $this->createAccountWithPlanAssignment($plan);

        $periodResolver = app(PeriodResolver::class);
        $period = $periodResolver->current($account->id);
        $periodStart = $period->startDate();

        for ($batch = 0; $batch < $numBatches; $batch++) {
            for ($op = 0; $op < $opsPerBatch; $op++) {
                // Randomly arm a failpoint ~20% of the time
                $shouldInjectFailure = random_int(1, 5) === 1;

                if ($shouldInjectFailure) {
                    $fpName = self::FAILPOINT_NAMES[array_rand(self::FAILPOINT_NAMES)];
                    $this->failpoints->arm($fpName);
                }

                $operation = match (random_int(0, 3)) {
                    0, 1 => 'reserve',
                    2 => 'commit',
                    3 => 'release',
                };

                $this->executeOperation($operation, $account->id, 'api_calls');

                // Always disarm all failpoints after each operation
                FailpointManager::reset();
                $this->failpoints = FailpointManager::instance();
                $this->app->instance(FailpointManager::class, $this->failpoints);
            }

            // After each batch: run reconciliation, then check invariants
            $this->artisan('usage:reconcile', ['--auto-correct' => true]);
            $this->artisan('usage:expire-reservations');

            $violations = InvariantChecker::check(
                $account->id,
                'api_calls',
                $periodStart,
                $limit,
            );

            $this->assertEmpty(
                $violations,
                "Invariant violations after batch {$batch} (with failure injection + reconcile): "
                    .implode('; ', $violations),
            );
        }
    }

    private function executeOperation(string $operation, int $accountId, string $metricCode): void
    {
        match ($operation) {
            'reserve' => $this->doReserve($accountId, $metricCode),
            'commit' => $this->doCommit(),
            'release' => $this->doRelease(),
        };
    }

    private function doReserve(int $accountId, string $metricCode): void
    {
        try {
            $limiter = app(UsageLimiter::class);
            $amount = random_int(1, 20);
            $result = $limiter->reserve(new UsageAttempt(
                billingAccountId: $accountId,
                metricCode: $metricCode,
                amount: $amount,
            ));

            if ($result->allowed) {
                $this->pendingUlids[] = $result->ulid;
            }
        } catch (UsageLimitExceededException|InsufficientBalanceException|\RuntimeException) {
            // Expected: either limit exceeded or failpoint triggered
        }
    }

    private function doCommit(): void
    {
        if (empty($this->pendingUlids)) {
            return;
        }

        $index = array_rand($this->pendingUlids);
        $ulid = $this->pendingUlids[$index];

        try {
            $limiter = app(UsageLimiter::class);
            $limiter->commit($ulid);
            unset($this->pendingUlids[$index]);
            $this->pendingUlids = array_values($this->pendingUlids);
        } catch (ReservationExpiredException|\RuntimeException) {
            unset($this->pendingUlids[$index]);
            $this->pendingUlids = array_values($this->pendingUlids);
        }
    }

    private function doRelease(): void
    {
        if (empty($this->pendingUlids)) {
            return;
        }

        $index = array_rand($this->pendingUlids);
        $ulid = $this->pendingUlids[$index];

        try {
            $limiter = app(UsageLimiter::class);
            $limiter->release($ulid);
        } catch (\RuntimeException) {
            // Failpoint may have triggered
        }

        unset($this->pendingUlids[$index]);
        $this->pendingUlids = array_values($this->pendingUlids);
    }
}
