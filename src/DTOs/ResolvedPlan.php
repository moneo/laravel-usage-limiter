<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\DTOs;

final readonly class ResolvedPlan
{
    /**
     * @param  array<string, ResolvedMetricLimit>  $metrics  Keyed by metric_code
     */
    public function __construct(
        public int $planId,
        public string $planCode,
        public array $metrics,
    ) {}

    public function getMetric(string $metricCode): ?ResolvedMetricLimit
    {
        return $this->metrics[$metricCode] ?? null;
    }

    public function hasMetric(string $metricCode): bool
    {
        return isset($this->metrics[$metricCode]);
    }

    /**
     * @return list<string>
     */
    public function metricCodes(): array
    {
        return array_keys($this->metrics);
    }
}
