<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Contracts;

use Carbon\CarbonImmutable;
use Moneo\UsageLimiter\DTOs\Period;

interface PeriodResolver
{
    /**
     * Get the current billing period.
     *
     * @param  int|null  $billingAccountId  Needed for per-account anchors (rolling resolvers)
     */
    public function current(?int $billingAccountId = null): Period;

    /**
     * Get the billing period containing the given date.
     *
     * @param  int|null  $billingAccountId  Needed for per-account anchors (rolling resolvers)
     */
    public function forDate(CarbonImmutable $date, ?int $billingAccountId = null): Period;
}
