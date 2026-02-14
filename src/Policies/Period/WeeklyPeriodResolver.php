<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Policies\Period;

use Carbon\CarbonImmutable;
use Moneo\UsageLimiter\Contracts\PeriodResolver;
use Moneo\UsageLimiter\DTOs\Period;

class WeeklyPeriodResolver implements PeriodResolver
{
    public function current(?int $billingAccountId = null): Period
    {
        return $this->forDate(CarbonImmutable::now('UTC'), $billingAccountId);
    }

    public function forDate(CarbonImmutable $date, ?int $billingAccountId = null): Period
    {
        // ISO week: Monday to Sunday
        $start = $date->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
        $end = $date->endOfWeek(CarbonImmutable::SUNDAY)->endOfDay();

        return new Period(
            start: $start,
            end: $end,
            key: $start->format('o-\\WW'), // ISO year + week number: "2026-W07"
        );
    }
}
