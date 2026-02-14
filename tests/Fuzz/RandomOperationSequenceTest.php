<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Tests\Fuzz;

use Moneo\UsageLimiter\Contracts\PeriodResolver;
use Moneo\UsageLimiter\Core\UsageLimiter;
use Moneo\UsageLimiter\DTOs\UsageAttempt;
use Moneo\UsageLimiter\Exceptions\InsufficientBalanceException;
use Moneo\UsageLimiter\Exceptions\ReservationExpiredException;
use Moneo\UsageLimiter\Exceptions\UsageLimitExceededException;
use Moneo\UsageLimiter\Tests\Concerns\CreatesTestFixtures;
use Moneo\UsageLimiter\Tests\Helpers\InvariantChecker;
use Moneo\UsageLimiter\Tests\TestCase;

/**
 * Fuzz test: random sequences of operations validate that invariants always hold.
 *
 * @group fuzz
 * @group nightly
 */
class RandomOperationSequenceTest extends TestCase
{
    use CreatesTestFixtures;

    private UsageLimiter $limiter;

    /** @var list<string> Active pending reservation ULIDs */
    private array $pendingUlids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->limiter = app(UsageLimiter::class);
    }

    public function test_random_operation_sequences_preserve_invariants(): void
    {
        $numBatches = 20;
        $opsPerBatch = 15;
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
                $operation = match (random_int(0, 4)) {
                    0, 1 => 'reserve',
                    2 => 'commit',
                    3 => 'release',
                    4 => 'expire',
                };

                $this->executeOperation($operation, $account->id, 'api_calls');
            }

            // After each batch: run ALL invariant checks
            $violations = InvariantChecker::check(
                $account->id,
                'api_calls',
                $periodStart,
                $limit,
            );

            $this->assertEmpty(
                $violations,
                "Invariant violations after batch {$batch}: " . implode('; ', $violations),
            );
        }
    }

    public function test_random_operations_with_multiple_metrics(): void
    {
        $plan = $this->createPlanWithMultipleMetrics('fuzz_plan', [
            'api_calls' => ['included_amount' => 200, 'enforcement_mode' => 'hard'],
            'storage_mb' => ['included_amount' => 100, 'enforcement_mode' => 'hard'],
        ]);
        $account = $this->createAccountWithPlanAssignment($plan);

        $periodResolver = app(PeriodResolver::class);
        $period = $periodResolver->current($account->id);
        $periodStart = $period->startDate();

        $metrics = ['api_calls', 'storage_mb'];
        $limits = ['api_calls' => 200, 'storage_mb' => 100];

        for ($batch = 0; $batch < 10; $batch++) {
            for ($op = 0; $op < 10; $op++) {
                $metric = $metrics[array_rand($metrics)];
                $operation = match (random_int(0, 3)) {
                    0, 1 => 'reserve',
                    2 => 'commit',
                    3 => 'release',
                };

                $this->executeOperation($operation, $account->id, $metric);
            }

            foreach ($metrics as $metric) {
                $violations = InvariantChecker::check(
                    $account->id,
                    $metric,
                    $periodStart,
                    $limits[$metric],
                );

                $this->assertEmpty(
                    $violations,
                    "Invariant violations for {$metric} after batch {$batch}: " . implode('; ', $violations),
                );
            }
        }
    }

    private function executeOperation(string $operation, int $accountId, string $metricCode): void
    {
        match ($operation) {
            'reserve' => $this->doReserve($accountId, $metricCode),
            'commit' => $this->doCommit(),
            'release' => $this->doRelease(),
            'expire' => $this->doExpire(),
        };
    }

    private function doReserve(int $accountId, string $metricCode): void
    {
        try {
            $amount = random_int(1, 30);
            $result = $this->limiter->reserve(new UsageAttempt(
                billingAccountId: $accountId,
                metricCode: $metricCode,
                amount: $amount,
            ));

            if ($result->allowed) {
                $this->pendingUlids[] = $result->ulid;
            }
        } catch (UsageLimitExceededException|InsufficientBalanceException) {
            // Expected when at limit
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
            $this->limiter->commit($ulid);
            unset($this->pendingUlids[$index]);
            $this->pendingUlids = array_values($this->pendingUlids);
        } catch (ReservationExpiredException|\RuntimeException) {
            // May have been expired/released already
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

        $this->limiter->release($ulid);
        unset($this->pendingUlids[$index]);
        $this->pendingUlids = array_values($this->pendingUlids);
    }

    private function doExpire(): void
    {
        $this->artisan('usage:expire-reservations');
        // Clear our tracking of pending ULIDs since some may have been expired
        // We can't know which ones, so just clear and let future ops deal with stale ULIDs
    }
}
