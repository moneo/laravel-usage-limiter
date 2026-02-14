<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\DTOs;

final readonly class AffordabilityResult
{
    public function __construct(
        public bool $affordable,
        public int $estimatedCostCents,
        public ?string $reason = null,
        public bool $isInsufficientBalance = false,
    ) {}

    public static function canAfford(int $estimatedCostCents = 0): self
    {
        return new self(affordable: true, estimatedCostCents: $estimatedCostCents);
    }

    public static function cannotAfford(string $reason, int $estimatedCostCents = 0, bool $isInsufficientBalance = false): self
    {
        return new self(
            affordable: false,
            estimatedCostCents: $estimatedCostCents,
            reason: $reason,
            isInsufficientBalance: $isInsufficientBalance,
        );
    }

    public static function free(): self
    {
        return new self(affordable: true, estimatedCostCents: 0);
    }
}
