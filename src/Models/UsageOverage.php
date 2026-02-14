<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Models;

use Illuminate\Database\Eloquent\Model;
use Moneo\UsageLimiter\Enums\OverageSettlementMode;
use Moneo\UsageLimiter\Models\Concerns\UsesPackageConnection;

class UsageOverage extends Model
{
    use UsesPackageConnection;

    protected $fillable = [
        'billing_account_id',
        'metric_code',
        'period_start',
        'overage_amount',
        'overage_unit_size',
        'unit_price_cents',
        'total_price_cents',
        'settlement_status',
        'invoiced_at',
        'metadata',
    ];

    public function getTable(): string
    {
        return $this->prefixedTable('usage_overages');
    }

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'overage_amount' => 'integer',
            'overage_unit_size' => 'integer',
            'unit_price_cents' => 'integer',
            'total_price_cents' => 'integer',
            'settlement_status' => OverageSettlementMode::class,
            'invoiced_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function scopePending($query)
    {
        return $query->where('settlement_status', OverageSettlementMode::Pending);
    }

    public function scopeForAccountPeriod($query, int $billingAccountId, string $periodStart)
    {
        return $query->where('billing_account_id', $billingAccountId)
            ->where('period_start', $periodStart);
    }
}
