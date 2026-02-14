<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Tests\Integration;

use Illuminate\Support\Facades\Event;
use Moneo\UsageLimiter\Core\UsageLimiter;
use Moneo\UsageLimiter\DTOs\EnforcementDecision;
use Moneo\UsageLimiter\DTOs\UsageAttempt;
use Moneo\UsageLimiter\Events\UsageCommitted;
use Moneo\UsageLimiter\Events\UsageReleased;
use Moneo\UsageLimiter\Events\UsageReserved;
use Moneo\UsageLimiter\Exceptions\UsageLimitExceededException;
use Moneo\UsageLimiter\Tests\TestCase;

class UsageLimiterLifecycleTest extends TestCase
{
    private UsageLimiter $limiter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->limiter = app(UsageLimiter::class);
    }

    public function test_full_reserve_commit_lifecycle(): void
    {
        Event::fake();

        $plan = $this->createPlanWithLimits('pro', [
            'api_calls' => ['included_amount' => 1000, 'enforcement_mode' => 'hard'],
        ]);
        $account = $this->createAccountWithPlan($plan);

        $attempt = new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 100,
        );

        // RESERVE
        $reservation = $this->limiter->reserve($attempt);
        $this->assertTrue($reservation->allowed);
        $this->assertNotEmpty($reservation->ulid);

        Event::assertDispatched(UsageReserved::class);

        // Check usage shows reserved
        $usage = $this->limiter->currentUsage($account->id, 'api_calls');
        $this->assertEquals(0, $usage['committed']);
        $this->assertEquals(100, $usage['reserved']);
        $this->assertEquals(900, $usage['remaining']);

        // COMMIT
        $commitResult = $this->limiter->commit($reservation->ulid);
        $this->assertTrue($commitResult->committed);

        Event::assertDispatched(UsageCommitted::class);

        // Check usage shows committed
        $usage = $this->limiter->currentUsage($account->id, 'api_calls');
        $this->assertEquals(100, $usage['committed']);
        $this->assertEquals(0, $usage['reserved']);
        $this->assertEquals(900, $usage['remaining']);
    }

    public function test_full_reserve_release_lifecycle(): void
    {
        Event::fake();

        $plan = $this->createPlanWithLimits('pro', [
            'api_calls' => ['included_amount' => 1000, 'enforcement_mode' => 'hard'],
        ]);
        $account = $this->createAccountWithPlan($plan);

        $attempt = new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 100,
        );

        // RESERVE
        $reservation = $this->limiter->reserve($attempt);
        $this->assertTrue($reservation->allowed);

        // RELEASE
        $releaseResult = $this->limiter->release($reservation->ulid);
        $this->assertTrue($releaseResult->released);

        Event::assertDispatched(UsageReleased::class);

        // Check usage shows nothing used
        $usage = $this->limiter->currentUsage($account->id, 'api_calls');
        $this->assertEquals(0, $usage['committed']);
        $this->assertEquals(0, $usage['reserved']);
        $this->assertEquals(1000, $usage['remaining']);
    }

    public function test_hard_enforcement_denies_over_limit(): void
    {
        $plan = $this->createPlanWithLimits('basic', [
            'api_calls' => ['included_amount' => 100, 'enforcement_mode' => 'hard'],
        ]);
        $account = $this->createAccountWithPlan($plan);

        // Use up the limit
        $attempt1 = new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 100,
        );
        $res1 = $this->limiter->reserve($attempt1);
        $this->limiter->commit($res1->ulid);

        // Try to reserve more
        $this->expectException(UsageLimitExceededException::class);

        $attempt2 = new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 1,
        );
        $this->limiter->reserve($attempt2);
    }

    public function test_check_without_reservation(): void
    {
        $plan = $this->createPlanWithLimits('pro', [
            'api_calls' => ['included_amount' => 1000, 'enforcement_mode' => 'hard'],
        ]);
        $account = $this->createAccountWithPlan($plan);

        // Check within limit
        $decision = $this->limiter->check($account->id, 'api_calls', 500);
        $this->assertEquals(EnforcementDecision::Allow, $decision);

        // Check over limit
        $decision = $this->limiter->check($account->id, 'api_calls', 1001);
        $this->assertEquals(EnforcementDecision::Deny, $decision);
    }

    public function test_idempotent_reserve(): void
    {
        $plan = $this->createPlanWithLimits('pro', [
            'api_calls' => ['included_amount' => 1000, 'enforcement_mode' => 'hard'],
        ]);
        $account = $this->createAccountWithPlan($plan);

        $attempt = new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 100,
            idempotencyKey: 'test-key-123',
        );

        // First reserve
        $res1 = $this->limiter->reserve($attempt);
        $this->assertTrue($res1->allowed);

        // Second reserve with same key — idempotent
        $res2 = $this->limiter->reserve($attempt);
        $this->assertTrue($res2->allowed);
        $this->assertEquals($res1->ulid, $res2->ulid);

        // Usage should only be counted once
        $usage = $this->limiter->currentUsage($account->id, 'api_calls');
        $this->assertEquals(100, $usage['reserved']);
    }

    public function test_idempotent_commit(): void
    {
        $plan = $this->createPlanWithLimits('pro', [
            'api_calls' => ['included_amount' => 1000, 'enforcement_mode' => 'hard'],
        ]);
        $account = $this->createAccountWithPlan($plan);

        $attempt = new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 100,
        );

        $reservation = $this->limiter->reserve($attempt);
        $this->limiter->commit($reservation->ulid);

        // Second commit — idempotent
        $result = $this->limiter->commit($reservation->ulid);
        $this->assertTrue($result->committed);

        // Usage should only be counted once
        $usage = $this->limiter->currentUsage($account->id, 'api_calls');
        $this->assertEquals(100, $usage['committed']);
    }

    public function test_multiple_metrics_on_same_account(): void
    {
        $plan = $this->createPlanWithLimits('pro', [
            'api_calls' => ['included_amount' => 1000, 'enforcement_mode' => 'hard'],
            'ai_tokens' => ['included_amount' => 50000, 'enforcement_mode' => 'hard'],
        ]);
        $account = $this->createAccountWithPlan($plan);

        // Reserve on both metrics
        $res1 = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 100,
        ));
        $res2 = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'ai_tokens',
            amount: 5000,
        ));

        $this->limiter->commit($res1->ulid);
        $this->limiter->commit($res2->ulid);

        $apiUsage = $this->limiter->currentUsage($account->id, 'api_calls');
        $this->assertEquals(100, $apiUsage['committed']);

        $tokenUsage = $this->limiter->currentUsage($account->id, 'ai_tokens');
        $this->assertEquals(5000, $tokenUsage['committed']);
    }

    public function test_reserved_usage_counts_toward_limit(): void
    {
        $plan = $this->createPlanWithLimits('basic', [
            'api_calls' => ['included_amount' => 100, 'enforcement_mode' => 'hard'],
        ]);
        $account = $this->createAccountWithPlan($plan);

        // Reserve 80
        $res1 = $this->limiter->reserve(new UsageAttempt(
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
