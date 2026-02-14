<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Tests\Unit\Policies;

use Carbon\CarbonImmutable;
use Moneo\UsageLimiter\DTOs\EnforcementContext;
use Moneo\UsageLimiter\DTOs\EnforcementDecision;
use Moneo\UsageLimiter\DTOs\Period;
use Moneo\UsageLimiter\DTOs\ResolvedMetricLimit;
use Moneo\UsageLimiter\Enums\EnforcementMode;
use Moneo\UsageLimiter\Enums\PricingMode;
use Moneo\UsageLimiter\Policies\Enforcement\SoftEnforcementPolicy;
use PHPUnit\Framework\TestCase;

class SoftEnforcementPolicyTest extends TestCase
{
    private SoftEnforcementPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new SoftEnforcementPolicy;
    }

    public function test_allows_within_limit(): void
    {
        $context = $this->buildContext(
            currentCommitted: 500,
            currentReserved: 100,
            requestedAmount: 200,
            effectiveLimit: 1000,
        );

        $this->assertEquals(EnforcementDecision::Allow, $this->policy->evaluate($context));
    }

    public function test_allows_with_warning_when_exceeding_limit(): void
    {
        $context = $this->buildContext(
            currentCommitted: 800,
            currentReserved: 100,
            requestedAmount: 200,
            effectiveLimit: 1000,
        );

        $this->assertEquals(EnforcementDecision::AllowWithWarning, $this->policy->evaluate($context));
    }

    public function test_never_denies(): void
    {
        $context = $this->buildContext(
            currentCommitted: 5000,
            currentReserved: 5000,
            requestedAmount: 5000,
            effectiveLimit: 100,
        );

        $decision = $this->policy->evaluate($context);
        $this->assertTrue($decision->isAllowed());
    }

    private function buildContext(
        int $currentCommitted,
        int $currentReserved,
        int $requestedAmount,
        int $effectiveLimit,
    ): EnforcementContext {
        $metricLimit = new ResolvedMetricLimit(
            metricCode: 'events',
            includedAmount: $effectiveLimit,
            overageEnabled: true,
            overageUnitSize: 100,
            overagePriceCents: 10,
            pricingMode: PricingMode::Postpaid,
            enforcementMode: EnforcementMode::Soft,
            maxOverageAmount: null,
            hybridOverflowMode: null,
        );

        return new EnforcementContext(
            billingAccountId: 1,
            metricLimit: $metricLimit,
            requestedAmount: $requestedAmount,
            currentCommitted: $currentCommitted,
            currentReserved: $currentReserved,
            effectiveLimit: $effectiveLimit,
            period: new Period(
                start: CarbonImmutable::parse('2026-02-01'),
                end: CarbonImmutable::parse('2026-02-28'),
                key: '2026-02',
            ),
        );
    }
}
