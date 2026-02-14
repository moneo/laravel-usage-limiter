<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Contracts;

use Carbon\CarbonImmutable;
use Moneo\UsageLimiter\Models\IdempotencyRecord;

interface IdempotencyStore
{
    /**
     * Check if an idempotency record exists for the given key and scope.
     */
    public function check(string $key, string $scope): ?IdempotencyRecord;

    /**
     * Store a new idempotency record. Throws on duplicate.
     */
    public function store(
        string $key,
        string $scope,
        ?string $resultType = null,
        ?int $resultId = null,
        ?array $payload = null,
        ?int $ttlHours = null,
    ): IdempotencyRecord;

    /**
     * Purge expired idempotency records.
     *
     * @return int Number of records deleted
     */
    public function cleanup(CarbonImmutable $olderThan): int;
}
