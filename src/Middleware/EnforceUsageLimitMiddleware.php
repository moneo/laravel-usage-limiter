<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Middleware;

use Closure;
use Moneo\UsageLimiter\Contracts\UsageLimiterAware;
use Moneo\UsageLimiter\Core\UsageLimiter;
use Moneo\UsageLimiter\DTOs\UsageAttempt;
use Moneo\UsageLimiter\Exceptions\InsufficientBalanceException;
use Moneo\UsageLimiter\Exceptions\UsageLimitExceededException;
use Throwable;

/**
 * Laravel Job Middleware for enforcing usage limits.
 *
 * The job must implement UsageLimiterAware to provide billing account,
 * metric code, amount, and idempotency key.
 *
 * Flow: Reserve → handle() → Commit (success) / Release (failure)
 */
class EnforceUsageLimitMiddleware
{
    private ?UsageLimiter $limiter = null;

    /**
     * Process the queued job.
     *
     * @param  \Closure(object): void  $next
     *
     * @throws UsageLimitExceededException
     * @throws InsufficientBalanceException
     */
    public function handle(object $job, Closure $next): void
    {
        if (! $job instanceof UsageLimiterAware) {
            // Job doesn't implement UsageLimiterAware — pass through without metering
            $next($job);

            return;
        }

        $limiter = $this->resolveLimiter();

        $attempt = new UsageAttempt(
            billingAccountId: $job->billingAccountId(),
            metricCode: $job->metricCode(),
            amount: $job->usageAmount(),
            idempotencyKey: $job->usageIdempotencyKey(),
            metadata: [
                'job_class' => get_class($job),
                'middleware' => self::class,
            ],
        );

        // RESERVE — throws if denied
        $reservation = $limiter->reserve($attempt);

        try {
            // EXECUTE
            $next($job);

            // COMMIT on success
            $limiter->commit($reservation->ulid);
        } catch (Throwable $e) {
            // RELEASE on failure
            $limiter->release($reservation->ulid);
            throw $e;
        }
    }

    private function resolveLimiter(): UsageLimiter
    {
        if ($this->limiter === null) {
            $this->limiter = app(UsageLimiter::class);
        }

        return $this->limiter;
    }
}
