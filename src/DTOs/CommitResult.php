<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\DTOs;

final readonly class CommitResult
{
    public function __construct(
        public string $ulid,
        public bool $committed,
        public bool $charged,
        public int $chargedAmountCents,
        public bool $overageRecorded,
        public ?string $warning = null,
    ) {}
}
