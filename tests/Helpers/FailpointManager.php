<?php

declare(strict_types=1);

namespace Moneo\UsageLimiter\Tests\Helpers;

/**
 * Test-only singleton that allows injecting exceptions at named points in the code.
 *
 * Production code calls FailpointManager::check('step.name') which is a no-op
 * when no failpoints are armed. Tests arm failpoints before exercising code paths
 * to simulate crashes at specific points.
 */
final class FailpointManager
{
    private static ?self $instance = null;

    /** @var array<string, \Closure|true> */
    private array $failpoints = [];

    /** @var array<string, int> */
    private array $hitCounts = [];

    private function __construct() {}

    public static function instance(): self
    {
        return self::$instance ??= new self;
    }

    public static function reset(): void
    {
        self::$instance = new self;
    }

    /**
     * Arm a named failpoint.
     *
     * @param  string  $name  Named step (e.g. 'reserve.afterAggregateUpdate')
     * @param  \Closure|true  $action  true = throw RuntimeException, Closure = call custom action
     */
    public function arm(string $name, \Closure|true $action = true): void
    {
        $this->failpoints[$name] = $action;
    }

    /**
     * Disarm a named failpoint.
     */
    public function disarm(string $name): void
    {
        unset($this->failpoints[$name]);
    }

    /**
     * Check if a failpoint is armed, and if so, trigger it.
     *
     * Called from instrumented code paths. No-op when not armed.
     */
    public function check(string $name): void
    {
        $this->hitCounts[$name] = ($this->hitCounts[$name] ?? 0) + 1;

        if (! isset($this->failpoints[$name])) {
            return;
        }

        $action = $this->failpoints[$name];

        if ($action === true) {
            throw new \RuntimeException("Failpoint triggered: {$name}");
        }

        $action();
    }

    /**
     * How many times a named failpoint has been checked (hit).
     */
    public function hitCount(string $name): int
    {
        return $this->hitCounts[$name] ?? 0;
    }

    /**
     * Whether a failpoint is currently armed.
     */
    public function isArmed(string $name): bool
    {
        return isset($this->failpoints[$name]);
    }

    /**
     * Get all armed failpoint names.
     *
     * @return list<string>
     */
    public function armedFailpoints(): array
    {
        return array_keys($this->failpoints);
    }
}
