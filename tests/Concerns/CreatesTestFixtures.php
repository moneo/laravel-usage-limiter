<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Tests\Concerns;

use Moneo\UsageLimiter\Contracts\PlanResolver;
use Moneo\UsageLimiter\Models\BillingAccount;
use Moneo\UsageLimiter\Models\BillingAccountMetricOverride;
use Moneo\UsageLimiter\Models\BillingAccountPlanAssignment;
use Moneo\UsageLimiter\Models\Plan;
use Moneo\UsageLimiter\Models\PlanMetricLimit;

trait CreatesTestFixtures
{
    protected function createPlan(
        string $code = 'test_plan',
        string $name = 'Test Plan',
        bool $isActive = true,
    ): Plan {
        return Plan::create([
            'code' => $code,
            'name' => $name,
            'is_active' => $isActive,
        ]);
    }

    protected function createPlanWithMetric(
        string $metricCode = 'api_calls',
        int $includedAmount = 1000,
        string $enforcementMode = 'hard',
        string $pricingMode = 'postpaid',
        bool $overageEnabled = false,
        ?int $overageUnitSize = null,
        ?int $overagePriceCents = null,
        ?int $maxOverageAmount = null,
        ?string $hybridOverflowMode = null,
        string $planCode = 'test_plan',
    ): Plan {
        $plan = $this->createPlan(code: $planCode);

        PlanMetricLimit::create([
            'plan_id' => $plan->id,
            'metric_code' => $metricCode,
            'included_amount' => $includedAmount,
            'overage_enabled' => $overageEnabled,
            'overage_unit_size' => $overageUnitSize,
            'overage_price_cents' => $overagePriceCents,
            'pricing_mode' => $pricingMode,
            'enforcement_mode' => $enforcementMode,
            'max_overage_amount' => $maxOverageAmount,
            'hybrid_overflow_mode' => $hybridOverflowMode,
        ]);

        return $plan;
    }

    protected function createPlanWithMultipleMetrics(
        string $planCode,
        array $metrics,
    ): Plan {
        $plan = $this->createPlan(code: $planCode);

        foreach ($metrics as $metricCode => $config) {
            PlanMetricLimit::create(array_merge([
                'plan_id' => $plan->id,
                'metric_code' => $metricCode,
                'included_amount' => 1000,
                'overage_enabled' => false,
                'pricing_mode' => 'postpaid',
                'enforcement_mode' => 'hard',
            ], $config));
        }

        return $plan;
    }

    protected function createAccount(
        string $name = 'Test Account',
        int $walletBalanceCents = 0,
        bool $isActive = true,
        ?string $externalId = null,
        bool $autoTopupEnabled = false,
        ?int $autoTopupThresholdCents = null,
        ?int $autoTopupAmountCents = null,
    ): BillingAccount {
        return BillingAccount::create([
            'external_id' => $externalId,
            'name' => $name,
            'wallet_balance_cents' => $walletBalanceCents,
            'is_active' => $isActive,
            'auto_topup_enabled' => $autoTopupEnabled,
            'auto_topup_threshold_cents' => $autoTopupThresholdCents,
            'auto_topup_amount_cents' => $autoTopupAmountCents,
        ]);
    }

    protected function createAccountWithPlanAssignment(
        Plan $plan,
        int $walletBalanceCents = 0,
        string $name = 'Test Account',
        bool $autoTopupEnabled = false,
        ?int $autoTopupThresholdCents = null,
        ?int $autoTopupAmountCents = null,
    ): BillingAccount {
        $account = $this->createAccount(
            name: $name,
            walletBalanceCents: $walletBalanceCents,
            autoTopupEnabled: $autoTopupEnabled,
            autoTopupThresholdCents: $autoTopupThresholdCents,
            autoTopupAmountCents: $autoTopupAmountCents,
        );

        BillingAccountPlanAssignment::create([
            'billing_account_id' => $account->id,
            'plan_id' => $plan->id,
            'started_at' => now(),
        ]);

        app(PlanResolver::class)->invalidateCache($account->id);

        return $account;
    }

    protected function addMetricOverride(
        BillingAccount $account,
        string $metricCode,
        ?int $includedAmount = null,
        ?bool $overageEnabled = null,
        ?int $overageUnitSize = null,
        ?int $overagePriceCents = null,
        ?string $pricingMode = null,
        ?string $enforcementMode = null,
        ?int $maxOverageAmount = null,
    ): BillingAccountMetricOverride {
        $override = BillingAccountMetricOverride::create([
            'billing_account_id' => $account->id,
            'metric_code' => $metricCode,
            'included_amount' => $includedAmount,
            'overage_enabled' => $overageEnabled,
            'overage_unit_size' => $overageUnitSize,
            'overage_price_cents' => $overagePriceCents,
            'pricing_mode' => $pricingMode,
            'enforcement_mode' => $enforcementMode,
            'max_overage_amount' => $maxOverageAmount,
            'started_at' => now(),
        ]);

        app(PlanResolver::class)->invalidateCache($account->id);

        return $override;
    }
}
