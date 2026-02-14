<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Exceptions;

use RuntimeException;

class ReservationExpiredException extends RuntimeException
{
    public function __construct(
        public readonly ?string $reservationUlid = null,
        string $message = '',
    ) {
        if ($message === '') {
            $message = 'Reservation has expired';
            if ($this->reservationUlid !== null) {
                $message .= " (ULID: {$this->reservationUlid})";
            }
        }

        parent::__construct($message);
    }
}
