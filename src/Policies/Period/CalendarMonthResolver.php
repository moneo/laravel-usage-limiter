<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Policies\Period;

use Carbon\CarbonImmutable;
use Moneo\UsageLimiter\Contracts\PeriodResolver;
use Moneo\UsageLimiter\DTOs\Period;

class CalendarMonthResolver implements PeriodResolver
{
    public function current(?int $billingAccountId = null): Period
    {
        return $this->forDate(CarbonImmutable::now('UTC'), $billingAccountId);
    }

    public function forDate(CarbonImmutable $date, ?int $billingAccountId = null): Period
    {
        $start = $date->startOfMonth()->startOfDay();
        $end = $date->endOfMonth()->endOfDay();

        return new Period(
            start: $start,
            end: $end,
            key: $start->format('Y-m'),
        );
    }
}
