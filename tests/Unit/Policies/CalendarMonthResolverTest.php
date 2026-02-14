<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Tests\Unit\Policies;

use Carbon\CarbonImmutable;
use Moneo\UsageLimiter\Policies\Period\CalendarMonthResolver;
use PHPUnit\Framework\TestCase;

class CalendarMonthResolverTest extends TestCase
{
    private CalendarMonthResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new CalendarMonthResolver;
    }

    public function test_resolves_february(): void
    {
        $period = $this->resolver->forDate(CarbonImmutable::parse('2026-02-14'));

        $this->assertEquals('2026-02-01', $period->startDate());
        $this->assertEquals('2026-02-28', $period->endDate());
        $this->assertEquals('2026-02', $period->key);
    }

    public function test_resolves_leap_year_february(): void
    {
        $period = $this->resolver->forDate(CarbonImmutable::parse('2028-02-15'));

        $this->assertEquals('2028-02-01', $period->startDate());
        $this->assertEquals('2028-02-29', $period->endDate());
    }

    public function test_resolves_december(): void
    {
        $period = $this->resolver->forDate(CarbonImmutable::parse('2026-12-31'));

        $this->assertEquals('2026-12-01', $period->startDate());
        $this->assertEquals('2026-12-31', $period->endDate());
        $this->assertEquals('2026-12', $period->key);
    }

    public function test_first_of_month_stays_in_same_period(): void
    {
        $period = $this->resolver->forDate(CarbonImmutable::parse('2026-03-01'));

        $this->assertEquals('2026-03-01', $period->startDate());
        $this->assertEquals('2026-03-31', $period->endDate());
    }
}
