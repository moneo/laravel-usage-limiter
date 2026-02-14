<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Tests\Integration;

use Moneo\UsageLimiter\Contracts\PlanResolver;
use Moneo\UsageLimiter\Enums\EnforcementMode;
use Moneo\UsageLimiter\Enums\PricingMode;
use Moneo\UsageLimiter\Models\BillingAccountMetricOverride;
use Moneo\UsageLimiter\Tests\TestCase;

class PlanResolverTest extends TestCase
{
    private PlanResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = app(PlanResolver::class);
    }

    public function test_resolves_plan_with_metrics(): void
    {
        $plan = $this->createPlanWithLimits('pro', [
            'api_calls' => [
                'included_amount' => 10000,
                'enforcement_mode' => 'hard',
                'pricing_mode' => 'postpaid',
            ],
            'ai_tokens' => [
                'included_amount' => 50000,
                'enforcement_mode' => 'soft',
                'pricing_mode' => 'prepaid',
            ],
        ]);
        $account = $this->createAccountWithPlan($plan);

        $resolved = $this->resolver->resolve($account->id);

        $this->assertEquals($plan->id, $resolved->planId);
        $this->assertEquals('pro', $resolved->planCode);
        $this->assertTrue($resolved->hasMetric('api_calls'));
        $this->assertTrue($resolved->hasMetric('ai_tokens'));
        $this->assertFalse($resolved->hasMetric('nonexistent'));

        $apiCalls = $resolved->getMetric('api_calls');
        $this->assertEquals(10000, $apiCalls->includedAmount);
        $this->assertEquals(EnforcementMode::Hard, $apiCalls->enforcementMode);

        $aiTokens = $resolved->getMetric('ai_tokens');
        $this->assertEquals(50000, $aiTokens->includedAmount);
        $this->assertEquals(EnforcementMode::Soft, $aiTokens->enforcementMode);
        $this->assertEquals(PricingMode::Prepaid, $aiTokens->pricingMode);
    }

    public function test_override_merges_with_plan(): void
    {
        $plan = $this->createPlanWithLimits('basic', [
            'api_calls' => [
                'included_amount' => 1000,
                'enforcement_mode' => 'hard',
                'pricing_mode' => 'postpaid',
            ],
        ]);
        $account = $this->createAccountWithPlan($plan);

        // Add an override with higher limit
        BillingAccountMetricOverride::create([
            'billing_account_id' => $account->id,
            'metric_code' => 'api_calls',
            'included_amount' => 5000,
            'started_at' => now(),
            'reason' => 'VIP customer upgrade',
        ]);

        $this->resolver->invalidateCache($account->id);

        $resolved = $this->resolver->resolve($account->id);
        $metric = $resolved->getMetric('api_calls');

        // Override: included amount is 5000
        $this->assertEquals(5000, $metric->includedAmount);
        // Non-overridden fields: use plan defaults
        $this->assertEquals(EnforcementMode::Hard, $metric->enforcementMode);
        $this->assertEquals(PricingMode::Postpaid, $metric->pricingMode);
    }

    public function test_returns_empty_plan_when_no_assignment(): void
    {
        $account = \Moneo\UsageLimiter\Models\BillingAccount::create([
            'name' => 'Unassigned Account',
        ]);

        $resolved = $this->resolver->resolve($account->id);

        $this->assertEquals(0, $resolved->planId);
        $this->assertEmpty($resolved->metrics);
    }
}
