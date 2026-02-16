<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Tests\Integration;

use Moneo\UsageLimiter\Core\UsageLimiter;
use Moneo\UsageLimiter\DTOs\UsageAttempt;
use Moneo\UsageLimiter\Exceptions\InsufficientBalanceException;
use Moneo\UsageLimiter\Models\UsageOverage;
use Moneo\UsageLimiter\Tests\Concerns\AssertsInvariants;
use Moneo\UsageLimiter\Tests\Concerns\CreatesTestFixtures;
use Moneo\UsageLimiter\Tests\TestCase;

class HybridPricingTest extends TestCase
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
    // E15: Within included = free, no wallet check
    // ---------------------------------------------------------------

    public function test_within_included_is_free(): void
    {
        $plan = $this->createPlanWithMetric(
            includedAmount: 100,
            pricingMode: 'hybrid',
            enforcementMode: 'hard',
            overageEnabled: true,
            overageUnitSize: 1,
            overagePriceCents: 100,
            maxOverageAmount: 1000,
            hybridOverflowMode: 'prepaid',
        );
        // Wallet is 0 -- within included should still work
        $account = $this->createAccountWithPlanAssignment($plan, walletBalanceCents: 0);

        // authorize sees committed_after=0+50=50 <= 100 -> free
        $res = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 50,
        ));
        $commitResult = $this->limiter->commit($res->ulid);

        // charge sees committed_after=50 <= 100 -> free
        $this->assertFalse($commitResult->charged);
        $this->assertEquals(0, $commitResult->chargedAmountCents);

        $account->refresh();
        $this->assertEquals(0, $account->wallet_balance_cents);
    }

    // ---------------------------------------------------------------
    // E16: Overflow via prepaid
    // ---------------------------------------------------------------

    public function test_overflow_via_prepaid_debits_wallet(): void
    {
        $plan = $this->createPlanWithMetric(
            includedAmount: 50,
            pricingMode: 'hybrid',
            enforcementMode: 'hard',
            overageEnabled: true,
            overageUnitSize: 1,
            overagePriceCents: 10,
            maxOverageAmount: 1000,
            hybridOverflowMode: 'prepaid',
        );
        $account = $this->createAccountWithPlanAssignment($plan, walletBalanceCents: 50000);

        $res = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 75,
        ));
        $this->limiter->commit($res->ulid);

        // committed_before=0, committed_after=75, included=50
        // overage_before=0, overage_after=25 => delta cost = 25 * 10 = 250 cents
        $account->refresh();
        $this->assertEquals(50000 - 250, $account->wallet_balance_cents);

        $this->assertWalletMatchesLedger($account->id, initialSeed: 50000);
    }

    // ---------------------------------------------------------------
    // E17: Overflow via postpaid
    // ---------------------------------------------------------------

    public function test_overflow_via_postpaid_records_overage(): void
    {
        $plan = $this->createPlanWithMetric(
            includedAmount: 50,
            pricingMode: 'hybrid',
            enforcementMode: 'hard',
            overageEnabled: true,
            overageUnitSize: 1,
            overagePriceCents: 10,
            maxOverageAmount: 1000,
            hybridOverflowMode: 'postpaid',
        );
        $account = $this->createAccountWithPlanAssignment($plan, walletBalanceCents: 0);

        $res = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 75,
        ));
        $commitResult = $this->limiter->commit($res->ulid);

        $this->assertTrue($commitResult->overageRecorded);

        // committed_after=75, included=50 => totalOverage=25
        $overage = UsageOverage::where('billing_account_id', $account->id)->first();
        $this->assertNotNull($overage);
        $this->assertEquals(25, $overage->overage_amount);
        $this->assertEquals(250, $overage->total_price_cents);

        // No wallet debit (postpaid)
        $this->assertDatabaseCount('ul_billing_transactions', 0);
    }

    // ---------------------------------------------------------------
    // E18: Boundary: exactly included amount is free
    // ---------------------------------------------------------------

    public function test_at_effective_free_boundary_is_free(): void
    {
        $plan = $this->createPlanWithMetric(
            includedAmount: 100,
            pricingMode: 'hybrid',
            enforcementMode: 'hard',
            overageEnabled: true,
            overageUnitSize: 1,
            overagePriceCents: 100,
            maxOverageAmount: 1000,
            hybridOverflowMode: 'prepaid',
        );
        $account = $this->createAccountWithPlanAssignment($plan, walletBalanceCents: 0);

        // amount=100: committed_after=100 <= included -> free
        $res = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 100,
        ));
        $commitResult = $this->limiter->commit($res->ulid);

        // charge sees committed_after=100 <= included -> free
        $this->assertFalse($commitResult->charged);
        $this->assertEquals(0, $commitResult->chargedAmountCents);
    }

    // ---------------------------------------------------------------
    // E19: 1 unit over effective free boundary = charged
    // ---------------------------------------------------------------

    public function test_one_unit_over_effective_boundary_is_charged(): void
    {
        $plan = $this->createPlanWithMetric(
            includedAmount: 100,
            pricingMode: 'hybrid',
            enforcementMode: 'hard',
            overageEnabled: true,
            overageUnitSize: 1,
            overagePriceCents: 50,
            maxOverageAmount: 1000,
            hybridOverflowMode: 'prepaid',
        );
        $account = $this->createAccountWithPlanAssignment($plan, walletBalanceCents: 10000);

        // amount=101: committed_after=101 -> overage_after=1
        $res = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 101,
        ));
        $commitResult = $this->limiter->commit($res->ulid);

        // delta cost: calculateOverageCost(1)-calculateOverageCost(0)=50 cents
        $this->assertTrue($commitResult->charged);
        $this->assertEquals(50, $commitResult->chargedAmountCents);

        $account->refresh();
        $this->assertEquals(10000 - 50, $account->wallet_balance_cents);
    }

    // ---------------------------------------------------------------
    // E20: Hybrid with insufficient wallet for overflow
    // ---------------------------------------------------------------

    public function test_hybrid_insufficient_wallet_for_overflow(): void
    {
        $plan = $this->createPlanWithMetric(
            includedAmount: 100,
            pricingMode: 'hybrid',
            enforcementMode: 'hard',
            overageEnabled: true,
            overageUnitSize: 1,
            overagePriceCents: 100,
            maxOverageAmount: 1000,
            hybridOverflowMode: 'prepaid',
        );
        // No funds for overflow
        $account = $this->createAccountWithPlanAssignment($plan, walletBalanceCents: 0);

        // amount=101 makes overage_after=1, estimated cost=100. wallet=0 -> denied
        $this->expectException(InsufficientBalanceException::class);

        $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 101,
        ));
    }
}
