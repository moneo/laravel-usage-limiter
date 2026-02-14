<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Exceptions;

use RuntimeException;

class IdempotencyConflictException extends RuntimeException
{
    public function __construct(
        public readonly ?string $idempotencyKey = null,
        string $message = '',
    ) {
        if ($message === '') {
            $message = 'Idempotency conflict';
            if ($this->idempotencyKey !== null) {
                $message .= " for key '{$this->idempotencyKey}'";
            }
        }

        parent::__construct($message);
    }
}
