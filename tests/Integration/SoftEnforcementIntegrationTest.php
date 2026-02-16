<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Tests\Integration;

use Moneo\UsageLimiter\Core\UsageLimiter;
use Moneo\UsageLimiter\DTOs\EnforcementDecision;
use Moneo\UsageLimiter\DTOs\UsageAttempt;
use Moneo\UsageLimiter\Models\UsagePeriodAggregate;
use Moneo\UsageLimiter\Tests\Concerns\AssertsInvariants;
use Moneo\UsageLimiter\Tests\Concerns\CreatesTestFixtures;
use Moneo\UsageLimiter\Tests\TestCase;

class SoftEnforcementIntegrationTest extends TestCase
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
    // F6: Soft enforcement allows over limit
    // ---------------------------------------------------------------

    public function test_soft_enforcement_allows_over_limit(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 100, enforcementMode: 'soft');
        $account = $this->createAccountWithPlanAssignment($plan);

        $result = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 150,
        ));

        $this->assertTrue($result->allowed);

        $aggregate = UsagePeriodAggregate::where('billing_account_id', $account->id)->first();
        $this->assertEquals(150, $aggregate->reserved_usage);
    }

    // ---------------------------------------------------------------
    // F7: Warning present in result
    // ---------------------------------------------------------------

    public function test_soft_enforcement_includes_warning(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 100, enforcementMode: 'soft');
        $account = $this->createAccountWithPlanAssignment($plan);

        $result = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 150,
        ));

        $this->assertTrue($result->allowed);
        $this->assertEquals(EnforcementDecision::AllowWithWarning, $result->decision);
        $this->assertNotNull($result->warning);
    }

    // ---------------------------------------------------------------
    // F8: Within limit: no warning
    // ---------------------------------------------------------------

    public function test_soft_enforcement_no_warning_within_limit(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 100, enforcementMode: 'soft');
        $account = $this->createAccountWithPlanAssignment($plan);

        $result = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 50,
        ));

        $this->assertTrue($result->allowed);
        $this->assertEquals(EnforcementDecision::Allow, $result->decision);
        $this->assertNull($result->warning);
    }

    // ---------------------------------------------------------------
    // F9: Check returns AllowWithWarning for soft over limit
    // ---------------------------------------------------------------

    public function test_check_returns_allow_with_warning(): void
    {
        $plan = $this->createPlanWithMetric(includedAmount: 100, enforcementMode: 'soft');
        $account = $this->createAccountWithPlanAssignment($plan);

        $decision = $this->limiter->check($account->id, 'api_calls', 150);
        $this->assertEquals(EnforcementDecision::AllowWithWarning, $decision);
    }
}
