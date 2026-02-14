<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\DTOs;

final readonly class ReleaseResult
{
    public function __construct(
        public string $ulid,
        public bool $released,
        public bool $refunded,
        public int $refundedAmountCents,
    ) {}
}
