<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Enums;

enum TransactionType: string
{
    case Credit = 'credit';
    case Debit = 'debit';
    case Refund = 'refund';
    case Adjustment = 'adjustment';
}
