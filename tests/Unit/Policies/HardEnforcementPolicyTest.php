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
use Moneo\UsageLimiter\Policies\Enforcement\HardEnforcementPolicy;
use PHPUnit\Framework\TestCase;

class HardEnforcementPolicyTest extends TestCase
{
    private HardEnforcementPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new HardEnforcementPolicy;
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

    public function test_denies_when_exceeding_limit(): void
    {
        $context = $this->buildContext(
            currentCommitted: 800,
            currentReserved: 100,
            requestedAmount: 200,
            effectiveLimit: 1000,
        );

        $this->assertEquals(EnforcementDecision::Deny, $this->policy->evaluate($context));
    }

    public function test_allows_exactly_at_limit(): void
    {
        $context = $this->buildContext(
            currentCommitted: 800,
            currentReserved: 0,
            requestedAmount: 200,
            effectiveLimit: 1000,
        );

        $this->assertEquals(EnforcementDecision::Allow, $this->policy->evaluate($context));
    }

    public function test_denies_one_over_limit(): void
    {
        $context = $this->buildContext(
            currentCommitted: 800,
            currentReserved: 0,
            requestedAmount: 201,
            effectiveLimit: 1000,
        );

        $this->assertEquals(EnforcementDecision::Deny, $this->policy->evaluate($context));
    }

    private function buildContext(
        int $currentCommitted,
        int $currentReserved,
        int $requestedAmount,
        int $effectiveLimit,
    ): EnforcementContext {
        $metricLimit = new ResolvedMetricLimit(
            metricCode: 'api_calls',
            includedAmount: $effectiveLimit,
            overageEnabled: false,
            overageUnitSize: null,
            overagePriceCents: null,
            pricingMode: PricingMode::Postpaid,
            enforcementMode: EnforcementMode::Hard,
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
