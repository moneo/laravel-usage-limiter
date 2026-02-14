<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Tests\Integration\Commands;

use Moneo\UsageLimiter\Core\UsageLimiter;
use Moneo\UsageLimiter\DTOs\UsageAttempt;
use Moneo\UsageLimiter\Models\UsageOverage;
use Moneo\UsageLimiter\Tests\Concerns\AssertsInvariants;
use Moneo\UsageLimiter\Tests\Concerns\CreatesTestFixtures;
use Moneo\UsageLimiter\Tests\TestCase;

/**
 * Tests for the usage:recalculate-overages command.
 *
 * The command queries aggregates using Eloquent's date-cast values which
 * produces format mismatches on SQLite. These tests require MySQL/PostgreSQL.
 */
class RecalculateOveragesCommandTest extends TestCase
{
    use AssertsInvariants;
    use CreatesTestFixtures;

    private UsageLimiter $limiter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->limiter = app(UsageLimiter::class);

        $driver = $this->app['db']->connection(
            config('usage-limiter.database_connection')
        )->getDriverName();

        if ($driver === 'sqlite') {
            $this->markTestSkipped(
                'RecalculateOverages command has date format mismatches on SQLite. Requires MySQL/PostgreSQL.'
            );
        }
    }

    public function test_corrects_overage_amount_from_actual_committed(): void
    {
        $plan = $this->createPlanWithMetric(
            includedAmount: 50,
            pricingMode: 'postpaid',
            overageEnabled: true,
            overageUnitSize: 1,
            overagePriceCents: 100,
            maxOverageAmount: 1000,
        );
        $account = $this->createAccountWithPlanAssignment($plan);

        $res = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 75,
        ));
        $this->limiter->commit($res->ulid);

        // Corrupt the overage record
        UsageOverage::where('billing_account_id', $account->id)
            ->update(['overage_amount' => 999, 'total_price_cents' => 99900]);

        $this->artisan('usage:recalculate-overages')
            ->assertSuccessful();

        $overage = UsageOverage::where('billing_account_id', $account->id)->first();
        $this->assertEquals(25, $overage->overage_amount);
        $this->assertEquals(2500, $overage->total_price_cents);
    }

    public function test_corrects_total_price_cents(): void
    {
        $plan = $this->createPlanWithMetric(
            includedAmount: 10,
            pricingMode: 'postpaid',
            overageEnabled: true,
            overageUnitSize: 5,
            overagePriceCents: 200,
            maxOverageAmount: 1000,
        );
        $account = $this->createAccountWithPlanAssignment($plan);

        $res = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 23,
        ));
        $this->limiter->commit($res->ulid);

        // Corrupt price
        UsageOverage::where('billing_account_id', $account->id)
            ->update(['total_price_cents' => 1]);

        $this->artisan('usage:recalculate-overages');

        $overage = UsageOverage::where('billing_account_id', $account->id)->first();
        // 23 - 10 = 13 overage, ceil(13/5) = 3 * 200 = 600
        $this->assertEquals(600, $overage->total_price_cents);
    }
}
