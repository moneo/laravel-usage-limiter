<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\DTOs;

final readonly class RefundResult
{
    public function __construct(
        public bool $refunded,
        public int $amountCents,
    ) {}

    public static function nothingToRefund(): self
    {
        return new self(refunded: false, amountCents: 0);
    }
}
