<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Moneo\UsageLimiter\Models\Concerns\UsesPackageConnection;

class BillingAccount extends Model
{
    use UsesPackageConnection;

    protected $fillable = [
        'external_id',
        'name',
        'wallet_balance_cents',
        'wallet_currency',
        'auto_topup_enabled',
        'auto_topup_threshold_cents',
        'auto_topup_amount_cents',
        'is_active',
        'metadata',
    ];

    public function getTable(): string
    {
        return $this->prefixedTable('billing_accounts');
    }

    protected function casts(): array
    {
        return [
            'wallet_balance_cents' => 'integer',
            'auto_topup_enabled' => 'boolean',
            'auto_topup_threshold_cents' => 'integer',
            'auto_topup_amount_cents' => 'integer',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function planAssignments(): HasMany
    {
        return $this->hasMany(BillingAccountPlanAssignment::class, 'billing_account_id');
    }

    public function currentPlanAssignment(): HasOne
    {
        return $this->hasOne(BillingAccountPlanAssignment::class, 'billing_account_id')
            ->whereNull('ended_at')
            ->latestOfMany('started_at');
    }

    public function metricOverrides(): HasMany
    {
        return $this->hasMany(BillingAccountMetricOverride::class, 'billing_account_id');
    }

    public function activeMetricOverrides(): HasMany
    {
        return $this->hasMany(BillingAccountMetricOverride::class, 'billing_account_id')
            ->whereNull('ended_at');
    }

    public function periodAggregates(): HasMany
    {
        return $this->hasMany(UsagePeriodAggregate::class, 'billing_account_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(UsageReservation::class, 'billing_account_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BillingTransaction::class, 'billing_account_id');
    }

    public function overages(): HasMany
    {
        return $this->hasMany(UsageOverage::class, 'billing_account_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
