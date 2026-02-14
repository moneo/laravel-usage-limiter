<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Contracts;

/**
 * Interface for jobs that consume metered resources.
 *
 * Jobs implementing this interface can be used with EnforceUsageLimitMiddleware.
 */
interface UsageLimiterAware
{
    /**
     * Get the billing account ID for this job's usage.
     */
    public function billingAccountId(): int;

    /**
     * Get the metric code this job consumes.
     */
    public function metricCode(): string;

    /**
     * Get the amount of usage this job will consume.
     */
    public function usageAmount(): int;

    /**
     * Get the idempotency key for this job's usage reservation.
     *
     * Return null to skip idempotency (not recommended for queue jobs).
     */
    public function usageIdempotencyKey(): ?string;
}
