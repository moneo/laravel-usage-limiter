<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Exceptions;

use Moneo\UsageLimiter\DTOs\ReservationResult;
use RuntimeException;

class UsageLimitExceededException extends RuntimeException
{
    public function __construct(
        public readonly ?ReservationResult $result = null,
        public readonly ?string $metricCode = null,
        public readonly ?int $billingAccountId = null,
        string $message = '',
    ) {
        if ($message === '') {
            $message = 'Usage limit exceeded';
            if ($this->metricCode !== null) {
                $message .= " for metric '{$this->metricCode}'";
            }
            if ($this->billingAccountId !== null) {
                $message .= " on billing account {$this->billingAccountId}";
            }
        }

        parent::__construct($message);
    }
}
