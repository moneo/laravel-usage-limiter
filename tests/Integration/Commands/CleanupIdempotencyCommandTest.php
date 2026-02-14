<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Tests\Integration\Commands;

use Moneo\UsageLimiter\Contracts\IdempotencyStore;
use Moneo\UsageLimiter\Models\IdempotencyRecord;
use Moneo\UsageLimiter\Tests\TestCase;

class CleanupIdempotencyCommandTest extends TestCase
{
    public function test_purges_expired_records(): void
    {
        $store = app(IdempotencyStore::class);

        $store->store('expired-1', 'test', ttlHours: 1);
        $store->store('expired-2', 'test', ttlHours: 1);
        IdempotencyRecord::query()->update(['expires_at' => now()->subHour()]);

        $store->store('fresh-1', 'test2', ttlHours: 48);

        $this->artisan('usage:cleanup-idempotency')
            ->assertSuccessful();

        $this->assertEquals(1, IdempotencyRecord::count());
        $this->assertDatabaseHas('ul_idempotency_records', ['key' => 'fresh-1']);
    }

    public function test_retains_non_expired_records(): void
    {
        $store = app(IdempotencyStore::class);

        $store->store('fresh-1', 'test', ttlHours: 48);
        $store->store('fresh-2', 'test2', ttlHours: 48);

        $this->artisan('usage:cleanup-idempotency')
            ->assertSuccessful();

        $this->assertEquals(2, IdempotencyRecord::count());
    }
}
