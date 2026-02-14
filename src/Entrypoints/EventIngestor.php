<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Entrypoints;

use Moneo\UsageLimiter\Core\UsageLimiter;
use Moneo\UsageLimiter\DTOs\CommitResult;
use Moneo\UsageLimiter\DTOs\UsageAttempt;
use Moneo\UsageLimiter\Exceptions\InsufficientBalanceException;
use Moneo\UsageLimiter\Exceptions\UsageLimitExceededException;

/**
 * High-level entrypoint for event-style metrics with no execution phase.
 *
 * Performs atomic Reserve + Commit in a single call.
 */
class EventIngestor
{
    public function __construct(
        private readonly UsageLimiter $limiter,
    ) {}

    /**
     * Ingest a single usage event (atomic reserve + commit).
     *
     * @throws UsageLimitExceededException If the reservation is denied by enforcement
     * @throws InsufficientBalanceException If the wallet cannot afford the usage (prepaid)
     */
    public function ingest(
        int $billingAccountId,
        string $metricCode,
        int $amount,
        ?string $idempotencyKey = null,
        ?array $metadata = null,
    ): CommitResult {
        $attempt = new UsageAttempt(
            billingAccountId: $billingAccountId,
            metricCode: $metricCode,
            amount: $amount,
            idempotencyKey: $idempotencyKey,
            metadata: $metadata,
        );

        // RESERVE — throws if denied
        $reservation = $this->limiter->reserve($attempt);

        // COMMIT immediately — no execution phase
        return $this->limiter->commit($reservation->ulid);
    }

    /**
     * Ingest multiple usage events in a batch.
     *
     * Each item is processed independently — partial success is possible.
     * Items that fail throw exceptions which are caught and collected.
     *
     * @param  array<int, array{metric_code: string, amount: int, idempotency_key?: string|null, metadata?: array|null}>  $items
     * @return array<int, CommitResult|UsageLimitExceededException|InsufficientBalanceException>
     */
    public function ingestBatch(int $billingAccountId, array $items): array
    {
        $results = [];

        foreach ($items as $index => $item) {
            try {
                $results[$index] = $this->ingest(
                    billingAccountId: $billingAccountId,
                    metricCode: $item['metric_code'],
                    amount: $item['amount'],
                    idempotencyKey: $item['idempotency_key'] ?? null,
                    metadata: $item['metadata'] ?? null,
                );
            } catch (UsageLimitExceededException|InsufficientBalanceException $e) {
                $results[$index] = $e;
            }
        }

        return $results;
    }
}
