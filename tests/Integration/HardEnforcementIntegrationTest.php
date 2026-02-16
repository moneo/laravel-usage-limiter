<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Tests\Integration;

use Moneo\UsageLimiter\Core\UsageLimiter;
use Moneo\UsageLimiter\DTOs\EnforcementDecision;
use Moneo\UsageLimiter\DTOs\UsageAttempt;
use Moneo\UsageLimiter\Exceptions\UsageLimitExceededException;
use Moneo\UsageLimiter\Tests\Concerns\AssertsInvariants;
use Moneo\UsageLimiter\Tests\Concerns\CreatesTestFixtures;
use Moneo\UsageLimiter\Tests\TestCase;

class HardEnforcementIntegrationTest extends TestCase
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
    // F1: Atomic conditional UPDATE blocks overshoot
    // ---------------------------------------------------------------

    public function test_hard_enforcement_blocks_at_limit(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 10, enforcementMode: 'hard');
        $account = $this->createAccountWithPlanAssignment($plan);

        // Fill to limit
        for ($i = 0; $i < 10; $i++) {
            $res = $this->limiter->reserve(new UsageAttempt(
                billingAccountId: $account->id,
                metricCode: 'api_calls',
                amount: 1,
            ));
            $this->limiter->commit($res->ulid);
        }

        $this->expectException(UsageLimitExceededException::class);

        $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 1,
        ));
    }

    // ---------------------------------------------------------------
    // F2: Exact boundary
    // ---------------------------------------------------------------

    public function test_exact_boundary_reserve_then_deny(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 100, enforcementMode: 'hard');
        $account = $this->createAccountWithPlanAssignment($plan);

        // Exact limit succeeds
        $res = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 100,
        ));
        $this->assertTrue($res->allowed);

        $this->limiter->commit($res->ulid);

        // 1 more fails
        $this->expectException(UsageLimitExceededException::class);

        $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 1,
        ));
    }

    // ---------------------------------------------------------------
    // F3: Multiple metrics independent
    // ---------------------------------------------------------------

    public function test_multiple_metrics_independent(): void
    {
        $plan = $this->createPlanWithMultipleMetrics('multi', [
            'api_calls' => ['included_amount' => 10, 'enforcement_mode' => 'hard'],
            'storage_mb' => ['included_amount' => 1000, 'enforcement_mode' => 'hard'],
        ]);
        $account = $this->createAccountWithPlanAssignment($plan);

        // Fill api_calls to limit
        $res = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 10,
        ));
        $this->limiter->commit($res->ulid);

        // api_calls is at limit
        try {
            $this->limiter->reserve(new UsageAttempt(
                billingAccountId: $account->id,
                metricCode: 'api_calls',
                amount: 1,
            ));
            $this->fail('Expected UsageLimitExceededException');
        } catch (UsageLimitExceededException) {
            // expected
        }

        // storage_mb still has capacity
        $storageRes = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'storage_mb',
            amount: 500,
        ));
        $this->assertTrue($storageRes->allowed);
    }

    // ---------------------------------------------------------------
    // F4: Overage-enabled metric has higher effective limit
    // ---------------------------------------------------------------

    public function test_overage_enabled_extends_effective_limit(): void
    {
        $plan = $this->createPlanWithMetric(
            includedAmount: 100,
            enforcementMode: 'hard',
            overageEnabled: true,
            overageUnitSize: 1,
            overagePriceCents: 10,
            maxOverageAmount: 50,
            pricingMode: 'postpaid',
        );
        $account = $this->createAccountWithPlanAssignment($plan);

        // Effective limit = 100 + 50 = 150
        $res = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 150,
        ));
        $this->assertTrue($res->allowed);

        // 151 fails
        $this->limiter->commit($res->ulid);

        $this->expectException(UsageLimitExceededException::class);

        $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 1,
        ));
    }

    // ---------------------------------------------------------------
    // F5: Check endpoint (read-only)
    // ---------------------------------------------------------------

    public function test_check_returns_correct_decision(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 100, enforcementMode: 'hard');
        $account = $this->createAccountWithPlanAssignment($plan);

        $this->assertEquals(
            EnforcementDecision::Allow,
            $this->limiter->check($account->id, 'api_calls', 100),
        );

        $this->assertEquals(
            EnforcementDecision::Deny,
            $this->limiter->check($account->id, 'api_calls', 101),
        );
    }
}
