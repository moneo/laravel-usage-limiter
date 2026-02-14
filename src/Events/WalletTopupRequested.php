<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Events;

use Illuminate\Foundation\Events\Dispatchable;

class WalletTopupRequested
{
    use Dispatchable;

    public function __construct(
        public readonly int $billingAccountId,
        public readonly int $requestedAmountCents,
        public readonly int $currentBalanceCents,
    ) {}
}
