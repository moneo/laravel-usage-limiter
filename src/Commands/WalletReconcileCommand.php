<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Moneo\UsageLimiter\Events\ReconciliationDivergenceDetected;
use Moneo\UsageLimiter\Models\BillingAccount;
use Moneo\UsageLimiter\Models\BillingTransaction;

class WalletReconcileCommand extends Command
{
    protected $signature = 'usage:wallet-reconcile
                            {--auto-correct : Automatically correct divergent wallet balances}';

    protected $description = 'Reconcile wallet balances against the billing transactions ledger';

    public function handle(): int
    {
        $autoCorrect = $this->option('auto-correct');

        $this->info('Starting wallet reconciliation...');

        $divergenceCount = 0;
        $txnTable = (new BillingTransaction)->getTable();
        $connection = DB::connection(config('usage-limiter.database_connection'));

        BillingAccount::chunk(100, function ($accounts) use ($autoCorrect, &$divergenceCount, $txnTable, $connection) {
            foreach ($accounts as $account) {
                $ledgerSum = (int) $connection->table($txnTable)
                    ->where('billing_account_id', $account->id)
                    ->sum('amount_cents');

                $firstTxn = $connection->table($txnTable)
                    ->where('billing_account_id', $account->id)
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->first(['amount_cents', 'balance_after_cents']);

                // Infer an opening balance from the first ledger row when present.
                // If the ledger is empty, treat current balance as the opening point.
                $openingBalance = $firstTxn !== null
                    ? ((int) $firstTxn->balance_after_cents - (int) $firstTxn->amount_cents)
                    : (int) $account->wallet_balance_cents;
                $expectedBalance = $openingBalance + $ledgerSum;

                if ($account->wallet_balance_cents !== $expectedBalance) {
                    $divergenceCount++;

                    $this->warn(sprintf(
                        'Divergence: account=%d balance=%d expected=%d diff=%d',
                        $account->id,
                        $account->wallet_balance_cents,
                        $expectedBalance,
                        $account->wallet_balance_cents - $expectedBalance,
                    ));

                    event(new ReconciliationDivergenceDetected(
                        billingAccountId: $account->id,
                        metricCode: 'wallet',
                        periodStart: 'all',
                        type: 'wallet_balance',
                        expected: $expectedBalance,
                        actual: $account->wallet_balance_cents,
                        corrected: $autoCorrect,
                    ));

                    if ($autoCorrect) {
                        $accountTable = $account->getTable();
                        $affected = $connection->table($accountTable)
                            ->where('id', $account->id)
                            ->where('wallet_balance_cents', $account->wallet_balance_cents)
                            ->update([
                                'wallet_balance_cents' => $expectedBalance,
                                'updated_at' => now('UTC'),
                            ]);

                        if ($affected === 0) {
                            $this->warn("  → Skipped correction for account #{$account->id} (balance changed concurrently)");

                            continue;
                        }

                        $this->info("  → Corrected wallet balance for account #{$account->id}");
                    }
                }
            }
        });

        $this->info("Wallet reconciliation complete. Divergences found: {$divergenceCount}");

        return $divergenceCount > 0 ? self::FAILURE : self::SUCCESS;
    }
}
