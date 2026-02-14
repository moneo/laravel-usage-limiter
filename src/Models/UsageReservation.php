<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Models;

use Illuminate\Database\Eloquent\Model;
use Moneo\UsageLimiter\Enums\ReservationStatus;
use Moneo\UsageLimiter\Models\Concerns\UsesPackageConnection;

class UsageReservation extends Model
{
    use UsesPackageConnection;

    protected $fillable = [
        'ulid',
        'billing_account_id',
        'metric_code',
        'period_start',
        'amount',
        'idempotency_key',
        'status',
        'reserved_at',
        'committed_at',
        'released_at',
        'expires_at',
        'metadata',
    ];

    public function getTable(): string
    {
        return $this->prefixedTable('usage_reservations');
    }

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'status' => ReservationStatus::class,
            'period_start' => 'date',
            'reserved_at' => 'datetime',
            'committed_at' => 'datetime',
            'released_at' => 'datetime',
            'expires_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === ReservationStatus::Pending;
    }

    public function isCommitted(): bool
    {
        return $this->status === ReservationStatus::Committed;
    }

    public function isReleased(): bool
    {
        return $this->status === ReservationStatus::Released;
    }

    public function isExpired(): bool
    {
        return $this->status === ReservationStatus::Expired;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            ReservationStatus::Committed,
            ReservationStatus::Released,
            ReservationStatus::Expired,
        ], true);
    }

    public function scopePending($query)
    {
        return $query->where('status', ReservationStatus::Pending);
    }

    public function scopeCommitted($query)
    {
        return $query->where('status', ReservationStatus::Committed);
    }

    public function scopeExpiredBefore($query, $cutoff)
    {
        return $query->where('status', ReservationStatus::Pending)
            ->where('expires_at', '<', $cutoff);
    }
}
