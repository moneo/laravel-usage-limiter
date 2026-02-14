<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Moneo\UsageLimiter\Contracts\UsageRepository;

class ExpireReservationsCommand extends Command
{
    protected $signature = 'usage:expire-reservations';

    protected $description = 'Expire stale pending reservations and release their usage holds';

    public function handle(UsageRepository $repository): int
    {
        $cutoff = CarbonImmutable::now('UTC');

        $this->info('Expiring stale pending reservations...');

        $count = $repository->expireStalePendingReservations($cutoff);

        $this->info("Expired {$count} reservation(s).");

        return self::SUCCESS;
    }
}
