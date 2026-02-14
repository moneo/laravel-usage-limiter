<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Moneo\UsageLimiter\Enums\EnforcementMode;
use Moneo\UsageLimiter\Enums\PricingMode;
use Moneo\UsageLimiter\Models\Concerns\UsesPackageConnection;

class BillingAccountMetricOverride extends Model
{
    use UsesPackageConnection;

    protected $fillable = [
        'billing_account_id',
        'metric_code',
        'included_amount',
        'overage_enabled',
        'overage_unit_size',
        'overage_price_cents',
        'pricing_mode',
        'enforcement_mode',
        'max_overage_amount',
        'hybrid_overflow_mode',
        'reason',
        'started_at',
        'ended_at',
        'metadata',
    ];

    public function getTable(): string
    {
        return $this->prefixedTable('billing_account_metric_overrides');
    }

    protected function casts(): array
    {
        // IMPORTANT: nullable override columns must NOT be cast to scalar types
        // (integer, boolean) because Eloquent converts null -> 0 / false, which
        // breaks the null-coalescing merge logic in EloquentPlanResolver.
        // Only cast non-nullable fields and enums/json here.
        return [
            'pricing_mode' => PricingMode::class,
            'enforcement_mode' => EnforcementMode::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * Check whether a given field has an explicit override value (not null in DB).
     */
    public function hasOverrideFor(string $field): bool
    {
        return $this->getRawOriginal($field) !== null;
    }

    public function billingAccount(): BelongsTo
    {
        return $this->belongsTo(BillingAccount::class, 'billing_account_id');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('ended_at');
    }

    public function scopeForMetric($query, string $metricCode)
    {
        return $query->where('metric_code', $metricCode);
    }
}
