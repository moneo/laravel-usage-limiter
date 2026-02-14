<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\DTOs;

final readonly class UsageAttempt
{
    public const MAX_AMOUNT = 1_000_000_000;

    public function __construct(
        public int $billingAccountId,
        public string $metricCode,
        public int $amount,
        public ?string $idempotencyKey = null,
        public ?array $metadata = null,
    ) {
        if ($this->billingAccountId <= 0) {
            throw new \InvalidArgumentException("billingAccountId must be a positive integer, got {$this->billingAccountId}");
        }

        if (trim($this->metricCode) === '') {
            throw new \InvalidArgumentException('metricCode must not be empty');
        }

        if ($this->amount <= 0) {
            throw new \InvalidArgumentException("Usage amount must be greater than zero, got {$this->amount}");
        }

        if ($this->amount > self::MAX_AMOUNT) {
            throw new \InvalidArgumentException(
                'Usage amount must not exceed '.self::MAX_AMOUNT.", got {$this->amount}"
            );
        }
    }
}
