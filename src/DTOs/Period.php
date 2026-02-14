<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\DTOs;

use Carbon\CarbonImmutable;

final readonly class Period
{
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public string $key,
    ) {}

    public function startDate(): string
    {
        return $this->start->format('Y-m-d');
    }

    public function endDate(): string
    {
        return $this->end->format('Y-m-d');
    }

    public function contains(CarbonImmutable $date): bool
    {
        return $date->gte($this->start) && $date->lte($this->end);
    }
}
