<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Moneo\UsageLimiter\Contracts\IdempotencyStore;

class CleanupIdempotencyCommand extends Command
{
    protected $signature = 'usage:cleanup-idempotency';

    protected $description = 'Purge expired idempotency records';

    public function handle(IdempotencyStore $store): int
    {
        $this->info('Cleaning up expired idempotency records...');

        $count = $store->cleanup(CarbonImmutable::now('UTC'));

        $this->info("Deleted {$count} expired idempotency record(s).");

        return self::SUCCESS;
    }
}
