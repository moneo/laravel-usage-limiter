<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Policies\Period;

use Carbon\CarbonImmutable;
use Moneo\UsageLimiter\Contracts\PeriodResolver;
use Moneo\UsageLimiter\DTOs\Period;
use Moneo\UsageLimiter\Models\BillingAccount;

/**
 * Rolling 30-day period anchored to the billing account's creation date.
 *
 * Each account gets 30-day periods starting from when they were created.
 * For example, if created on Jan 15, periods are: Jan 15 - Feb 13, Feb 14 - Mar 15, etc.
 */
class Rolling30DayResolver implements PeriodResolver
{
    private const PERIOD_DAYS = 30;

    public function current(?int $billingAccountId = null): Period
    {
        return $this->forDate(CarbonImmutable::now('UTC'), $billingAccountId);
    }

    public function forDate(CarbonImmutable $date, ?int $billingAccountId = null): Period
    {
        $anchor = $this->getAnchorDate($billingAccountId);

        // Calculate how many complete 30-day periods have elapsed since the anchor
        $daysSinceAnchor = $anchor->diffInDays($date, false);
        $periodNumber = (int) floor($daysSinceAnchor / self::PERIOD_DAYS);

        $start = $anchor->addDays($periodNumber * self::PERIOD_DAYS)->startOfDay();
        $end = $start->addDays(self::PERIOD_DAYS - 1)->endOfDay();

        return new Period(
            start: $start,
            end: $end,
            key: "rolling-{$start->format('Y-m-d')}",
        );
    }

    private function getAnchorDate(?int $billingAccountId): CarbonImmutable
    {
        if ($billingAccountId !== null) {
            $account = BillingAccount::find($billingAccountId);
            if ($account !== null) {
                return CarbonImmutable::parse($account->created_at)->startOfDay();
            }
        }

        // Fallback: use the first of the current month
        return CarbonImmutable::now('UTC')->startOfMonth()->startOfDay();
    }
}
