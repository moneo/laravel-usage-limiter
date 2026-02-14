<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Moneo\UsageLimiter\Contracts\IdempotencyStore;
use Moneo\UsageLimiter\Models\IdempotencyRecord;

class EloquentIdempotencyStore implements IdempotencyStore
{
    public function check(string $key, string $scope): ?IdempotencyRecord
    {
        return IdempotencyRecord::where('key', $key)
            ->where('scope', $scope)
            ->first();
    }

    public function store(
        string $key,
        string $scope,
        ?string $resultType = null,
        ?int $resultId = null,
        ?array $payload = null,
        ?int $ttlHours = null,
    ): IdempotencyRecord {
        $ttlHours = max($ttlHours ?? (int) config('usage-limiter.idempotency_ttl_hours', 48), 1);

        try {
            return IdempotencyRecord::create([
                'key' => $key,
                'scope' => $scope,
                'result_type' => $resultType,
                'result_id' => $resultId,
                'result_payload' => $payload,
                'expires_at' => now('UTC')->addHours($ttlHours),
                'created_at' => now('UTC'),
            ]);
        } catch (QueryException $e) {
            // Duplicate key — fetch and return existing
            $code = (string) $e->getCode();
            if ($code === '23000' || $code === '23505' || str_contains($e->getMessage(), 'Duplicate entry')) {
                return $this->check($key, $scope);
            }
            throw $e;
        }
    }

    public function cleanup(CarbonImmutable $olderThan): int
    {
        return IdempotencyRecord::where('expires_at', '<', $olderThan)->delete();
    }
}
