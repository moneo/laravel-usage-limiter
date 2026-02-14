<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Tests\Integration;

use Moneo\UsageLimiter\Contracts\PlanResolver;
use Moneo\UsageLimiter\Core\UsageLimiter;
use Moneo\UsageLimiter\DTOs\UsageAttempt;
use Moneo\UsageLimiter\Enums\EnforcementMode;
use Moneo\UsageLimiter\Enums\PricingMode;
use Moneo\UsageLimiter\Exceptions\UsageLimitExceededException;
use Moneo\UsageLimiter\Models\BillingAccountPlanAssignment;
use Moneo\UsageLimiter\Tests\Concerns\CreatesTestFixtures;
use Moneo\UsageLimiter\Tests\TestCase;

class PlanResolutionTest extends TestCase
{
    use CreatesTestFixtures;

    private PlanResolver $resolver;

    private UsageLimiter $limiter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = app(PlanResolver::class);
        $this->limiter = app(UsageLimiter::class);
    }

    // ---------------------------------------------------------------
    // G1: Base plan fields resolve correctly
    // ---------------------------------------------------------------

    public function test_base_plan_fields_resolve(): void
    {
        $plan = $this->createPlanWithMetric(
            metricCode: 'api_calls',
            includedAmount: 500,
            enforcementMode: 'hard',
            pricingMode: 'postpaid',
            overageEnabled: true,
            overageUnitSize: 10,
            overagePriceCents: 50,
        );
        $account = $this->createAccountWithPlanAssignment($plan);

        $resolved = $this->resolver->resolveMetric($account->id, 'api_calls');

        $this->assertNotNull($resolved);
        $this->assertEquals('api_calls', $resolved->metricCode);
        $this->assertEquals(500, $resolved->includedAmount);
        $this->assertEquals(EnforcementMode::Hard, $resolved->enforcementMode);
        $this->assertEquals(PricingMode::Postpaid, $resolved->pricingMode);
        $this->assertTrue($resolved->overageEnabled);
        $this->assertEquals(10, $resolved->overageUnitSize);
        $this->assertEquals(50, $resolved->overagePriceCents);
    }

    // ---------------------------------------------------------------
    // G2: Override non-null fields take precedence
    // ---------------------------------------------------------------

    public function test_override_nonnull_fields_take_precedence(): void
    {
        $plan = $this->createPlanWithMetric(
            metricCode: 'api_calls',
            includedAmount: 500,
            enforcementMode: 'hard',
        );
        $account = $this->createAccountWithPlanAssignment($plan);

        $this->addMetricOverride($account, 'api_calls', includedAmount: 1000);

        $resolved = $this->resolver->resolveMetric($account->id, 'api_calls');

        $this->assertEquals(1000, $resolved->includedAmount);
    }

    // ---------------------------------------------------------------
    // G3: Override null fields fall through to plan defaults
    // ---------------------------------------------------------------

    public function test_override_null_fields_fall_through(): void
    {
        $plan = $this->createPlanWithMetric(
            metricCode: 'api_calls',
            includedAmount: 500,
            enforcementMode: 'hard',
            pricingMode: 'postpaid',
        );
        $account = $this->createAccountWithPlanAssignment($plan);

        // Override only included_amount, leave enforcement/pricing null
        $this->addMetricOverride($account, 'api_calls', includedAmount: 1000);

        $resolved = $this->resolver->resolveMetric($account->id, 'api_calls');

        $this->assertEquals(1000, $resolved->includedAmount);
        $this->assertEquals(EnforcementMode::Hard, $resolved->enforcementMode);
        $this->assertEquals(PricingMode::Postpaid, $resolved->pricingMode);
    }

    // ---------------------------------------------------------------
    // G4: Override included_amount changes effective limit
    // ---------------------------------------------------------------

    public function test_override_changes_effective_limit(): void
    {
        $plan = $this->createPlanWithMetric(
            metricCode: 'api_calls',
            includedAmount: 100,
            enforcementMode: 'hard',
        );
        $account = $this->createAccountWithPlanAssignment($plan);

        // Original limit: 100
        $this->expectException(UsageLimitExceededException::class);
        $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 200,
        ));
    }

    public function test_override_increases_effective_limit(): void
    {
        $plan = $this->createPlanWithMetric(
            metricCode: 'api_calls',
            includedAmount: 100,
            enforcementMode: 'hard',
        );
        $account = $this->createAccountWithPlanAssignment($plan);

        $this->addMetricOverride($account, 'api_calls', includedAmount: 500);

        // Now limit is 500
        $res = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 200,
        ));
        $this->assertTrue($res->allowed);
    }

    // ---------------------------------------------------------------
    // G5: Plan assignment with ended_at is ignored
    // ---------------------------------------------------------------

    public function test_ended_plan_assignment_is_ignored(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 1000);
        $account = $this->createAccount();

        BillingAccountPlanAssignment::create([
            'billing_account_id' => $account->id,
            'plan_id' => $plan->id,
            'started_at' => now()->subDays(30),
            'ended_at' => now()->subDays(1), // Ended
        ]);

        $this->resolver->invalidateCache($account->id);

        $resolved = $this->resolver->resolveMetric($account->id, 'api_calls');
        $this->assertNull($resolved);
    }

    // ---------------------------------------------------------------
    // G7: Plan change mid-period with cache invalidation
    // ---------------------------------------------------------------

    public function test_plan_change_with_cache_invalidation(): void
    {
        $plan1 = $this->createPlanWithMetric(
            metricCode: 'api_calls',
            includedAmount: 100,
            enforcementMode: 'hard',
            planCode: 'plan_v1',
        );
        $account = $this->createAccountWithPlanAssignment($plan1);

        $resolved1 = $this->resolver->resolveMetric($account->id, 'api_calls');
        $this->assertEquals(100, $resolved1->includedAmount);

        // End the current assignment
        BillingAccountPlanAssignment::where('billing_account_id', $account->id)
            ->whereNull('ended_at')
            ->update(['ended_at' => now()]);

        // Assign new plan
        $plan2 = $this->createPlanWithMetric(
            metricCode: 'api_calls',
            includedAmount: 500,
            enforcementMode: 'hard',
            planCode: 'plan_v2',
        );
        BillingAccountPlanAssignment::create([
            'billing_account_id' => $account->id,
            'plan_id' => $plan2->id,
            'started_at' => now(),
        ]);

        // Without invalidation, cache might return old plan
        $this->resolver->invalidateCache($account->id);

        $resolved2 = $this->resolver->resolveMetric($account->id, 'api_calls');
        $this->assertEquals(500, $resolved2->includedAmount);
    }

    // ---------------------------------------------------------------
    // G8: Cache invalidation clears cached plan
    // ---------------------------------------------------------------

    public function test_cache_invalidation_clears_cached_plan(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 100, planCode: 'cached');
        $account = $this->createAccountWithPlanAssignment($plan);

        // First call caches
        $this->resolver->resolve($account->id);

        // Invalidate
        $this->resolver->invalidateCache($account->id);

        // This should fetch fresh from DB (no stale data)
        $resolved = $this->resolver->resolve($account->id);
        $this->assertEquals('cached', $resolved->planCode);
    }
}
