<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Tests\Unit\DTOs;

use Carbon\CarbonImmutable;
use Moneo\UsageLimiter\DTOs\Period;
use PHPUnit\Framework\TestCase;

class PeriodTest extends TestCase
{
    public function test_period_dates(): void
    {
        $period = new Period(
            start: CarbonImmutable::parse('2026-02-01'),
            end: CarbonImmutable::parse('2026-02-28'),
            key: '2026-02',
        );

        $this->assertEquals('2026-02-01', $period->startDate());
        $this->assertEquals('2026-02-28', $period->endDate());
        $this->assertEquals('2026-02', $period->key);
    }

    public function test_period_contains(): void
    {
        $period = new Period(
            start: CarbonImmutable::parse('2026-02-01'),
            end: CarbonImmutable::parse('2026-02-28'),
            key: '2026-02',
        );

        $this->assertTrue($period->contains(CarbonImmutable::parse('2026-02-15')));
        $this->assertTrue($period->contains(CarbonImmutable::parse('2026-02-01')));
        $this->assertTrue($period->contains(CarbonImmutable::parse('2026-02-28')));
        $this->assertFalse($period->contains(CarbonImmutable::parse('2026-01-31')));
        $this->assertFalse($period->contains(CarbonImmutable::parse('2026-03-01')));
    }
}
