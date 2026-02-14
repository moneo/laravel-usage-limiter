<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Tests\Concerns;

use Moneo\UsageLimiter\Tests\Helpers\FailpointManager;

/**
 * Trait for tests that need to inject failpoints into the system.
 *
 * Automatically resets the FailpointManager before each test and binds
 * it into the container so the FailpointAware repository decorators can find it.
 */
trait SimulatesFailpoints
{
    protected FailpointManager $failpoints;

    protected function setUpFailpoints(): void
    {
        FailpointManager::reset();
        $this->failpoints = FailpointManager::instance();
        $this->app->instance(FailpointManager::class, $this->failpoints);
    }

    protected function armFailpoint(string $name, \Closure|true $action = true): void
    {
        $this->failpoints->arm($name, $action);
    }

    protected function assertFailpointHit(string $name, int $expectedCount = 1): void
    {
        $this->assertEquals(
            $expectedCount,
            $this->failpoints->hitCount($name),
            "Expected failpoint '{$name}' to be hit {$expectedCount} time(s), got {$this->failpoints->hitCount($name)}",
        );
    }
}
