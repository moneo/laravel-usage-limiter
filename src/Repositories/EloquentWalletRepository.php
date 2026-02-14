<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Repositories;

use Illuminate\Support\Facades\DB;
use Moneo\UsageLimiter\Contracts\WalletRepository;
use Moneo\UsageLimiter\Enums\TransactionType;
use Moneo\UsageLimiter\Models\BillingAccount;
use Moneo\UsageLimiter\Models\BillingTransaction;

class EloquentWalletRepository implements WalletRepository
{
    private const DEADLOCK_RETRIES = 3;

    public function getBalance(int $billingAccountId): int
    {
        return (int) BillingAccount::where('id', $billingAccountId)->value('wallet_balance_cents');
    }

    public function atomicDebit(
        int $billingAccountId,
        int $amountCents,
        string $idempotencyKey,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $description = null,
        ?array $metadata = null,
    ): bool {
        // Check if already processed (idempotency)
        $existing = $this->getTransactionByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            return true; // Already debited — idempotent success
        }

        $accountTable = (new BillingAccount)->getTable();
        $txnTable = (new BillingTransaction)->getTable();

        try {
            return $this->connection()->transaction(function () use (
                $billingAccountId, $amountCents, $idempotencyKey,
                $referenceType, $referenceId, $description, $metadata,
                $accountTable, $txnTable,
            ) {
                $now = now('UTC')->toDateTimeString();

                // Step 1: Atomic conditional decrement
                $affected = $this->connection()->update(
                    "UPDATE {$accountTable}
                     SET wallet_balance_cents = wallet_balance_cents - ?,
                         updated_at = ?
                     WHERE id = ?
                       AND wallet_balance_cents >= ?",
                    [$amountCents, $now, $billingAccountId, $amountCents]
                );

                if ($affected === 0) {
                    return false; // Insufficient funds
                }

                // Step 2: Read the updated balance
                $balanceAfter = (int) $this->connection()
                    ->table($accountTable)
                    ->where('id', $billingAccountId)
                    ->value('wallet_balance_cents');

                // Step 3: Record the transaction (idempotent via unique key)
                $this->connection()->table($txnTable)->insert([
                    'billing_account_id' => $billingAccountId,
                    'type' => TransactionType::Debit->value,
                    'amount_cents' => -$amountCents,
                    'balance_after_cents' => $balanceAfter,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'idempotency_key' => $idempotencyKey,
                    'description' => $description ?? "Debit of {$amountCents} cents",
                    'metadata' => $metadata ? json_encode($metadata) : null,
                    'created_at' => now('UTC'),
                ]);

                return true;
            }, self::DEADLOCK_RETRIES);
        } catch (\Illuminate\Database\QueryException $e) {
            // Duplicate idempotency_key means a concurrent request already debited.
            // The transaction above rolled back (balance restored). Return idempotent success.
            if ($this->isDuplicateKeyException($e)) {
                return true;
            }
            throw $e;
        }
    }

    public function atomicCredit(
        int $billingAccountId,
        int $amountCents,
        string $idempotencyKey,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $description = null,
        ?array $metadata = null,
    ): bool {
        // Check if already processed (idempotency)
        $existing = $this->getTransactionByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            return true; // Already credited — idempotent success
        }

        $accountTable = (new BillingAccount)->getTable();
        $txnTable = (new BillingTransaction)->getTable();

        try {
            return $this->connection()->transaction(function () use (
                $billingAccountId, $amountCents, $idempotencyKey,
                $referenceType, $referenceId, $description, $metadata,
                $accountTable, $txnTable,
            ) {
                $now = now('UTC')->toDateTimeString();

                $affected = $this->connection()->update(
                    "UPDATE {$accountTable}
                     SET wallet_balance_cents = wallet_balance_cents + ?,
                         updated_at = ?
                     WHERE id = ?",
                    [$amountCents, $now, $billingAccountId]
                );

                if ($affected === 0) {
                    return false; // Account not found
                }

                $balanceAfter = (int) $this->connection()
                    ->table($accountTable)
                    ->where('id', $billingAccountId)
                    ->value('wallet_balance_cents');

                $this->connection()->table($txnTable)->insert([
                    'billing_account_id' => $billingAccountId,
                    'type' => TransactionType::Credit->value,
                    'amount_cents' => $amountCents,
                    'balance_after_cents' => $balanceAfter,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'idempotency_key' => $idempotencyKey,
                    'description' => $description ?? "Credit of {$amountCents} cents",
                    'metadata' => $metadata ? json_encode($metadata) : null,
                    'created_at' => now('UTC'),
                ]);

                return true;
            }, self::DEADLOCK_RETRIES);
        } catch (\Illuminate\Database\QueryException $e) {
            // Duplicate idempotency_key means a concurrent request already credited.
            // The transaction above rolled back (no double credit). Return idempotent success.
            if ($this->isDuplicateKeyException($e)) {
                return true;
            }
            throw $e;
        }
    }

    public function getTransactionByIdempotencyKey(string $key): ?BillingTransaction
    {
        return BillingTransaction::where('idempotency_key', $key)->first();
    }

    private function isDuplicateKeyException(\Illuminate\Database\QueryException $e): bool
    {
        // MySQL: 1062 (Duplicate entry), PostgreSQL: 23505 (unique_violation)
        $code = (string) $e->getCode();

        return $code === '23000' || $code === '23505' || str_contains($e->getMessage(), 'Duplicate entry');
    }

    private function connection(): \Illuminate\Database\Connection
    {
        $connectionName = config('usage-limiter.database_connection');

        return DB::connection($connectionName);
    }
}
