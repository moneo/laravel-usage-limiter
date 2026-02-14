<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Moneo\UsageLimiter\Enums\EnforcementMode;
use Moneo\UsageLimiter\Enums\PricingMode;
use Moneo\UsageLimiter\Models\Concerns\UsesPackageConnection;

class PlanMetricLimit extends Model
{
    use UsesPackageConnection;

    protected $fillable = [
        'plan_id',
        'metric_code',
        'included_amount',
        'overage_enabled',
        'overage_unit_size',
        'overage_price_cents',
        'pricing_mode',
        'enforcement_mode',
        'max_overage_amount',
        'hybrid_overflow_mode',
        'metadata',
    ];

    public function getTable(): string
    {
        return $this->prefixedTable('plan_metric_limits');
    }

    protected function casts(): array
    {
        return [
            'included_amount' => 'integer',
            'overage_enabled' => 'boolean',
            'overage_unit_size' => 'integer',
            'overage_price_cents' => 'integer',
            'pricing_mode' => PricingMode::class,
            'enforcement_mode' => EnforcementMode::class,
            'max_overage_amount' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }
}
