<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Tests\Integration\LeakagePrevention;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Moneo\UsageLimiter\Core\UsageLimiter;
use Moneo\UsageLimiter\DTOs\UsageAttempt;
use Moneo\UsageLimiter\Entrypoints\EventIngestor;
use Moneo\UsageLimiter\Entrypoints\ExecutionGateway;
use Moneo\UsageLimiter\Events\ReconciliationDivergenceDetected;
use Moneo\UsageLimiter\Models\UsagePeriodAggregate;
use Moneo\UsageLimiter\Tests\Concerns\AssertsInvariants;
use Moneo\UsageLimiter\Tests\Concerns\CreatesTestFixtures;
use Moneo\UsageLimiter\Tests\TestCase;

class BypassDetectionTest extends TestCase
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
    // J1: ExecutionGateway releases on callback exception
    // ---------------------------------------------------------------

    public function test_execution_gateway_releases_on_exception(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 1000);
        $account = $this->createAccountWithPlanAssignment($plan);

        $gateway = app(ExecutionGateway::class);

        try {
            $gateway->execute(
                $account->id,
                'api_calls',
                10,
                function (): never {
                    throw new \RuntimeException('Job failed mid-execution');
                },
            );
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertEquals('Job failed mid-execution', $e->getMessage());
        }

        // Reservation should be released, reserved_usage should be 0
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
    // J2: ExecutionGateway commits on success
    // ---------------------------------------------------------------

    public function test_execution_gateway_commits_on_success(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 1000);
        $account = $this->createAccountWithPlanAssignment($plan);

        $gateway = app(ExecutionGateway::class);

        $result = $gateway->execute(
            $account->id,
            'api_calls',
            10,
            fn (): string => 'work done',
        );

        $this->assertEquals('work done', $result);

        $aggregate = UsagePeriodAggregate::where('billing_account_id', $account->id)->first();
        $this->assertEquals(10, $aggregate->committed_usage);
        $this->assertEquals(0, $aggregate->reserved_usage);
    }

    // ---------------------------------------------------------------
    // J3: Direct DB writes detected by reconcile
    // ---------------------------------------------------------------

    public function test_direct_db_write_detected_by_reconcile(): void
    {
        Event::fake();

        $plan = $this->createPlanWithMetric(includedAmount: 1000);
        $account = $this->createAccountWithPlanAssignment($plan);

        // Legitimate usage
        $res = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 100,
        ));
        $this->limiter->commit($res->ulid);

        // Bypass: direct DB write to aggregate (simulates an attacker or bug)
        UsagePeriodAggregate::where('billing_account_id', $account->id)
            ->increment('committed_usage', 50);

        // Reconcile detects the 50-unit drift
        $this->artisan('usage:reconcile')
            ->assertFailed();

        Event::assertDispatched(ReconciliationDivergenceDetected::class, function ($event): bool {
            return $event->type === 'committed_usage';
        });
    }

    // ---------------------------------------------------------------
    // J4: EventIngestor goes through reserve path
    // ---------------------------------------------------------------

    public function test_event_ingestor_creates_reservation_and_commits(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 1000);
        $account = $this->createAccountWithPlanAssignment($plan);

        $ingestor = app(EventIngestor::class);
        $result = $ingestor->ingest($account->id, 'api_calls', 25);

        $this->assertTrue($result->committed);

        // Reservation exists and is committed
        $this->assertDatabaseCount('ul_usage_reservations', 1);

        $aggregate = UsagePeriodAggregate::where('billing_account_id', $account->id)->first();
        $this->assertEquals(25, $aggregate->committed_usage);
        $this->assertEquals(0, $aggregate->reserved_usage);

        $this->assertAggregateMatchesReservations(
            $account->id,
            'api_calls',
            $aggregate->period_start->format('Y-m-d'),
        );
    }
}
