<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Models;

use Illuminate\Database\Eloquent\Model;
use Moneo\UsageLimiter\Models\Concerns\UsesPackageConnection;

class IdempotencyRecord extends Model
{
    use UsesPackageConnection;

    public $timestamps = false;

    protected $fillable = [
        'key',
        'scope',
        'result_type',
        'result_id',
        'result_payload',
        'expires_at',
        'created_at',
    ];

    public function getTable(): string
    {
        return $this->prefixedTable('idempotency_records');
    }

    protected function casts(): array
    {
        return [
            'result_id' => 'integer',
            'result_payload' => 'array',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function scopeExpiredBefore($query, $cutoff)
    {
        return $query->where('expires_at', '<', $cutoff);
    }
}
