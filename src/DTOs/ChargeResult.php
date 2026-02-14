<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\DTOs;

final readonly class ChargeResult
{
    public function __construct(
        public bool $charged,
        public int $amountCents,
        public bool $overageRecorded,
        public ?string $transactionIdempotencyKey = null,
    ) {}

    public static function free(): self
    {
        return new self(charged: false, amountCents: 0, overageRecorded: false);
    }

    public static function noCharge(): self
    {
        return new self(charged: false, amountCents: 0, overageRecorded: false);
    }
}
