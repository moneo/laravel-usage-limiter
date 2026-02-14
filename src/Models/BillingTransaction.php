<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Moneo\UsageLimiter\Enums\TransactionType;
use Moneo\UsageLimiter\Models\Concerns\UsesPackageConnection;

class BillingTransaction extends Model
{
    use UsesPackageConnection;

    public $timestamps = false;

    protected $fillable = [
        'billing_account_id',
        'type',
        'amount_cents',
        'balance_after_cents',
        'reference_type',
        'reference_id',
        'idempotency_key',
        'description',
        'metadata',
        'created_at',
    ];

    public function getTable(): string
    {
        return $this->prefixedTable('billing_transactions');
    }

    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'amount_cents' => 'integer',
            'balance_after_cents' => 'integer',
            'reference_id' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function billingAccount(): BelongsTo
    {
        return $this->belongsTo(BillingAccount::class, 'billing_account_id');
    }

    public function scopeForAccount($query, int $billingAccountId)
    {
        return $query->where('billing_account_id', $billingAccountId);
    }
}
