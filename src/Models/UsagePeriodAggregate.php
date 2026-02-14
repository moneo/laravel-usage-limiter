<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Models;

use Illuminate\Database\Eloquent\Model;
use Moneo\UsageLimiter\Models\Concerns\UsesPackageConnection;

class UsagePeriodAggregate extends Model
{
    use UsesPackageConnection;

    protected $fillable = [
        'billing_account_id',
        'metric_code',
        'period_start',
        'period_end',
        'metadata',
    ];

    public function getTable(): string
    {
        return $this->prefixedTable('usage_period_aggregates');
    }

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'committed_usage' => 'integer',
            'reserved_usage' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function totalUsage(): int
    {
        return $this->committed_usage + $this->reserved_usage;
    }

    public function scopeForAccountMetricPeriod($query, int $billingAccountId, string $metricCode, string $periodStart)
    {
        return $query->where('billing_account_id', $billingAccountId)
            ->where('metric_code', $metricCode)
            ->where('period_start', $periodStart);
    }
}
