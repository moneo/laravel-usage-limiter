<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Support;

use Illuminate\Support\Facades\Facade;
use Moneo\UsageLimiter\Core\UsageLimiter;

/**
 * @method static \Moneo\UsageLimiter\DTOs\ReservationResult reserve(\Moneo\UsageLimiter\DTOs\UsageAttempt $attempt)
 * @method static \Moneo\UsageLimiter\DTOs\CommitResult commit(string $reservationUlid)
 * @method static \Moneo\UsageLimiter\DTOs\ReleaseResult release(string $reservationUlid)
 * @method static \Moneo\UsageLimiter\DTOs\EnforcementDecision check(int $billingAccountId, string $metricCode, int $amount)
 * @method static array currentUsage(int $billingAccountId, string $metricCode)
 * @method static void invalidatePlanCache(int $billingAccountId)
 *
 * @see \Moneo\UsageLimiter\Core\UsageLimiter
 */
class UsageLimiterFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return UsageLimiter::class;
    }
}
