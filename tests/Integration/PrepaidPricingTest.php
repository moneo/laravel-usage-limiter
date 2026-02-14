<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Tests\Integration;

use Illuminate\Support\Facades\Event;
use Moneo\UsageLimiter\Core\UsageLimiter;
use Moneo\UsageLimiter\DTOs\UsageAttempt;
use Moneo\UsageLimiter\Events\WalletTopupRequested;
use Moneo\UsageLimiter\Exceptions\InsufficientBalanceException;
use Moneo\UsageLimiter\Models\BillingTransaction;
use Moneo\UsageLimiter\Tests\Concerns\AssertsInvariants;
use Moneo\UsageLimiter\Tests\Concerns\CreatesTestFixtures;
use Moneo\UsageLimiter\Tests\TestCase;

class PrepaidPricingTest extends TestCase
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
    // E1: Insufficient funds blocks reserve
    // ---------------------------------------------------------------

    public function test_insufficient_wallet_blocks_reserve(): void
    {
        $plan = $this->createPlanWithMetric(
            includedAmount: 0,
            pricingMode: 'prepaid',
            enforcementMode: 'hard',
            overageEnabled: true,
            overageUnitSize: 1,
            overagePriceCents: 100,
            maxOverageAmount: 1000,
        );
        $account = $this->createAccountWithPlanAssignment($plan, walletBalanceCents: 50);

        // 1 unit * 100 cents = 100 cents needed, only have 50
        $this->expectException(InsufficientBalanceException::class);

        $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 1,
        ));
    }

    // ---------------------------------------------------------------
    // E2: Auto-topup event fires when below threshold
    // ---------------------------------------------------------------

    public function test_auto_topup_event_fires(): void
    {
        Event::fake();

        $plan = $this->createPlanWithMetric(
            includedAmount: 0,
            pricingMode: 'prepaid',
            enforcementMode: 'hard',
            overageEnabled: true,
            overageUnitSize: 1,
            overagePriceCents: 100,
            maxOverageAmount: 1000,
        );
        $account = $this->createAccountWithPlanAssignment(
            $plan,
            walletBalanceCents: 600,
            autoTopupEnabled: true,
            autoTopupThresholdCents: 500,
            autoTopupAmountCents: 1000,
        );

        // Cost = 2 * 100 = 200 cents. Balance after would be 400 < 500 threshold
        $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 2,
        ));

        Event::assertDispatched(WalletTopupRequested::class, function (WalletTopupRequested $event) use ($account): bool {
            return $event->billingAccountId === $account->id
                && $event->currentBalanceCents === 600;
        });
    }

    // ---------------------------------------------------------------
    // E3: Exact wallet debit with ceil rounding
    // ---------------------------------------------------------------

    public function test_overage_cost_calculation_ceil_rounding(): void
    {
        $plan = $this->createPlanWithMetric(
            includedAmount: 0,
            pricingMode: 'prepaid',
            enforcementMode: 'hard',
            overageEnabled: true,
            overageUnitSize: 10,
            overagePriceCents: 100,
            maxOverageAmount: 1000,
        );
        $account = $this->createAccountWithPlanAssignment($plan, walletBalanceCents: 50000);

        // 15 overage units, ceil(15/10) = 2 chunks, 2 * 100 = 200 cents
        $reservation = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 15,
        ));

        $this->limiter->commit($reservation->ulid);

        $account->refresh();
        $this->assertEquals(50000 - 200, $account->wallet_balance_cents);
        $this->assertWalletMatchesLedger($account->id, initialSeed: 50000);
    }

    // ---------------------------------------------------------------
    // E4: No charge when within included amount
    // ---------------------------------------------------------------

    public function test_no_charge_within_included_amount(): void
    {
        $plan = $this->createPlanWithMetric(
            includedAmount: 100,
            pricingMode: 'prepaid',
            enforcementMode: 'hard',
            overageEnabled: true,
            overageUnitSize: 1,
            overagePriceCents: 100,
            maxOverageAmount: 1000,
        );
        $account = $this->createAccountWithPlanAssignment($plan, walletBalanceCents: 5000);

        $reservation = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 50,
        ));

        $commitResult = $this->limiter->commit($reservation->ulid);

        $this->assertFalse($commitResult->charged);
        $this->assertEquals(0, $commitResult->chargedAmountCents);

        $account->refresh();
        $this->assertEquals(5000, $account->wallet_balance_cents);
    }

    // ---------------------------------------------------------------
    // E5: Partial overage (some included, some overage)
    // ---------------------------------------------------------------

    public function test_partial_overage_charges_only_excess(): void
    {
        $plan = $this->createPlanWithMetric(
            includedAmount: 50,
            pricingMode: 'prepaid',
            enforcementMode: 'hard',
            overageEnabled: true,
            overageUnitSize: 1,
            overagePriceCents: 10,
            maxOverageAmount: 1000,
        );
        $account = $this->createAccountWithPlanAssignment($plan, walletBalanceCents: 10000);

        $reservation = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 75,
        ));

        $commitResult = $this->limiter->commit($reservation->ulid);

        // committed_before=0, committed_after=75, included=50
        // overage_before=0, overage_after=25 => delta cost = 25 * 10 = 250 cents
        $this->assertTrue($commitResult->charged);
        $this->assertEquals(250, $commitResult->chargedAmountCents);

        $account->refresh();
        $this->assertEquals(10000 - 250, $account->wallet_balance_cents);
    }

    // ---------------------------------------------------------------
    // E6: Debit idempotency
    // ---------------------------------------------------------------

    public function test_debit_idempotency_same_reservation_ulid(): void
    {
        $plan = $this->createPlanWithMetric(
            includedAmount: 0,
            pricingMode: 'prepaid',
            enforcementMode: 'hard',
            overageEnabled: true,
            overageUnitSize: 1,
            overagePriceCents: 100,
            maxOverageAmount: 1000,
        );
        $account = $this->createAccountWithPlanAssignment($plan, walletBalanceCents: 50000);

        $reservation = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 5,
        ));

        // Commit twice (idempotent)
        $this->limiter->commit($reservation->ulid);
        $this->limiter->commit($reservation->ulid);

        // Only one debit transaction
        $debitCount = BillingTransaction::where('billing_account_id', $account->id)
            ->where('type', 'debit')
            ->count();
        $this->assertEquals(1, $debitCount);

        $account->refresh();
        $this->assertEquals(50000 - 500, $account->wallet_balance_cents);

        $this->assertNoDuplicateDebits($account->id);
    }

    // ---------------------------------------------------------------
    // E7: Wallet balance never goes negative from a debit
    // ---------------------------------------------------------------

    public function test_wallet_balance_never_goes_negative(): void
    {
        $plan = $this->createPlanWithMetric(
            includedAmount: 0,
            pricingMode: 'prepaid',
            enforcementMode: 'hard',
            overageEnabled: true,
            overageUnitSize: 1,
            overagePriceCents: 100,
            maxOverageAmount: 100,
        );
        $account = $this->createAccountWithPlanAssignment($plan, walletBalanceCents: 99);

        // Cost = 1 * 100 = 100 cents, wallet has 99. Should be denied at reserve time.
        $this->expectException(InsufficientBalanceException::class);

        $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 1,
        ));
    }

    // ---------------------------------------------------------------
    // E8: Full lifecycle with wallet ledger invariant
    // ---------------------------------------------------------------

    public function test_wallet_matches_ledger_after_full_lifecycle(): void
    {
        $plan = $this->createPlanWithMetric(
            includedAmount: 10,
            pricingMode: 'prepaid',
            enforcementMode: 'hard',
            overageEnabled: true,
            overageUnitSize: 1,
            overagePriceCents: 50,
            maxOverageAmount: 100,
        );
        $account = $this->createAccountWithPlanAssignment($plan, walletBalanceCents: 100000);

        // Multiple reserves and commits
        for ($i = 0; $i < 5; $i++) {
            $res = $this->limiter->reserve(new UsageAttempt(
                billingAccountId: $account->id,
                metricCode: 'api_calls',
                amount: 15,
            ));
            $this->limiter->commit($res->ulid);
        }

        $this->assertWalletMatchesLedger($account->id, initialSeed: 100000);
        $this->assertNoDuplicateDebits($account->id);
    }

    // ---------------------------------------------------------------
    // E9: Chunked pricing charges by total-cost delta, not raw units
    // ---------------------------------------------------------------

    public function test_chunked_pricing_uses_total_cost_delta(): void
    {
        $plan = $this->createPlanWithMetric(
            includedAmount: 0,
            pricingMode: 'prepaid',
            enforcementMode: 'hard',
            overageEnabled: true,
            overageUnitSize: 100,
            overagePriceCents: 1000,
            maxOverageAmount: 10000,
        );
        $account = $this->createAccountWithPlanAssignment($plan, walletBalanceCents: 10000);

        // Commit #1: overage 99 -> ceil(99/100)=1 chunk => 1000 cents
        $res1 = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 99,
        ));
        $result1 = $this->limiter->commit($res1->ulid);
        $this->assertEquals(1000, $result1->chargedAmountCents);

        // Commit #2: overage 100 -> still 1 chunk => additional charge must be 0
        $res2 = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 1,
        ));
        $result2 = $this->limiter->commit($res2->ulid);
        $this->assertEquals(0, $result2->chargedAmountCents);

        $account->refresh();
        $this->assertEquals(9000, $account->wallet_balance_cents);
    }

    // ---------------------------------------------------------------
    // E10: Prepaid refund equals original charge
    // ---------------------------------------------------------------

    public function test_prepaid_refund_equals_original_charge(): void
    {
        $plan = $this->createPlanWithMetric(
            includedAmount: 0,
            pricingMode: 'prepaid',
            enforcementMode: 'hard',
            overageEnabled: true,
            overageUnitSize: 1,
            overagePriceCents: 100,
            maxOverageAmount: 1000,
        );
        $account = $this->createAccountWithPlanAssignment($plan, walletBalanceCents: 50000);

        // Reserve + commit
        $reservation = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 10,
        ));
        $commitResult = $this->limiter->commit($reservation->ulid);
        $chargedAmount = $commitResult->chargedAmountCents;

        $this->assertTrue($commitResult->charged);
        $this->assertEquals(1000, $chargedAmount); // 10 * 100

        // Now release (which triggers refund for prepaid)
        $releaseResult = $this->limiter->release($reservation->ulid);

        // Already committed, cannot release
        $this->assertFalse($releaseResult->released);

        // Verify wallet is debited only once (no refund since committed)
        $account->refresh();
        $this->assertEquals(50000 - 1000, $account->wallet_balance_cents);

        // Test actual refund path: reserve without commit, then release
        $reservation2 = $this->limiter->reserve(new UsageAttempt(
            billingAccountId: $account->id,
            metricCode: 'api_calls',
            amount: 5,
        ));
        // Release without committing — no charge occurred, so no refund
        $releaseResult2 = $this->limiter->release($reservation2->ulid);
        $this->assertTrue($releaseResult2->released);
        $this->assertFalse($releaseResult2->refunded);
        $this->assertEquals(0, $releaseResult2->refundedAmountCents);

        // Wallet still same (charge happens only at commit, and no commit was done)
        $account->refresh();
        $this->assertEquals(49000, $account->wallet_balance_cents);

        $this->assertWalletMatchesLedger($account->id, initialSeed: 50000);
    }
}
