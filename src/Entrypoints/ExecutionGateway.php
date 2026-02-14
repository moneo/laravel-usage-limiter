<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Entrypoints;

use Closure;
use Moneo\UsageLimiter\Core\UsageLimiter;
use Moneo\UsageLimiter\DTOs\UsageAttempt;
use Moneo\UsageLimiter\Exceptions\InsufficientBalanceException;
use Moneo\UsageLimiter\Exceptions\ReservationExpiredException;
use Moneo\UsageLimiter\Exceptions\UsageLimitExceededException;
use Throwable;

/**
 * High-level entrypoint for metered work with a distinct execution phase.
 *
 * Wraps the Reserve → Execute → Commit/Release lifecycle into a single call.
 */
class ExecutionGateway
{
    public function __construct(
        private readonly UsageLimiter $limiter,
    ) {}

    /**
     * Execute metered work.
     *
     * Reserve, run the callback, commit on success, release on exception.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T The callback's return value
     *
     * @throws UsageLimitExceededException If the reservation is denied by enforcement
     * @throws InsufficientBalanceException If the wallet cannot afford the usage (prepaid)
     * @throws Throwable If the callback throws (reservation is released before re-throwing)
     */
    public function execute(
        int $billingAccountId,
        string $metricCode,
        int $amount,
        Closure $callback,
        ?string $idempotencyKey = null,
        ?array $metadata = null,
    ): mixed {
        $attempt = new UsageAttempt(
            billingAccountId: $billingAccountId,
            metricCode: $metricCode,
            amount: $amount,
            idempotencyKey: $idempotencyKey,
            metadata: $metadata,
        );

        // RESERVE — throws if denied
        $reservation = $this->limiter->reserve($attempt);

        // EXECUTE + COMMIT
        try {
            $result = $callback();
            $this->limiter->commit($reservation->ulid);
        } catch (ReservationExpiredException $e) {
            // Callback succeeded but reservation expired (TTL exceeded during execution).
            // Side effects already applied — log for revenue reconciliation.
            report($e);
            throw $e;
        } catch (Throwable $e) {
            // RELEASE on failure (callback or commit failure)
            $this->limiter->release($reservation->ulid);
            throw $e;
        }

        return $result;
    }
}
