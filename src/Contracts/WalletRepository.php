<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Contracts;

use Moneo\UsageLimiter\Models\BillingTransaction;

interface WalletRepository
{
    /**
     * Get the current wallet balance in cents.
     */
    public function getBalance(int $billingAccountId): int;

    /**
     * Atomic debit: UPDATE ... SET balance = balance - amount WHERE balance >= amount.
     * Also inserts a billing_transaction with idempotency_key.
     *
     * @return bool False if insufficient funds or idempotency duplicate
     */
    public function atomicDebit(
        int $billingAccountId,
        int $amountCents,
        string $idempotencyKey,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $description = null,
        ?array $metadata = null,
    ): bool;

    /**
     * Atomic credit: increment wallet balance and record transaction.
     *
     * @return bool False only if idempotency duplicate
     */
    public function atomicCredit(
        int $billingAccountId,
        int $amountCents,
        string $idempotencyKey,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $description = null,
        ?array $metadata = null,
    ): bool;

    /**
     * Find a billing transaction by its idempotency key.
     */
    public function getTransactionByIdempotencyKey(string $key): ?BillingTransaction;
}
