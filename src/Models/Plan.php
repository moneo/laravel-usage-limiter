<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Moneo\UsageLimiter\Models\Concerns\UsesPackageConnection;

class Plan extends Model
{
    use UsesPackageConnection;

    protected $fillable = [
        'code',
        'name',
        'is_active',
        'metadata',
    ];

    public function getTable(): string
    {
        return $this->prefixedTable('plans');
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function metricLimits(): HasMany
    {
        return $this->hasMany(PlanMetricLimit::class, 'plan_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(BillingAccountPlanAssignment::class, 'plan_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
