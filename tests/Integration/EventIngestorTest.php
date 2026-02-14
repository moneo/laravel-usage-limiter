<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Tests\Integration;

use Moneo\UsageLimiter\Core\UsageLimiter;
use Moneo\UsageLimiter\Entrypoints\EventIngestor;
use Moneo\UsageLimiter\Exceptions\UsageLimitExceededException;
use Moneo\UsageLimiter\Tests\TestCase;

class EventIngestorTest extends TestCase
{
    private EventIngestor $ingestor;

    private UsageLimiter $limiter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ingestor = app(EventIngestor::class);
        $this->limiter = app(UsageLimiter::class);
    }

    public function test_ingest_reserves_and_commits_atomically(): void
    {
        $plan = $this->createPlanWithLimits('pro', [
            'events' => ['included_amount' => 10000, 'enforcement_mode' => 'hard'],
        ]);
        $account = $this->createAccountWithPlan($plan);

        $result = $this->ingestor->ingest(
            billingAccountId: $account->id,
            metricCode: 'events',
            amount: 5,
        );

        $this->assertTrue($result->committed);

        $usage = $this->limiter->currentUsage($account->id, 'events');
        $this->assertEquals(5, $usage['committed']);
        $this->assertEquals(0, $usage['reserved']);
    }

    public function test_ingest_respects_hard_enforcement(): void
    {
        $plan = $this->createPlanWithLimits('basic', [
            'events' => ['included_amount' => 10, 'enforcement_mode' => 'hard'],
        ]);
        $account = $this->createAccountWithPlan($plan);

        // Fill up
        $this->ingestor->ingest($account->id, 'events', 10);

        // Try one more
        $this->expectException(UsageLimitExceededException::class);
        $this->ingestor->ingest($account->id, 'events', 1);
    }

    public function test_ingest_with_idempotency(): void
    {
        $plan = $this->createPlanWithLimits('pro', [
            'events' => ['included_amount' => 10000, 'enforcement_mode' => 'hard'],
        ]);
        $account = $this->createAccountWithPlan($plan);

        $result1 = $this->ingestor->ingest(
            billingAccountId: $account->id,
            metricCode: 'events',
            amount: 5,
            idempotencyKey: 'event-abc-123',
        );

        $result2 = $this->ingestor->ingest(
            billingAccountId: $account->id,
            metricCode: 'events',
            amount: 5,
            idempotencyKey: 'event-abc-123',
        );

        // Usage should only be counted once
        $usage = $this->limiter->currentUsage($account->id, 'events');
        $this->assertEquals(5, $usage['committed']);
    }

    public function test_ingest_batch(): void
    {
        $plan = $this->createPlanWithLimits('pro', [
            'events' => ['included_amount' => 10000, 'enforcement_mode' => 'hard'],
            'api_calls' => ['included_amount' => 10000, 'enforcement_mode' => 'hard'],
        ]);
        $account = $this->createAccountWithPlan($plan);

        $results = $this->ingestor->ingestBatch($account->id, [
            ['metric_code' => 'events', 'amount' => 5],
            ['metric_code' => 'api_calls', 'amount' => 3],
        ]);

        $this->assertCount(2, $results);

        $usage1 = $this->limiter->currentUsage($account->id, 'events');
        $this->assertEquals(5, $usage1['committed']);

        $usage2 = $this->limiter->currentUsage($account->id, 'api_calls');
        $this->assertEquals(3, $usage2['committed']);
    }
}
