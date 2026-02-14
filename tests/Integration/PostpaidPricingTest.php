<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Tests\Integration;

use Moneo\UsageLimiter\Core\UsageLimiter;
use Moneo\UsageLimiter\DTOs\UsageAttempt;
use Moneo\UsageLimiter\Models\UsageOverage;
use Moneo\UsageLimiter\Models\UsagePeriodAggregate;
use Moneo\UsageLimiter\Tests\Concerns\AssertsInvariants;
use Moneo\UsageLimiter\Tests\Concerns\CreatesTestFixtures;
use Moneo\UsageLimiter\Tests\TestCase;

class PostpaidPricingTest extends TestCase
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
    // E9: Postpaid always authorizes
    // ---------------------------------------------------------------

    public function test_postpaid_always_authorizes_regardless_of_wallet(): void
    {
        $plan = $this->createPlanWithMetric(
            includedAmount: 100,
            pricingMode: 'postpaid',
            enforcementMode: 'hard',
            overageEnabled: true,
            overageUnitSize: 1,
            overagePriceCents: 100,
            maxOverageAmount: 1000,
        );
        // Wallet is 0 but postpaid doesn't check
        $account = $this->createAccountWithPlanAssignment($plan, walletBalanceCents: 0);

        $reservation = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 50,
        ));

        $this->assertTrue($reservation->allowed);
    }

    // ---------------------------------------------------------------
    // E10: Overage record created with correct math
    // ---------------------------------------------------------------

    public function test_overage_record_created_with_correct_math(): void
    {
        $plan = $this->createPlanWithMetric(
            includedAmount: 50,
            pricingMode: 'postpaid',
            enforcementMode: 'hard',
            overageEnabled: true,
            overageUnitSize: 5,
            overagePriceCents: 200,
            maxOverageAmount: 1000,
        );
        $account = $this->createAccountWithPlanAssignment($plan);

        $reservation = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 73,
        ));

        $this->limiter->commit($reservation->ulid);

        // committed_after=73, included=50 => total overage=23
        // ceil(23/5) = 5 chunks * 200 = 1000 cents
        $overage = UsageOverage::where('billing_account_id', $account->id)->first();
        $this->assertNotNull($overage);
        $this->assertEquals(23, $overage->overage_amount);
        $this->assertEquals(5, $overage->overage_unit_size);
        $this->assertEquals(200, $overage->unit_price_cents);
        $this->assertEquals(1000, $overage->total_price_cents);

        $aggregate = UsagePeriodAggregate::where('billing_account_id', $account->id)->first();
        $this->assertOverageMathCorrect(
            $account->id,
            'api_calls',
            $aggregate->period_start->format('Y-m-d'),
        );
    }

    // ---------------------------------------------------------------
    // E11: Overage record is upserted (cumulative per period)
    // ---------------------------------------------------------------

    /**
     * Overage upsert across multiple commits requires MySQL/PostgreSQL.
     * SQLite has a date format mismatch with Eloquent's updateOrCreate on date columns.
     */
    public function test_overage_record_upserted_across_commits(): void
    {
        $driver = $this->app['db']->connection(
            config('usage-limiter.database_connection')
        )->getDriverName();

        if ($driver === 'sqlite') {
            $this->markTestSkipped('Overage upsert requires MySQL/PostgreSQL (SQLite date format mismatch with updateOrCreate).');
        }
        $plan = $this->createPlanWithMetric(
            includedAmount: 10,
            pricingMode: 'postpaid',
            enforcementMode: 'hard',
            overageEnabled: true,
            overageUnitSize: 1,
            overagePriceCents: 50,
            maxOverageAmount: 1000,
        );
        $account = $this->createAccountWithPlanAssignment($plan);

        // First commit: 20 total, 10 overage
        $res1 = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 20,
        ));
        $this->limiter->commit($res1->ulid);

        // Second commit: 35 total, 25 overage
        $res2 = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 15,
        ));
        $this->limiter->commit($res2->ulid);

        // Should still be just one overage record (upserted)
        $overageCount = UsageOverage::where('billing_account_id', $account->id)->count();
        $this->assertEquals(1, $overageCount);

        $overage = UsageOverage::where('billing_account_id', $account->id)->first();
        $this->assertEquals(25, $overage->overage_amount);
        $this->assertEquals(25 * 50, $overage->total_price_cents);
    }

    // ---------------------------------------------------------------
    // E12: No overage when within included
    // ---------------------------------------------------------------

    public function test_no_overage_when_within_included(): void
    {
        $plan = $this->createPlanWithMetric(
            includedAmount: 100,
            pricingMode: 'postpaid',
            enforcementMode: 'hard',
            overageEnabled: true,
            overageUnitSize: 1,
            overagePriceCents: 100,
            maxOverageAmount: 1000,
        );
        $account = $this->createAccountWithPlanAssignment($plan);

        $reservation = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 50,
        ));

        $commitResult = $this->limiter->commit($reservation->ulid);

        $this->assertFalse($commitResult->overageRecorded);
        $this->assertDatabaseCount('ul_usage_overages', 0);
    }

    // ---------------------------------------------------------------
    // E13: Overage with unit_size rounding edge cases
    // ---------------------------------------------------------------

    public function test_overage_unit_size_rounding(): void
    {
        $plan = $this->createPlanWithMetric(
            includedAmount: 0,
            pricingMode: 'postpaid',
            enforcementMode: 'hard',
            overageEnabled: true,
            overageUnitSize: 100,
            overagePriceCents: 1000,
            maxOverageAmount: 10000,
        );
        $account = $this->createAccountWithPlanAssignment($plan);

        // committed_after=1, included=0 => total overage=1
        // ceil(1/100) = 1 chunk * 1000 = 1000 cents
        $res = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 1,
        ));
        $this->limiter->commit($res->ulid);

        $overage = UsageOverage::where('billing_account_id', $account->id)->first();
        $this->assertEquals(1, $overage->overage_amount);
        $this->assertEquals(1000, $overage->total_price_cents);
    }

    // ---------------------------------------------------------------
    // E14: Wallet unchanged for postpaid
    // ---------------------------------------------------------------

    public function test_postpaid_does_not_touch_wallet(): void
    {
        $plan = $this->createPlanWithMetric(
            includedAmount: 0,
            pricingMode: 'postpaid',
            enforcementMode: 'hard',
            overageEnabled: true,
            overageUnitSize: 1,
            overagePriceCents: 100,
            maxOverageAmount: 1000,
        );
        $account = $this->createAccountWithPlanAssignment($plan, walletBalanceCents: 5000);

        $res = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 50,
        ));
        $this->limiter->commit($res->ulid);

        $account->refresh();
        $this->assertEquals(5000, $account->wallet_balance_cents);
        $this->assertDatabaseCount('ul_billing_transactions', 0);
    }

    // ---------------------------------------------------------------
    // E15: overageUnitSize=0 does not cause division by zero
    // ---------------------------------------------------------------

    public function test_overage_unit_size_zero_does_not_crash(): void
    {
        $plan = $this->createPlanWithMetric(
            includedAmount: 0,
            pricingMode: 'postpaid',
            enforcementMode: 'hard',
            overageEnabled: true,
            overageUnitSize: 0,
            overagePriceCents: 100,
            maxOverageAmount: 1000,
        );
        $account = $this->createAccountWithPlanAssignment($plan);

        $res = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 5,
        ));
        $commitResult = $this->limiter->commit($res->ulid);

        // calculateOverageCost() returns 0 when overageUnitSize is 0 (misconfiguration guard).
        // This is now consistent across prepaid and postpaid.
        $this->assertFalse($commitResult->overageRecorded);
        $this->assertDatabaseCount('ul_usage_overages', 0);
    }

    // ---------------------------------------------------------------
    // E16: Multi-commit postpaid chunked overage (cumulative price)
    // ---------------------------------------------------------------

    public function test_multi_commit_chunked_overage_cumulative(): void
    {
        $driver = $this->app['db']->connection(
            config('usage-limiter.database_connection')
        )->getDriverName();

        if ($driver === 'sqlite') {
            $this->markTestSkipped('Overage upsert requires MySQL/PostgreSQL.');
        }

        $plan = $this->createPlanWithMetric(
            includedAmount: 0,
            pricingMode: 'postpaid',
            enforcementMode: 'hard',
            overageEnabled: true,
            overageUnitSize: 100,
            overagePriceCents: 1000,
            maxOverageAmount: 10000,
        );
        $account = $this->createAccountWithPlanAssignment($plan);

        // Commit #1: 99 units -> ceil(99/100)=1 chunk => 1000 cents
        $res1 = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 99,
        ));
        $this->limiter->commit($res1->ulid);

        $overage1 = UsageOverage::where('billing_account_id', $account->id)->first();
        $this->assertEquals(99, $overage1->overage_amount);
        $this->assertEquals(1000, $overage1->total_price_cents);

        // Commit #2: 1 more unit -> total 100 -> still 1 chunk => 1000 cents (same)
        $res2 = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 1,
        ));
        $this->limiter->commit($res2->ulid);

        $overage2 = UsageOverage::where('billing_account_id', $account->id)->first();
        $this->assertEquals(100, $overage2->overage_amount);
        $this->assertEquals(1000, $overage2->total_price_cents);

        // Commit #3: 1 more unit -> total 101 -> 2 chunks => 2000 cents
        $res3 = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 1,
        ));
        $this->limiter->commit($res3->ulid);

        $overage3 = UsageOverage::where('billing_account_id', $account->id)->first();
        $this->assertEquals(101, $overage3->overage_amount);
        $this->assertEquals(2000, $overage3->total_price_cents);

        // Only 1 overage record (upserted)
        $this->assertEquals(1, UsageOverage::where('billing_account_id', $account->id)->count());
    }
}
