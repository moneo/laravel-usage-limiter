<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Exceptions;

use RuntimeException;

class InsufficientBalanceException extends RuntimeException
{
    public function __construct(
        public readonly ?int $billingAccountId = null,
        public readonly ?int $requiredCents = null,
        public readonly ?int $availableCents = null,
        string $message = '',
    ) {
        if ($message === '') {
            $message = 'Insufficient wallet balance';
            if ($this->billingAccountId !== null) {
                $message .= " for billing account {$this->billingAccountId}";
            }
            if ($this->requiredCents !== null && $this->availableCents !== null) {
                $message .= " (required: {$this->requiredCents}, available: {$this->availableCents})";
            }
        }

        parent::__construct($message);
    }
}
