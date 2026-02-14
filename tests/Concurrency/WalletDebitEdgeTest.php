<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Tests\Concurrency;

use Moneo\UsageLimiter\Contracts\WalletRepository;
use Moneo\UsageLimiter\Models\BillingTransaction;
use Moneo\UsageLimiter\Tests\Concerns\AssertsInvariants;
use Moneo\UsageLimiter\Tests\Concerns\CreatesTestFixtures;
use Moneo\UsageLimiter\Tests\TestCase;

/**
 * Concurrency tests for wallet debit operations at balance edge.
 *
 * Uses in-process sequential simulation since atomicDebit is already
 * protected by DB-level conditional UPDATE.
 *
 * @group concurrency
 */
class WalletDebitEdgeTest extends TestCase
{
    use AssertsInvariants;
    use CreatesTestFixtures;

    /**
     * Sequential simulation: 10 debits of 20 cents each with wallet=100.
     * Exactly 5 should succeed (100 / 20 = 5).
     */
    public function test_sequential_wallet_debits_no_overdraw(): void
    {
        $account = $this->createAccount(walletBalanceCents: 100);

        $walletRepo = app(WalletRepository::class);
        $successes = 0;

        for ($i = 0; $i < 10; $i++) {
            $result = $walletRepo->atomicDebit(
                $account->id,
                20,
                "debit-{$i}",
                description: "Test debit {$i}",
            );
            if ($result) {
                $successes++;
            }
        }

        $this->assertEquals(5, $successes);

        $account->refresh();
        $this->assertEquals(0, $account->wallet_balance_cents);

        $txnCount = BillingTransaction::where('billing_account_id', $account->id)
            ->where('type', 'debit')
            ->count();
        $this->assertEquals(5, $txnCount);

        $this->assertWalletMatchesLedger($account->id, initialSeed: 100);
    }

    /**
     * Same idempotency key: only 1 debit regardless of attempts.
     */
    public function test_debit_idempotency_same_key(): void
    {
        $account = $this->createAccount(walletBalanceCents: 1000);

        $walletRepo = app(WalletRepository::class);

        for ($i = 0; $i < 5; $i++) {
            $walletRepo->atomicDebit(
                $account->id,
                200,
                'same-idem-key',
            );
        }

        // Only 1 debit
        $txnCount = BillingTransaction::where('billing_account_id', $account->id)
            ->where('type', 'debit')
            ->count();
        $this->assertEquals(1, $txnCount);

        $account->refresh();
        $this->assertEquals(800, $account->wallet_balance_cents);

        $this->assertNoDuplicateDebits($account->id);
    }
}
