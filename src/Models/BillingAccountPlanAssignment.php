<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Moneo\UsageLimiter\Models\Concerns\UsesPackageConnection;

class BillingAccountPlanAssignment extends Model
{
    use UsesPackageConnection;

    protected $fillable = [
        'billing_account_id',
        'plan_id',
        'started_at',
        'ended_at',
        'metadata',
    ];

    public function getTable(): string
    {
        return $this->prefixedTable('billing_account_plan_assignments');
    }

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function billingAccount(): BelongsTo
    {
        return $this->belongsTo(BillingAccount::class, 'billing_account_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function scopeCurrent($query)
    {
        return $query->whereNull('ended_at');
    }
}
