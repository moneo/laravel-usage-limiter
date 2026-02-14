<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Tests\Unit\DTOs;

use Moneo\UsageLimiter\DTOs\ResolvedMetricLimit;
use Moneo\UsageLimiter\Enums\EnforcementMode;
use Moneo\UsageLimiter\Enums\PricingMode;
use PHPUnit\Framework\TestCase;

class ResolvedMetricLimitTest extends TestCase
{
    public function test_effective_limit_without_overage(): void
    {
        $limit = new ResolvedMetricLimit(
            metricCode: 'api_calls',
            includedAmount: 1000,
            overageEnabled: false,
            overageUnitSize: null,
            overagePriceCents: null,
            pricingMode: PricingMode::Postpaid,
            enforcementMode: EnforcementMode::Hard,
            maxOverageAmount: null,
            hybridOverflowMode: null,
        );

        $this->assertEquals(1000, $limit->effectiveLimit());
    }

    public function test_effective_limit_with_capped_overage(): void
    {
        $limit = new ResolvedMetricLimit(
            metricCode: 'api_calls',
            includedAmount: 1000,
            overageEnabled: true,
            overageUnitSize: 100,
            overagePriceCents: 50,
            pricingMode: PricingMode::Postpaid,
            enforcementMode: EnforcementMode::Hard,
            maxOverageAmount: 500,
            hybridOverflowMode: null,
        );

        $this->assertEquals(1500, $limit->effectiveLimit());
    }

    public function test_effective_limit_with_unlimited_overage(): void
    {
        $limit = new ResolvedMetricLimit(
            metricCode: 'api_calls',
            includedAmount: 1000,
            overageEnabled: true,
            overageUnitSize: 100,
            overagePriceCents: 50,
            pricingMode: PricingMode::Postpaid,
            enforcementMode: EnforcementMode::Soft,
            maxOverageAmount: null,
            hybridOverflowMode: null,
        );

        $this->assertEquals(PHP_INT_MAX, $limit->effectiveLimit());
    }

    public function test_overage_cost_calculation(): void
    {
        $limit = new ResolvedMetricLimit(
            metricCode: 'ai_tokens',
            includedAmount: 10000,
            overageEnabled: true,
            overageUnitSize: 1000,
            overagePriceCents: 100,
            pricingMode: PricingMode::Postpaid,
            enforcementMode: EnforcementMode::Hard,
            maxOverageAmount: null,
            hybridOverflowMode: null,
        );

        // 2500 overage units, unit size 1000 = ceil(2500/1000) = 3 chunks * 100 cents = 300
        $this->assertEquals(300, $limit->calculateOverageCost(2500));

        // 0 overage = 0 cost
        $this->assertEquals(0, $limit->calculateOverageCost(0));

        // Exactly 1000 = 1 chunk = 100 cents
        $this->assertEquals(100, $limit->calculateOverageCost(1000));
    }

    public function test_overage_cost_when_disabled(): void
    {
        $limit = new ResolvedMetricLimit(
            metricCode: 'api_calls',
            includedAmount: 1000,
            overageEnabled: false,
            overageUnitSize: null,
            overagePriceCents: null,
            pricingMode: PricingMode::Postpaid,
            enforcementMode: EnforcementMode::Hard,
            maxOverageAmount: null,
            hybridOverflowMode: null,
        );

        $this->assertEquals(0, $limit->calculateOverageCost(500));
    }
}
