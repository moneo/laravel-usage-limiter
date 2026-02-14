<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Tests\Integration\Commands;

use Illuminate\Support\Facades\Event;
use Moneo\UsageLimiter\Contracts\WalletRepository;
use Moneo\UsageLimiter\Events\ReconciliationDivergenceDetected;
use Moneo\UsageLimiter\Models\BillingAccount;
use Moneo\UsageLimiter\Tests\Concerns\AssertsInvariants;
use Moneo\UsageLimiter\Tests\Concerns\CreatesTestFixtures;
use Moneo\UsageLimiter\Tests\TestCase;

class WalletReconcileCommandTest extends TestCase
{
    use AssertsInvariants;
    use CreatesTestFixtures;

    public function test_detects_wallet_balance_drift(): void
    {
        Event::fake();

        $account = $this->createAccount(walletBalanceCents: 0);

        $walletRepo = app(WalletRepository::class);
        $walletRepo->atomicCredit($account->id, 1000, 'seed-credit');

        // Corrupt the balance
        BillingAccount::where('id', $account->id)
            ->update(['wallet_balance_cents' => 9999]);

        $this->artisan('usage:wallet-reconcile')
            ->assertFailed();

        Event::assertDispatched(ReconciliationDivergenceDetected::class, function ($event) use ($account): bool {
            return $event->billingAccountId === $account->id
                && $event->type === 'wallet_balance';
        });
    }

    public function test_auto_correct_fixes_wallet_balance(): void
    {
        $account = $this->createAccount(walletBalanceCents: 0);

        $walletRepo = app(WalletRepository::class);
        $walletRepo->atomicCredit($account->id, 1000, 'seed-credit');

        // Corrupt
        BillingAccount::where('id', $account->id)
            ->update(['wallet_balance_cents' => 9999]);

        $this->artisan('usage:wallet-reconcile', ['--auto-correct' => true]);

        $account->refresh();
        $this->assertEquals(1000, $account->wallet_balance_cents);

        $this->assertWalletMatchesLedger($account->id);
    }

    public function test_no_divergence_returns_success(): void
    {
        $account = $this->createAccount(walletBalanceCents: 0);

        $walletRepo = app(WalletRepository::class);
        $walletRepo->atomicCredit($account->id, 500, 'credit-1');

        $this->artisan('usage:wallet-reconcile')
            ->assertSuccessful();
    }
}
