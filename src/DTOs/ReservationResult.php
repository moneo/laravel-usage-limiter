<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\DTOs;

final readonly class ReservationResult
{
    public function __construct(
        public string $ulid,
        public bool $allowed,
        public EnforcementDecision $decision,
        public ?string $warning = null,
        public ?array $metadata = null,
        public bool $isInsufficientBalance = false,
    ) {}

    public static function denied(string $reason, bool $isInsufficientBalance = false): self
    {
        return new self(
            ulid: '',
            allowed: false,
            decision: EnforcementDecision::Deny,
            warning: $reason,
            isInsufficientBalance: $isInsufficientBalance,
        );
    }
}
