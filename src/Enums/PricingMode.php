<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Enums;

enum PricingMode: string
{
    case Prepaid = 'prepaid';
    case Postpaid = 'postpaid';
    case Hybrid = 'hybrid';
}
